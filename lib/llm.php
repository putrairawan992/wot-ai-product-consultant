<?php
declare(strict_types=1);

/**
 * Provider transport + reply policing.
 * Keys come from the environment only. No defaults, no fallbacks baked in.
 */

/** @return array{provider:string,key:string} */
function wot_pick_provider(): array
{
    $deepseek = trim((string) getenv('DEEPSEEK_API_KEY'));
    $gemini   = trim((string) getenv('GEMINI_API_KEY'));
    $claude   = trim((string) getenv('CLAUDE_API_KEY'));

    if ($deepseek !== '') {
        return ['provider' => 'deepseek', 'key' => $deepseek];
    }
    if ($gemini !== '') {
        return ['provider' => 'gemini', 'key' => $gemini];
    }
    if ($claude !== '') {
        return ['provider' => 'claude', 'key' => $claude];
    }
    throw new WotUserError('The consultant is not configured.', 503, 'not_configured');
}

/** @return array{text:string,truncated:bool} */
function wot_llm_reply(string $provider, string $key, string $system, array $messages): array
{
    $messages = array_values(array_filter($messages, static fn($m) => is_array($m) && isset($m['role'], $m['content'])));
    if (count($messages) > 12) {
        $messages = array_slice($messages, -12);
    }

    return match ($provider) {
        'deepseek' => wot_call_deepseek($key, $system, $messages),
        'gemini'   => wot_call_gemini($key, $system, $messages),
        'claude'   => wot_call_claude($key, $system, $messages),
        default    => throw new WotUserError('Unsupported provider.', 503, 'not_configured'),
    };
}


/**
 * Streaming variant of the same transport. Deltas are handed to $onDelta as they
 * arrive; the accumulated text and the truncation flag are returned unchanged, so the
 * caller can police the finished answer exactly like the buffered path.
 *
 * DeepSeek streams natively. Gemini and Claude have no stream here: their buffered
 * answer is delivered as a single delta so the wire format stays identical.
 *
 * @param callable(string):void $onDelta
 * @return array{text:string,truncated:bool}
 */
function wot_llm_stream(string $provider, string $key, string $system, array $messages, callable $onDelta): array
{
    $messages = array_values(array_filter($messages, static fn($m) => is_array($m) && isset($m['role'], $m['content'])));
    if (count($messages) > 12) {
        $messages = array_slice($messages, -12);
    }

    if ($provider !== 'deepseek') {
        $result = wot_llm_reply($provider, $key, $system, $messages);
        if ($result['text'] !== '') {
            $onDelta($result['text']);
        }
        return $result;
    }

    $payload = json_encode(
        wot_deepseek_payload($system, $messages) + ['stream' => true],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $full     = '';
    $buffer   = '';
    $truncated = false;
    $errored  = null;

    $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
    if ($ch === false) {
        throw new WotUserError('The consultant is unavailable right now. Please try again.', 502, 'upstream_error');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: text/event-stream',
            'Authorization: Bearer ' . $key,
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$buffer, &$full, &$truncated, $onDelta): int {
            $buffer .= $chunk;
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line   = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);
                if ($line === '' || !str_starts_with($line, 'data:')) {
                    continue;
                }
                $data = trim(substr($line, 5));
                if ($data === '' || $data === '[DONE]') {
                    continue;
                }
                $event = json_decode($data, true);
                if (!is_array($event)) {
                    continue;
                }
                $choice = $event['choices'][0] ?? null;
                if (!is_array($choice)) {
                    continue;
                }
                if (($choice['finish_reason'] ?? null) === 'length') {
                    $truncated = true;
                }
                $delta = $choice['delta']['content'] ?? '';
                if (is_string($delta) && $delta !== '') {
                    $full .= $delta;
                    $onDelta($delta);
                }
            }
            return strlen($chunk);
        },
    ]);

    $ok   = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($ok === false || $code >= 400 || $full === '') {
        $errored = $err !== '' ? $err : ('http ' . $code);
    }
    if ($errored !== null) {
        throw new WotUserError('The consultant is unavailable right now. Please try again.', 502, 'upstream_error');
    }

    return ['text' => $full, 'truncated' => $truncated];
}

