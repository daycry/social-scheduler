<?php
/**
 * Spike YouTube (T-04) — script DESECHABLE, PHP plano + curl. Ver spike/README.md.
 * Uso: php spike/youtube_probe.php --auth | --code=XXX | --publish=video|short | --inspect=<videoId> | --check
 * Servidor de callback: php -S 127.0.0.1:8080 spike/youtube_probe.php  (atiende /google/callback)
 */
const P = 'youtube';
// youtube.upload no permite borrar: `youtube` cubre subir, leer, editar y borrar
const SCOPES = 'https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';
const QUOTA_INSERT = 1600; // unidades documentadas de videos.insert (verificar en la consola de cuotas)

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
function http(string $method, string $url, array $params = [], array $headers = [], $body = null, bool $withHeaders = false): array {
    $ch = curl_init();
    if ($method === 'GET' && $params) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
    curl_setopt_array($ch, [CURLOPT_URL => $url, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 600, CURLOPT_HEADER => $withHeaders]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? http_build_query($params));
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return [0, ['curl_error' => $err], ''];
    $hdr = $withHeaders ? substr($raw, 0, $hs) : '';
    $b = $withHeaders ? substr($raw, $hs) : $raw;
    return [$code, json_decode($b, true) ?? [], $hdr ?: $b];
}
function store(string $suffix, array $data): void { file_put_contents(__DIR__ . "/.$suffix-" . P . '.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); }
function fetch_store(string $suffix): array { $f = __DIR__ . "/.$suffix-" . P . '.json'; return is_file($f) ? (json_decode(file_get_contents($f), true) ?: []) : []; }
function record(string $key, $val): void { $r = fetch_store('results'); $r[$key] = ['at' => gmdate('c'), 'data' => $val]; store('results', $r); }
function out($v): void { echo json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n"; }

// ---------- YouTube ----------
function auth_url(): string {
    // access_type=offline + prompt=consent para obtener refresh_token
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => env('GOOGLE_CLIENT_ID'), 'redirect_uri' => env('GOOGLE_REDIRECT_URI'), 'response_type' => 'code',
        'scope' => SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'state' => 'spike']);
}

function exchange_code(string $code): array {
    [$c, $j] = http('POST', 'https://oauth2.googleapis.com/token', ['code' => $code, 'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'redirect_uri' => env('GOOGLE_REDIRECT_URI'), 'grant_type' => 'authorization_code']);
    if (!isset($j['access_token'])) { fwrite(STDERR, "ERROR canjeando code ($c): " . json_encode($j) . "\n"); exit(1); }
    $j['saved_at'] = gmdate('c');
    $j['refresh_token_issued_at'] = gmdate('c'); // para medir la caducidad de 7 días en Testing
    store('tokens', $j);
    return $j;
}

function token(): string {
    $t = fetch_store('tokens');
    if (!$t) { fwrite(STDERR, "ERROR: no hay token. Ejecuta --auth, autoriza y usa --code=XXX (o el servidor de callback).\n"); exit(2); }
    if (strtotime($t['saved_at']) + ($t['expires_in'] ?? 0) - 120 < time()) {
        if (empty($t['refresh_token'])) { fwrite(STDERR, "ERROR: sin refresh_token; repite --auth.\n"); exit(2); }
        [$c, $j] = http('POST', 'https://oauth2.googleapis.com/token', ['client_id' => env('GOOGLE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_CLIENT_SECRET'), 'refresh_token' => $t['refresh_token'], 'grant_type' => 'refresh_token']);
        if (!isset($j['access_token'])) {
            record('refresh_failure', ['http' => $c, 'response' => $j, 'refresh_token_issued_at' => $t['refresh_token_issued_at'] ?? null]);
            fwrite(STDERR, "ERROR refrescando ($c): " . json_encode($j) . " (invalid_grant tras 7 días = caducidad de Testing; anótalo en el informe)\n"); exit(1);
        }
        $t = array_merge($t, $j, ['saved_at' => gmdate('c')]);
        store('tokens', $t);
    }
    return $t['access_token'];
}

function inspect(string $vid): array {
    [$c, $j] = http('GET', 'https://www.googleapis.com/youtube/v3/videos', ['part' => 'status,snippet,contentDetails', 'id' => $vid],
        ['Authorization: Bearer ' . token()]);
    $it = $j['items'][0] ?? null;
    $r = ['http' => $c, 'found' => (bool) $it, 'privacyStatus' => $it['status']['privacyStatus'] ?? null,
        'uploadStatus' => $it['status']['uploadStatus'] ?? null, 'rejectionReason' => $it['status']['rejectionReason'] ?? null,
        'selfDeclaredMadeForKids' => $it['status']['selfDeclaredMadeForKids'] ?? null, 'duration' => $it['contentDetails']['duration'] ?? null,
        'error' => $j['error'] ?? null];
    record("inspect.$vid", $r);
    return $r;
}

function publish(string $kind): void {
    need(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET']);
    $var = $kind === 'short' ? 'YOUTUBE_TEST_SHORT_FILE' : 'YOUTUBE_TEST_VIDEO_FILE';
    if (!in_array($kind, ['video', 'short'], true)) { fwrite(STDERR, "ERROR: tipo desconocido '$kind' (video|short)\n"); exit(2); }
    need([$var]);
    $f = env($var);
    if (!is_file($f)) { fwrite(STDERR, "ERROR: no existe $var=$f\n"); exit(2); }
    $size = filesize($f);
    $meta = ['snippet' => ['title' => 'Spike ' . $kind . ' ' . gmdate('c'), 'description' => 'prueba del spike' . ($kind === 'short' ? ' #Shorts' : ''), 'categoryId' => '22'],
        'status' => ['privacyStatus' => (env('SPIKE_PRIVACY') === 'public' ? 'public' : 'private'), 'selfDeclaredMadeForKids' => false]];
    // 1) iniciar sesión de subida reanudable
    [$c, $j, $hdr] = http('POST', 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status', [],
        ['Authorization: Bearer ' . token(), 'Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Length: ' . $size, 'X-Upload-Content-Type: video/*'],
        json_encode($meta), true);
    if ($c !== 200 || !preg_match('/^location:\s*(\S+)/mi', $hdr, $m)) {
        record("publish.$kind", ['ok' => false, 'phase' => 'init', 'http' => $c, 'response' => $j]);
        fwrite(STDERR, "ERROR iniciando subida ($c): " . json_encode($j) . "\n"); exit(1);
    }
    $session = $m[1];
    // 2) subir en un solo PUT (suficiente para ficheros de prueba; el worker real troceará)
    [$c2, $j2] = http('PUT', $session, [], ['Content-Type: video/*', 'Content-Length: ' . $size], file_get_contents($f));
    $ok = $c2 >= 200 && $c2 < 300 && isset($j2['id']);
    record("publish.$kind", ['ok' => $ok, 'http' => $c2, 'video_id' => $j2['id'] ?? null, 'quota_units_estimated' => $ok ? QUOTA_INSERT : 0, 'response' => $j2]);
    if (!$ok) { fwrite(STDERR, "ERROR subiendo ($c2): " . json_encode($j2) . "\n"); exit(1); }
    out(['video_id' => $j2['id'], 'inspect' => inspect($j2['id']), 'quota_units_estimated' => QUOTA_INSERT]);
}

function check(): void {
    $t = fetch_store('tokens');
    token(); // valida/refresca; falla limpio si no hay token
    [$c, $j] = http('GET', 'https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true'], ['Authorization: Bearer ' . token()]);
    if ($c !== 200) { fwrite(STDERR, "ERROR consultando canal ($c): " . json_encode($j) . "\n"); exit(1); }
    $r = fetch_store('results');
    echo "== YouTube --check ==\n";
    echo "Canal: " . ($j['items'][0]['snippet']['title'] ?? '(sin canal)') . " | scope: " . ($t['scope'] ?? '?') . "\n";
    $iss = $t['refresh_token_issued_at'] ?? null;
    echo "refresh_token emitido: " . ($iss ?? '?') . " | edad: " . ($iss ? round((time() - strtotime($iss)) / 86400, 1) . ' días' : '?') . " (en Testing caduca a los 7 días: reejecuta --check pasada la semana)\n";
    if (isset($r['refresh_failure'])) echo "FALLO DE REFRESH registrado: " . json_encode($r['refresh_failure']['data']['response']) . "\n";
    echo "\n";
    printf("%-8s %-5s %-14s %-9s %-14s %s\n", 'TIPO', 'OK', 'VIDEO', 'VISIB.', 'CUOTA(ud)', 'detalle');
    $total = 0;
    foreach (['video', 'short'] as $k) {
        $x = $r["publish.$k"]['data'] ?? null;
        if (!$x) { printf("%-8s %-5s\n", $k, '-'); continue; }
        $vis = ($x['ok'] ?? false) ? (inspect($x['video_id'])['privacyStatus'] ?? '?') : '-';
        $total += $x['quota_units_estimated'] ?? 0;
        printf("%-8s %-5s %-14s %-9s %-14s %s\n", $k, ($x['ok'] ?? false) ? 'sí' : 'NO', $x['video_id'] ?? '-', $vis, $x['quota_units_estimated'] ?? 0,
            ($x['ok'] ?? false) ? '' : substr(json_encode($x['response']), 0, 90));
    }
    echo "\nCuota estimada consumida: $total ud (cupo por defecto 10000/día => ~" . floor(10000 / QUOTA_INSERT) . " subidas/día). CONTRASTA con la consola (APIs & Services > YouTube Data API v3 > Quotas).\n";
    echo "Si privacyStatus=private aunque pediste public: bloqueo de proyecto sin auditar. Pega esta salida en spike-report.md §YouTube.\n";
}

// ---------- main ----------
load_env();
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    header('Content-Type: text/plain');
    if ($path === '/google/callback' && isset($_GET['code'])) {
        need(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI']);
        $t = exchange_code($_GET['code']);
        echo "Token guardado. refresh_token: " . (empty($t['refresh_token']) ? 'NO (repite con prompt=consent)' : 'sí') . ". Ejecuta --check.\n";
    } else { http_response_code(404); echo "esperando /google/callback?code=...\n"; }
    return;
}
$o = getopt('', ['auth', 'code:', 'publish:', 'inspect:', 'check', 'delete:']);
if (!$o) { echo "Uso: php spike/youtube_probe.php --auth | --code=XXX | --publish=video|short | --inspect=<videoId> | --check\n"; exit(2); }
need(['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI']);
if (isset($o['auth'])) { echo auth_url() . "\n"; exit(0); }
if (isset($o['code'])) { $t = exchange_code($o['code']); out(['saved' => true, 'refresh_token' => !empty($t['refresh_token']), 'scope' => $t['scope'] ?? null, 'expires_in' => $t['expires_in'] ?? null]); exit(0); }
if (isset($o['publish'])) { publish($o['publish']); exit(0); }
if (isset($o['inspect'])) { out(inspect($o['inspect'])); exit(0); }
if (isset($o['check'])) { check(); exit(0); }
if (isset($o['delete'])) { [$c, $j] = http('DELETE', 'https://www.googleapis.com/youtube/v3/videos?id=' . urlencode($o['delete']), [], ['Authorization: Bearer ' . token()]); out(['http' => $c, 'ok' => $c === 204, 'response' => $j]); exit($c === 204 ? 0 : 1); }
