<?php
// report_renderer.php
// Serve reports via PHP's built-in web server

$format = $_GET['format'] ?? 'html';

if ($format === 'txt' || $format === 'text' || $format === 'md') {
    header('Content-Type: text/plain; charset=UTF-8');
} elseif ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
} elseif ($format === 'tsv') {
    header('Content-Type: text/tab-separated-values; charset=UTF-8');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}
header('Cache-Control: no-cache');

define('PROJECT_ROOT', __DIR__);

$configFile = PROJECT_ROOT . '/config.json';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo "error: config.json not found.";
    exit(1);
}
$config = json_decode(file_get_contents($configFile), true);
if (!is_array($config)) {
    http_response_code(500);
    echo "error: config.json is not valid JSON.";
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

// Build opts from query params, mirroring parseArgs() defaults.
$daysParam = isset($_GET['days']) ? (int)$_GET['days'] : null;
$opts = [
    'days'            => $daysParam ?? (isset($_GET['date_range']) ? parseDateRangeParam($_GET['date_range']) : 1),
    'from'            => $_GET['from'] ?? null,
    'to'              => $_GET['to']   ?? null,
    'project'         => $_GET['project'] ?? null,
    'format'          => $format === 'html' ? 'md' : $format,
    'show_unmatched'  => !empty($_GET['show_unmatched']),
    'list_projects'   => false,
    'help'            => false,
];

function parseDateRangeParam(string $param): int
{
    if (preg_match('/^(\d+)days?$/', $param, $m)) {
        return (int)$m[1];
    }
    return 1;
}

$tz = new DateTimeZone($config['timezone']);
[$from, $to] = resolveDateRange($opts, $tz);

$dir    = reportsDir($from);
$key    = reportsCacheKey($from, $to);
$cached = rangeIsHistorical($to, $tz) ? loadCachedSources($dir, $key) : null;

if ($cached) {
    ['events' => $events, 'chrome' => $chrome, 'commits' => $commits] = $cached;
} else {
    $events  = loadActivityWatch($config, $from, $to);
    $chrome  = loadChromeHistory($config, $from, $to);
    $commits = loadGitCommits($config, $from, $to);
    backfillChromeUrls($events, $chrome, (int)$config['chrome_correlation_window_seconds']);
}

[$bucket, $unmatched] = classifyAndAggregate($events, $commits, $config, $tz, $opts);

$out = match ($opts['format']) {
    'json' => renderJson($bucket, $unmatched, $from, $to, $tz),
    'tsv'  => renderTsv($bucket, $from, $to, $tz),
    default => renderMarkdown($bucket, $unmatched, $from, $to, $tz, $opts, $config),
};

if ($format === 'html') {
    require_once PROJECT_ROOT . '/vendor/autoload.php';
    $pd   = new ParsedownExtra();
    $body = $pd->text($out);

    // Compute prev/next links based on the actual resolved range.
    $rangeDays  = max(1, (int)$from->diff($to)->days + 1);
    $interval   = new DateInterval("P{$rangeDays}D");
    $prevFrom   = $from->sub($interval)->format('Y-m-d');
    $prevTo     = $from->sub(new DateInterval('P1D'))->format('Y-m-d');
    $nextFrom   = $to->add(new DateInterval('P1D'))->format('Y-m-d');
    $nextTo     = $to->add($interval)->format('Y-m-d');
    $today      = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $yesterday  = (new DateTimeImmutable('yesterday', $tz))->format('Y-m-d');
    $isFuture   = $nextFrom > $today;

    $currentProject = $opts['project'] ?? '';
    $projects = array_keys($config['projects'] ?? []);

    // Build a query string helper that merges overrides with current params.
    $baseParams = array_filter([
        'project' => $currentProject,
        'format'  => 'html',
    ]);
    $navUrl = function (array $extra) use ($baseParams): string {
        $p = array_filter(array_merge($baseParams, $extra), fn($v) => $v !== '' && $v !== null);
        return '?' . http_build_query($p);
    };

    // Build project options HTML.
    $projectOptions = '<option value="">All projects</option>';
    foreach ($projects as $p) {
        $sel = ($p === $currentProject) ? ' selected' : '';
        $escaped_p = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
        $projectOptions .= "<option value=\"$escaped_p\"$sel>$escaped_p</option>";
    }

    $prevUrl     = $navUrl(['from' => $prevFrom, 'to' => $prevTo]);
    $nextUrl     = $isFuture ? '' : $navUrl(['from' => $nextFrom, 'to' => $nextTo]);
    $todayUrl    = $navUrl(['from' => $today,     'to' => $today]);
    $yestUrl     = $navUrl(['from' => $yesterday, 'to' => $yesterday]);
    $week7Url    = $navUrl(['days' => 7,  'from' => null, 'to' => null]);
    $week30Url   = $navUrl(['days' => 30, 'from' => null, 'to' => null]);
    $nextBtn     = $isFuture
        ? '<span class="btn disabled">Next &rsaquo;</span>'
        : "<a class=\"btn\" href=\"$nextUrl\">Next &rsaquo;</a>";

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Activity Report</title>
    <style>
      body { font-family: system-ui, sans-serif; max-width: 900px; margin: 0 auto; padding: 0 1rem 2rem; line-height: 1.6; }
      h1 { margin-top: 0.5em; }
      h2, h3, h4 { margin-top: 1.5em; }
      h2 { border-bottom: 1px solid #ddd; padding-bottom: 0.3em; }
      code { background: #f0f0f0; padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.9em; }
      ul { padding-left: 1.5em; }
      em { color: #555; }
      nav { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #ddd;
            padding: 0.5rem 0; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; z-index: 10; }
      .btn { display: inline-block; padding: 0.25rem 0.6rem; border: 1px solid #bbb;
             border-radius: 4px; text-decoration: none; color: inherit; font-size: 0.85rem;
             background: #f8f8f8; white-space: nowrap; }
      .btn:hover { background: #e8e8e8; }
      .btn.disabled { color: #aaa; border-color: #ddd; pointer-events: none; }
      .sep { color: #ccc; }
      select { padding: 0.25rem 0.4rem; border: 1px solid #bbb; border-radius: 4px;
               font-size: 0.85rem; background: #f8f8f8; }
    </style>
    </head>
    <body>
    <nav>
      <a class="btn" href="$prevUrl">&lsaquo; Prev</a>
      $nextBtn
      <span class="sep">|</span>
      <a class="btn" href="$todayUrl">Today</a>
      <a class="btn" href="$yestUrl">Yesterday</a>
      <a class="btn" href="$week7Url">7 days</a>
      <a class="btn" href="$week30Url">30 days</a>
      <span class="sep">|</span>
      <form method="get" style="display:contents">
        <input type="hidden" name="from" value="{$from->format('Y-m-d')}">
        <input type="hidden" name="to" value="{$to->format('Y-m-d')}">
        <input type="hidden" name="format" value="html">
        <select name="project" onchange="this.form.submit()">$projectOptions</select>
      </form>
      <span class="sep">|</span>
      <a class="btn" href="{$navUrl(['format'=>'md'])}">md</a>
      <a class="btn" href="{$navUrl(['format'=>'json'])}">json</a>
      <a class="btn" href="{$navUrl(['format'=>'tsv'])}">tsv</a>
    </nav>
    $body
    </body>
    </html>
    HTML;
} else {
    echo $out;
}
