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

/**
 * Writes config.json using pretty-printed JSON and a trailing newline.
 */
function saveConfigJson(string $configFile, array $config): void
{
    if (file_exists($configFile)) {
        $backupDir = PROJECT_ROOT . '/reports/config';
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            throw new RuntimeException('Failed to create config backup directory');
        }

        $stamp = (new DateTimeImmutable('now'))->format('Ymd\THis_u');
        $backupPath = $backupDir . '/config.' . $stamp . '.json';
        if (!copy($configFile, $backupPath)) {
            throw new RuntimeException('Failed to backup config.json');
        }
    }

    $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if ($json === false) {
        throw new RuntimeException('Failed to encode config.json');
    }
    if (file_put_contents($configFile, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write config.json');
    }
}

/**
 * Appends a value to an array key if not already present.
 */
function addUniqueValue(array &$arr, string $key, string $value): void
{
    $arr[$key] = $arr[$key] ?? [];
    if (!in_array($value, $arr[$key], true)) {
        $arr[$key][] = $value;
    }
}

/**
 * Parses a "Workspace / channel" signal label into parts.
 *
 * @return array{workspace: string, channel: string}|null
 */
function parseSlackSignal(string $value): ?array
{
    if (!str_contains($value, ' / ')) {
        return null;
    }
    [$workspace, $channel] = explode(' / ', $value, 2);
    $workspace = trim($workspace);
    $channel = trim($channel);
    if ($workspace === '' || $channel === '') {
        return null;
    }
    return ['workspace' => $workspace, 'channel' => $channel];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            saveConfigJson($configFile, $config);
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

                saveConfigJson($configFile, $config);
                echo json_encode(['ok' => true]);
                exit;
            }

            if (!array_key_exists($project, $config['projects'] ?? [])) {
                http_response_code(400);
                echo json_encode(['error' => 'Unknown project']);
                exit(1);
            }

            $p =& $config['projects'][$project];
            switch ($kind) {
                case 'vscode':
                    addUniqueValue($p, 'vscode_dirs', $value);
                    break;
                case 'browser':
                    if ($value === '(no url)') {
                        http_response_code(400);
                        echo json_encode(['error' => 'Cannot reassign browser signal without host']);
                        exit(1);
                    }
                    addUniqueValue($p, 'domains', $value);
                    break;
                case 'slack':
                    $parsed = parseSlackSignal($value);
                    if ($parsed === null) {
                        http_response_code(400);
                        echo json_encode(['error' => 'Invalid slack signal format']);
                        exit(1);
                    }
                    $p['slack'] = $p['slack'] ?? [];
                    $rule = ['workspace' => $parsed['workspace']];
                    if ($parsed['channel'] !== '__threads__' && $parsed['channel'] !== '__activity__' && $parsed['channel'] !== '__huddle__') {
                        $rule['channel_glob'] = $parsed['channel'];
                    }
                    $exists = false;
                    foreach ($p['slack'] as $existing) {
                        if (
                            ($existing['workspace'] ?? null) === $rule['workspace']
                            && ($existing['channel_glob'] ?? null) === ($rule['channel_glob'] ?? null)
                        ) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists) {
                        $p['slack'][] = $rule;
                    }
                    break;
                case 'apps':
                    if (str_starts_with($value, 'ssh:')) {
                        addUniqueValue($p, 'ssh_hosts', substr($value, 4));
                    } else {
                        addUniqueValue($p, 'apps', $value);
                    }
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Unsupported kind']);
                    exit(1);
            }

            saveConfigJson($configFile, $config);
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
            if ($grouping === '') {
                unset($config['projects'][$project]['grouping']);
            } else {
                $config['projects'][$project]['grouping'] = $grouping;
            }

            saveConfigJson($configFile, $config);
            echo json_encode(['ok' => true]);
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
] = loadSourcesForRange($config, $tz, $from, $to);

$fullOpts = $opts;
$fullOpts['project'] = null;
[$fullBucket, $fullUnmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $fullOpts);

if ($hasProjectFilter) {
    [$bucket, $unmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $opts);
} else {
    [$bucket, $unmatched] = [$fullBucket, $fullUnmatched];
}

$out = renderJson($bucket, $unmatched, $from, $to, $tz);
$fullOut = renderJson($fullBucket, $fullUnmatched, $from, $to, $tz);

saveGeneratedReport($dir, $key, $from, $to, 'json', null, $fromCache, $fullOut);

header('X-Report-Source: generated');
header('X-Report-Age-Seconds: 0');
echo $out;
