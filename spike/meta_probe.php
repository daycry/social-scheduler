<?php
/**
 * Spike Meta (T-02) — script DESECHABLE, PHP plano + curl. Ver spike/README.md.
 * Uso: php spike/meta_probe.php --auth | --code=XXX | --publish=<tipo> | --insights | --check
 * Servidor de callback: php -S 127.0.0.1:8080 spike/meta_probe.php  (atiende /meta/callback)
 */
const P = 'meta';
const SCOPES = 'pages_show_list,pages_read_engagement,pages_manage_posts,read_insights,instagram_basic,instagram_content_publish,instagram_manage_insights';

// ---------- helpers comunes (copiados en cada probe; desechables) ----------
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
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120]);
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
function apierr(array $j): string { return isset($j['error']) ? json_encode($j['error']) : json_encode($j); }

// ---------- Meta ----------
function g(string $path = ''): string { return 'https://graph.facebook.com/' . (env('META_GRAPH_VERSION') ?: 'v23.0') . '/' . ltrim($path, '/'); }

function auth_url(): string {
    return 'https://www.facebook.com/' . (env('META_GRAPH_VERSION') ?: 'v23.0') . '/dialog/oauth?' . http_build_query([
        'client_id' => env('META_APP_ID'), 'redirect_uri' => env('META_REDIRECT_URI'),
        'scope' => SCOPES, 'response_type' => 'code', 'state' => 'spike']);
}

function exchange_code(string $code): array {
    [$c, $j] = http('GET', g('oauth/access_token'), ['client_id' => env('META_APP_ID'), 'client_secret' => env('META_APP_SECRET'),
        'redirect_uri' => env('META_REDIRECT_URI'), 'code' => $code]);
    if (!isset($j['access_token'])) { fwrite(STDERR, "ERROR canjeando code ($c): " . apierr($j) . "\n"); exit(1); }
    // token de larga duración (~60 días)
    [$c2, $l] = http('GET', g('oauth/access_token'), ['grant_type' => 'fb_exchange_token', 'client_id' => env('META_APP_ID'),
        'client_secret' => env('META_APP_SECRET'), 'fb_exchange_token' => $j['access_token']]);
    $user = $l['access_token'] ?? $j['access_token'];
    [, $pages] = http('GET', g('me/accounts'), ['access_token' => $user, 'fields' => 'id,name,access_token,instagram_business_account{id,username}']);
    $t = ['user_token' => $user, 'user_token_expires_in' => $l['expires_in'] ?? null, 'long_lived' => isset($l['access_token']),
        'pages' => $pages['data'] ?? [], 'pages_error' => $pages['error'] ?? null, 'saved_at' => gmdate('c')];
    store('tokens', $t);
    return $t;
}

function ctx(): array {
    $t = fetch_store('tokens');
    if (!$t) { fwrite(STDERR, "ERROR: no hay token. Ejecuta --auth, autoriza y usa --code=XXX (o el servidor de callback).\n"); exit(2); }
    $p = $t['pages'][0] ?? null;
    if ($want = env('META_PAGE_ID')) { foreach ($t['pages'] ?? [] as $cand) { if ($cand['id'] === $want) { $p = $cand; } } }
    if (!$p) { fwrite(STDERR, "ERROR: el usuario no administra ninguna Página (revisa roles y scopes). Detalle: " . json_encode($t['pages_error']) . "\n"); exit(2); }
    return [$t, $p, $p['instagram_business_account']['id'] ?? null];
}

function wait_container(string $id, string $tok): array {
    for ($i = 0; $i < 40; $i++) {
        [, $j] = http('GET', g($id), ['fields' => 'status_code,status', 'access_token' => $tok]);
        if (($j['status_code'] ?? '') === 'FINISHED' || ($j['status_code'] ?? '') === 'ERROR') return $j;
        sleep(5);
    }
    return ['status_code' => 'TIMEOUT'];
}

