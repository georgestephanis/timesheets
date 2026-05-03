<?php
// report_renderer.php
// Serve reports via PHP's built-in web server

$format  = $_GET['format'] ?? 'html';
$rebuild = !empty($_GET['rebuild']);

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
    'days'           => $daysParam ?? (isset($_GET['date_range']) ? parseDateRangeParam($_GET['date_range']) : 1),
    'from'           => $_GET['from'] ?? null,
    'to'             => $_GET['to']   ?? null,
    'project'        => $_GET['project'] ?? null,
    'format'         => $format === 'html' ? 'md' : $format,
    'show_unmatched' => !empty($_GET['show_unmatched']),
    'list_projects'  => false,
    'help'           => false,
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

$dir  = reportsDir($from);
$key  = reportsCacheKey($from, $to);
$slug = $opts['project'] !== null ? '--' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $opts['project']) : '';

// Snapshot the most recent prior JSON report before we generate a new one.
// Timestamped filenames sort lexicographically, so the last glob result is newest.
function findLatestReport(string $dir, string $key, string $slug, string $ext): ?string
{
    $files = glob("$dir/report-$key$slug--*.$ext") ?: [];
    sort($files);
    return $files ? end($files) : null;
}

$latestJson  = findLatestReport($dir, $key, $slug, 'json');
$oldJsonData = ($rebuild && $latestJson !== null)
    ? json_decode(file_get_contents($latestJson), true)
    : null;

// Bypass the source cache when the user explicitly requests a rebuild.
$cached    = (!$rebuild && rangeIsHistorical($to, $tz)) ? loadCachedSources($dir, $key) : null;
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

$out = match ($opts['format']) {
    'json' => renderJson($bucket, $unmatched, $from, $to, $tz),
    'tsv'  => renderTsv($bucket, $from, $to, $tz),
    default => renderMarkdown($bucket, $unmatched, $from, $to, $tz, $opts, $config),
};

// Persist to reports/ (mirrors CLI behaviour).
if (!$fromCache) {
    saveCachedSources($dir, $key, $from, $to, $events, $chrome, $commits);
}
saveGeneratedReport($dir, $key, $from, $to, $opts['format'], $opts['project'], $fromCache, $out);
if ($opts['format'] === 'md') {
    $jsonOut = renderJson($bucket, $unmatched, $from, $to, $tz);
    saveGeneratedReport($dir, $key, $from, $to, 'json', $opts['project'], $fromCache, $jsonOut);
}

/**
 * Compares an old JSON report's days structure against the freshly-aggregated bucket.
 * Returns a list of new-project and updated-project change records.
 *
 * Old commits come from renderJson() as {sha, ...}; new commits are raw bucket arrays
 * with a 'sha' key, so SHA-based comparison works across both shapes.
 */
function computeBucketDiff(array $oldDays, array $newBucket): array
{
    $changes = [];
    foreach ($newBucket as $date => $projects) {
        foreach ($projects as $name => $rec) {
            $oldRec       = $oldDays[$date][$name] ?? null;
            $oldShas      = array_column($oldRec['commits'] ?? [], 'sha');
            $newCommits   = $rec['commits'] ?? [];
            $addedCommits = array_filter($newCommits, fn($c) => !in_array($c['sha'], $oldShas));
            $secDiff      = ($rec['seconds'] ?? 0) - ($oldRec['seconds'] ?? 0);

            if ($oldRec === null && (($rec['seconds'] ?? 0) > 0 || $newCommits)) {
                $changes[] = [
                    'type'    => 'new_project',
                    'date'    => $date,
                    'project' => $name,
                    'seconds' => $rec['seconds'] ?? 0,
                    'commits' => count($newCommits),
                ];
            } elseif ($oldRec !== null && ($secDiff > 0 || count($addedCommits) > 0)) {
                $changes[] = [
                    'type'          => 'updated',
                    'date'          => $date,
                    'project'       => $name,
                    'added_seconds' => $secDiff,
                    'added_commits' => count($addedCommits),
                ];
            }
        }
    }
    return $changes;
}

$diff = ($rebuild && $oldJsonData !== null)
    ? computeBucketDiff($oldJsonData['days'] ?? [], $bucket)
    : null;

