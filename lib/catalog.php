<?php
declare(strict_types=1);

/**
 * Catalog index + understanding + retrieval.
 *
 * Primary schema (products_enriched.json):
 *   {"generated","source","products":[{nid,name,url,category,category_path[],standards[],application,spec_summary,image}]}
 * Fallback schema (products.json): {"categories":{ "<Category>": [ {"name","url"} ] }}
 *
 * Everything is in-memory per request: no cache files, no database, no network.
 */

const WOT_GENERIC_TYPE_WORDS = [
    'tester', 'testing', 'test', 'machine', 'machines', 'equipment', 'instrument',
    'instruments', 'apparatus', 'system', 'systems', 'series', 'device', 'devices',
    'analyzer', 'analysers', 'analyser', 'unit', 'units', 'meter', 'tool', 'tools',
];

const WOT_STOPWORDS = [
    'the', 'and', 'for', 'are', 'you', 'your', 'need', 'needs', 'want', 'wants', 'test',
    'testing', 'tested', 'what', 'which', 'have', 'has', 'that', 'this', 'with', 'from',
    'can', 'could', 'would', 'should', 'does', 'did', 'how', 'why', 'when', 'where', 'who',
    'best', 'good', 'please', 'hello', 'hi', 'hey', 'thanks', 'thank', 'about', 'into',
    'also', 'out', 'not', 'our', 'we', 'i', 'my', 'me', 'is', 'it', 'of', 'on', 'to', 'in',
    'a', 'an', 'do', 'any', 'some', 'there', 'here', 'be', 'been', 'was', 'were', 'looking',
    'look', 'find', 'give', 'show', 'tell', 'know', 'like', 'get', 'buy', 'price', 'quote',
    'type', 'kind', 'option', 'options', 'one', 'two', 'yes', 'yeah', 'ok', 'okay', 'sure',
    'per', 'please', 'much', 'many', 'than', 'then', 'but', 'or', 'if', 'so', 'as', 'at',
    'by', 'up', 'down', 'over', 'under', 'more', 'most', 'less', 'very', 'really', 'just',
];

/** Lowercase token list, stopwords removed, minimum length 2. */
function wot_tokens(string $text): array
{
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? '';
    $out  = [];
    foreach (explode(' ', $text) as $tok) {
        if ($tok === '' || strlen($tok) < 2) {
            continue;
        }
        if (in_array($tok, WOT_STOPWORDS, true)) {
            continue;
        }
        $out[$tok] = true;
    }
    return array_map('strval', array_keys($out)); // numeric tokens must stay strings
}

function wot_ensure_slash(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return $url;
    }
    if (preg_match('#\?|\#|/[a-z0-9_-]+\.[a-z0-9]{2,4}$#i', $url)) {
        return $url; // query strings and files (.png/.pdf/.html) keep their exact form
    }
    return str_ends_with($url, '/') ? $url : $url . '/';
}

/** @return list<string> enriched catalog locations, most specific first */
function wot_enriched_candidates(): array
{
    $root = dirname(__DIR__);
    return [
        $root . '/products_enriched.json',
        $root . '/catalog/products_enriched.json',
    ];
}

/** @return list<array<string,mixed>> */
function wot_catalog_index(): array
{
    static $index = null;
    static $cacheKey = null;

    $root = dirname(__DIR__);
    $key  = '';
    foreach ([$root . '/products_enriched.json', $root . '/catalog/products_enriched.json', $root . '/products.json'] as $file) {
        if (is_file($file)) {
            $key .= $file . ':' . (string) @filemtime($file) . ':' . (string) @filesize($file) . '|';
        }
    }
    if ($index !== null && $key === $cacheKey) {
        return $index;
    }
    $cacheKey = $key;

    $byUrl = [];

    foreach (wot_enriched_candidates() as $enriched) {
        if (!is_file($enriched)) {
            continue;
        }
        $raw  = @file_get_contents($enriched);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $row) {
                $product = wot_make_product(is_array($row) ? $row : [], '', []);
                if ($product !== null) {
                    $byUrl[$product['url']] = $product;
                }
            }
            break; // first readable enriched file wins
        }
    }

    // Always merge the base catalog so a partially enriched file cannot shrink coverage.
    $base = $root . '/products.json';
    if (is_file($base)) {
        $raw  = @file_get_contents($base);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data) && isset($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $category => $rows) {
                if (!is_array($rows)) {
                    continue;
                }
                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $url = wot_ensure_slash((string) ($row['url'] ?? ''));
                    if ($url === '' || isset($byUrl[$url])) {
                        continue;
                    }
                    $product = wot_make_product([
                        'name'           => $row['name'] ?? '',
                        'url'            => $url,
                        'category'       => (string) $category,
                        'category_path'  => [(string) $category],
                    ], (string) $category, [(string) $category]);
                    if ($product !== null) {
                        $byUrl[$url] = $product;
                    }
                }
            }
        }
    }

    $index = array_values($byUrl);
    return $index;
}

/** @return array<string,mixed>|null */
function wot_make_product(array $row, string $category, array $path): ?array
{
    $name = trim((string) ($row['name'] ?? ''));
    $url  = wot_ensure_slash((string) ($row['url'] ?? ''));
    if ($name === '' || $url === '') {
        return null;
    }
    if (!str_starts_with($url, 'https://www.' . WOT_SITE_HOST . '/')) {
        return null; // only first-party product URLs are ever published
    }

    $category = trim((string) ($row['category'] ?? $category));
    $path     = $row['category_path'] ?? $path;
    $path     = is_array($path) ? array_values(array_filter(array_map('strval', $path))) : [];
    if ($path === [] && $category !== '') {
        $path = [$category];
    }

    $standards = $row['standards'] ?? [];
    $standards = is_array($standards) ? array_values(array_filter(array_map(
        static fn($s): string => is_string($s) ? trim($s) : '',
        $standards
    ))) : [];
    $standards = array_values(array_unique($standards));

    $application  = is_string($row['application'] ?? null) ? trim($row['application']) : '';
    $specSummary  = is_string($row['spec_summary'] ?? null) ? trim($row['spec_summary']) : '';
    $image        = is_string($row['image'] ?? null) ? trim($row['image']) : '';
    if ($image !== '' && !str_starts_with($image, 'https://www.' . WOT_SITE_HOST . '/')) {
        $image = str_starts_with($image, '/') ? 'https://www.' . WOT_SITE_HOST . $image : '';
    }

    $hay = mb_strtolower(implode(' ', [
        $name,
        implode(' ', $path),
        $category,
        implode(' ', $standards),
        $application,
        $specSummary,
        str_replace('-', ' ', rawurldecode(basename(rtrim($url, '/')))),
    ]), 'UTF-8');

    preg_match_all('/\b\d+(?:\.\d+)?\s?kn\b/i', mb_strtolower($name . ' ' . $url, 'UTF-8'), $capMatch);
    $capacities = [];
    foreach ($capMatch[0] as $hit) {
        $capacities[] = (float) preg_replace('/[^0-9.]/', '', $hit);
    }

    return [
        'id'           => $row['nid'] ?? basename(rtrim($url, '/')),
        'name'         => $name,
        'url'          => $url,
        'category'     => $category,
        'category_path'=> $path,
        'standards'    => array_slice($standards, 0, 12),
        'application'  => $application,
        'spec_summary' => $specSummary,
        'image'        => $image,
        'hay'          => $hay,
        'name_lc'      => mb_strtolower($name, 'UTF-8'),
        'capacities'   => $capacities,
    ];
}

/**
 * Deterministic standards -> equipment type table. Seeded from the catalog's
 * own coverage so a standard number always lands on a real equipment family.
 * Shape: 'ISO 6506' => [ [equipment type phrases], [category hints] ].
 */
