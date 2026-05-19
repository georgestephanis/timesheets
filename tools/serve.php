#!/usr/bin/env php
<?php
declare(strict_types=1);

$port = 8000;
while ($port <= 8999) {
    $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if ($sock === false) {
        break; // port not in use — available
    }
    fclose($sock);
    $port++;
}

if ($port > 8999) {
    fwrite(STDERR, "error: no available port found in range 8000–8999.\n");
    exit(1);
}

$root   = dirname(__DIR__);
$entry  = $root . '/apps/web/index.php';
$url    = "http://localhost:{$port}";

echo "Web UI: {$url}\n";

// Open in the default browser (non-blocking).
if (PHP_OS_FAMILY === 'Darwin') {
    exec('open ' . escapeshellarg($url));
} elseif (PHP_OS_FAMILY === 'Windows') {
    exec('start "" ' . escapeshellarg($url));
} else {
    exec('xdg-open ' . escapeshellarg($url) . ' &');
}

passthru(PHP_BINARY . ' -S localhost:' . $port . ' ' . escapeshellarg($entry), $exitCode);
exit($exitCode);
