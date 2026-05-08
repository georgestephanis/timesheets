<?php

declare(strict_types=1);

// Sets project grouping based on integration provenance:
// - Harvest/ClickUp connections with "Big Orange"/"BOL" => "Big Orange Lab"
// - Harvest/ClickUp connections with "Bethink" => "BethinkStudio"

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
 * Maps integration connection label to a grouping name.
 */
function groupingForConnection(string $connectionName, string $source): ?string
{
    $n = strtolower($connectionName);
    if (str_contains($n, 'bethink')) {
        return 'BethinkStudio';
    }
    if (str_contains($n, 'big orange') || str_contains($n, 'bigorangelab') || str_contains($n, 'bol')) {
        return 'Big Orange Lab';
    }

    // By request, ClickUp-synced projects should default to Big Orange Lab unless a Bethink connection name says otherwise.
    if ($source === 'clickup') {
        return 'Big Orange Lab';
    }

    return null;
}

/**
 * Returns discovered Harvest project names grouped by grouping label.
 *
 * @return array<string, array<string, bool>>
 */
function discoverHarvestProjectsByGrouping(array $harvestConnections): array
{
    $byGrouping = [];

    foreach ($harvestConnections as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }

        $token = (string)($conn['token'] ?? '');
        $accountId = (string)($conn['account_id'] ?? '');
        $name = (string)($conn['name'] ?? "harvest[$idx]");
        $grouping = groupingForConnection($name, 'harvest');
        if ($token === '' || $accountId === '' || $grouping === null) {
            continue;
        }

        $projects = [];
        $page = 1;
        $pages = 1;
        $listed = false;
        do {
            try {
                $json = httpGetJson(
                    'https://api.harvestapp.com/v2/projects?' . http_build_query([
                        'is_active' => 'true',
                        'page' => (string)$page,
                    ]),
                    [
                        'Authorization: Bearer ' . $token,
                        'Harvest-Account-ID: ' . $accountId,
                        'User-Agent: activity-report',
                        'Accept: application/json',
                    ]
                );
            } catch (RuntimeException $e) {
                $json = [];
                $pages = 0;
            }

            foreach (($json['projects'] ?? []) as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $pn = trim((string)($p['name'] ?? ''));
                if ($pn !== '') {
                    $projects[$pn] = true;
                    $listed = true;
                }
            }

            $pages = max(0, (int)($json['total_pages'] ?? 0));
            $page++;
        } while ($page <= $pages);

        // Fallback when /projects is not authorized.
        if (!$listed) {
            $page = 1;
            $pages = 1;
            do {
                try {
                    $json = httpGetJson(
                        'https://api.harvestapp.com/v2/time_entries?' . http_build_query([
                            'from' => (new DateTimeImmutable('now -365 days', new DateTimeZone('UTC')))->format('Y-m-d'),
                            'to' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
                            'page' => (string)$page,
                        ]),
                        [
                            'Authorization: Bearer ' . $token,
                            'Harvest-Account-ID: ' . $accountId,
                            'User-Agent: activity-report',
                            'Accept: application/json',
                        ]
                    );
                } catch (RuntimeException $e) {
                    break;
                }

                foreach (($json['time_entries'] ?? []) as $te) {
                    if (!is_array($te)) {
                        continue;
                    }
                    $pn = trim((string)($te['project']['name'] ?? ''));
                    if ($pn !== '') {
                        $projects[$pn] = true;
                    }
                }

                $pages = max(1, (int)($json['total_pages'] ?? 1));
                $page++;
            } while ($page <= $pages);
        }

        foreach (array_keys($projects) as $pn) {
            $byGrouping[$grouping][$pn] = true;
        }
    }

    return $byGrouping;
}

/**
 * Returns discovered ClickUp names grouped by grouping label.
 *
 * @return array<string, array<string, bool>>
 */
