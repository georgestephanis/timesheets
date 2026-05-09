<?php
// api.php — JSON data endpoint consumed by the JS frontend in report_renderer.php.
//
// GET params: from, to, days, project, rebuild
// Returns the latest saved JSON report for the range when available; regenerates
// (and saves) when none exists or ?rebuild=1 is requested.
// Adds X-Report-Source: cached|generated so the JS can show the right badge.

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache');

if (function_exists('set_time_limit')) {
    set_time_limit(0);
}

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

require_once PROJECT_ROOT . '/src/config.php';

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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'config') {
    header('Content-Type: application/json; charset=UTF-8');
    readfile($configFile);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject cross-origin POST requests. Browsers always send Origin for cross-site fetches;
    // when it is present, it must be localhost or 127.0.0.1 (any port).
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && !preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#', $origin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden: cross-origin request']);
        exit(1);
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '{}', true);
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON payload']);
        exit(1);
    }

    $action = $payload['action'] ?? '';
    if (!is_string($action) || $action === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing action']);
        exit(1);
    }

    switch ($action) {
        case 'flag_projects_personal':
            $projects = $payload['projects'] ?? [];
            if (!is_array($projects)) {
                http_response_code(400);
                echo json_encode(['error' => 'projects must be an array']);
                exit(1);
            }
            $config['ignored_projects'] = $config['ignored_projects'] ?? [];
            foreach ($projects as $name) {
                if (!is_string($name) || $name === '') {
                    continue;
                }
                if (!array_key_exists($name, $config['projects'] ?? [])) {
                    continue;
                }
                if (!in_array($name, $config['ignored_projects'], true)) {
                    $config['ignored_projects'][] = $name;
                }
            }
            saveConfigWithBackup($config, $configFile, 'api');
            echo json_encode(['ok' => true, 'ignored_projects' => $config['ignored_projects']]);
            exit;

        case 'reassign_signal':
            $project = $payload['project'] ?? '';
            $kind = $payload['kind'] ?? '';
            $value = $payload['value'] ?? '';
            $newProjectName = $payload['new_project_name'] ?? '';

            if (
                !is_string($project) || !is_string($kind) || !is_string($value) || !is_string($newProjectName)
                || $project === '' || $kind === '' || $value === ''
            ) {
                http_response_code(400);
                echo json_encode(['error' => 'project, kind, and value are required']);
                exit(1);
            }

            if ($newProjectName !== '') {
                if (!array_key_exists($newProjectName, $config['projects'] ?? [])) {
                    $config['projects'][$newProjectName] = [];
                }
                $project = $newProjectName;
            }

            if ($project === '__personal__') {
                switch ($kind) {
                    case 'browser':
                        if ($value === '(no url)') {
                            http_response_code(400);
                            echo json_encode(['error' => 'Cannot mark browser signal without host as personal']);
                            exit(1);
                        }
                        addUniqueValue($config, 'personal_hosts', $value);
                        break;
                    case 'apps':
                        addUniqueValue($config, 'personal_apps', str_starts_with($value, 'ssh:') ? substr($value, 4) : $value);
                        break;
                    default:
                        http_response_code(400);
                        echo json_encode(['error' => 'Personal reassignment is supported for browser and apps signals']);
                        exit(1);
                }
                saveConfigWithBackup($config, $configFile, 'api');
                echo json_encode(['ok' => true]);
                exit;
            }

            if ($project === '__correlated__') {
                if ($kind !== 'apps') {
                    http_response_code(400);
                    echo json_encode(['error' => 'Correlated attribution is only supported for apps signals']);
                    exit(1);
                }
                $app = str_starts_with($value, 'ssh:') ? substr($value, 4) : $value;
                addUniqueValue($config, 'correlated_apps', $app);
                saveConfigWithBackup($config, $configFile, 'api');
                echo json_encode(['ok' => true]);
                exit;
            }

            if (!array_key_exists($project, $config['projects'] ?? [])) {
                http_response_code(400);
                echo json_encode(['error' => 'Unknown project']);
                exit(1);
            }

            // Validate inputs that applySignalToProject would silently ignore.
            if ($kind === 'browser' && $value === '(no url)') {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot reassign browser signal without host']);
                exit(1);
            }
            if ($kind === 'slack' && parseSlackSignal($value) === null) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid slack signal format']);
                exit(1);
            }
            if (!in_array($kind, ['vscode', 'browser', 'slack', 'apps'], true)) {
                http_response_code(400);
                echo json_encode(['error' => 'Unsupported kind']);
                exit(1);
            }

            applySignalToProject($config, $kind, $value, $project);
            saveConfigWithBackup($config, $configFile, 'api');
            echo json_encode(['ok' => true]);
            exit;

        case 'set_project_grouping':
            $project = $payload['project'] ?? '';
            $grouping = $payload['grouping'] ?? '';
            if (!is_string($project) || !is_string($grouping) || $project === '') {
                http_response_code(400);
                echo json_encode(['error' => 'project and grouping are required']);
                exit(1);
            }
            if (!array_key_exists($project, $config['projects'] ?? [])) {
                http_response_code(400);
                echo json_encode(['error' => 'Unknown project']);
                exit(1);
            }

            $grouping = trim($grouping);
            if ($grouping !== '' && isset($config['groupings']) && is_array($config['groupings'])) {
                if (!array_key_exists($grouping, $config['groupings'])) {
                    foreach ($config['groupings'] as $canonical => $def) {
                        if (in_array($grouping, $def['aliases'] ?? [], true)) {
                            $grouping = (string)$canonical;
                            break;
                        }
                    }
                }
                if (!array_key_exists($grouping, $config['groupings'])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Unknown grouping: $grouping"]);
                    exit(1);
                }
            }
            if ($grouping === '') {
                unset($config['projects'][$project]['grouping']);
            } else {
                $config['projects'][$project]['grouping'] = $grouping;
            }

            saveConfigWithBackup($config, $configFile, 'api');
            echo json_encode(['ok' => true]);
            exit;

        case 'save_config':
            require_once PROJECT_ROOT . '/src/helpers.php';
            $newConfig = $payload['config'] ?? null;
            if (!is_array($newConfig)) {
                http_response_code(400);
                echo json_encode(['error' => 'config must be an object']);
                exit(1);
            }
            foreach (['timezone', 'paths', 'git_authors', 'projects'] as $req) {
                if (!isset($newConfig[$req])) {
                    http_response_code(400);
                    echo json_encode(['error' => "Missing required config key: $req"]);
                    exit(1);
                }
            }
            if (
                !is_array($newConfig['paths'])
                || empty($newConfig['paths']['activitywatch'])
                || empty($newConfig['paths']['chrome'])
            ) {
                http_response_code(400);
                echo json_encode(['error' => 'paths.activitywatch and paths.chrome are required']);
                exit(1);
            }
            if (!is_array($newConfig['projects'])) {
                http_response_code(400);
                echo json_encode(['error' => 'projects must be an object']);
                exit(1);
            }
            // Validate that the data paths exist on this server.
            $awPath = expandPath((string)$newConfig['paths']['activitywatch']);
            if (!is_dir($awPath)) {
                http_response_code(400);
                echo json_encode(['error' => "ActivityWatch path does not exist: {$awPath}"]);
                exit(1);
            }
            $chromePath = expandPath((string)$newConfig['paths']['chrome']);
            if (!is_dir($chromePath)) {
                http_response_code(400);
                echo json_encode(['error' => "Chrome user data path does not exist: {$chromePath}"]);
                exit(1);
            }
            $backupPath = saveConfigWithBackup($newConfig, $configFile, 'ui');
            echo json_encode(['ok' => true, 'backup' => $backupPath]);
            exit;

        case 'generate_summary':
            $date = (string)($payload['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                http_response_code(400);
                echo json_encode(['error' => 'date must be YYYY-MM-DD']);
                exit(1);
            }

            require_once PROJECT_ROOT . '/src/helpers.php';
            require_once PROJECT_ROOT . '/src/cache.php';
            require_once PROJECT_ROOT . '/src/loader-activitywatch.php';
            require_once PROJECT_ROOT . '/src/loader-chrome.php';
            require_once PROJECT_ROOT . '/src/loader-git.php';
            require_once PROJECT_ROOT . '/src/loader-integrations.php';
            require_once PROJECT_ROOT . '/src/integrations/llm.php';
            require_once PROJECT_ROOT . '/src/classifiers.php';
            require_once PROJECT_ROOT . '/src/renderers.php';
            require_once PROJECT_ROOT . '/src/cli.php';

            if (llmGetConnection($config) === null) {
                http_response_code(400);
                echo json_encode(['error' => 'No LLM connection configured in integrations.llm']);
                exit(1);
            }

            $tz   = new DateTimeZone($config['timezone']);
            $from = new DateTimeImmutable($date . ' 00:00:00', $tz);
            $to   = new DateTimeImmutable($date . ' 23:59:59', $tz);

            [
                'events'     => $events,
                'commits'    => $commits,
                'external'   => $external,
                'from_cache' => $fromCache,
            ] = loadSourcesForRange($config, $tz, $from, $to);

            $sumOpts = [
                'days' => null, 'from' => $date, 'to' => $date,
                'project' => null, 'format' => 'json',
                'show_unmatched' => false, 'list_projects' => false,
                'help' => false, 'suggest' => false,
            ];
            [$fullBucket, $fullUnmatched, $fullTimeline] = classifyAndAggregate(
                $events,
                $commits,
                $external,
                $config,
                $tz,
                $sumOpts
            );

            $summary = llmDailySummary($date, $fullBucket[$date] ?? [], $external, $config, $tz, $fullTimeline[$date] ?? []);
            if ($summary === null) {
                http_response_code(500);
                echo json_encode(['error' => 'LLM returned no summary — check your LLM configuration and ensure there is activity data for this date']);
                exit(1);
            }

            // Persist the summary by saving a fresh JSON report for this day.
            $summaries = [$date => $summary];
            $warnings  = getIntegrationWarnings();
            $dir       = reportsDir($from);
            $key       = reportsCacheKey($from, $to);
            $jsonOut   = renderJson($fullBucket, $fullUnmatched, $from, $to, $tz, $warnings, $fullTimeline, $summaries);
            saveGeneratedReport($dir, $key, $from, $to, 'json', null, $fromCache, $jsonOut);

            echo json_encode(['ok' => true, 'summary' => $summary]);
            exit;

        case 'suggest_time_logging':
            $date = (string)($payload['date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                http_response_code(400);
                echo json_encode(['error' => 'date must be YYYY-MM-DD']);
                exit(1);
            }

            require_once PROJECT_ROOT . '/src/helpers.php';
            require_once PROJECT_ROOT . '/src/cache.php';
            require_once PROJECT_ROOT . '/src/loader-activitywatch.php';
            require_once PROJECT_ROOT . '/src/loader-chrome.php';
            require_once PROJECT_ROOT . '/src/loader-git.php';
            require_once PROJECT_ROOT . '/src/loader-integrations.php';
            require_once PROJECT_ROOT . '/src/integrations/shared.php';
            require_once PROJECT_ROOT . '/src/integrations/harvest.php';
            require_once PROJECT_ROOT . '/src/integrations/clickup.php';
            require_once PROJECT_ROOT . '/src/integrations/llm.php';
            require_once PROJECT_ROOT . '/src/classifiers.php';
            require_once PROJECT_ROOT . '/src/renderers.php';
            require_once PROJECT_ROOT . '/src/cli.php';

            if (!llmSuggestLoggingAvailable($config)) {
                http_response_code(400);
                echo json_encode(['error' => 'LLM or time_tracking not configured']);
                exit(1);
            }

            $tz   = new DateTimeZone($config['timezone']);
            $from = new DateTimeImmutable($date . ' 00:00:00', $tz);
            $to   = new DateTimeImmutable($date . ' 23:59:59', $tz);

            [
                'events'   => $events,
                'commits'  => $commits,
                'external' => $external,
            ] = loadSourcesForRange($config, $tz, $from, $to);

            $sumOpts = [
                'days' => null, 'from' => $date, 'to' => $date,
                'project' => null, 'format' => 'json',
                'show_unmatched' => false, 'list_projects' => false,
                'help' => false, 'suggest' => false,
            ];
            [$fullBucket] = classifyAndAggregate($events, $commits, $external, $config, $tz, $sumOpts);

            $suggestions = llmSuggestTimeLogging($date, $fullBucket[$date] ?? [], $external, $config);
            echo json_encode(['ok' => true, 'suggestions' => $suggestions]);
            exit;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unsupported action']);
            exit(1);
    }
}