/**
 * Splits accumulated text into a prefix that is safe to render mid-stream and a tail to
 * hold back. Anything that could become a link (an open label, an unfinished `](`, a
 * URL that is still growing) stays buffered until it is complete.
 *
 * @return array{0:string,1:string}
 */
function wot_stream_safe_prefix(string $text): array
{
    $cut = strlen($text);

    $lastOpen  = strrpos($text, '[');
    $lastClose = strrpos($text, ']');
    if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose)) {
        $cut = min($cut, $lastOpen);
    }

    $head      = substr($text, 0, $cut);
    $destStart = strrpos($head, '](');
    if ($destStart !== false && strpos($text, ')', $destStart) === false) {
        $labelOpen = strrpos(substr($head, 0, $destStart), '[');
        $cut = min($cut, $labelOpen === false ? $destStart : $labelOpen);
    }

    $head = substr($text, 0, $cut);
    if (preg_match('/(https?:\/\/\S*)$/', $head, $m, PREG_OFFSET_CAPTURE)) {
        $cut = min($cut, $m[1][1]);
    }

    return [substr($text, 0, $cut), substr($text, $cut)];
}

/**
 * Shared DeepSeek request body. `effort` is the model's reasoning budget: the default
 * "high" spends several seconds thinking before the first content token, which misses the
 * 3 s first-token target, so the consultant runs at "low" unless WOT_CHAT_EFFORT overrides it.
 *
 * @return array<string,mixed>
 */
function wot_deepseek_payload(string $system, array $messages): array
{
    $payload = [
        'model'       => (string) (getenv('WOT_CHAT_MODEL') ?: 'deepseek-flash'),
        'messages'    => array_merge([['role' => 'system', 'content' => $system]], $messages),
        'max_tokens'  => 1400,
        'temperature' => 0.3,
    ];
    $effort = trim((string) getenv('WOT_CHAT_EFFORT'));
    if ($effort === '') {
        $effort = 'low';
    }
    if (in_array($effort, ['low', 'high', 'max'], true)) {
        $payload['effort'] = $effort;
    }
    return $payload;
}

/** @return array{text:string,truncated:bool} */
function wot_call_deepseek(string $key, string $system, array $messages): array
{
    $res = wot_http_post('https://api.deepseek.com/v1/chat/completions', [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], wot_deepseek_payload($system, $messages));

    $data = json_decode((string) $res['body'], true);
    if (!is_array($data)) {
        throw new WotUserError('The consultant is unavailable right now. Please try again.', 502, 'upstream_error');
    }
    $choice = $data['choices'][0] ?? null;
    $text   = is_array($choice) ? trim((string) ($choice['message']['content'] ?? '')) : '';
    $finish = is_array($choice) ? (string) ($choice['finish_reason'] ?? '') : '';

    if ($text === '') {
        throw new WotUserError('The consultant is unavailable right now. Please try again.', 502, 'upstream_empty');
    }
    return ['text' => $text, 'truncated' => $finish === 'length'];
}

/** @return array{text:string,truncated:bool} */
function wot_call_gemini(string $key, string $system, array $messages): array
{
    $contents = [];
    foreach ($messages as $m) {
        $contents[] = [
            'role'  => $m['role'] === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => (string) $m['content']]],
        ];
    }
    $res = wot_http_post(
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode($key),
        ['Content-Type: application/json'],
        [
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents'           => $contents,
            'generationConfig'   => ['maxOutputTokens' => 1400, 'temperature' => 0.3],
        ]
    );
    $data = json_decode((string) $res['body'], true);
    $text = is_array($data) ? trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? '')) : '';
    if ($text === '') {
        throw new WotUserError('The consultant is unavailable right now. Please try again.', 502, 'upstream_empty');
    }
    $finish = is_array($data) ? (string) ($data['candidates'][0]['finishReason'] ?? '') : '';
    return ['text' => $text, 'truncated' => $finish === 'MAX_TOKENS'];
}