function wot_standard_table(): array
{
    static $table = null;
    if ($table !== null) {
        return $table;
    }

    $table = [
        // Metals — hardness
        'ISO 6506'   => [['brinell hardness tester', 'hardness testers', 'universal hardness tester'], ['Metal Testing Equipment']],
        'ASTM E10'   => [['brinell hardness tester', 'universal hardness tester'], ['Metal Testing Equipment']],
        'ISO 6508'   => [['rockwell hardness tester', 'universal hardness tester'], ['Metal Testing Equipment']],
        'ASTM E18'   => [['rockwell hardness tester', 'universal hardness tester'], ['Metal Testing Equipment']],
        'ISO 6507'   => [['vickers hardness tester', 'micro hardness tester'], ['Metallography Equipment', 'Metal Testing Equipment']],
        'ASTM E384'  => [['micro hardness tester', 'vickers hardness tester'], ['Metallography Equipment']],
        'ASTM E92'   => [['vickers hardness tester'], ['Metallography Equipment']],
        'ISO 2039-2' => [['rockwell hardness tester', 'hardness testers'], ['Metal Testing Equipment', 'Plastic Testing Equipment']],
        'ISO 2039-1' => [['ball indentation hardness tester', 'hardness testers'], ['Plastic Testing Equipment', 'Metal Testing Equipment']],
        'ASTM D785'  => [['rockwell hardness tester', 'hardness testers'], ['Plastic Testing Equipment']],
        'ASTM A956'  => [['portable hardness tester', 'hardness tester'], ['Portable Testers', 'Metal Testing Equipment']],
        'ISO 7619'   => [['shore hardness tester', 'durometer'], ['Rubber & Elastomer Testing Equipment']],
        'ASTM D2240' => [['shore hardness tester', 'durometer', 'irhd hardness tester'], ['Rubber & Elastomer Testing Equipment', 'Plastic Testing Equipment']],
        'ISO 868'    => [['shore hardness tester', 'durometer'], ['Rubber & Elastomer Testing Equipment', 'Plastic Testing Equipment']],
        'ASTM D2583' => [['barcol hardness tester', 'hardness tester'], ['Plastic Testing Equipment']],
        'ISO 2039'   => [['hardness tester', 'rockwell hardness tester'], ['Plastic Testing Equipment']],
        // Metals — tensile / mechanical
        'ISO 6892'   => [['universal testing machine', 'tensile tester', 'tensile testing machine'], ['Metal Testing Equipment', 'Cable & Wire Testing Equipment']],
        'ISO 6892-1' => [['universal testing machine', 'tensile tester'], ['Metal Testing Equipment', 'Cable & Wire Testing Equipment']],
        'ASTM E8'    => [['universal testing machine', 'tensile tester'], ['Metal Testing Equipment']],
        'ASTM A370'  => [['universal testing machine', 'tensile tester'], ['Metal Testing Equipment']],
        'ISO 15630'  => [['universal testing machine', 'tensile tester'], ['Concrete Testing Equipment', 'Metal Testing Equipment']],
        'ISO 148'    => [['impact testing machine', 'charpy impact tester'], ['Metal Testing Equipment']],
        'ASTM E23'   => [['impact testing machine', 'charpy impact tester'], ['Metal Testing Equipment']],
        'ASTM E399'  => [['universal testing machine', 'fatigue testing machine'], ['Metal Testing Equipment']],
        'ISO 7438'   => [['bend testing machine', 'universal testing machine'], ['Metal Testing Equipment']],
        'ASTM E290'  => [['bend testing machine', 'universal testing machine'], ['Metal Testing Equipment']],
        'ISO 6506-1' => [['brinell hardness tester'], ['Metal Testing Equipment']],
        // Plastics / rubber / film
        'ASTM D638'  => [['tensile tester', 'universal testing machine', 'tensile testing machine'], ['Plastic Testing Equipment']],
        'ISO 527'    => [['tensile tester', 'universal testing machine'], ['Plastic Testing Equipment']],
        'ASTM D412'  => [['tensile tester', 'universal testing machine'], ['Rubber & Elastomer Testing Equipment']],
        'ISO 37'     => [['tensile tester', 'universal testing machine'], ['Rubber & Elastomer Testing Equipment']],
        'ASTM D882'  => [['tensile tester', 'tensile tester qtens'], ['Flexible Packaging & Film Testing Equipment']],
        'ISO 6383'   => [['tensile tester', 'tear tester'], ['Flexible Packaging & Film Testing Equipment']],
        'ASTM D1922' => [['tear tester', 'tear resistance tester'], ['Flexible Packaging & Film Testing Equipment']],
        'ASTM D1004' => [['tear tester', 'tensile tester'], ['Flexible Packaging & Film Testing Equipment']],
        'ISO 34'     => [['tear tester', 'tensile tester'], ['Rubber & Elastomer Testing Equipment']],
        'ASTM D790'  => [['flexural tester', 'universal testing machine'], ['Plastic Testing Equipment']],
        'ISO 178'    => [['flexural tester', 'universal testing machine'], ['Plastic Testing Equipment']],
        'ASTM D256'  => [['impact tester', 'izod impact tester'], ['Plastic Testing Equipment']],
        'ISO 180'    => [['impact tester', 'izod impact tester'], ['Plastic Testing Equipment']],
        'ASTM D6110' => [['impact tester', 'charpy impact tester'], ['Plastic Testing Equipment']],
        'ISO 179'    => [['impact tester', 'charpy impact tester'], ['Plastic Testing Equipment']],
        'ASTM D1238' => [['melt flow indexer', 'melt flow tester', 'extrusion plastometer'], ['Plastic Testing Equipment', 'Plastics PVC / Pipe Testing Equipment']],
        'ISO 1133'   => [['melt flow indexer', 'melt flow tester', 'extrusion plastometer'], ['Plastic Testing Equipment', 'Plastics PVC / Pipe Testing Equipment']],
        'ASTM D1525' => [['vicat softening point tester', 'heat deflection tester'], ['Plastic Testing Equipment']],
        'ISO 306'    => [['vicat softening point tester', 'heat deflection tester'], ['Plastic Testing Equipment']],
        'ASTM D648'  => [['heat deflection temperature tester', 'vicat softening point tester'], ['Plastic Testing Equipment']],
        'ISO 75'     => [['heat deflection temperature tester'], ['Plastic Testing Equipment']],
        'ASTM D792'  => [['density balance', 'analytical balance'], ['Analytical Balances', 'Plastic Testing Equipment']],
        'ISO 1183'   => [['density balance', 'analytical balance'], ['Analytical Balances', 'Plastic Testing Equipment']],
        'ASTM D395'  => [['compression set tester'], ['Rubber & Elastomer Testing Equipment']],
        'ISO 815'    => [['compression set tester'], ['Rubber & Elastomer Testing Equipment']],
        'ASTM D573'  => [['aging oven', 'ozone test chamber'], ['Rubber & Elastomer Testing Equipment']],
        'ISO 188'    => [['aging oven', 'ozone test chamber'], ['Rubber & Elastomer Testing Equipment', 'Environmental Chambers']],
        'ISO 1431'   => [['ozone test chamber', 'ozone tester'], ['Accelerated Weathering Testers & Chambers']],
        'ASTM D1149' => [['ozone test chamber', 'ozone tester'], ['Accelerated Weathering Testers & Chambers']],
        // Corrosion / weathering
        'ASTM B117'  => [['salt spray chamber', 'salt spray tester', 'salt fog cabinet'], ['Accelerated Weathering Testers & Chambers', 'Environmental Chambers', 'Color, Paint & Coating Testing Equipment']],
        'ISO 9227'   => [['salt spray chamber', 'salt spray tester'], ['Accelerated Weathering Testers & Chambers', 'Environmental Chambers']],
        'ASTM G85'   => [['salt spray chamber', 'cyclic corrosion test chamber'], ['Accelerated Weathering Testers & Chambers']],
        'ASTM D2247' => [['humidity chamber', 'temperature humidity chamber'], ['Environmental Chambers']],
        'ISO 6270'   => [['humidity chamber', 'condensation cabinet'], ['Environmental Chambers', 'Color, Paint & Coating Testing Equipment']],
        'ASTM G154'  => [['uv weathering chamber', 'uv light accelerated test chamber'], ['Accelerated Weathering Testers & Chambers']],
        'ISO 4892'   => [['uv weathering chamber', 'xenon arc test chamber'], ['Accelerated Weathering Testers & Chambers']],
        'ASTM G155'  => [['xenon arc test chamber', 'weathering chamber'], ['Accelerated Weathering Testers & Chambers']],
        'IEC 60068'  => [['thermal shock chamber', 'environmental chamber'], ['Environmental Chambers']],
        'IEC 60529'  => [['ip test chamber', 'ingress protection test equipment'], ['Environmental Chambers', 'Semiconductor and Electronics Reliability Testing Equipment']],
        // Coatings / paint / color
        'ASTM D3359' => [['cross cut adhesion tester', 'adhesion tester'], ['Color, Paint & Coating Testing Equipment']],
        'ISO 2409'   => [['cross cut adhesion tester', 'adhesion tester'], ['Color, Paint & Coating Testing Equipment']],
        'ASTM D3363' => [['pencil hardness tester'], ['Color, Paint & Coating Testing Equipment']],
        'ASTM D2794' => [['impact tester', 'dupont impact tester'], ['Color, Paint & Coating Testing Equipment']],
        'ISO 6272'   => [['impact tester', 'dupont impact tester'], ['Color, Paint & Coating Testing Equipment']],
        'ASTM D523'  => [['gloss meter', 'glossmeter'], ['Color, Paint & Coating Testing Equipment']],
        'ISO 2813'   => [['gloss meter', 'glossmeter'], ['Color, Paint & Coating Testing Equipment']],
        'ASTM D2244' => [['color spectrophotometer', 'colorimeter'], ['Color, Paint & Coating Testing Equipment']],
        'ASTM D4060' => [['taber abrasion tester', 'rotary abrasion tester'], ['Color, Paint & Coating Testing Equipment']],
        'ASTM D2486' => [['scrub abrasion tester', 'washability tester'], ['Color, Paint & Coating Testing Equipment']],
        'ISO 2808'   => [['coating thickness gauge', 'dry film thickness gauge'], ['Color, Paint & Coating Testing Equipment', 'Portable Testers']],
        'ISO 15184'  => [['pencil hardness tester'], ['Color, Paint & Coating Testing Equipment']],
        'ASTM D522'  => [['mandrel bend tester', 'flexibility tester'], ['Color, Paint & Coating Testing Equipment']],
        // Textiles / leather
        'ISO 12947'  => [['martindale abrasion tester'], ['Textile Testing Equipment']],
        'ASTM D4966' => [['martindale abrasion tester'], ['Textile Testing Equipment']],
        'ASTM D4970' => [['martindale abrasion tester', 'pilling tester'], ['Textile Testing Equipment']],
        'ASTM D5034' => [['tensile tester', 'universal testing machine'], ['Textile Testing Equipment']],
        'ISO 13934'  => [['tensile tester', 'universal testing machine'], ['Textile Testing Equipment']],
        'ASTM D3884' => [['taber abrasion tester'], ['Textile Testing Equipment']],
        'AATCC 8'    => [['crockmeter', 'crock meter'], ['Textile Testing Equipment']],
        'ISO 105'    => [['crockmeter', 'color fastness tester'], ['Textile Testing Equipment']],
        'ASTM D1424' => [['tear tester', 'elmendorf tear tester'], ['Textile Testing Equipment']],
        // Packaging / paper
        'ASTM F88'   => [['seal strength tester', 'tensile tester'], ['Flexible Packaging & Film Testing Equipment']],
        'ASTM F1929' => [['dye penetration tester', 'seal strength tester'], ['Flexible Packaging & Film Testing Equipment']],
        'ISO 11607'  => [['seal strength tester', 'burst tester'], ['Flexible Packaging & Film Testing Equipment', 'Medical Testing Equipment']],
        'ISO 536'    => [['grammage tester', 'basis weight tester'], ['Paper & Cardboard Testing Equipment']],
        'ISO 1924'   => [['tensile tester'], ['Paper & Cardboard Testing Equipment']],
        'ISO 1974'   => [['tear tester', 'elmendorf tear tester'], ['Paper & Cardboard Testing Equipment']],
        'ISO 2758'   => [['burst tester', 'bursting strength tester'], ['Paper & Cardboard Testing Equipment']],
        'ISO 2493'   => [['stiffness tester'], ['Paper & Cardboard Testing Equipment']],
        'TAPPI T403' => [['burst tester'], ['Paper & Cardboard Testing Equipment']],
        // Concrete / cement / soil / asphalt
        'ASTM C39'   => [['compression testing machine', 'universal testing machine'], ['Concrete Testing Equipment']],
        'EN 12390'   => [['compression testing machine', 'universal testing machine'], ['Concrete Testing Equipment']],
        'ASTM C109'  => [['compression testing machine'], ['Cement Testing Equipment']],
        'ISO 679'    => [['compression testing machine'], ['Cement Testing Equipment']],
        'ASTM C191'  => [['vicat apparatus', 'setting time apparatus'], ['Cement Testing Equipment']],
        'ASTM D698'  => [['proctor compaction apparatus'], ['Soil Testing Equipment']],
        'ASTM D4318' => [['atterberg limits apparatus', 'liquid limit device'], ['Soil Testing Equipment']],
        'ASTM D6927' => [['marshall stability tester'], ['Asphalt Testing Equipment']],
        'ASTM D6307' => [['ignition oven', 'asphalt binder ignition oven'], ['Asphalt Testing Equipment']],
        'ASTM D4595' => [['tensile tester', 'wide width tensile tester'], ['Geosynthetics and Geotextile Testing Equipment']],
        'ISO 10319'  => [['tensile tester', 'wide width tensile tester'], ['Geosynthetics and Geotextile Testing Equipment']],
        // Petroleum
        'ASTM D86'   => [['distillation apparatus'], ['Petroleum Testing Equipment']],
        'ASTM D93'   => [['flash point tester', 'pensky martens flash point tester'], ['Petroleum Testing Equipment']],
        'ASTM D445'  => [['kinematic viscosity bath'], ['Petroleum Testing Equipment']],
        'ASTM D4294' => [['xrf sulfur analyzer', 'x-ray fluorescence analyzer'], ['XRF Analyzers', 'Petroleum Testing Equipment']],
        // Cannabis / pharma / analytical
        'HPLC'       => [['hplc high performance liquid chromatograph', 'hplc'], ['Cannabis Testing Equipment', 'Analytical Instruments']],
        'ASTM D8910' => [['hplc high performance liquid chromatograph'], ['Cannabis Testing Equipment']],
        'USP 711'    => [['dissolution tester'], ['Pharmaceutical Testing Equipment']],
        'USP 905'    => [['tablet hardness tester', 'friability tester'], ['Pharmaceutical Testing Equipment']],
        'USP 1216'   => [['tablet hardness tester'], ['Pharmaceutical Testing Equipment']],
        // Flammability / electrical
        'ASTM D2863' => [['limiting oxygen index chamber'], ['Fire and Flammability Testing Equipment', 'Plastic Testing Equipment']],
        'ISO 4589'   => [['limiting oxygen index chamber'], ['Fire and Flammability Testing Equipment']],
        'IEC 60695'  => [['glow wire tester', 'needle flame tester'], ['Fire and Flammability Testing Equipment']],
        'UL 94'      => [['flammability tester', 'glow wire tester'], ['Fire and Flammability Testing Equipment']],
        'IEC 60332'  => [['wire cable fire resistance tester', 'flame tester'], ['Cable & Wire Testing Equipment', 'Fire and Flammability Testing Equipment']],
        'IEC 60811'  => [['cable testing equipment', 'tensile tester'], ['Cable & Wire Testing Equipment']],
        'ISO 6722'   => [['wire cable testing equipment'], ['Cable & Wire Testing Equipment']],
        'IEC 60112'  => [['comparative tracking index tester'], ['Cable & Wire Testing Equipment']],
        'ASTM D149'  => [['dielectric strength tester'], ['Cable & Wire Testing Equipment']],
        // Batteries
        'IEC 62133'  => [['battery testing equipment', 'battery crush tester', 'battery impact tester'], ['Battery Testing Equipment']],
        'UN 38.3'    => [['battery testing equipment', 'battery thermal chamber'], ['Battery Testing Equipment']],
    ];

    return $table;
}


