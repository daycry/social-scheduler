<?php
/**
 * Spike TikTok (T-03) — script DESECHABLE, PHP plano + curl. Ver spike/README.md.
 * Uso: php spike/tiktok_probe.php --auth | --code=XXX | --creator-info | --publish=video_file|video_url|photo | --status=<publish_id> | --check
 * Servidor de callback: php -S 127.0.0.1:8080 spike/tiktok_probe.php  (atiende /tiktok/callback)
 */
const P = 'tiktok';
const SCOPES = 'user.info.basic,video.publish,video.upload';
const API = 'https://open.tiktokapis.com';

// ---------- helpers comunes (desechables) ----------
function load_env(): void {
    $f = __DIR__ . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) {
        if ($l[0] === '#' || !str_contains($l, '=')) continue;
        [$k, $v] = explode('=', $l, 2);
        $_ENV[trim($k)] = trim($v, " \t\"'");
    }
}
function env(string $k): string { return $_ENV[$k] ?? getenv($k) ?: ''; }
function need(array $vars): void {
    $miss = array_values(array_filter($vars, fn($v) => env($v) === ''));
    if ($miss) {
        fwrite(STDERR, "ERROR: faltan variables en spike/.env: " . implode(', ', $miss) . " (ver spike/README.md y spike/.env.example)\n");
        exit(2);
    }
}
function http(string $method, string $url, array $params = [], array $headers = [], $body = null): array {
    $ch = curl_init();
    if ($method === 'GET' && $params) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 300]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? http_build_query($params));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, ['curl_error' => $err], ''];
    return [$code, json_decode($raw, true) ?? [], $raw];
}
function store(string $suffix, array $data): void { file_put_contents(__DIR__ . "/.$suffix-" . P . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
function fetch_store(string $suffix): array { $f = __DIR__ . "/.$suffix-" . P . '.json'; return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function record(string $key, $val): void { $r = fetch_store('results'); $r[$key] = ['at' => gmdate('c'), 'data' => $val]; store('results', $r); }
function out($v): void { echo json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; }

// ---------- TikTok ----------
function auth_url(): string {
    return 'https://www.tiktok.com/v2/auth/authorize/?' . http_build_query([
        'client_key' => env('TIKTOK_CLIENT_KEY'), 'scope' => SCOPES, 'response_type' => 'code',
        'redirect_uri' => env('TIKTOK_REDIRECT_URI'), 'state' => 'spike']);
}

function exchange_code(string $code): array {
    [$c, $j] = http('POST', API . '/v2/oauth/token/', [], ['Content-Type: application/x-www-form-urlencoded', 'Cache-Control: no-cache'],
        http_build_query(['client_key' => env('TIKTOK_CLIENT_KEY'), 'client_secret' => env('TIKTOK_CLIENT_SECRET'), 'code' => $code,
            'grant_type' => 'authorization_code', 'redirect_uri' => env('TIKTOK_REDIRECT_URI')]));
    if (!isset($j['access_token'])) { fwrite(STDERR, "ERROR canjeando code ($c): " . json_encode($j) . "\n"); exit(1); }
    $j['saved_at'] = gmdate('c');
    store('tokens', $j);
    return $j;
}

function token(): string {
    $t = fetch_store('tokens');
    if (!$t) { fwrite(STDERR, "ERROR: no hay token. Ejecuta --auth, autoriza y usa --code=XXX (o el servidor de callback).\n"); exit(2); }
    // refrescar si el access token caducó
    if (strtotime($t['saved_at']) + ($t['expires_in'] ?? 0) - 120 < time() && !empty($t['refresh_token'])) {
        [$c, $j] = http('POST', API . '/v2/oauth/token/', [], ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query(['client_key' => env('TIKTOK_CLIENT_KEY'), 'client_secret' => env('TIKTOK_CLIENT_SECRET'),
                'grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]));
        if (isset($j['access_token'])) { $j['saved_at'] = gmdate('c'); store('tokens', $j); $t = $j; }
        else { fwrite(STDERR, "ERROR refrescando token ($c): " . json_encode($j) . "\n"); exit(1); }
    }
    return $t['access_token'];
}

function api(string $path, array $json): array {
    [$c, $j] = http('POST', API . $path, [], ['Authorization: Bearer ' . token(), 'Content-Type: application/json; charset=UTF-8'], json_encode($json));
    return [$c, $j];
}

function creator_info(): array {
    [$c, $j] = api('/v2/post/publish/creator_info/query/', []);
    $ok = $c === 200 && ($j['error']['code'] ?? 'x') === 'ok';
    record('creator_info', ['http' => $c, 'ok' => $ok, 'response' => $j]);
    return [$ok, $j];
}

function best_privacy(array $info): string {
    $opts = $info['data']['privacy_level_options'] ?? [];
    return in_array('SELF_ONLY', $opts, true) ? 'SELF_ONLY' : ($opts[0] ?? 'SELF_ONLY');
}

function publish(string $type): void {
    [$ok, $ci] = creator_info();
    if (!$ok) { fwrite(STDERR, "ERROR creator_info: " . json_encode($ci) . "\n"); exit(1); }
    $priv = best_privacy($ci); // se usa la opción más restrictiva: es una prueba
    $post = ['title' => 'Spike ' . gmdate('c'), 'privacy_level' => $priv, 'disable_comment' => true];
    switch ($type) {
        case 'video_file':
            need(['TIKTOK_TEST_VIDEO_FILE']);
            $f = env('TIKTOK_TEST_VIDEO_FILE');
            if (!is_file($f)) { fwrite(STDERR, "ERROR: no existe TIKTOK_TEST_VIDEO_FILE=$f\n"); exit(2); }
            $size = filesize($f);
            // un solo chunk si <= 64 MB; si no, chunks de 10 MB (el último absorbe el resto)
            $chunk = $size <= 64 * 1024 * 1024 ? $size : 10 * 1024 * 1024;
            $total = (int) floor($size / $chunk);
            [$c, $j] = api('/v2/post/publish/video/init/', ['post_info' => $post,
                'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $chunk, 'total_chunk_count' => max(1, $total)]]);
            if (($j['error']['code'] ?? 'x') !== 'ok') break;
            $fh = fopen($f, 'rb'); $off = 0; $n = max(1, $total); $puts = [];
            for ($i = 0; $i < $n; $i++) {
                $len = ($i === $n - 1) ? $size - $off : $chunk;
                $data = fread($fh, $len);
                [$pc] = http('PUT', $j['data']['upload_url'], [], ['Content-Type: video/mp4', 'Content-Length: ' . $len,
                    'Content-Range: bytes ' . $off . '-' . ($off + $len - 1) . '/' . $size], $data);
                $puts[] = $pc; $off += $len;
            }
            fclose($fh);
            $j['_upload_http'] = $puts;
            break;
        case 'video_url':
            need(['TIKTOK_TEST_VIDEO_URL']);
            [$c, $j] = api('/v2/post/publish/video/init/', ['post_info' => $post,
                'source_info' => ['source' => 'PULL_FROM_URL', 'video_url' => env('TIKTOK_TEST_VIDEO_URL')]]);
            break;
        case 'photo':
            need(['TIKTOK_TEST_PHOTO_URL']);
            [$c, $j] = api('/v2/post/publish/content/init/', ['media_type' => 'PHOTO', 'post_mode' => 'DIRECT_POST',
                'post_info' => ['title' => $post['title'], 'description' => 'spike', 'privacy_level' => $priv, 'disable_comment' => true],
                'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => [env('TIKTOK_TEST_PHOTO_URL')]]]);
            break;
        default: fwrite(STDERR, "ERROR: tipo desconocido '$type' (video_file|video_url|photo)\n"); exit(2);
    }
    $ok = ($j['error']['code'] ?? 'x') === 'ok';
    record("publish.$type", ['privacy_used' => $priv, 'ok' => $ok, 'response' => $j]);
    out(['type' => $type, 'privacy_used' => $priv, 'ok' => $ok, 'response' => $j]);
    if (!$ok) exit(1);
}

function status(string $id): void {
    [$c, $j] = api('/v2/post/publish/status/fetch/', ['publish_id' => $id]);
    record("status.$id", ['http' => $c, 'response' => $j]);
    out($j);
}

function check(): void {
    $t = fetch_store('tokens');
    [$ok, $ci] = creator_info();
    if (!$ok) { fwrite(STDERR, "ERROR: creator_info/query falló: " . json_encode($ci) . "\n"); exit(1); }
    $d = $ci['data'];
    echo "== TikTok --check ==\n";
    echo "Cuenta: " . ($d['creator_username'] ?? '?') . " (" . ($d['creator_nickname'] ?? '?') . ")\n";
    echo "privacy_level_options: " . implode(', ', $d['privacy_level_options'] ?? []) . "\n";
    echo "¿solo SELF_ONLY? " . (($d['privacy_level_options'] ?? []) === ['SELF_ONLY'] ? 'SÍ (app sin auditar)' : 'no') . "\n";
    echo "max_video_post_duration_sec: " . ($d['max_video_post_duration_sec'] ?? '?') . " | comentarios/duet/stitch deshabilitados: "
        . json_encode([$d['comment_disabled'] ?? null, $d['duet_disabled'] ?? null, $d['stitch_disabled'] ?? null]) . "\n";
    echo "access_token expira en " . ($t['expires_in'] ?? '?') . " s | refresh_token expira en " . ($t['refresh_expires_in'] ?? '?') . " s | scope: " . ($t['scope'] ?? '?') . "\n\n";
    $r = fetch_store('results');
    printf("%-14s %-6s %s\n", 'PUBLICACIÓN', 'OK', 'publish_id / error');
    foreach (['video_file', 'video_url', 'photo'] as $ty) {
        $x = $r["publish.$ty"]['data'] ?? null;
        printf("%-14s %-6s %s\n", $ty, $x ? ($x['ok'] ? 'sí' : 'NO') : '-', $x ? ($x['ok'] ? ($x['response']['data']['publish_id'] ?? '') : ($x['response']['error']['code'] ?? '') . ' ' . ($x['response']['error']['message'] ?? '')) : 'sin probar');
    }
    echo "\nPega esta salida en spike-report.md §TikTok (anota si PULL_FROM_URL falló por dominio no verificado: url_ownership_unverified).\n";
}

// ---------- main ----------
load_env();
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    header('Content-Type: text/plain');
    if ($path === '/tiktok/callback' && isset($_GET['code'])) {
        need(['TIKTOK_CLIENT_KEY', 'TIKTOK_CLIENT_SECRET', 'TIKTOK_REDIRECT_URI']);
        $t = exchange_code($_GET['code']);
        echo "Token guardado. scope=" . ($t['scope'] ?? '') . ". Ejecuta --check.\n";
    } else { http_response_code(404); echo "esperando /tiktok/callback?code=...\n"; }
    return;
}
$o = getopt('', ['auth', 'code:', 'creator-info', 'publish:', 'status:', 'check']);
if (!$o) { echo "Uso: php spike/tiktok_probe.php --auth | --code=XXX | --creator-info | --publish=video_file|video_url|photo | --status=<id> | --check\n"; exit(2); }
need(['TIKTOK_CLIENT_KEY', 'TIKTOK_CLIENT_SECRET', 'TIKTOK_REDIRECT_URI']);
if (isset($o['auth'])) { echo auth_url() . "\n"; exit(0); }
if (isset($o['code'])) { $t = exchange_code($o['code']); out(['saved' => true, 'scope' => $t['scope'] ?? null, 'expires_in' => $t['expires_in'] ?? null, 'refresh_expires_in' => $t['refresh_expires_in'] ?? null]); exit(0); }
if (isset($o['creator-info'])) { [$ok, $j] = creator_info(); out($j); exit($ok ? 0 : 1); }
if (isset($o['publish'])) { publish($o['publish']); exit(0); }
if (isset($o['status'])) { status($o['status']); exit(0); }
if (isset($o['check'])) { check(); exit(0); }
