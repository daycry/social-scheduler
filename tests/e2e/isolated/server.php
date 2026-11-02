<?php

declare(strict_types=1);

use Illuminate\Http\Request;

require __DIR__.'/bootstrap.php';
if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('General E2E is loopback-only.');
}
generalRoot();
$public = realpath(dirname(__DIR__, 3).'/public');
$asset = realpath($public.'/'.ltrim(rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/'));
if ($asset !== false && is_file($asset) && str_starts_with($asset, $public.DIRECTORY_SEPARATOR) && pathinfo($asset, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
$app = generalBootstrap();
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/__general-isolation-proof') {
    header('Content-Type: application/json');
    echo json_encode(generalResourcePaths(), JSON_THROW_ON_ERROR);

    return;
}
$app->handleRequest(Request::capture());
