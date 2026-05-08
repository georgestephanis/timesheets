<?php

declare(strict_types=1);

/**
 * External integrations loader orchestration.
 */

require_once __DIR__ . '/integrations/shared.php';
require_once __DIR__ . '/integrations/harvest.php';
require_once __DIR__ . '/integrations/clickup.php';
require_once __DIR__ . '/integrations/github.php';

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

    foreach (($integrations['harvest'] ?? []) as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $label = (string)($conn['name'] ?? "harvest[$idx]");
        // Auto-resolve user_id from /v2/users/me when absent/non-standard, then persist it.
        if (!idLooksStandard($conn['user_id'] ?? null)) {
            $resolved = resolveHarvestUserId($conn);
            if ($resolved !== null) {
                $conn['user_id'] = $resolved;
                $config['integrations']['harvest'][$idx]['user_id'] = $resolved;
                $configDirty = true;
            }
        }
        try {
            foreach (loadHarvestTimeEntries($conn, $from, $to) as $row) {
                $rows[] = $row;
            }
        } catch (RuntimeException $e) {
            integrationWarning("[$label] " . $e->getMessage());
        }
    }

    foreach (($integrations['clickup'] ?? []) as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $label = (string)($conn['name'] ?? "clickup[$idx]");
        // Auto-resolve assignee from /api/v2/user when absent/non-standard, then persist it.
        if (!idLooksStandard($conn['assignee'] ?? null)) {
            $resolved = resolveClickUpUserId($conn);
            if ($resolved !== null) {
                $conn['assignee'] = $resolved;
                $config['integrations']['clickup'][$idx]['assignee'] = $resolved;
                $configDirty = true;
            }
        }
        try {
            foreach (loadClickUpTimeEntries($conn, $from, $to) as $row) {
                $rows[] = $row;
            }
        } catch (RuntimeException $e) {
            integrationWarning("[$label] " . $e->getMessage());
        }
    }

    foreach (($integrations['github'] ?? []) as $idx => $conn) {
        if (!is_array($conn)) {
            continue;
        }
        $label = (string)($conn['name'] ?? "github[$idx]");
        if (PHP_SAPI !== 'cli') {
            integrationWarning("[$label] skipped in web requests; prebuild daily caches via CLI to include GitHub activity");
            continue;
        }
        try {
            foreach (loadGitHubActivity($conn, $config, $from, $to) as $row) {
                $rows[] = $row;
            }
        } catch (RuntimeException $e) {
            integrationWarning("[$label] " . $e->getMessage());
        }
    }

    if ($configDirty) {
        $configFile = PROJECT_ROOT . '/config.json';
        $existing = is_file($configFile) ? (json_decode((string)file_get_contents($configFile), true) ?? []) : [];
        foreach (($config['integrations']['harvest'] ?? []) as $idx => $conn) {
            if (isset($conn['user_id']) && is_array($existing['integrations']['harvest'][$idx] ?? null)) {
                $existing['integrations']['harvest'][$idx]['user_id'] = $conn['user_id'];
            }
        }
        foreach (($config['integrations']['clickup'] ?? []) as $idx => $conn) {
            if (isset($conn['assignee']) && is_array($existing['integrations']['clickup'][$idx] ?? null)) {
                $existing['integrations']['clickup'][$idx]['assignee'] = (string)$conn['assignee'];
            }
        }

        if (is_file($configFile) && backupConfigSnapshot($configFile, 'integrations') === null) {
            integrationWarning('failed to create config backup in reports/config before auto-save');
        }
        file_put_contents($configFile, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
    return $rows;
}

/**
 * Emits a warning in both CLI and web contexts, and collects it for the current request.
 */
function integrationWarning(string $message): void
{
    global $_integrationWarnings;
    $_integrationWarnings[] = $message;

    $line = 'warning: ' . $message;
    if (defined('STDERR')) {
        fwrite(STDERR, $line . "\n");
        return;
    }

    error_log($line);
}

/**
 * Returns all warnings collected by integrationWarning() during this request.
 *
 * @return list<string>
 */
function getIntegrationWarnings(): array
{
    global $_integrationWarnings;
    return $_integrationWarnings ?? [];
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