/**
 * Application vocabulary -> equipment family. Covers the ask when the visitor names a
 * material, a method, or an industry instead of a standard number. Longest phrase wins.
 * Shape: 'phrase' => [ [equipment types], [category hints] ].
 */
function wot_application_lexicon(): array
{
    static $lexicon = null;
    if ($lexicon !== null) {
        return $lexicon;
    }

    $lexicon = [
        'melt flow index'  => [['melt flow indexer', 'melt flow tester', 'extrusion plastometer'], ['Plastic Testing Equipment', 'Plastics PVC / Pipe Testing Equipment']],
        'melt flow'        => [['melt flow indexer', 'melt flow tester', 'extrusion plastometer'], ['Plastic Testing Equipment']],
        'melt index'       => [['melt flow indexer', 'melt flow tester'], ['Plastic Testing Equipment']],
        'mfi'              => [['melt flow indexer', 'melt flow tester'], ['Plastic Testing Equipment']],
        'hplc'             => [['hplc high performance liquid chromatograph', 'hplc'], ['Cannabis Testing Equipment', 'Analytical Instruments', 'Pharmaceutical Testing Equipment']],
        'potency'          => [['hplc high performance liquid chromatograph', 'hplc'], ['Cannabis Testing Equipment', 'Analytical Instruments']],
        'cannabinoid'      => [['hplc high performance liquid chromatograph', 'hplc'], ['Cannabis Testing Equipment']],
        'thc'              => [['hplc high performance liquid chromatograph', 'hplc'], ['Cannabis Testing Equipment']],
        'cbd'              => [['hplc high performance liquid chromatograph', 'hplc'], ['Cannabis Testing Equipment']],
        'cannabis'         => [['hplc high performance liquid chromatograph', 'gas chromatograph', 'atomic absorption spectrophotometer'], ['Cannabis Testing Equipment']],
        'gas chromatograph' => [['gas chromatograph'], ['Analytical Instruments', 'Cannabis Testing Equipment']],
        'chromatograph'    => [['hplc high performance liquid chromatograph', 'gas chromatograph'], ['Analytical Instruments']],
        'spectrophotometer' => [['uv-vis spectrophotometer', 'color spectrophotometer'], ['Analytical Instruments', 'Color, Paint & Coating Testing Equipment']],
        'hardness'         => [['hardness testers', 'brinell hardness tester', 'rockwell hardness tester'], ['Metal Testing Equipment']],
        'brinell'          => [['brinell hardness tester'], ['Metal Testing Equipment']],
        'rockwell'         => [['rockwell hardness tester'], ['Metal Testing Equipment']],
        'vickers'          => [['vickers hardness tester', 'macro vickers hardness tester'], ['Metallography Equipment']],
        'micro hardness'   => [['micro hardness tester'], ['Metallography Equipment']],
        'shore'            => [['shore hardness tester', 'durometer'], ['Rubber & Elastomer Testing Equipment']],
        'durometer'        => [['durometer', 'shore hardness tester'], ['Rubber & Elastomer Testing Equipment']],
        'irhd'             => [['irhd hardness tester', 'durometer'], ['Rubber & Elastomer Testing Equipment']],
        'pencil hardness'  => [['pencil hardness tester'], ['Color, Paint & Coating Testing Equipment']],
        'tensile'          => [['universal testing machine', 'tensile tester'], ['Metal Testing Equipment', 'Cable & Wire Testing Equipment', 'Plastic Testing Equipment', 'Rubber & Elastomer Testing Equipment']],
        'utm'              => [['universal testing machine', 'tensile tester'], ['Metal Testing Equipment', 'Cable & Wire Testing Equipment']],
        'compression'      => [['universal testing machine', 'compression testing machine'], ['Concrete Testing Equipment', 'Metal Testing Equipment']],
        'flexural'         => [['flexural tester', 'universal testing machine'], ['Plastic Testing Equipment']],
        'bending'          => [['bend testing machine', 'universal testing machine'], ['Metal Testing Equipment']],
        'impact'           => [['impact tester', 'charpy impact tester'], ['Plastic Testing Equipment', 'Metal Testing Equipment']],
        'charpy'           => [['charpy impact tester', 'impact testing machine'], ['Metal Testing Equipment']],
        'izod'             => [['izod impact tester', 'impact tester'], ['Plastic Testing Equipment']],
        'martindale'       => [['martindale abrasion tester'], ['Textile Testing Equipment']],
        'pilling'          => [['martindale abrasion tester', 'pilling tester'], ['Textile Testing Equipment']],
        'abrasion'         => [['abrasion tester', 'martindale abrasion tester', 'taber abrasion tester'], ['Textile Testing Equipment', 'Color, Paint & Coating Testing Equipment']],
        'taber'            => [['taber abrasion tester'], ['Color, Paint & Coating Testing Equipment']],
        'scrub'            => [['scrub abrasion tester', 'washability tester'], ['Color, Paint & Coating Testing Equipment']],
        'salt spray'       => [['salt spray chamber', 'salt spray tester', 'salt fog cabinet'], ['Accelerated Weathering Testers & Chambers', 'Environmental Chambers', 'Color, Paint & Coating Testing Equipment']],
        'salt fog'         => [['salt spray chamber', 'salt spray tester'], ['Accelerated Weathering Testers & Chambers']],
        'corrosion'        => [['salt spray chamber', 'cyclic corrosion test chamber'], ['Accelerated Weathering Testers & Chambers', 'Environmental Chambers']],
        'weathering'       => [['uv weathering chamber', 'xenon arc test chamber'], ['Accelerated Weathering Testers & Chambers']],
        'uv'               => [['uv weathering chamber', 'uv light accelerated test chamber'], ['Accelerated Weathering Testers & Chambers']],
        'xenon'            => [['xenon arc test chamber'], ['Accelerated Weathering Testers & Chambers']],
        'ozone'            => [['ozone test chamber', 'ozone tester'], ['Accelerated Weathering Testers & Chambers']],
        'humidity'         => [['humidity chamber', 'temperature humidity chamber'], ['Environmental Chambers']],
        'thermal shock'    => [['thermal shock chamber'], ['Environmental Chambers']],
        'climatic'         => [['environmental chamber', 'climatic chamber'], ['Environmental Chambers']],
        'flammability'     => [['flammability tester', 'flame tester'], ['Fire and Flammability Testing Equipment']],
        'flame'            => [['flame tester', 'flammability tester'], ['Fire and Flammability Testing Equipment']],
        'glow wire'        => [['glow wire tester'], ['Fire and Flammability Testing Equipment']],
        'needle flame'     => [['needle flame tester'], ['Fire and Flammability Testing Equipment']],
        'limiting oxygen'  => [['limiting oxygen index chamber'], ['Fire and Flammability Testing Equipment']],
        'loi'              => [['limiting oxygen index chamber'], ['Fire and Flammability Testing Equipment']],
        'vicat'            => [['vicat softening point tester'], ['Plastic Testing Equipment']],
        'heat deflection'  => [['heat deflection temperature tester'], ['Plastic Testing Equipment']],
        'hdt'              => [['heat deflection temperature tester'], ['Plastic Testing Equipment']],
        'density'          => [['density balance', 'analytical balance'], ['Analytical Balances']],
        'gloss'            => [['gloss meter'], ['Color, Paint & Coating Testing Equipment']],
        'color'            => [['colorimeter', 'color spectrophotometer'], ['Color, Paint & Coating Testing Equipment']],
        'colour'           => [['colorimeter', 'color spectrophotometer'], ['Color, Paint & Coating Testing Equipment']],
        'adhesion'         => [['cross cut adhesion tester', 'adhesion tester'], ['Color, Paint & Coating Testing Equipment']],
        'cross cut'        => [['cross cut adhesion tester'], ['Color, Paint & Coating Testing Equipment']],
        'coating thickness' => [['coating thickness gauge'], ['Color, Paint & Coating Testing Equipment', 'Portable Testers']],
        'film thickness'   => [['coating thickness gauge'], ['Color, Paint & Coating Testing Equipment']],
        'wire'             => [['wire testing equipment', 'universal testing machine'], ['Cable & Wire Testing Equipment']],
        'cable'            => [['cable testing equipment', 'universal testing machine'], ['Cable & Wire Testing Equipment']],
        'rebar'            => [['universal testing machine', 'compression testing machine'], ['Concrete Testing Equipment', 'Metal Testing Equipment']],
        'concrete'         => [['compression testing machine', 'universal testing machine'], ['Concrete Testing Equipment']],
        'cement'           => [['compression testing machine', 'vicat apparatus'], ['Cement Testing Equipment']],
        'soil'             => [['proctor compaction apparatus', 'direct shear apparatus'], ['Soil Testing Equipment']],
        'asphalt'          => [['marshall stability tester'], ['Asphalt Testing Equipment']],
        'bitumen'          => [['marshall stability tester'], ['Asphalt Testing Equipment']],
        'viscosity'        => [['kinematic viscosity bath', 'viscometer'], ['Petroleum Testing Equipment']],
        'flash point'      => [['flash point tester'], ['Petroleum Testing Equipment']],
        'distillation'     => [['distillation apparatus'], ['Petroleum Testing Equipment']],
        'fuel'             => [['flash point tester', 'distillation apparatus'], ['Petroleum Testing Equipment']],
        'lubricant'        => [['kinematic viscosity bath'], ['Petroleum Testing Equipment']],
        'tablet'           => [['tablet hardness tester', 'dissolution tester'], ['Pharmaceutical Testing Equipment']],
        'tablet hardness'  => [['tablet hardness tester'], ['Pharmaceutical Testing Equipment']],
        'dissolution'      => [['dissolution tester'], ['Pharmaceutical Testing Equipment']],
        'moisture'         => [['moisture analyzer'], ['Water Quality & Moisture Analysis']],
        'water content'    => [['moisture analyzer', 'karl fischer titrator'], ['Water Quality & Moisture Analysis']],
        'ph meter'         => [['ph meter'], ['Water Quality & Moisture Analysis']],
        'seal strength'    => [['seal strength tester'], ['Flexible Packaging & Film Testing Equipment']],
        'packaging'        => [['seal strength tester', 'tensile tester'], ['Flexible Packaging & Film Testing Equipment']],
        'tear'             => [['tear tester'], ['Flexible Packaging & Film Testing Equipment', 'Paper & Cardboard Testing Equipment']],
        'burst'            => [['burst tester'], ['Paper & Cardboard Testing Equipment', 'Flexible Packaging & Film Testing Equipment']],
        'grammage'         => [['grammage tester'], ['Paper & Cardboard Testing Equipment']],
        'paper'            => [['tensile tester', 'burst tester'], ['Paper & Cardboard Testing Equipment']],
        'textile'          => [['martindale abrasion tester', 'tensile tester'], ['Textile Testing Equipment']],
        'fabric'           => [['martindale abrasion tester', 'tensile tester'], ['Textile Testing Equipment']],
        'rubber'           => [['tensile tester', 'durometer'], ['Rubber & Elastomer Testing Equipment']],
        'elastomer'        => [['tensile tester', 'durometer'], ['Rubber & Elastomer Testing Equipment']],
        'plastic'          => [['tensile tester', 'impact tester'], ['Plastic Testing Equipment']],
        'polymer'          => [['melt flow indexer', 'tensile tester'], ['Plastic Testing Equipment']],
        'polypropylene'    => [['melt flow indexer', 'tensile tester', 'impact tester'], ['Plastic Testing Equipment']],
        'polyethylene'     => [['melt flow indexer', 'tensile tester'], ['Plastic Testing Equipment']],
        'steel'            => [['universal testing machine', 'hardness testers'], ['Metal Testing Equipment']],
        'metal'            => [['universal testing machine', 'hardness testers'], ['Metal Testing Equipment']],
        'geotextile'       => [['tensile tester'], ['Geosynthetics and Geotextile Testing Equipment']],
        'geosynthetic'     => [['tensile tester'], ['Geosynthetics and Geotextile Testing Equipment']],
        'microscope'       => [['microscope'], ['Microscopes', 'Metallography Equipment']],
        'metallography'    => [['metallurgical microscope', 'specimen cutting machine'], ['Metallography Equipment']],
        'xrf'              => [['xrf analyzer', 'x-ray fluorescence analyzer'], ['XRF Analyzers']],
        'sulfur'           => [['xrf sulfur analyzer'], ['XRF Analyzers', 'Petroleum Testing Equipment']],
        'balance'          => [['analytical balance'], ['Analytical Balances']],
        'battery'          => [['battery testing equipment', 'battery crush tester'], ['Battery Testing Equipment']],
        'mask'             => [['face mask testing equipment'], ['Face Mask Testing Equipment']],
        'freeze dry'       => [['freeze dryer', 'lyophilizer'], ['Freeze Dryers']],
        'freeze dryer'     => [['freeze dryer'], ['Freeze Dryers']],
        'proctor'          => [['proctor compaction apparatus'], ['Soil Testing Equipment']],
        'california bearing' => [['california bearing ratio apparatus'], ['Soil Testing Equipment']],
    ];

    return $lexicon;
}

