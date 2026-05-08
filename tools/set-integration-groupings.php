<?php

declare(strict_types=1);

// Sets project grouping based on integration provenance.
//
// Reads grouping rules from the "groupings_map" key in config.json:
//   connections    — glob patterns matched against connection names → grouping label
//   clickup_default — fallback grouping for any ClickUp connection with no match
//   priority       — ordered list of groupings; first match wins when a project
//                    satisfies multiple groupings
//
// Run after sync-integration-projects.php to auto-assign groupings to the
// Harvest/ClickUp-synced project stubs.

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/loader-integrations.php';
require_once TOOL_ROOT . '/src/config.php';

$configPath = TOOL_ROOT . '/config.json';
$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    fwrite(STDERR, "error: config.json is invalid JSON\n");
    exit(1);
}
if (!isset($config['projects']) || !is_array($config['projects'])) {
    fwrite(STDERR, "error: config.json has no projects object\n");
    exit(1);
}

/**
 * Maps integration connection label to a grouping name via groupings_map config.
 *
 * @param array<string, mixed> $groupingsMap
 */
function groupingForConnection(string $connectionName, string $source, array $groupingsMap): ?string
{
    foreach (($groupingsMap['connections'] ?? []) as $glob => $grouping) {
        if (fnmatch((string)$glob, $connectionName, FNM_CASEFOLD)) {
            return (string)$grouping;
        }
    }
    if ($source === 'clickup' && isset($groupingsMap['clickup_default'])) {
        return (string)$groupingsMap['clickup_default'];
    }
    return null;
}

/**
 * Returns Harvest project names grouped by grouping label, using the catalog loader.
 *
 * @param array<string, mixed> $groupingsMap
 * @return array<string, array<string, bool>>
 */
function discoverHarvestProjectsByGrouping(array $harvestConnections, array $groupingsMap, int $timeout = 20): array
{
    $byGrouping = [];

    foreach ($harvestConnections as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $name     = (string)($conn['name'] ?? "harvest[$idx]");
        $grouping = groupingForConnection($name, 'harvest', $groupingsMap);
        if ($grouping === null) {
            continue;
        }
        foreach (array_keys(harvestFetchProjectNames($conn, $timeout)) as $pn) {
            $byGrouping[$grouping][$pn] = true;
        }
    }

    return $byGrouping;
}

/**
 * Returns ClickUp names grouped by grouping label, using the catalog loader.
 *
 * @param array<string, mixed> $groupingsMap
 * @return array<string, array<string, bool>>
 */
function discoverClickUpNamesByGrouping(array $clickupConnections, array $groupingsMap, int $timeout = 20): array
{
    $byGrouping = [];

    foreach ($clickupConnections as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $name     = (string)($conn['name'] ?? "clickup[$idx]");
        $grouping = groupingForConnection($name, 'clickup', $groupingsMap);
        if ($grouping === null) {
            continue;
        }
        foreach (array_keys(clickupFetchAllNames($conn, $timeout)) as $n) {
            $byGrouping[$grouping][$n] = true;
        }
    }

    return $byGrouping;
}

$groupingsMap = $config['groupings_map'] ?? null;
if (!is_array($groupingsMap) || empty($groupingsMap['priority'])) {
    fwrite(STDERR, "error: config.json has no groupings_map.priority — add a groupings_map section to config.json\n");
    exit(1);
}

$httpTimeout = (int)($config['integration_http_timeout_seconds'] ?? 20);
$harvestByGroup = discoverHarvestProjectsByGrouping($config['integrations']['harvest'] ?? [], $groupingsMap, $httpTimeout);
$clickupByGroup = discoverClickUpNamesByGrouping($config['integrations']['clickup'] ?? [], $groupingsMap, $httpTimeout);

$updated = 0;
foreach ($config['projects'] as $projectName => &$projectConfig) {
    if (!is_array($projectConfig)) {
        continue;
    }

    $targetGroup = null;

    foreach ($groupingsMap['priority'] as $group) {
        $hasMatch = false;

        foreach (($projectConfig['harvest_projects'] ?? []) as $pattern) {
            if (!is_string($pattern)) {
                continue;
            }
            foreach (array_keys($harvestByGroup[$group] ?? []) as $name) {
                if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
                    $hasMatch = true;
                    break 2;
                }
            }
        }

        if (!$hasMatch) {
            foreach (($projectConfig['clickup_tasks'] ?? []) as $pattern) {
                if (!is_string($pattern)) {
                    continue;
                }
                foreach (array_keys($clickupByGroup[$group] ?? []) as $name) {
                    if (fnmatch($pattern, $name, FNM_CASEFOLD)) {
                        $hasMatch = true;
                        break 2;
                    }
                }
            }
        }

        if ($hasMatch) {
            $targetGroup = $group;
            break;
        }
    }

    if ($targetGroup !== null && ($projectConfig['grouping'] ?? null) !== $targetGroup) {
        $projectConfig['grouping'] = $targetGroup;
        $updated++;
    }
}
unset($projectConfig);

if ($updated === 0) {
    echo "No grouping updates needed.\n";
    exit(0);
}

try {
    $backupPath = saveConfigWithBackup($config, $configPath, 'grouping');
} catch (RuntimeException $e) {
    fwrite(STDERR, "error: " . $e->getMessage() . "\n");
    exit(1);
}

echo 'Grouping updates applied: ' . $updated . "\n";
echo 'Backup: ' . $backupPath . "\n";