if ($format === 'html') {
    require_once PROJECT_ROOT . '/vendor/autoload.php';
    $pd   = new ParsedownExtra();
    $body = $pd->text($out);

    // Compute prev/next links based on the actual resolved range.
    $rangeDays = max(1, (int)$from->diff($to)->days + 1);
    $interval  = new DateInterval("P{$rangeDays}D");
    $prevFrom  = $from->sub($interval)->format('Y-m-d');
    $prevTo    = $from->sub(new DateInterval('P1D'))->format('Y-m-d');
    $nextFrom  = $to->add(new DateInterval('P1D'))->format('Y-m-d');
    $nextTo    = $to->add($interval)->format('Y-m-d');
    $today     = (new DateTimeImmutable('now', $tz))->format('Y-m-d');
    $yesterday = (new DateTimeImmutable('yesterday', $tz))->format('Y-m-d');
    $isFuture  = $nextFrom > $today;

    $currentProject = $opts['project'] ?? '';
    $projects       = array_keys($config['projects'] ?? []);

    // Query-string helper: merges overrides into base params, strips nulls/''.
    $baseParams = array_filter(['project' => $currentProject, 'format' => 'html']);
    $navUrl     = function (array $extra) use ($baseParams): string {
        $p = array_filter(array_merge($baseParams, $extra), fn($v) => $v !== '' && $v !== null && $v !== false);
        return '?' . http_build_query($p);
    };

    $projectOptions = '<option value="">All projects</option>';
    foreach ($projects as $p) {
        $sel       = ($p === $currentProject) ? ' selected' : '';
        $escaped_p = htmlspecialchars($p, ENT_QUOTES, 'UTF-8');
        $projectOptions .= "<option value=\"$escaped_p\"$sel>$escaped_p</option>";
    }

    $prevUrl   = $navUrl(['from' => $prevFrom, 'to' => $prevTo]);
    $nextUrl   = $isFuture ? '' : $navUrl(['from' => $nextFrom, 'to' => $nextTo]);
    $todayUrl  = $navUrl(['from' => $today,     'to' => $today]);
    $yestUrl   = $navUrl(['from' => $yesterday, 'to' => $yesterday]);
    $week7Url  = $navUrl(['days' => 7,  'from' => null, 'to' => null]);
    $week30Url = $navUrl(['days' => 30, 'from' => null, 'to' => null]);
    $nextBtn   = $isFuture
        ? '<span class="btn disabled">Next &rsaquo;</span>'
        : "<a class=\"btn\" href=\"$nextUrl\">Next &rsaquo;</a>";

    // Rebuild URL always uses the resolved dates so the range is preserved.
    $rebuildUrl = $navUrl(['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'rebuild' => '1']);

    // Data-source badge and rebuild button.
    if ($rebuild) {
        $badge      = '<span class="badge badge--rebuilt">Rebuilt</span>';
        $rebuildBtn = "<a class=\"btn\" href=\"$rebuildUrl\">Rebuild again</a>";
    } elseif ($fromCache) {
        $badge      = '<span class="badge badge--cached">Cached</span>';
        $rebuildBtn = "<a class=\"btn\" href=\"$rebuildUrl\">Rebuild from source</a>";
    } else {
        $badge      = '<span class="badge badge--live">Live</span>';
        $rebuildBtn = "<a class=\"btn\" href=\"$rebuildUrl\">Refresh</a>";
    }

    // Diff banner shown after a rebuild.
    $diffBanner = '';
    if ($rebuild) {
        if ($oldJsonData === null) {
            $diffBanner = '<div class="diff-banner diff-banner--info">Rebuilt — no prior snapshot to compare against.</div>';
        } elseif ($diff === []) {
            $diffBanner = '<div class="diff-banner diff-banner--clean">&#10003; Rebuilt — no changes found.</div>';
        } else {
            $items = '';
            foreach ($diff as $c) {
                $p = htmlspecialchars($c['project'], ENT_QUOTES, 'UTF-8');
                if ($c['type'] === 'new_project') {
                    $secStr = $c['seconds'] > 0 ? ' &mdash; ' . fmtDur($c['seconds']) : '';
                    $cmtStr = $c['commits'] > 0 ? ", {$c['commits']} commit(s)" : '';
                    $items .= "<li><strong>{$c['date']}</strong>: new project <em>$p</em>$secStr$cmtStr</li>";
                } else {
                    $addSec = $c['added_seconds'] > 0 ? ' +' . fmtDur($c['added_seconds']) : '';
                    $addCmt = $c['added_commits'] > 0 ? ", +{$c['added_commits']} commit(s)" : '';
                    $items .= "<li><strong>{$c['date']}</strong>: updated <em>$p</em>$addSec$addCmt</li>";
                }
            }
            $n          = count($diff);
            $diffBanner = "<div class=\"diff-banner diff-banner--changes\"><strong>Rebuilt &mdash; $n change(s):</strong><ul>$items</ul></div>";
        }
    }

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
      .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px;
               font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
      .badge--live    { background: #d1fae5; color: #065f46; }
      .badge--cached  { background: #fef3c7; color: #92400e; }
      .badge--rebuilt { background: #dbeafe; color: #1e40af; }
      .diff-banner { margin: 1rem 0; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.9rem; }
      .diff-banner ul { margin: 0.4rem 0 0; padding-left: 1.5em; }
      .diff-banner--info    { background: #f0f9ff; border: 1px solid #bae6fd; color: #0c4a6e; }
      .diff-banner--clean   { background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d; }
      .diff-banner--changes { background: #fffbeb; border: 1px solid #fde68a; color: #78350f; }
    </style>
    </head>
    <body>
    <nav>
      $badge
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
      <span class="sep">|</span>
      $rebuildBtn
    </nav>
    $diffBanner
    $body
    </body>
    </html>
    HTML;
} else {
    echo $out;
}
