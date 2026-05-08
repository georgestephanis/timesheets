<?php
// index.php — router script for PHP's built-in web server.
// Usage: php -S localhost:8000 index.php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';

// Reject any path that contains a traversal sequence.
if (str_contains($path, '..')) {
    http_response_code(400);
    exit;
}

$file = __DIR__ . $path;

if ($path === '/api.php') {
    require __DIR__ . '/api.php';
} elseif ($path !== '/' && file_exists($file) && !is_dir($file)) {
    // Serve static assets directly so the correct www/ directory is always used,
    // regardless of which directory the `php -S` process was started from.
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    header('Content-Type: ' . match ($ext) {
        'css'        => 'text/css; charset=utf-8',
        'js'         => 'application/javascript; charset=utf-8',
        'png'        => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'svg'        => 'image/svg+xml',
        'ico'        => 'image/x-icon',
        'woff2'      => 'font/woff2',
        default      => 'application/octet-stream',
    });
    readfile($file);
    exit;
} else {
    require __DIR__ . '/report_renderer.php';
}
