<?php

declare(strict_types=1);

/**
 * Lists git repositories registered in GitHub Desktop and optionally adds
 * unconfigured ones to config.json.
 *
 * Usage:
 *   php tools/list-github-desktop-repos.php           # preview only
 *   php tools/list-github-desktop-repos.php --apply   # write changes to config.json
 *
 * For repos whose basename matches an existing project name (case-insensitive),
 * the path is appended to that project's repos list. For repos with no matching
 * project, a new project entry is created using the repo basename as the name.
 * A timestamped backup of config.json is written to reports/config/ before any change.
 */

define('PROJECT_ROOT', dirname(__DIR__));

require_once PROJECT_ROOT . '/src/loader-github-desktop.php';

$apply = in_array('--apply', $argv ?? [], true);

// ── Load repos from GitHub Desktop ───────────────────────────────────────────

$repos = discoverGitHubDesktopRepos(withTimestamps: true);

if (!$repos) {
    fwrite(STDERR, "No GitHub Desktop repositories found.\n");
    fwrite(STDERR, "Expected LevelDB at: ~/Library/Application Support/GitHub Desktop/IndexedDB/\n");
    exit(1);
}

uasort($repos, fn($a, $b) => $b['last_commit_ts'] <=> $a['last_commit_ts']);

// ── Load config ───────────────────────────────────────────────────────────────

$configFile = PROJECT_ROOT . '/config.json';
if (!is_file($configFile)) {
    fwrite(STDERR, "error: config.json not found\n");
    exit(1);
}
$config = json_decode((string)file_get_contents($configFile), true);
if (!is_array($config)) {
    fwrite(STDERR, "error: config.json is invalid JSON\n");
    exit(1);
}

$home = (string)(getenv('HOME') ?: '');

/**
 * Converts an absolute path to a ~/... form when it's under $HOME, otherwise
 * returns the path unchanged. Mirrors the format used in the rest of config.json.
 */
function toConfigPath(string $abs, string $home): string
{
    return ($home !== '' && str_starts_with($abs, $home . '/'))
        ? '~/' . substr($abs, strlen($home) + 1)
        : $abs;
}

// Build a flat map of expanded absolute path => project name for every
// explicitly configured repo, so we can skip already-known paths.
$configured = []; // absolute path => project name
foreach ($config['projects'] ?? [] as $projName => $p) {
    foreach ($p['repos'] ?? [] as $r) {
        $abs = ($home !== '' && str_starts_with((string)$r, '~/'))
            ? $home . substr((string)$r, 1)
            : (string)$r;
        $configured[$abs] = $projName;
    }
}

$autoDiscover = ($config['discover_repos'] ?? null) === 'github_desktop';

// ── Compute additions ─────────────────────────────────────────────────────────

// additions: list of [path, projectName, isNewProject]
$additions = [];

foreach ($repos as $path => $info) {
    if (isset($configured[$path])) {
        continue; // already in config
    }

    // Find a matching existing project by repo basename (case-insensitive).
    $matched = null;
    foreach (array_keys($config['projects'] ?? []) as $projName) {
        if (strcasecmp($projName, $info['name']) === 0) {
            $matched = $projName;
            break;
        }
    }

    $isNew = $matched === null;
    $additions[] = [
        'path'       => $path,
        'project'    => $matched ?? $info['name'],
        'is_new'     => $isNew,
        'recent'     => $info['recent'],
        'last_ts'    => $info['last_commit_ts'],
    ];
}

// ── Print table ───────────────────────────────────────────────────────────────

echo str_pad('LAST COMMIT', 11) . '  ' . str_pad('STATUS', 20) . '  PATH' . PHP_EOL;
echo str_repeat('-', 90) . PHP_EOL;

$additionPaths = array_column($additions, 'path');

foreach ($repos as $path => $info) {
    $date   = $info['last_commit_ts'] ? date('Y-m-d', $info['last_commit_ts']) : 'no commits';
    $recent = $info['recent'] ? ' RECENT' : '       ';

    if (isset($configured[$path])) {
        $status = 'in ' . $configured[$path];
    } else {
        $idx = array_search($path, $additionPaths, true);
        $add = $additions[$idx];
        if ($apply) {
            $status = $add['is_new'] ? '+ new project' : '+ → ' . $add['project'];
        } else {
            $status = $add['is_new'] ? 'would add (new)' : 'would add → ' . $add['project'];
        }
    }

    printf("%-11s%s  %-22s  %s\n", $date, $recent, $status, $path);
}

echo PHP_EOL;

if (!$additions) {
    echo 'All GitHub Desktop repositories are already in config.json.' . PHP_EOL;
    exit(0);
}

if (!$apply) {
    echo count($additions) . ' repo(s) not yet in config.json.' . PHP_EOL;
    echo 'Run with --apply to add them.' . PHP_EOL;
    if (!$autoDiscover) {
        echo PHP_EOL . 'Tip: "discover_repos": "github_desktop" in config.json also works at report time.' . PHP_EOL;
    }
    exit(0);
}

// ── Apply additions ───────────────────────────────────────────────────────────

// Backup first.
$backupDir = PROJECT_ROOT . '/reports/config';
if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
    fwrite(STDERR, "error: could not create reports/config backup directory\n");
    exit(1);
}
$backupPath = $backupDir . '/config.github-desktop.' . date('Ymd\\THis_u') . '.json';
if (!copy($configFile, $backupPath)) {
    fwrite(STDERR, "error: failed to create config backup\n");
    exit(1);
}

foreach ($additions as $add) {
    $configPath = toConfigPath($add['path'], $home);
    if ($add['is_new']) {
        // Preserve insertion order — new projects go at the end.
        $config['projects'][$add['project']] = ['repos' => [$configPath]];
    } else {
        $config['projects'][$add['project']]['repos'][] = $configPath;
    }
}

file_put_contents(
    $configFile,
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
);

$newCount      = count(array_filter($additions, fn($a) => $a['is_new']));
$existingCount = count($additions) - $newCount;

echo 'Added ' . count($additions) . ' repo(s): '
    . $newCount . ' new project(s), '
    . $existingCount . ' appended to existing project(s).' . PHP_EOL;
echo 'Backup: ' . $backupPath . PHP_EOL;