/**
 * Equipment types + categories implied by application wording in the message.
 * Longest matching phrase wins so "melt flow index" beats "index".
 *
 * @return array{0:list<string>,1:list<string>}
 */
function wot_application_profile(string $message): array
{
    $text = ' ' . mb_strtolower($message, 'UTF-8') . ' ';
    $text = preg_replace('/[^a-z0-9]+/u', ' ', $text) ?? $text;
    $text = ' ' . trim($text) . ' ';

    $types = [];
    $cats  = [];
    foreach (wot_application_lexicon() as $phrase => $profile) {
        $needle = ' ' . $phrase . ' ';
        if (str_contains($text, $needle)) {
            foreach ($profile[0] as $type) {
                $types[] = $type;
            }
            foreach ($profile[1] as $cat) {
                $cats[] = $cat;
            }
        }
    }
    return [array_values(array_unique($types)), array_values(array_unique($cats))];
}

/** Equipment-type synonym expansion used by the widened retrieval pass. */
function wot_type_synonyms(): array
{
    return [
        'universal testing machine' => ['utm', 'tensile tester', 'tensile testing machine', 'tensile'],
        'tensile tester'            => ['utm', 'universal testing machine', 'tensile'],
        'brinell hardness tester'   => ['brinell', 'hardness testers', 'bb testers'],
        'rockwell hardness tester'  => ['rockwell', 'hardness testers'],
        'vickers hardness tester'   => ['vickers', 'micro hardness tester'],
        'shore hardness tester'     => ['durometer', 'irhd', 'shore'],
        'melt flow indexer'         => ['mfi', 'melt flow tester', 'extrusion plastometer'],
        'salt spray chamber'        => ['salt spray tester', 'salt fog cabinet', 'corrosion chamber'],
        'hplc high performance liquid chromatograph' => ['hplc', 'liquid chromatograph', 'chromatograph'],
        'martindale abrasion tester'=> ['martindale', 'abrasion tester'],
        'taber abrasion tester'     => ['taber', 'abrasion tester'],
        'gloss meter'               => ['gloss', 'glossmeter'],
        'color spectrophotometer'   => ['spectrophotometer', 'colorimeter', 'color'],
        'impact tester'             => ['impact'],
        'ozone test chamber'        => ['ozone'],
        'uv weathering chamber'     => ['uv', 'weathering'],
        'compression testing machine' => ['compression tester', 'compression machine'],
        'grammage tester'           => ['basis weight', 'grammage'],
        'tear tester'               => ['tear'],
        'burst tester'              => ['burst'],
        'xrf sulfur analyzer'       => ['xrf', 'x-ray fluorescence'],
        'tablet hardness tester'    => ['tablet hardness'],
        'glow wire tester'          => ['glow wire'],
        'limiting oxygen index chamber' => ['loi', 'oxygen index'],
    ];
}