/** @return array{text:string,truncated:bool} */
function wot_call_claude(string $key, string $system, array $messages): array
{
    $res = wot_http_post('https://api.anthropic.com/v1/messages', [
        'Content-Type: application/json',
        'x-api-key: ' . $key,
        'anthropic-version: 2023-06-01',
    ], [
        'model'      => 'claude-haiku-4-5-20251001',
        'max_tokens' => 1400,
        'system'     => $system,
        'messages'   => $messages,
    ]);
    $data = json_decode((string) $res['body'], true);
    $text = is_array($data) ? trim((string) ($data['content'][0]['text'] ?? '')) : '';
    if ($text === '') {
        throw new WotUserError('The consultant is unavailable right now. Please try again.', 502, 'upstream_empty');
    }
    return ['text' => $text, 'truncated' => ($data['stop_reason'] ?? '') === 'max_tokens'];
}

/**
 * Repairs a reply that ran out of budget in the middle of a markdown link, so the
 * UI never receives half a URL.
 */
function wot_repair_truncation(string $reply): string
{
    $open = substr_count($reply, '[');
    $close = substr_count($reply, ']');
    if ($open > $close) {
        $pos = strrpos($reply, '[');
        if ($pos !== false) {
            $reply = trim(substr($reply, 0, $pos));
        }
    }
    // A link whose "(" was never closed.
    $lastOpenParen = strrpos($reply, '](');
    if ($lastOpenParen !== false && strpos($reply, ')', $lastOpenParen) === false) {
        $reply = trim(substr($reply, 0, $lastOpenParen + 1));
    }
    return trim($reply);
}

/**
 * Keeps only first-party links that were actually supplied as candidates, and rewrites
 * them to the canonical trailing-slash form. Anything else degrades to plain text.
 *
 * @param array<string,bool> $allowedCandidateUrls
 */
function wot_police_links(string $reply, array $allowedCandidateUrls): string
{
    static $staticPages = [
        'https://www.worldoftest.com/contact'          => 'https://www.worldoftest.com/contact/',
        'https://www.worldoftest.com/contact/'         => 'https://www.worldoftest.com/contact/',
        'https://www.worldoftest.com/product-categories'  => 'https://www.worldoftest.com/product-categories',
        'https://www.worldoftest.com/product-categories/' => 'https://www.worldoftest.com/product-categories',
        'https://worldoftest.com/'                     => 'https://www.worldoftest.com/',
        'https://www.worldoftest.com/'                 => 'https://www.worldoftest.com/',
    ];

    $resolve = static function (string $url) use ($allowedCandidateUrls, $staticPages): ?string {
        $url = trim($url);
        if (isset($staticPages[$url])) {
            return $staticPages[$url];
        }
        $canonical = wot_ensure_slash($url);
        if (isset($staticPages[$canonical])) {
            return $staticPages[$canonical];
        }
        if (isset($allowedCandidateUrls[$canonical]) || isset($allowedCandidateUrls[$url])) {
            return $canonical;
        }
        return null;
    };

    // Markdown links first.
    $reply = preg_replace_callback(
        '/\[([^\]\n]{1,120})\]\((https?:\/\/[^\s\)]+)\)/u',
        static function (array $m) use ($resolve): string {
            $resolved = $resolve($m[2]);
            return $resolved === null ? $m[1] : '[' . $m[1] . '](' . $resolved . ')';
        },
        $reply
    ) ?? $reply;

    // Bare URLs: keep allowed ones canonical, drop the rest to text.
    $reply = preg_replace_callback(
        '/https?:\/\/[^\s\)\]<"\']+/u',
        static function (array $m) use ($resolve): string {
            $resolved = $resolve($m[0]);
            return $resolved ?? 'worldoftest.com';
        },
        $reply
    ) ?? $reply;

    return $reply;
}

