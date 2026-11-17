<?php
declare(strict_types=1);

/**
 * Warm data/image-cache.json (P2-4).
 *
 * The chat endpoint resolves a product image from the enriched catalog row when the row
 * has one, and otherwise does one bounded scrape per URL and stores the result in the
 * image cache. That leaves the first visitor of every cache-less product waiting on a
 * scrape, so this script pre-fills the file: catalog image where the row carries one, one
 * scrape through wot_product_images() where it does not.
 *
 * Then it verifies that every distinct image URL still answers 200, because a warm cache
 * full of dead URLs is worse than an empty one.
 *
 *   php tests/warm_images.php                     # warm + verify, write data/image-cache.json
 *   php tests/warm_images.php --no-verify          # skip the HTTP check (offline)
 *   php tests/warm_images.php --limit 50           # smoke test
 *   php tests/warm_images.php --cache /path/file.json
 *
 * No dependencies, no writes outside the cache file.
 */

require __DIR__ . '/../lib/catalog.php';
require __DIR__ . '/../lib/llm.php';

$root = dirname(__DIR__);

$opts = getopt('', ['cache:', 'no-verify', 'limit:', 'concurrency:', 'quiet']);
$cacheFile = $opts['cache'] ?? (rtrim((string) (getenv('WOT_DATA_DIR') ?: $root . '/data'), '/') . '/image-cache.json');
$verify    = !isset($opts['no-verify']);
$limit     = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 0;
$conc      = isset($opts['concurrency']) ? max(1, min(16, (int) $opts['concurrency'])) : 6;
$quiet     = isset($opts['quiet']);

$catalogFile = $root . '/products_enriched.json';
if (!is_file($catalogFile)) {
    fwrite(STDERR, "missing catalog: $catalogFile\n");
    exit(1);
}
$catalog = json_decode((string) file_get_contents($catalogFile), true);
$products = is_array($catalog) && isset($catalog['products']) && is_array($catalog['products'])
    ? $catalog['products']
    : [];
if ($products === []) {
    fwrite(STDERR, "catalog is empty: $catalogFile\n");
    exit(1);
}
if ($limit > 0) {
    $products = array_slice($products, 0, $limit);
}

$cache = [];
if (is_file($cacheFile)) {
    $raw = @file_get_contents($cacheFile);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $cache = $decoded;
    }
}

$scratch = tempnam(sys_get_temp_dir(), 'wotimg');
file_put_contents($scratch, '{}');

$seeded = 0;   // catalog row already had an image
$scraped = 0;  // resolved by fetching the product page
$unresolved = 0;
$already = 0;

foreach ($products as $row) {
    $url = wot_ensure_slash((string) ($row['url'] ?? ''));
    if ($url === '' || !str_starts_with($url, 'https://www.worldoftest.com/')) {
        continue;
    }
    $known = trim((string) ($row['image'] ?? ''));
    if ($known !== '') {
        if (($cache[$url] ?? '') === $known) {
            $already++;
        } else {
            $cache[$url] = $known;
            $seeded++;
        }
        continue;
    }
    if (($cache[$url] ?? '') !== '') {
        $already++;
        continue;
    }
    $name = (string) ($row['name'] ?? '');
    $images = wot_product_images(
        'See [' . $name . '](' . $url . ')',
        [['url' => $url, 'name' => $name, 'image' => '']],
        $scratch
    );
    $found = $images[$url] ?? '';
    if ($found !== '') {
        $cache[$url] = $found;
        $scraped++;
    } else {
        $unresolved++;
    }
}

ksort($cache);
$encoded = json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
$dir = dirname($cacheFile);
if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    @unlink($scratch);
    fwrite(STDERR, "cannot create $dir\n");
    exit(1);
}
if ($encoded === false || file_put_contents($cacheFile, $encoded . "\n") === false) {
    @unlink($scratch);
    fwrite(STDERR, "cannot write $cacheFile\n");
    exit(1);
}
@chmod($cacheFile, 0640);
@unlink($scratch);

if (!$quiet) {
    printf("cache: %s\n", $cacheFile);
    printf("entries: %d (seeded %d, scraped %d, already present %d, unresolved %d)\n",
        count($cache), $seeded, $scraped, $already, $unresolved);
}

if (!$verify) {
    exit(0);
}

// ── verify every distinct image URL still answers 200 ─────────────────────────
$urls = array_values(array_unique(array_filter($cache, static fn($v) => is_string($v) && $v !== '')));
$total = count($urls);
$ok = 0;
$bad = [];
$i = 0;

while ($i < $total) {
    $mh = curl_multi_init();
    $handles = [];
    for ($n = 0; $n < $conc && $i < $total; $n++, $i++) {
        $ch = curl_init($urls[$i]);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WOTConsultant/2.0)',
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int) $ch] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);
    foreach ($handles as $ch) {
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($code === 200) {
            $ok++;
        } else {
            $bad[] = 'HTTP ' . $code . ' ' . curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        }
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    if (!$quiet && $i % 600 < $conc) {
        printf("  checked %d/%d\n", min($i, $total), $total);
    }
}

printf("distinct image urls: %d\n", $total);
printf("http 200: %d\n", $ok);
printf("failed: %d\n", count($bad));
foreach (array_slice($bad, 0, 12) as $line) {
    printf("  %s\n", $line);
}
exit($bad === [] ? 0 : 2);