/** Normalizes "astm d-2240-15" style input to "ASTM D2240" plus base variants. */
function wot_standard_candidates(string $standard): array
{
    $standard = strtoupper(trim(preg_replace('/\s+/', ' ', $standard) ?? $standard));
    if (!preg_match('/^([A-Z]+)\s+([A-Z]{0,2})\s*-?\s*(\d[\d.\-]*)$/', $standard, $m)) {
        return [$standard];
    }
    $org  = $m[1];
    $desig = preg_replace('/[^A-Z0-9.\-]/', '', $m[2] . $m[3]) ?? ($m[2] . $m[3]);
    $out  = [$org . ' ' . $desig];

    // ASTM D2240-15 -> ASTM D2240 ; ISO 6892-1:2019 -> ISO 6892-1 and ISO 6892
    $noRevision = preg_replace('/:\d{4}$/', '', $desig) ?? $desig;
    $out[] = $org . ' ' . $noRevision;
    $noSuffix = preg_replace('/-\d+$/', '', $noRevision) ?? $noRevision;
    $out[] = $org . ' ' . $noSuffix;
    if ($org === 'ASTM' && preg_match('/^([A-Z])(\d+)$/', $desig, $dm)) {
        $out[] = 'ASTM ' . $dm[1] . '-' . $dm[2];
    }
    return array_values(array_unique(array_filter($out)));
}