function publish(string $type): void {
    [$t, $page, $ig] = ctx();
    $ptok = $page['access_token'];
    $img = env('META_TEST_IMAGE_URL'); $vid = env('META_TEST_VIDEO_URL');
    $caption = 'Spike Meta ' . gmdate('c');
    switch ($type) {
        case 'fb_text': [$c, $j] = http('POST', g("{$page['id']}/feed"), ['message' => $caption, 'access_token' => $ptok]); break;
        case 'fb_photo': need(['META_TEST_IMAGE_URL']); [$c, $j] = http('POST', g("{$page['id']}/photos"), ['url' => $img, 'caption' => $caption, 'access_token' => $ptok]); break;
        case 'ig_image': case 'ig_reel': case 'ig_story':
            if (!$ig) { fwrite(STDERR, "ERROR: la Página no tiene cuenta IG Business/Creator enlazada.\n"); exit(2); }
            $p = ['access_token' => $ptok];
            if ($type === 'ig_image') { need(['META_TEST_IMAGE_URL']); $p += ['image_url' => $img, 'caption' => $caption]; }
            if ($type === 'ig_reel')  { need(['META_TEST_VIDEO_URL']); $p += ['media_type' => 'REELS', 'video_url' => $vid, 'caption' => $caption]; }
            if ($type === 'ig_story') { need(['META_TEST_IMAGE_URL']); $p += ['media_type' => 'STORIES', 'image_url' => $img]; }
            [$c, $cont] = http('POST', g("$ig/media"), $p);
            if (!isset($cont['id'])) { $j = $cont; break; }
            $st = $type === 'ig_reel' ? wait_container($cont['id'], $ptok) : ['status_code' => 'n/a'];
            [$c, $j] = http('POST', g("$ig/media_publish"), ['creation_id' => $cont['id'], 'access_token' => $ptok]);
            $j['_container'] = $cont['id']; $j['_container_status'] = $st;
            break;
        default: fwrite(STDERR, "ERROR: tipo desconocido '$type' (fb_text|fb_photo|ig_image|ig_reel|ig_story)\n"); exit(2);
    }
    $ok = $c >= 200 && $c < 300 && !isset($j['error']);
    record("publish.$type", ['http' => $c, 'ok' => $ok, 'response' => $j]);
    out(['type' => $type, 'http' => $c, 'ok' => $ok, 'response' => $j]);
    if (!$ok) exit(1);
}

function insights(): array {
    [$t, $page, $ig] = ctx(); $ptok = $page['access_token']; $res = [];
    $try = function (string $label, string $path, array $p) use (&$res, $ptok) {
        [$c, $j] = http('GET', g($path), $p + ['access_token' => $ptok]);
        $res[$label] = isset($j['error']) ? ['ok' => false, 'error' => $j['error']['message'] ?? 'error'] : ['ok' => true, 'sample' => $j['data'][0] ?? $j];
    };
    // Métricas individuales para saber cuáles acepta la versión vigente (pregunta (c): impressions vs views).
    if ($ig) {
        foreach (['impressions', 'views', 'reach', 'profile_views', 'accounts_engaged', 'follower_count'] as $m)
            $try("ig.account.$m", "$ig/insights", ['metric' => $m, 'period' => 'day', 'metric_type' => 'total_value']);
        [, $media] = http('GET', g("$ig/media"), ['fields' => 'id,media_type,timestamp', 'limit' => 1, 'access_token' => $ptok]);
        if ($mid = $media['data'][0]['id'] ?? null)
            foreach (['impressions', 'views', 'reach', 'likes', 'comments', 'shares', 'saved', 'total_interactions'] as $m)
                $try("ig.media.$m", "$mid/insights", ['metric' => $m]);
    }
    foreach (['page_impressions', 'page_media_view', 'page_post_engagements', 'page_follows', 'page_fans'] as $m)
        $try("fb.page.$m", "{$page['id']}/insights", ['metric' => $m, 'period' => 'day']);
    $try('ig.publishing_limit', ($ig ?: 'me') . '/content_publishing_limit', ['fields' => 'quota_usage,config']);
    record('insights', $res);
    return $res;
}

