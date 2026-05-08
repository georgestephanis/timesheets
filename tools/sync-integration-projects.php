<?php

declare(strict_types=1);

// Syncs Harvest and ClickUp project catalogs into config.json project mappings.

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/loader-integrations.php';
require_once TOOL_ROOT . '/src/config.php';

$configPath = TOOL_ROOT . '/config.json';
$configRaw = file_get_contents($configPath);
$config = json_decode((string)$configRaw, true);
if (!is_array($config)) {
    fwrite(STDERR, "error: config.json is invalid JSON\n");
    exit(1);
}

if (!isset($config['projects']) || !is_array($config['projects'])) {
    $config['projects'] = [];
}
$projects = &$config['projects'];

$canon = [];
foreach (array_keys($projects) as $name) {
    $canon[strtolower((string)$name)] = (string)$name;
}

$updatedMappings = 0;
$addedProjects = [];

$ensureMapping = static function (string $projectName, string $key, string $pattern) use (&$projects, &$updatedMappings): void {
    if (!isset($projects[$projectName]) || !is_array($projects[$projectName])) {
        $projects[$projectName] = [];
    }
    if (!isset($projects[$projectName][$key]) || !is_array($projects[$projectName][$key])) {
        $projects[$projectName][$key] = [];
    }
    if (!in_array($pattern, $projects[$projectName][$key], true)) {
        $projects[$projectName][$key][] = $pattern;
        $updatedMappings++;
    }
};

$upsertProject = static function (string $externalName) use (&$projects, &$canon, &$addedProjects): string {
    $k = strtolower($externalName);
    if (isset($canon[$k])) {
        return $canon[$k];
    }

    $projects[$externalName] = [];
    $canon[$k] = $externalName;
    $addedProjects[] = $externalName;
    return $externalName;
};

// Harvest: sync active project names as exact match patterns.
foreach (($config['integrations']['harvest'] ?? []) as $idx => $conn) {
    if (!is_array($conn)) {
        continue;
    }

    foreach (array_keys(harvestFetchProjectNames($conn, (int)($config['integration_http_timeout_seconds'] ?? 20))) as $name) {
        $local = $upsertProject($name);
        $ensureMapping($local, 'harvest_projects', $name);
    }
}

// ClickUp: collect project-like names (spaces, folders, lists).
$clickupNames = [];
foreach (($config['integrations']['clickup'] ?? []) as $idx => $conn) {
    if (!is_array($conn)) {
        continue;
    }

    foreach (array_keys(clickupFetchAllNames($conn, (int)($config['integration_http_timeout_seconds'] ?? 20))) as $name) {
        $clickupNames[$name] = true;
    }
}

foreach (array_keys($clickupNames) as $name) {
    if (strlen($name) < 3) {
        continue;
    }

    $local = $upsertProject($name);
    $ensureMapping($local, 'clickup_tasks', '*' . $name . '*');
}

try {
    $backup = saveConfigWithBackup($config, $configPath, 'sync');
} catch (RuntimeException $e) {
    fwrite(STDERR, "error: " . $e->getMessage() . "\n");
    exit(1);
}

echo "Backup: $backup\n";
echo 'Added projects: ' . count(array_unique($addedProjects)) . "\n";
echo 'Updated mappings: ' . $updatedMappings . "\n";