/** Finds standards mentioned anywhere in a message. */
function wot_extract_standards(string $text): array
{
    preg_match_all(
        '/\b(ISO|ASTM|EN|DIN|BS|JIS|GB|IEC|AATCC|AASHTO|API|UL|UN|SAE|USP|TAPPI|SSPC)\s*([A-Z]{0,2}\s*-?\s*\d{1,5}(?:[.\-:]\d{1,4})?)/i',
        $text,
        $matches,
        PREG_SET_ORDER
    );
    $out = [];
    foreach ($matches as $m) {
        $raw = strtoupper(preg_replace('/\s+/', ' ', trim($m[1] . ' ' . $m[2])) ?? '');
        $raw = preg_replace('/\s*-\s*(?=\d)/', '-', $raw) ?? $raw;       // "ISO 6892 - 1" -> "ISO 6892-1"
        $raw = preg_replace('/([A-Z])\s*-\s*(\d)/', '$1$2', $raw) ?? $raw;   // "D - 2240" -> "D2240"
        if (strlen($raw) < 4) {
            continue;
        }
        $base = preg_replace('/[.:]\d{2,4}$/', '', $raw) ?? $raw;
        $out[strtoupper($raw)] = strtoupper($raw);
        if ($base !== $raw) {
            $out[strtoupper($base)] = strtoupper($base);
        }
    }
    return array_values($out);
}

/**
 * Standards index built from the enriched catalog: every standard that real products
 * declare, with the equipment names and categories that cite it. This is the seed for
 * standards -> equipment mapping, so the mapping follows the catalog instead of a guess.
 *
 * @return array<string,array{names:list<string>,cats:array<string,int>,count:int}>
 */
function wot_standards_index(array $index): array
{
    static $map = [];
    static $mapKey = null;
    $key = count($index) . ':' . ($index[0]['url'] ?? '');
    if ($mapKey === $key) {
        return $map;
    }
    $mapKey = $key;
    $map    = [];

    foreach ($index as $product) {
        foreach ($product['standards'] as $standard) {
            $slug = strtoupper(trim((string) $standard));
            if ($slug === '') {
                continue;
            }
            $map[$slug] ??= ['names' => [], 'cats' => [], 'count' => 0];
            if (!in_array($product['name'], $map[$slug]['names'], true) && count($map[$slug]['names']) < 6) {
                $map[$slug]['names'][] = $product['name'];
            }
            foreach ($product['category_path'] as $category) {
                $category = (string) $category;
                if ($category === '') {
                    continue;
                }
                $map[$slug]['cats'][$category] = ($map[$slug]['cats'][$category] ?? 0) + 1;
            }
            $map[$slug]['count']++;
        }
    }
    return $map;
}

/** Profile of a standard taken from the catalog's own standards index. */
function wot_index_standard_profile(string $standard, array $index): array
{
    $map    = wot_standards_index($index);
    $needle = strtoupper(trim($standard));
    if ($needle === '') {
        return ['types' => [], 'cats' => [], 'count' => 0, 'keys' => []];
    }

    $names = [];
    $cats  = [];
    $count = 0;
    $keys  = [];
    foreach ($map as $slug => $row) {
        $matches = $slug === $needle
            || str_starts_with($slug, $needle . '-')
            || str_starts_with($slug, $needle . ':')
            || str_starts_with($slug, $needle . ' ')
            || str_starts_with($needle, $slug . '-')
            || str_starts_with($needle, $slug . ':');
        if (!$matches) {
            continue;
        }
        $keys[] = $slug;
        $count += $row['count'];
        foreach (array_slice($row['names'], 0, 2) as $name) {
            $names[$name] = true;
        }
        foreach ($row['cats'] as $category => $hits) {
            if ($category === 'Products') {
                continue; // container node, never an equipment family
            }
            $cats[$category] = ($cats[$category] ?? 0) + $hits;
        }
    }
    arsort($cats);

    return [
        'types' => array_slice(array_keys($names), 0, 4),
        'cats'  => array_slice(array_keys($cats), 0, 3),
        'count' => $count,
        'keys'  => $keys,
    ];
}

/**
 * Resolves a standard through both sources: the curated equipment-family table and the
 * catalog's own standards index. `source` says which one produced the answer.
 */
function wot_standard_profile(string $standard, array $index): array
{
    $table    = wot_standard_table();
    $tableHit = null;
    foreach (wot_standard_candidates($standard) as $cand) {
        if (isset($table[$cand])) {
            $tableHit = ['key' => $cand, 'row' => $table[$cand]];
            break;
        }
        foreach ($table as $key => $row) {
            if (str_starts_with($cand, $key) || str_starts_with($key, $cand)) {
                $tableHit = ['key' => $key, 'row' => $row];
                break 2;
            }
        }
    }

    $fromIndex = wot_index_standard_profile($standard, $index);

    $types = [];
    $cats  = [];
    if ($tableHit !== null) {
        $types = $tableHit['row'][0];
        $cats  = $tableHit['row'][1];
    }
    // Index-derived product names are not equipment families: adding them as types would
    // over-weight the specific models that happen to cite the standard. Their categories
    // carry the family signal, and each citing product already earns the declared-standard
    // bonus in scoring.
    foreach ($fromIndex['cats'] as $category) {
        if (!in_array($category, $cats, true)) {
            $cats[] = $category;
        }
    }

    $source = match (true) {
        $tableHit !== null && $fromIndex['count'] > 0 => 'table+index',
        $tableHit !== null                            => 'table',
        $fromIndex['count'] > 0                       => 'index',
        default                                       => 'none',
    };

    return [
        'types'       => array_values(array_unique($types)),
        'cats'        => array_values(array_unique($cats)),
        'matched'     => $tableHit['key'] ?? ($fromIndex['keys'][0] ?? null),
        'source'      => $source,
        'index_hits'  => $fromIndex['count'],
        'index_names' => $fromIndex['types'],
    ];
}

/**
 * Understands one turn: standards, equipment types, subjects, capacity, categories.
 * The previous turn is used only as a fallback subject, never concatenated wholesale.
 *
 * @param list<array{role:string,content:string}> $history
 * @return array<string,mixed>
 */