require_once PROJECT_ROOT . '/src/helpers.php';
require_once PROJECT_ROOT . '/src/cache.php';
require_once PROJECT_ROOT . '/src/loader-activitywatch.php';
require_once PROJECT_ROOT . '/src/loader-chrome.php';
require_once PROJECT_ROOT . '/src/loader-git.php';
require_once PROJECT_ROOT . '/src/loader-integrations.php';
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
$hasProjectFilter = !empty($opts['project']);

// For historical ranges, serve the latest saved JSON if not rebuilding.
if (!$rebuild && !$hasProjectFilter && rangeIsHistorical($to, $tz)) {
    $latest = findLatestReport($dir, $key, '', 'json');
    if ($latest) {
        $ageSec = max(0, time() - (int)filemtime($latest));
        header('X-Report-Source: cached');
        header('X-Report-Age-Seconds: ' . (string)$ageSec);
        readfile($latest);
        exit;
    }
}

// Generate fresh data.
[
    'events' => $events,
    'chrome' => $chrome,
    'commits' => $commits,
    'external' => $external,
    'from_cache' => $fromCache,
] = loadSourcesForRange($config, $tz, $from, $to, $rebuild);

$fullOpts = $opts;
$fullOpts['project'] = null;
[$fullBucket, $fullUnmatched, $fullTimeline] = classifyAndAggregate($events, $commits, $external, $config, $tz, $fullOpts);

if ($hasProjectFilter) {
    [$bucket, $unmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $opts);
} else {
    [$bucket, $unmatched] = [$fullBucket, $fullUnmatched];
}
$timeline = $hasProjectFilter ? [] : $fullTimeline;

// Load any cached LLM day summaries for all days in the range.
$summaries = [];
foreach (rangeDays($from, $to, $tz) as $day) {
    $cached = loadCachedLlmSummary($day);
    if ($cached !== null) {
        $summaries[$day->format('Y-m-d')] = $cached;
    }
}

$warnings = getIntegrationWarnings();
$out     = renderJson($bucket, $unmatched, $from, $to, $tz, $warnings, $timeline, $summaries);
$fullOut = renderJson($fullBucket, $fullUnmatched, $from, $to, $tz, [], $fullTimeline, $summaries);

saveGeneratedReport($dir, $key, $from, $to, 'json', null, $fromCache, $fullOut);

header('X-Report-Source: generated');
header('X-Report-Age-Seconds: 0');
echo $out;
