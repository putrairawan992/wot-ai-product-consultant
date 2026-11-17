<?php
declare(strict_types=1);

/**
 * Security primitives for the WOT consultant API: error shaping, CORS allowlist,
 * strict input typing, per-IP rate limiting, Turnstile verification, PII scrubbing.
 * No secrets live in this file; every credential is read from the environment.
 */

const WOT_SITE_HOST   = 'worldoftest.com';
const WOT_CONTACT_URL = 'https://www.worldoftest.com/contact/';
const WOT_BROWSE_URL  = 'https://www.worldoftest.com/product-categories';

final class WotUserError extends RuntimeException
{
    public function __construct(string $message, public int $status = 400, public string $slug = 'bad_request')
    {
        parent::__construct($message);
    }
}

/** Errors become JSON, never HTML with paths or traces. */
function wot_bootstrap(): void
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('html_errors', '0');
    ini_set('log_errors', '1');
    header_remove('X-Powered-By');
    error_reporting(E_ALL);

    set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
        if (!(error_reporting() & $no)) {
            return false;
        }
        throw new ErrorException($str, 0, $no, $file, $line);
    });

    set_exception_handler(static function (Throwable $e): void {
        error_log('[wot] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if ($e instanceof WotUserError) {
            wot_json(['error' => $e->getMessage(), 'code' => $e->slug], $e->status);
            return;
        }
        wot_json(['error' => 'The consultant is unavailable right now. Please try again.', 'code' => 'server_error'], 500);
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err === null || !in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        error_log('[wot] fatal: ' . $err['message'] . ' @ ' . $err['file'] . ':' . $err['line']);
        if (!headers_sent()) {
            wot_json(['error' => 'The consultant is unavailable right now. Please try again.', 'code' => 'server_error'], 500);
        }
    });
}

