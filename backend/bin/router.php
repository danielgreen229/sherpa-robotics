<?php

declare(strict_types=1);

// Optional local development server. Docker uses Nginx + PHP-FPM.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
if (preg_match('~^/media/[a-f0-9]{64}\.(jpg|png|gif|webp)$~D', $path)) {
    require dirname(__DIR__) . '/vendor/autoload.php';
    $file = App\Support\Config::storage() . $path;
    if (!is_file($file)) {
        http_response_code(404);
        return;
    }
    header('Content-Type: ' . (new finfo(FILEINFO_MIME_TYPE))->file($file));
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    return;
}
if (str_starts_with($path, '/api/') || $path === '/openapi.json') {
    require dirname(__DIR__) . '/public/index.php';
    return;
}
$root = realpath(dirname(__DIR__, 2) . '/frontend/dist/catalog/browser');
$file = $root ? realpath($root . $path) : false;
if ($root && $file && str_starts_with($file, $root . '/') && is_file($file)) {
    $type = ['js' => 'application/javascript', 'css' => 'text/css', 'html' => 'text/html', 'woff2' => 'font/woff2', 'svg' => 'image/svg+xml', 'png' => 'image/png'][pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
    header('Content-Type: ' . $type);
    readfile($file);
} elseif ($root) {
    header('Content-Type: text/html; charset=utf-8');
    readfile($root . '/index.html');
} else {
    http_response_code(404);
    echo 'Build frontend first: npm run build';
}
