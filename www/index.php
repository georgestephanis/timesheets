<?php
// index.php — router script for PHP's built-in web server.
// Usage: php -S localhost:8000 index.php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/api.php') {
    require __DIR__ . '/api.php';
} elseif (file_exists(__DIR__ . $path) && !is_dir(__DIR__ . $path) && $path !== '/') {
    return false; // let the built-in server serve static files directly
} else {
    require __DIR__ . '/report_renderer.php';
}