/** @param array<string,mixed> $payload */
function wot_json(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/** Only worldoftest.com (any subdomain) and loopback dev origins may read the API. */
function wot_origin_allowed(?string $origin): bool
{
    if ($origin === null || $origin === '') {
        return false;
    }
    $parts = parse_url($origin);
    if (!is_array($parts) || empty($parts['host']) || empty($parts['scheme'])) {
        return false;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }
    $host = strtolower($parts['host']);
    if ($host === WOT_SITE_HOST || str_ends_with($host, '.' . WOT_SITE_HOST)) {
        return true;
    }
    return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
}

function wot_send_cors(?string $origin): void
{
    if ($origin === null || !wot_origin_allowed($origin)) {
        return; // no ACAO header at all: foreign origins get nothing from the browser
    }
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    header('Access-Control-Max-Age: 600');
}

/**
 * Strict request validation. Nothing is coerced silently: a wrong type is a 400.
 *
 * @return array{message:string,history:list<array{role:string,content:string}>,cf_token:string,session_id:string}
 */
function wot_read_payload(string $rawBody): array
{
    if (strlen($rawBody) > 65536) {
        throw new WotUserError('Request too large.', 413, 'payload_too_large');
    }
    $body = json_decode($rawBody, true);
    if (!is_array($body)) {
        throw new WotUserError('Body must be a JSON object.', 400, 'invalid_json');
    }

    $message = $body['message'] ?? null;
    if (!is_string($message)) {
        throw new WotUserError('message must be a string.', 400, 'invalid_message');
    }
    $message = trim($message);
    if ($message === '') {
        throw new WotUserError('Message is empty.', 400, 'empty_message');
    }
    if (mb_strlen($message) > 1000) {
        throw new WotUserError('Message too long (max 1000 characters).', 400, 'message_too_long');
    }

    $history = $body['history'] ?? [];
    if (!is_array($history) || ($history !== [] && array_is_list($history) === false)) {
        throw new WotUserError('history must be an array.', 400, 'invalid_history');
    }
    $cleanHistory = [];
    foreach ($history as $turn) {
        if (!is_array($turn)) {
            throw new WotUserError('history must contain message objects.', 400, 'invalid_history');
        }
        $role    = $turn['role'] ?? null;
        $content = $turn['content'] ?? null;
        if (!is_string($role) || !is_string($content)) {
            throw new WotUserError('history entries need string role and content.', 400, 'invalid_history');
        }
        if (!in_array($role, ['user', 'assistant'], true)) {
            continue;
        }
        $content = trim($content);
        if ($content === '') {
            continue;
        }
        $cleanHistory[] = ['role' => $role, 'content' => mb_substr($content, 0, 4000)];
    }
    if (count($cleanHistory) > 12) {
        $cleanHistory = array_slice($cleanHistory, -12);
    }

    $cfToken = $body['cf_token'] ?? '';
    if (!is_string($cfToken)) {
        throw new WotUserError('cf_token must be a string.', 400, 'invalid_cf_token');
    }
    if (strlen($cfToken) > 4096) {
        $cfToken = '';
    }

    $sessionId = $body['session_id'] ?? '';
    if (!is_string($sessionId)) {
        throw new WotUserError('session_id must be a string.', 400, 'invalid_session_id');
    }
    $sessionId = preg_replace('/[^A-Za-z0-9._-]/', '', $sessionId) ?? '';
    $sessionId = substr($sessionId, 0, 64);

    return [
        'message'    => $message,
        'history'    => $cleanHistory,
        'cf_token'   => $cfToken,
        'session_id' => $sessionId,
    ];
}

/**
 * File-based sliding counters. Returns null when the request may proceed,
 * otherwise the retry hint for a 429.
 *
 * @return array{retry_after:int,limit:string}|null
 */
function wot_rate_limit(string $ip, string $dir, int $perMinute = 10, int $perDay = 200): ?array
{
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return null; // fail open: never take the chat down because storage is read-only
    }
    $file = $dir . '/' . sha1($ip) . '.json';
    $fp   = @fopen($file, 'c+');
    if ($fp === false) {
        return null;
    }
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return null;
    }

    $now  = time();
    $raw  = stream_get_contents($fp) ?: '';
    $st   = json_decode($raw, true);
    $st   = is_array($st) ? $st : [];

    $minuteStart = (int) ($st['minute_start'] ?? 0);
    $minuteCount = (int) ($st['minute_count'] ?? 0);
    $day         = (string) ($st['day'] ?? '');
    $dayCount    = (int) ($st['day_count'] ?? 0);
    $today       = gmdate('Y-m-d', $now);

    if ($day !== $today) {
        $day      = $today;
        $dayCount = 0;
    }
    if ($minuteStart === 0 || $now - $minuteStart >= 60) {
        $minuteStart = $now;
        $minuteCount = 0;
    }

    $blocked = null;
    if ($minuteCount >= $perMinute) {
        $blocked = ['retry_after' => max(1, 60 - ($now - $minuteStart)), 'limit' => 'minute'];
    } elseif ($dayCount >= $perDay) {
        $blocked = ['retry_after' => max(60, 86400 - ($now % 86400)), 'limit' => 'day'];
    }

    if ($blocked === null) {
        $minuteCount++;
        $dayCount++;
    }

    $state = json_encode([
        'minute_start' => $minuteStart,
        'minute_count' => $minuteCount,
        'day'          => $day,
        'day_count'    => $dayCount,
    ]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string) $state);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $blocked;
}

/** Verifies a Turnstile token. Only called when CF_TURNSTILE_SECRET is set. */
function wot_turnstile_ok(string $secret, string $token, string $ip): bool
{
    if ($token === '') {
        return false;
    }
    $res = wot_http_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
        'Content-Type: application/json',
    ], [
        'secret'   => $secret,
        'response' => $token,
        'remoteip' => $ip,
    ]);
    $data = json_decode((string) $res['body'], true);
    return is_array($data) && ($data['success'] ?? false) === true;
}

/** Query logs keep the test intent, never contact details. */
function wot_scrub_pii(string $text): string
{
    $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]{2,}/u', '[email]', $text) ?? $text;
    $text = preg_replace('/(?<!\d)(?:\+?\d[\d\s().-]{6,}\d)(?!\d)/u', '[number]', $text) ?? $text;
    return $text;
}

/** @return array{body:string,code:int} */
function wot_http_post(string $url, array $headers, array $payload): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['body' => '', 'code' => 0];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => is_string($body) ? $body : '', 'code' => $code];
}
