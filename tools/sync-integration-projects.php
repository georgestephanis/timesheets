<?php

declare(strict_types=1);

// Syncs Harvest and ClickUp project catalogs into config.json project mappings.

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/loader-integrations.php';

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

$warnings = [];
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

    $token = (string)($conn['token'] ?? '');
    $accountId = (string)($conn['account_id'] ?? '');
    $label = (string)($conn['name'] ?? "harvest[$idx]");
    if ($token === '' || $accountId === '') {
        continue;
    }

    $page = 1;
    $pages = 1;
    $harvestCollectedAny = false;
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
            $warnings[] = "[$label] " . $e->getMessage();
            break;
        }

        foreach (($json['projects'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $name = trim((string)($p['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $local = $upsertProject($name);
            $ensureMapping($local, 'harvest_projects', $name);
            $harvestCollectedAny = true;
        }

        $pages = max(1, (int)($json['total_pages'] ?? 1));
        $page++;
    } while ($page <= $pages);

    // Fallback for accounts/tokens that cannot list projects but can read time entries.
    if (!$harvestCollectedAny) {
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
                $warnings[] = "[$label fallback/time_entries] " . $e->getMessage();
                break;
            }

            foreach (($json['time_entries'] ?? []) as $te) {
                if (!is_array($te)) {
                    continue;
                }
                $name = trim((string)($te['project']['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $local = $upsertProject($name);
                $ensureMapping($local, 'harvest_projects', $name);
                $harvestCollectedAny = true;
            }

            $pages = max(1, (int)($json['total_pages'] ?? 1));
            $page++;
        } while ($page <= $pages);
    }
}

// ClickUp: collect project-like names (spaces, folders, lists).
$clickupNames = [];
foreach (($config['integrations']['clickup'] ?? []) as $idx => $conn) {
    if (!is_array($conn)) {
        continue;
    }

    $token = (string)($conn['token'] ?? '');
    $rawTeamIds = $conn['team_id'] ?? [];
    $teamIds = is_array($rawTeamIds) ? $rawTeamIds : [$rawTeamIds];
    $teamIds = array_values(array_filter(array_map('strval', $teamIds), static fn($v) => $v !== ''));
    $label = (string)($conn['name'] ?? "clickup[$idx]");
    if ($token === '' || $teamIds === []) {
        continue;
    }

    foreach ($teamIds as $teamId) {
        try {
            $spaceJson = httpGetJson(
                'https://api.clickup.com/api/v2/team/' . rawurlencode($teamId) . '/space?archived=false',
                [
                    'Authorization: ' . $token,
                    'Accept: application/json',
                ]
            );
        } catch (RuntimeException $e) {
            $warnings[] = "[$label/team:$teamId] " . $e->getMessage();
            continue;
        }

        foreach (($spaceJson['spaces'] ?? []) as $space) {
            if (!is_array($space)) {
                continue;
            }

            $spaceId = (string)($space['id'] ?? '');
            $spaceName = trim((string)($space['name'] ?? ''));
            if ($spaceName !== '') {
                $clickupNames[$spaceName] = true;
            }
            if ($spaceId === '') {
                continue;
            }

            try {
                $foldersJson = httpGetJson(
                    'https://api.clickup.com/api/v2/space/' . rawurlencode($spaceId) . '/folder?archived=false',
                    [
                        'Authorization: ' . $token,
                        'Accept: application/json',
                    ]
                );
            } catch (RuntimeException $e) {
                $warnings[] = "[$label/space:$spaceId] " . $e->getMessage();
                $foldersJson = ['folders' => []];
            }

            foreach (($foldersJson['folders'] ?? []) as $folder) {
                if (!is_array($folder)) {
                    continue;
                }
                $folderId = (string)($folder['id'] ?? '');
                $folderName = trim((string)($folder['name'] ?? ''));
                if ($folderName !== '') {
                    $clickupNames[$folderName] = true;
                }
                if ($folderId === '') {
                    continue;
                }

                try {
                    $folderListsJson = httpGetJson(
                        'https://api.clickup.com/api/v2/folder/' . rawurlencode($folderId) . '/list?archived=false',
                        [
                            'Authorization: ' . $token,
                            'Accept: application/json',
                        ]
                    );
                } catch (RuntimeException $e) {
                    $warnings[] = "[$label/folder:$folderId] " . $e->getMessage();
                    $folderListsJson = ['lists' => []];
                }

                foreach (($folderListsJson['lists'] ?? []) as $list) {
                    if (!is_array($list)) {
                        continue;
                    }
                    $listName = trim((string)($list['name'] ?? ''));
                    if ($listName !== '') {
                        $clickupNames[$listName] = true;
                    }
                }
            }

            try {
                $spaceListsJson = httpGetJson(
                    'https://api.clickup.com/api/v2/space/' . rawurlencode($spaceId) . '/list?archived=false',
                    [
                        'Authorization: ' . $token,
                        'Accept: application/json',
                    ]
                );
            } catch (RuntimeException $e) {
                $warnings[] = "[$label/space:$spaceId folderless] " . $e->getMessage();
                $spaceListsJson = ['lists' => []];
            }

            foreach (($spaceListsJson['lists'] ?? []) as $list) {
                if (!is_array($list)) {
                    continue;
                }
                $listName = trim((string)($list['name'] ?? ''));
                if ($listName !== '') {
                    $clickupNames[$listName] = true;
                }
            }
        }
    }
}

foreach (array_keys($clickupNames) as $name) {
    if (strlen($name) < 3) {
        continue;
    }

    $local = $upsertProject($name);
    $ensureMapping($local, 'clickup_tasks', '*' . $name . '*');
}

$backupDir = TOOL_ROOT . '/reports/config';
if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
    fwrite(STDERR, "error: failed to create reports/config backup directory\n");
    exit(1);
}

$backup = $backupDir . '/config.sync.' . date('Ymd\\THis_u') . '.json';
if (!copy($configPath, $backup)) {
    fwrite(STDERR, "error: failed to create backup\n");
    exit(1);
}

file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

echo "Backup: $backup\n";
echo 'Added projects: ' . count(array_unique($addedProjects)) . "\n";
echo 'Updated mappings: ' . $updatedMappings . "\n";
if ($warnings !== []) {
    echo "Warnings:\n";
    foreach ($warnings as $w) {
        echo '- ' . $w . "\n";
    }
}