function discoverClickUpNamesByGrouping(array $clickupConnections): array
{
    $byGrouping = [];

    foreach ($clickupConnections as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }

        $token = (string)($conn['token'] ?? '');
        $name = (string)($conn['name'] ?? "clickup[$idx]");
        $grouping = groupingForConnection($name, 'clickup');
        $rawTeamId = $conn['team_id'] ?? [];
        $teamIds = is_array($rawTeamId) ? $rawTeamId : [$rawTeamId];
        $teamIds = array_values(array_filter(array_map('strval', $teamIds), static fn($v) => $v !== ''));
        if ($token === '' || $teamIds === [] || $grouping === null) {
            continue;
        }

        $names = [];
        foreach ($teamIds as $teamId) {
            try {
                $spaces = httpGetJson(
                    'https://api.clickup.com/api/v2/team/' . rawurlencode($teamId) . '/space?archived=false',
                    [
                        'Authorization: ' . $token,
                        'Accept: application/json',
                    ]
                );
            } catch (RuntimeException $e) {
                continue;
            }

            foreach (($spaces['spaces'] ?? []) as $space) {
                if (!is_array($space)) {
                    continue;
                }

                $spaceId = (string)($space['id'] ?? '');
                $spaceName = trim((string)($space['name'] ?? ''));
                if ($spaceName !== '') {
                    $names[$spaceName] = true;
                }
                if ($spaceId === '') {
                    continue;
                }

                try {
                    $folders = httpGetJson(
                        'https://api.clickup.com/api/v2/space/' . rawurlencode($spaceId) . '/folder?archived=false',
                        [
                            'Authorization: ' . $token,
                            'Accept: application/json',
                        ]
                    );
                } catch (RuntimeException $e) {
                    $folders = ['folders' => []];
                }

                foreach (($folders['folders'] ?? []) as $folder) {
                    if (!is_array($folder)) {
                        continue;
                    }
                    $folderId = (string)($folder['id'] ?? '');
                    $folderName = trim((string)($folder['name'] ?? ''));
                    if ($folderName !== '') {
                        $names[$folderName] = true;
                    }
                    if ($folderId === '') {
                        continue;
                    }

                    try {
                        $lists = httpGetJson(
                            'https://api.clickup.com/api/v2/folder/' . rawurlencode($folderId) . '/list?archived=false',
                            [
                                'Authorization: ' . $token,
                                'Accept: application/json',
                            ]
                        );
                    } catch (RuntimeException $e) {
                        $lists = ['lists' => []];
                    }

                    foreach (($lists['lists'] ?? []) as $list) {
                        if (!is_array($list)) {
                            continue;
                        }
                        $listName = trim((string)($list['name'] ?? ''));
                        if ($listName !== '') {
                            $names[$listName] = true;
                        }
                    }
                }

                try {
                    $spaceLists = httpGetJson(
                        'https://api.clickup.com/api/v2/space/' . rawurlencode($spaceId) . '/list?archived=false',
                        [
                            'Authorization: ' . $token,
                            'Accept: application/json',
                        ]
                    );
                } catch (RuntimeException $e) {
                    $spaceLists = ['lists' => []];
                }

                foreach (($spaceLists['lists'] ?? []) as $list) {
                    if (!is_array($list)) {
                        continue;
                    }
                    $listName = trim((string)($list['name'] ?? ''));
                    if ($listName !== '') {
                        $names[$listName] = true;
                    }
                }
            }
        }

        foreach (array_keys($names) as $n) {
            $byGrouping[$grouping][$n] = true;
        }
    }

    return $byGrouping;
}

$harvestByGroup = discoverHarvestProjectsByGrouping($config['integrations']['harvest'] ?? []);
$clickupByGroup = discoverClickUpNamesByGrouping($config['integrations']['clickup'] ?? []);

$updated = 0;
foreach ($config['projects'] as $projectName => &$projectConfig) {
    if (!is_array($projectConfig)) {
        continue;
    }

    $targetGroup = null;

    // Bethink takes precedence over Big Orange Lab if both signals exist.
    foreach (['BethinkStudio', 'Big Orange Lab'] as $group) {
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