function check(): void {
    [$t, $page, $ig] = ctx();
    [$c, $d] = http('GET', g('debug_token'), ['input_token' => $t['user_token'], 'access_token' => env('META_APP_ID') . '|' . env('META_APP_SECRET')]);
    $dd = $d['data'] ?? [];
    if (empty($dd['is_valid'])) { fwrite(STDERR, "ERROR: token no válido: " . apierr($d) . "\n"); exit(1); }
    $r = fetch_store('results');
    $ins = $r['insights']['data'] ?? insights();
    echo "== Meta --check ==\nGraph: " . (env('META_GRAPH_VERSION') ?: 'v23.0') . " | Página: {$page['name']} ({$page['id']}) | IG: " . ($ig ?: 'NO ENLAZADA') . "\n";
    echo "Token larga duración: " . ($t['long_lived'] ? 'sí' : 'no') . " | caduca: " . (isset($dd['expires_at']) && $dd['expires_at'] ? gmdate('c', $dd['expires_at']) : 'nunca/desconocido') . " | data_access hasta: " . (isset($dd['data_access_expires_at']) ? gmdate('c', $dd['data_access_expires_at']) : '?') . "\n";
    echo "Permisos concedidos: " . implode(', ', $dd['scopes'] ?? []) . "\n\n";
    printf("%-22s %-6s %s\n", 'TIPO PUBLICADO', 'OK', 'ID / error');
    foreach (['fb_text', 'fb_photo', 'ig_image', 'ig_reel', 'ig_story'] as $ty) {
        $x = $r["publish.$ty"]['data'] ?? null;
        printf("%-22s %-6s %s\n", $ty, $x ? ($x['ok'] ? 'sí' : 'NO') : '-', $x ? json_encode($x['ok'] ? ($x['response']['id'] ?? $x['response']['post_id'] ?? '') : ($x['response']['error']['message'] ?? '')) : 'sin probar');
    }
    echo "\n";
    printf("%-28s %-6s %s\n", 'MÉTRICA DE INSIGHTS', 'OK', 'detalle');
    foreach ($ins as $k => $v) printf("%-28s %-6s %s\n", $k, $v['ok'] ? 'sí' : 'NO', $v['ok'] ? substr(json_encode($v['sample']), 0, 80) : $v['error']);
    echo "\nPega esta salida en spike-report.md §Meta.\n";
}

// ---------- main ----------
load_env();
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($path === '/meta/callback' && isset($_GET['code'])) {
        need(['META_APP_ID', 'META_APP_SECRET', 'META_REDIRECT_URI']);
        $t = exchange_code($_GET['code']);
        header('Content-Type: text/plain');
        echo "Token guardado (larga duración: " . ($t['long_lived'] ? 'sí' : 'no') . "). Páginas: " . count($t['pages']) . ". Cierra esto y ejecuta --check.\n";
    } else { http_response_code(404); echo "esperando /meta/callback?code=...\n"; }
    return;
}
$o = getopt('', ['auth', 'code:', 'publish:', 'insights', 'check']);
if (!$o) { echo "Uso: php spike/meta_probe.php --auth | --code=XXX | --publish=fb_text|fb_photo|ig_image|ig_reel|ig_story | --insights | --check\n"; exit(2); }
need(['META_APP_ID', 'META_APP_SECRET', 'META_REDIRECT_URI']);
if (isset($o['auth'])) { echo auth_url() . "\n"; exit(0); }
if (isset($o['code'])) { $t = exchange_code($o['code']); out(['saved' => true, 'long_lived' => $t['long_lived'], 'pages' => array_map(fn($p) => ['id' => $p['id'], 'name' => $p['name'], 'ig' => $p['instagram_business_account']['id'] ?? null], $t['pages'])]); exit(0); }
if (isset($o['publish'])) { publish($o['publish']); exit(0); }
if (isset($o['insights'])) { out(insights()); exit(0); }
if (isset($o['check'])) { check(); exit(0); }