function wot_understand(string $message, array $history, array $index): array
{
    $standards = wot_extract_standards($message);
    $types     = [];
    $catHints  = [];

    $resolution = [];
    foreach ($standards as $std) {
        $profile = wot_standard_profile($std, $index);
        foreach ($profile['types'] as $type) {
            $types[] = $type;
        }
        foreach ($profile['cats'] as $cat) {
            $catHints[] = $cat;
        }
        $resolution[$std] = [
            'source'     => $profile['source'],
            'matched'    => $profile['matched'],
            'index_hits' => $profile['index_hits'],
        ];
    }

    [$appTypes, $appCats] = wot_application_profile($message);
    foreach ($appTypes as $type) {
        $types[] = $type;
    }
    foreach ($appCats as $cat) {
        $catHints[] = $cat;
    }

    $textLc = mb_strtolower($message, 'UTF-8');
    $subjectTokens = wot_tokens($message);

    // Explicit equipment wording in the current message wins: pull catalog category names in.
    $categories = wot_categories($index);
    foreach ($categories as $cat) {
        if ($cat !== '' && str_contains($textLc, mb_strtolower($cat, 'UTF-8'))) {
            $catHints[] = $cat;
        }
    }

    // Capacity: "50 kN", "1000kN", "300 L".
    $capacity = null;
    if (preg_match('/\b(\d{1,6}(?:\.\d+)?)\s*(kn|kilonewton|kilonewtons|n)\b/i', $textLc, $cm)) {
        $value = (float) $cm[1];
        $unit  = strtolower($cm[2]);
        if ($unit === 'n') {
            $value /= 1000;
        }
        if ($value > 0 && $value < 100000) {
            $capacity = ['value' => $value, 'unit' => 'kN', 'raw' => $cm[0]];
        }
    }

    // Previous turn: only the immediately preceding user message and the last bot reply.
    $prevUser = '';
    $lastBot  = '';
    for ($i = count($history) - 1; $i >= 0; $i--) {
        $role = $history[$i]['role'];
        if ($role === 'assistant' && $lastBot === '') {
            $lastBot = $history[$i]['content'];
        } elseif ($role === 'user' && $lastBot !== '' && $prevUser === '') {
            $prevUser = $history[$i]['content'];
            break;
        }
    }
    if ($prevUser === '') {
        // No assistant reply in between (single-turn), take the previous user message.
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'user') {
                $prevUser = $history[$i]['content'];
                break;
            }
        }
    }

    $prevStandards = $prevUser !== '' ? wot_extract_standards($prevUser) : [];
    $prevTypes     = [];
    $prevCats      = [];
    foreach ($prevStandards as $std) {
        $profile = wot_standard_profile($std, $index);
        foreach ($profile['types'] as $type) {
            $prevTypes[] = $type;
        }
        foreach ($profile['cats'] as $cat) {
            $prevCats[] = $cat;
        }
    }
    $prevTokens = $prevUser !== '' ? wot_tokens($prevUser . ' ' . $lastBot) : [];

    // Products named in the last bot reply stay "under discussion".
    $discussed = [];
    if ($lastBot !== '') {
        $botLc = mb_strtolower($lastBot, 'UTF-8');
        foreach ($index as $product) {
            if (str_contains($botLc, $product['name_lc'])) {
                $discussed[] = $product['url'];
                $catHints[]  = $product['category'];
            }
        }
        $discussed = array_slice(array_values(array_unique($discussed)), 0, 4);
    }

    // A bare qualification ("yes, 50 kN") inherits the subject from the previous turn.
    $isQualification = $types === [] && count($subjectTokens) <= 6;
    if ($isQualification) {
        $types    = array_merge($types, $prevTypes);
        $catHints = array_merge($catHints, $prevCats);
    }

    $types    = array_values(array_unique(array_filter(array_map('trim', $types))));
    $catHints = array_values(array_unique(array_filter(array_map('trim', $catHints))));

    // Subject tokens: message tokens minus equipment words and minus inherited noise.
    $subject = [];
    foreach ($subjectTokens as $tok) {
        if (in_array($tok, WOT_GENERIC_TYPE_WORDS, true)) {
            continue;
        }
        if (preg_match('/^(iso|astm|en|din|iec|bs|gb|jis|kn|mm|cm|kg|mpa)$/', $tok)) {
            continue;
        }
        $subject[] = $tok;
    }
    if ($subject === [] && $isQualification) {
        foreach ($prevTokens as $tok) {
            if (in_array($tok, WOT_GENERIC_TYPE_WORDS, true) || strlen($tok) < 3) {
                continue;
            }
            if (preg_match('/^\d/', $tok)) {
                continue;
            }
            $subject[] = $tok;
            if (count($subject) >= 8) {
                break;
            }
        }
    }

    return [
        'standards'   => $standards,
        'resolution'  => $resolution,
        'types'       => array_slice($types, 0, 6),
        'cat_hints'   => array_slice($catHints, 0, 6),
        'subject'     => array_slice(array_values(array_unique($subject)), 0, 12),
        'capacity'    => $capacity,
        'discussed'   => $discussed,
        'prev_user'   => $prevUser,
        'is_qualification' => $isQualification,
    ];
}

/** @return list<string> */
function wot_categories(array $index): array
{
    $cats = [];
    foreach ($index as $product) {
        if ($product['category'] !== '') {
            $cats[$product['category']] = true;
        }
        foreach ($product['category_path'] as $c) {
            if ($c !== '') {
                $cats[$c] = true;
            }
        }
    }
    return array_keys($cats);
}

function wot_type_terms(string $type): array
{
    $terms = [];
    foreach (preg_split('/\s+/', mb_strtolower($type, 'UTF-8')) ?: [] as $word) {
        if ($word === '' || strlen($word) < 3 || in_array($word, WOT_GENERIC_TYPE_WORDS, true)) {
            continue;
        }
        $terms[] = $word;
    }
    return $terms === [] ? [mb_strtolower($type, 'UTF-8')] : $terms;
}

/**
 * Accessories (indenters, blocks, cutters, extensometers, controllers) declare standards
 * precisely, which makes them outrank the actual machine. When the visitor did not ask
 * for an accessory, push them down so the machine leads the answer.
 */
function wot_is_accessory_name(string $name): bool
{
    return (bool) preg_match(
        '/accessor|indenter|test block|reference material|specimen (?:cutter|grinding|preparation)|sample cutter|\bcutter\b|grinding machine|punching|\bextensometer\b|controller|software|calibration|spare part|fixture|\bgrips?\b/i',
        $name
    );
}

