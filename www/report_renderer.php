<?php
// report_renderer.php
// Serve reports via PHP's built-in web server.
// HTML format: static shell + JS renderer (data fetched async from api.php).
// Other formats: full PHP pipeline rendered server-side.


$format = $_GET['format'] ?? 'html';

define('PROJECT_ROOT', dirname(__DIR__));

$configFile = PROJECT_ROOT . '/config.json';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo $format === 'html' ? '<p>error: config.json not found.</p>' : 'error: config.json not found.';
    exit(1);
}
$config = json_decode(file_get_contents($configFile), true);
if (!is_array($config)) {
    http_response_code(500);
    echo $format === 'html' ? '<p>error: config.json is not valid JSON.</p>' : 'error: config.json is not valid JSON.';
    exit(1);
}

// ── HTML: serve JS shell, all rendering is client-side ────────────────────────
if ($format === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache');

    $tz        = new DateTimeZone($config['timezone']);
    $jsConfig  = json_encode([
        'timezone'          => $config['timezone'],
        'minSec'            => (int)($config['min_event_seconds_to_show'] ?? 0),
        'projects'          => array_map(
            fn($name, $p) => ['name' => $name, 'grouping' => $p['grouping'] ?? null],
            array_keys($config['projects'] ?? []),
            array_values($config['projects'] ?? [])
        ),
        'today'             => (new DateTimeImmutable('now', $tz))->format('Y-m-d'),
        'yesterday'         => (new DateTimeImmutable('yesterday', $tz))->format('Y-m-d'),
        'harvestConfigured' => !empty($config['integrations']['harvest']),
        'groupings'         => (object)($config['groupings'] ?? []),
    ], JSON_UNESCAPED_UNICODE);

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activity Report</title>
<link rel="stylesheet" href="static/app.css">
</head>
<body>
<nav id="nav"></nav>
<div id="warnings-banner"></div>
<div id="diff-banner"></div>
<div id="admin"></div>
<div id="content-wrap">
<main id="report"><p class="loading">Loading&hellip;</p></main>
<aside id="harvest-sidebar"></aside>
</div>
<script>const SITE = <?= $jsConfig ?>;</script>
<script src="static/app.js"></script>
</body>
</html>
    <?php
    exit;
}

// ── Non-HTML formats: full PHP pipeline ───────────────────────────────────────
if ($format === 'txt' || $format === 'text' || $format === 'md') {
    header('Content-Type: text/plain; charset=UTF-8');
} elseif ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
} elseif ($format === 'tsv') {
    header('Content-Type: text/tab-separated-values; charset=UTF-8');
} else {
    http_response_code(400);
    echo "Unsupported format: $format";
    exit(1);
}
header('Cache-Control: no-cache');

require_once PROJECT_ROOT . '/src/helpers.php';
require_once PROJECT_ROOT . '/src/cache.php';
require_once PROJECT_ROOT . '/src/loader-activitywatch.php';
require_once PROJECT_ROOT . '/src/loader-chrome.php';
require_once PROJECT_ROOT . '/src/loader-git.php';
require_once PROJECT_ROOT . '/src/loader-integrations.php';
require_once PROJECT_ROOT . '/src/classifiers.php';
require_once PROJECT_ROOT . '/src/renderers.php';
require_once PROJECT_ROOT . '/src/cli.php';

$rebuild   = !empty($_GET['rebuild']);
$daysParam = isset($_GET['days']) ? (int)$_GET['days'] : null;
$opts      = [
    'days'           => $daysParam ?? 1,
    'from'           => $_GET['from'] ?? null,
    'to'             => $_GET['to']   ?? null,
    'project'        => $_GET['project'] ?? null,
    'format'         => $format === 'md' ? 'md' : $format,
    'show_unmatched' => !empty($_GET['show_unmatched']),
    'list_projects'  => false,
    'help'           => false,
];

$tz = new DateTimeZone($config['timezone']);
[$from, $to] = resolveDateRange($opts, $tz);

$dir  = reportsDir($from);
$key  = reportsCacheKey($from, $to);

$cached    = (!$rebuild && rangeIsHistorical($to, $tz)) ? loadCachedSources($dir, $key) : null;
$fromCache = $cached !== null;

if ($fromCache) {
    ['events' => $events, 'chrome' => $chrome, 'commits' => $commits, 'external' => $external] = $cached;
} else {
    $events  = loadActivityWatch($config, $from, $to);
    $chrome  = loadChromeHistory($config, $from, $to);
    $commits = loadGitCommits($config, $from, $to);
    $external = loadIntegrationActivity($config, $from, $to);
    backfillChromeUrls($events, $chrome, (int)$config['chrome_correlation_window_seconds']);
}

$fullOpts = $opts;
$fullOpts['project'] = null;
[$fullBucket, $fullUnmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $fullOpts);

$hasProjectFilter = !empty($opts['project']);
if ($hasProjectFilter) {
    [$bucket, $unmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $opts);
} else {
    [$bucket, $unmatched] = [$fullBucket, $fullUnmatched];
}

$out = match ($opts['format']) {
    'json' => renderJson($bucket, $unmatched, $from, $to, $tz),
    'tsv'  => renderTsv($bucket, $from, $to, $tz),
    default => renderMarkdown($bucket, $unmatched, $from, $to, $tz, $opts, $config),
};

$fullOut = match ($opts['format']) {
    'json' => renderJson($fullBucket, $fullUnmatched, $from, $to, $tz),
    'tsv'  => renderTsv($fullBucket, $from, $to, $tz),
    default => renderMarkdown($fullBucket, $fullUnmatched, $from, $to, $tz, $fullOpts, $config),
};

if (!$fromCache) {
    saveCachedSources($dir, $key, $from, $to, $events, $chrome, $commits, $external);
}
saveGeneratedReport($dir, $key, $from, $to, $opts['format'], null, $fromCache, $fullOut);
if ($opts['format'] === 'md') {
    $jsonOut = renderJson($fullBucket, $fullUnmatched, $from, $to, $tz);
    saveGeneratedReport($dir, $key, $from, $to, 'json', null, $fromCache, $jsonOut);
}

echo $out;