/** Removes internal search phrasing the client flagged. */
function wot_strip_internal_phrasing(string $reply): string
{
    $map = [
        '/\bour matched catalog\b/i'      => 'this shortlist',
        '/\bour matched inventory\b/i'    => 'this shortlist',
        '/\bmv?y matched catalog\b/i'     => 'this shortlist',
        '/\bour catalog contains\b/i'     => 'this shortlist includes',
        '/\bmv?y matched inventory\b/i'   => 'this shortlist',
        '/\bthe matched catalog\b/i'      => 'this shortlist',
        '/\bin (?:my|our) catalog\b/i'    => 'in this shortlist',
    ];
    return preg_replace(array_keys($map), array_values($map), $reply) ?? $reply;
}

/**
 * A consultant answer that recommends nothing is not an answer. If the model replied
 * without citing a supplied candidate, append the top candidates so the visitor always
 * gets a real product link.
 *
 * @param list<array<string,mixed>> $candidates
 */
function wot_augment_missing_links(string $reply, array $candidates): string
{
    if ($candidates === [] || $reply === '') {
        return $reply;
    }
    foreach ($candidates as $candidate) {
        if (str_contains($reply, $candidate['url']) || str_contains($reply, rtrim($candidate['url'], '/'))) {
            return $reply;
        }
    }
    $lines = [];
    foreach (array_slice($candidates, 0, 2) as $candidate) {
        $lines[] = '- [' . $candidate['name'] . '](' . $candidate['url'] . ')';
    }
    return rtrim($reply) . "\n\nClosest matches in the catalog:\n" . implode("\n", $lines);
}

/**
 * Product images: catalog image when the enriched record has one, otherwise one
 * cached scrape per URL (bounded, never more than 2 lookups per reply).
 *
 * @param list<array<string,mixed>> $candidates
 * @return array<string,string> url => image url
 */
function wot_product_images(string $reply, array $candidates, string $cacheFile): array
{
    preg_match_all('#https://www\.worldoftest\.com/[^\s\)\]"\'<>]+#', $reply, $m);
    $urls = array_values(array_unique(array_slice($m[0], 0, 4)));
    if ($urls === []) {
        return [];
    }

    $images = [];
    $bySlug = [];
    foreach ($candidates as $p) {
        $bySlug[$p['url']] = $p['image'] ?? '';
    }

    $cache = [];
    if (is_file($cacheFile)) {
        $raw   = @file_get_contents($cacheFile);
        $cache = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
    }

    $scrapes = 0;
    foreach ($urls as $url) {
        $canonical = wot_ensure_slash($url);
        $known     = $bySlug[$canonical] ?? '';
        if ($known !== '') {
            $images[$canonical] = $known;
            continue;
        }
        if (isset($cache[$canonical]) && $cache[$canonical] !== '') {
            $images[$canonical] = $cache[$canonical];
            continue;
        }
        if ($scrapes >= 2 || !isset($bySlug[$canonical])) {
            continue; // only products we actually supplied are worth a lookup
        }
        $scrapes++;
        $ch = curl_init($canonical);
        if ($ch === false) {
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 4,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; WOTConsultant/2.0)',
        ]);
        $html = curl_exec($ch);
        curl_close($ch);
        if (!is_string($html) || $html === '') {
            continue;
        }
        if (preg_match('#src=["\']([^"\']*(?:styles/product|sites/default/files/styles)[^"\']+)["\']#i', $html, $img)) {
            $src = $img[1];
            $src = str_starts_with($src, 'http') ? $src : 'https://www.worldoftest.com' . $src;
            if (str_starts_with($src, 'https://www.worldoftest.com/')) {
                $images[$canonical] = $src;
                $cache[$canonical]  = $src;
            }
        }
    }

    if ($cache !== []) {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if ($cache !== [] && @file_put_contents($cacheFile, json_encode($cache, JSON_UNESCAPED_SLASHES)) === false) {
            // non-fatal: images simply get re-scraped next time
        }
    }

    return $images;
}