function wot_score_product(array $product, array $q): int
{
    $score = 0;
    $hay   = $product['hay'];
    $name  = $product['name_lc'];

    // Equipment type: the strongest signal, weighted by how specific the type is.
    $typeWeights = [1.0, 0.62, 0.4, 0.32, 0.26, 0.22];
    foreach ($q['types'] as $typeIndex => $type) {
        $weight = $typeWeights[$typeIndex] ?? 0.2;
        $typeLc = mb_strtolower($type, 'UTF-8');
        if ($typeLc !== '' && str_contains($name, $typeLc)) {
            $score += (int) round(22 * $weight);
            continue;
        }
        if ($typeLc !== '' && str_contains($hay, $typeLc)) {
            $score += (int) round(14 * $weight);
        }
        $termHits = 0;
        foreach (wot_type_terms($type) as $term) {
            if (str_contains($name, $term)) {
                $termHits += 6;
            } elseif (str_contains($hay, $term)) {
                $termHits += 3;
            }
        }
        $score += min(18, $termHits);
        foreach (wot_type_synonyms()[$typeLc] ?? [] as $syn) {
            if (str_contains($name, $syn)) {
                $score += 8;
                break;
            }
            if (str_contains($hay, $syn)) {
                $score += 4;
                break;
            }
        }
    }

    // Category hints.
    $productCats = array_map(static fn($c) => mb_strtolower((string) $c, 'UTF-8'), $product['category_path']);
    if ($product['category'] !== '') {
        $productCats[] = mb_strtolower($product['category'], 'UTF-8');
    }
    foreach ($q['cat_hints'] as $hint) {
        $hintLc = mb_strtolower($hint, 'UTF-8');
        foreach ($productCats as $pc) {
            if ($pc === $hintLc) {
                $score += 12;
                break;
            }
            if ($hintLc !== '' && (str_contains($pc, $hintLc) || str_contains($hintLc, $pc))) {
                $score += 6;
                break;
            }
        }
    }

    // Declared standards.
    foreach ($q['standards'] as $std) {
        $stdLc = mb_strtolower($std, 'UTF-8');
        foreach ($product['standards'] as $declared) {
            $declaredLc = mb_strtolower($declared, 'UTF-8');
            if ($declaredLc === $stdLc || str_starts_with($declaredLc, $stdLc) || str_starts_with($stdLc, $declaredLc)) {
                $score += 26;
                break 2;
            }
        }
        if ($stdLc !== '' && str_contains($hay, $stdLc)) {
            $score += 16;
        } elseif (preg_match('/(?:iso|astm)\s*([a-z]?\d{3,5})/i', $std, $sm) && str_contains($hay, $sm[1])) {
            $score += 6;
        }
    }

    // Subject vocabulary (sample, material, industry).
    foreach ($q['subject'] as $tok) {
        if (strlen($tok) < 3) {
            continue;
        }
        if (str_contains($name, $tok)) {
            $score += 8;
        } elseif (str_contains($hay, $tok)) {
            $score += 4;
        }
    }

    // Load capacity: exact hit is decisive, insufficient capacity is penalized.
    if (is_array($q['capacity'])) {
        $want = (float) $q['capacity']['value'];
        if ($product['capacities'] !== []) {
            $max = max($product['capacities']);
            if (abs($max - $want) < 0.001) {
                $score += 30;
            } elseif ($max >= $want * 1.5 && $max <= $want * 4) {
                $score += 6;
            } elseif ($max < $want) {
                $score -= 18;
            }
        }
    }

    // Accessories must not lead unless an accessory was actually requested.
    if ($q['types'] !== [] && wot_is_accessory_name($product['name'])) {
        $askedForAccessory = false;
        foreach ($q['types'] as $type) {
            if (str_contains($product['name_lc'], mb_strtolower($type, 'UTF-8'))) {
                $askedForAccessory = true;
                break;
            }
        }
        if (!$askedForAccessory) {
            $score -= 12;
        }
    }

    // Already discussed in the previous reply: keep continuity but do not lock the answer.
    if (in_array($product['url'], $q['discussed'], true)) {
        $score += 4;
    }

    return $score;
}

/** Broad pass: equipment type terms and category hints only, no standards/subject. */
function wot_score_wide(array $product, array $q): int
{
    $score = 0;
    $hay   = $product['hay'];
    $name  = $product['name_lc'];

    foreach ($q['types'] as $type) {
        $typeLc = mb_strtolower($type, 'UTF-8');
        foreach (wot_type_terms($type) as $term) {
            if (str_contains($name, $term)) {
                $score += 6;
            } elseif (str_contains($hay, $term)) {
                $score += 3;
            }
        }
        foreach (wot_type_synonyms()[$typeLc] ?? [] as $syn) {
            if (str_contains($name, $syn) || str_contains($hay, $syn)) {
                $score += 3;
                break;
            }
        }
    }
    foreach ($q['cat_hints'] as $hint) {
        $hintLc = mb_strtolower($hint, 'UTF-8');
        foreach ($product['category_path'] as $c) {
            $cLc = mb_strtolower((string) $c, 'UTF-8');
            if ($cLc === $hintLc || ($hintLc !== '' && str_contains($cLc, $hintLc))) {
                $score += 8;
                break;
            }
        }
    }
    foreach ($q['subject'] as $tok) {
        if (strlen($tok) >= 4 && (str_contains($name, $tok) || str_contains($hay, $tok))) {
            $score += 2;
        }
    }
    return $score;
}

/**
 * Two-pass retrieval. Primary pass ranks everything; the widened pass (categories,
 * synonyms, then category browse) always runs before the caller may say "no match".
 *
 * @return array{pass:string,candidates:list<array<string,mixed>>,primary_count:int,total:int}
 */
function wot_retrieve(array $q, array $index, int $max = 10): array
{
    $scored = [];
    foreach ($index as $i => $product) {
        $score = wot_score_product($product, $q);
        if ($score > 0) {
            $scored[] = ['score' => $score, 'i' => $i];
        }
    }
    usort($scored, static fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($index[$a['i']]['name'], $index[$b['i']]['name']));

    $primary = [];
    foreach ($scored as $row) {
        if ($row['score'] < 8) {
            continue;
        }
        $primary[] = $index[$row['i']];
        if (count($primary) >= $max) {
            break;
        }
    }

    if (count($primary) >= 6) {
        return ['pass' => 'primary', 'candidates' => $primary, 'primary_count' => count($primary), 'total' => count($scored)];
    }

    // Widened pass 1: category + synonym terms, no standards requirement.
    $have = array_flip(array_map(static fn($p) => $p['url'], $primary));
    $wide = [];
    foreach ($index as $i => $product) {
        if (isset($have[$product['url']])) {
            continue;
        }
        $score = wot_score_wide($product, $q);
        if ($score >= 6) {
            $wide[] = ['score' => $score, 'i' => $i];
        }
    }
    usort($wide, static fn($a, $b) => $b['score'] <=> $a['score'] ?: strcmp($index[$a['i']]['name'], $index[$b['i']]['name']));
    foreach ($wide as $row) {
        $primary[] = $index[$row['i']];
        if (count($primary) >= max(6, min($max, 10))) {
            break;
        }
    }

    if (count($primary) >= 6 || ($primary !== [] && $q['types'] === [] && $q['cat_hints'] === [])) {
        return ['pass' => 'widened', 'candidates' => array_slice($primary, 0, $max), 'primary_count' => count($primary), 'total' => count($scored)];
    }

    // Widened pass 2: category browse — every hinted category contributes representatives.
    if ($q['cat_hints'] !== []) {
        $hints = array_map(static fn($c) => mb_strtolower($c, 'UTF-8'), $q['cat_hints']);
        $browse = [];
        foreach ($index as $product) {
            if (isset($have[$product['url']])) {
                continue;
            }
            foreach ($product['category_path'] as $c) {
                $cLc = mb_strtolower((string) $c, 'UTF-8');
                foreach ($hints as $hint) {
                    if ($cLc === $hint || ($hint !== '' && str_contains($cLc, $hint))) {
                        $browse[$product['url']] = $product;
                        break 3;
                    }
                }
            }
        }
        $browse = array_values($browse);
        usort($browse, static fn($a, $b) => strcmp($a['name'], $b['name']));
        foreach ($browse as $product) {
            if (count($primary) >= 6) {
                break;
            }
            $primary[] = $product;
        }
    }

    return [
        'pass'          => $primary === [] ? 'empty' : 'widened',
        'candidates'    => array_slice($primary, 0, $max),
        'primary_count' => count($primary),
        'total'         => count($scored),
    ];
}

/** Formats the candidate block handed to the model. Deliberately compact. */
function wot_candidates_block(array $candidates): string
{
    if ($candidates === []) {
        return '';
    }
    $lines = [];
    foreach ($candidates as $p) {
        $parts = [
            '- ' . $p['name'],
            $p['url'],
            'category: ' . ($p['category'] !== '' ? $p['category'] : 'n/a'),
        ];
        if ($p['standards'] !== []) {
            $parts[] = 'standards: ' . implode(', ', array_slice($p['standards'], 0, 6));
        }
        if ($p['application'] !== '') {
            $parts[] = 'application: ' . wot_clip($p['application'], 120);
        }
        if ($p['spec_summary'] !== '') {
            $parts[] = 'specs: ' . wot_clip($p['spec_summary'], 180);
        }
        $lines[] = implode(' | ', $parts);
    }
    return implode("\n", $lines);
}

function wot_clip(string $text, int $limit): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return mb_substr($text, 0, $limit - 1) . '…';
}

/** Candidate lookups used to police links in the model reply. */
function wot_candidate_urls(array $candidates): array
{
    $urls = [];
    foreach ($candidates as $p) {
        $urls[$p['url']] = true;
    }
    return $urls;
}
