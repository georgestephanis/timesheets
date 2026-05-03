<?php
// api.php — JSON data endpoint consumed by the JS frontend in report_renderer.php.
//
// GET params: from, to, days, project, rebuild
// Returns the latest saved JSON report for the range when available; regenerates
// (and saves) when none exists or ?rebuild=1 is requested.
// Adds X-Report-Source: cached|generated so the JS can show the right badge.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

set_exception_handler(function (Throwable $e): void {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
});
set_error_handler(function (int $errno, string $errstr): never {
    throw new \ErrorException($errstr, $errno);
});

define('PROJECT_ROOT', dirname(__DIR__));

$configFile = PROJECT_ROOT . '/config.json';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.json not found']);
    exit(1);
}
$config = json_decode(file_get_contents($configFile), true);
if (!is_array($config)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.json is not valid JSON']);
    exit(1);
}

require_once PROJECT_ROOT . '/src/helpers.php';
require_once PROJECT_ROOT . '/src/cache.php';
require_once PROJECT_ROOT . '/src/loader-activitywatch.php';
require_once PROJECT_ROOT . '/src/loader-chrome.php';
require_once PROJECT_ROOT . '/src/loader-git.php';
require_once PROJECT_ROOT . '/src/classifiers.php';
require_once PROJECT_ROOT . '/src/renderers.php';
require_once PROJECT_ROOT . '/src/cli.php';

$rebuild = !empty($_GET['rebuild']);
$opts    = [
    'days'           => isset($_GET['days']) ? (int)$_GET['days'] : 1,
    'from'           => $_GET['from'] ?? null,
    'to'             => $_GET['to']   ?? null,
    'project'        => $_GET['project'] ?? null,
    'format'         => 'json',
    'show_unmatched' => !empty($_GET['show_unmatched']),
    'list_projects'  => false,
    'help'           => false,
];

$tz   = new DateTimeZone($config['timezone']);
[$from, $to] = resolveDateRange($opts, $tz);

$dir  = reportsDir($from);
$key  = reportsCacheKey($from, $to);
$slug = $opts['project'] !== null ? '--' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $opts['project']) : '';

// For historical ranges, serve the latest saved JSON if not rebuilding.
if (!$rebuild && rangeIsHistorical($to, $tz)) {
    $latest = findLatestReport($dir, $key, $slug, 'json');
    if ($latest) {
        header('X-Report-Source: cached');
        readfile($latest);
        exit;
    }
}

// Generate fresh data.
$cached    = rangeIsHistorical($to, $tz) ? loadCachedSources($dir, $key) : null;
$fromCache = $cached !== null;

if ($fromCache) {
    ['events' => $events, 'chrome' => $chrome, 'commits' => $commits] = $cached;
} else {
    $events  = loadActivityWatch($config, $from, $to);
    $chrome  = loadChromeHistory($config, $from, $to);
    $commits = loadGitCommits($config, $from, $to);
    backfillChromeUrls($events, $chrome, (int)$config['chrome_correlation_window_seconds']);
}

[$bucket, $unmatched] = classifyAndAggregate($events, $commits, $config, $tz, $opts);
$out = renderJson($bucket, $unmatched, $from, $to, $tz);

if (!$fromCache) {
    saveCachedSources($dir, $key, $from, $to, $events, $chrome, $commits);
}
saveGeneratedReport($dir, $key, $from, $to, 'json', $opts['project'], $fromCache, $out);

header('X-Report-Source: generated');
echo $out;
