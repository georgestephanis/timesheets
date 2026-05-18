<?php

declare(strict_types=1);

/**
 * External integrations loader orchestration.
 */

require_once __DIR__ . '/integrations/shared.php';
require_once __DIR__ . '/integrations/harvest.php';
require_once __DIR__ . '/integrations/harvest-catalog.php';
require_once __DIR__ . '/integrations/clickup.php';
require_once __DIR__ . '/integrations/clickup-catalog.php';
require_once __DIR__ . '/integrations/github.php';
require_once __DIR__ . '/integrations/clockify.php';
require_once __DIR__ . '/integrations/clockify-catalog.php';

/**
 * Loads external integration activity rows from configured providers.
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of query window.
 * @param  DateTimeImmutable $to     End of query window.
 * @return list<array<string, mixed>>
 */
function loadIntegrationActivity(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $rows = [];
    $integrations = $config['integrations'] ?? [];
    $configDirty = false;
    $httpTimeout = (int)($config['integration_http_timeout_seconds'] ?? 20);

    foreach (($integrations['harvest'] ?? []) as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $label = (string)($conn['name'] ?? "harvest[$idx]");
        // Auto-resolve user_id from /v2/users/me when absent/non-standard, then persist it.
        if (!idLooksStandard($conn['user_id'] ?? null)) {
            $resolved = resolveHarvestUserId($conn, $httpTimeout);
            if ($resolved !== null) {
                $conn['user_id'] = $resolved;
                $config['integrations']['harvest'][$idx]['user_id'] = $resolved;
                $configDirty = true;
            }
        }
        try {
            foreach (loadHarvestTimeEntries($conn, $from, $to, $httpTimeout) as $row) {
                $rows[] = $row;
            }
        } catch (RuntimeException $e) {
            warning('integrations', "[$label] " . $e->getMessage());
        }
    }

    foreach (($integrations['clickup'] ?? []) as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $label = (string)($conn['name'] ?? "clickup[$idx]");
        // Auto-resolve assignee from /api/v2/user when absent/non-standard, then persist it.
        if (!idLooksStandard($conn['assignee'] ?? null)) {
            $resolved = resolveClickUpUserId($conn, $httpTimeout);
            if ($resolved !== null) {
                $conn['assignee'] = $resolved;
                $config['integrations']['clickup'][$idx]['assignee'] = $resolved;
                $configDirty = true;
            }
        }
        try {
            foreach (loadClickUpTimeEntries($conn, $from, $to, $httpTimeout) as $row) {
                $rows[] = $row;
            }
        } catch (RuntimeException $e) {
            warning('integrations', "[$label] " . $e->getMessage());
        }
    }

    foreach (($integrations['github'] ?? []) as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $label = (string)($conn['name'] ?? "github[$idx]");
        if (PHP_SAPI !== 'cli') {
            warning('integrations', "[$label] skipped in web requests; prebuild daily caches via CLI to include GitHub activity");
            continue;
        }
        try {
            foreach (loadGitHubActivity($conn, $config, $from, $to) as $row) {
                $rows[] = $row;
            }
        } catch (RuntimeException $e) {
            warning('integrations', "[$label] " . $e->getMessage());
        }
    }

    // Clockify integration
    foreach (($integrations['clockify'] ?? []) as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $label = (string)($conn['name'] ?? "clockify[$idx]");
        // Auto-resolve user_id and workspace_id from /v1/user when absent/non-standard, then persist.
        if (!idLooksStandard($conn['user_id'] ?? null) || !idLooksStandard($conn['workspace_id'] ?? null)) {
            $resolved = resolveClockifyUserInfo($conn, $httpTimeout);
            if ($resolved !== null) {
                $conn['user_id']                                          = $resolved['user_id'];
                $conn['workspace_id']                                     = $resolved['workspace_id'];
                $config['integrations']['clockify'][$idx]['user_id']      = $resolved['user_id'];
                $config['integrations']['clockify'][$idx]['workspace_id'] = $resolved['workspace_id'];
                $configDirty = true;
            }
        }
        try {
            foreach (loadClockifyTimeEntries($conn, $from, $to, $httpTimeout) as $row) {
                $rows[] = $row;
            }
        } catch (RuntimeException $e) {
            warning('integrations', "[$label] " . $e->getMessage());
        }
    }

    if ($configDirty) {
        $configFile = PROJECT_ROOT . '/config.json';
        $existing = is_file($configFile) ? (json_decode((string)file_get_contents($configFile), true) ?? []) : [];

        // Match by connection name rather than array index to survive reordering.
        foreach (($config['integrations']['harvest'] ?? []) as $conn) {
            if (!isset($conn['user_id'], $conn['name'])) {
                continue;
            }
            foreach (($existing['integrations']['harvest'] ?? []) as &$existingConn) {
                if (is_array($existingConn) && ($existingConn['name'] ?? null) === $conn['name']) {
                    $existingConn['user_id'] = $conn['user_id'];
                    break;
                }
            }
            unset($existingConn);
        }
        foreach (($config['integrations']['clickup'] ?? []) as $conn) {
            if (!isset($conn['assignee'], $conn['name'])) {
                continue;
            }
            foreach (($existing['integrations']['clickup'] ?? []) as &$existingConn) {
                if (is_array($existingConn) && ($existingConn['name'] ?? null) === $conn['name']) {
                    $existingConn['assignee'] = (string)$conn['assignee'];
                    break;
                }
            }
            unset($existingConn);
        }
        foreach (($config['integrations']['clockify'] ?? []) as $conn) {
            if (!isset($conn['user_id'], $conn['workspace_id'], $conn['name'])) {
                continue;
            }
            foreach (($existing['integrations']['clockify'] ?? []) as &$existingConn) {
                if (is_array($existingConn) && ($existingConn['name'] ?? null) === $conn['name']) {
                    $existingConn['user_id']      = (string)$conn['user_id'];
                    $existingConn['workspace_id'] = (string)$conn['workspace_id'];
                    break;
                }
            }
            unset($existingConn);
        }

        if (is_file($configFile) && backupConfigSnapshot($configFile, 'integrations') === null) {
            warning('integrations', 'failed to create config backup in reports/config before auto-save');
        }
        $json = json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tmpFile = $configFile . '.tmp.' . getmypid();
        if (file_put_contents($tmpFile, $json, LOCK_EX) !== false) {
            rename($tmpFile, $configFile);
        } else {
            @unlink($tmpFile);
            warning('integrations', 'failed to write config.json during auto-save of resolved IDs');
        }
    }

    usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
    return $rows;
}

/**
 * Returns all warnings collected during this request.
 *
 * Alias for getWarnings() — kept for backwards compatibility.
 *
 * @return list<string>
 */
function getIntegrationWarnings(): array
{
    return getWarnings();
}

/**
 * Creates a timestamped config snapshot in reports/config.
 *
 * @return string|null Backup path on success, null on failure.
 */
function backupConfigSnapshot(string $configFile, string $source): ?string
{
    $backupDir = PROJECT_ROOT . '/reports/config';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
        return null;
    }

    $stamp = (new DateTimeImmutable('now'))->format('Ymd\\THis_u');
    $backupPath = $backupDir . '/config.' . $source . '.' . $stamp . '.json';
    return copy($configFile, $backupPath) ? $backupPath : null;
}
