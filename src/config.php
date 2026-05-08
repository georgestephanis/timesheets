<?php

declare(strict_types=1);

/**
 * Shared config-file helpers: atomic save with backup, signal-application, and parsing utilities.
 *
 * Used by api.php, cli.php, and every tool in tools/. All callers rely on these rather
 * than maintaining their own backup+write implementations.
 */

/**
 * Saves $config to $configFile atomically with a timestamped backup.
 *
 * Writes the JSON to a temp file, then renames it over $configFile so readers
 * never observe a partial write. The backup is created before any write so the
 * original is recoverable if encoding or rename fails.
 *
 * @param  array  $config     Config array to serialize.
 * @param  string $configFile Absolute path to config.json.
 * @param  string $source     Tag embedded in the backup filename (e.g. 'api', 'sync', 'remotes').
 * @return string             Absolute path of the backup file that was created.
 * @throws RuntimeException   On any I/O failure.
 */
function saveConfigWithBackup(array $config, string $configFile, string $source): string
{
    $backupDir = dirname($configFile) . '/reports/config';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
        throw new RuntimeException('Failed to create config backup directory');
    }

    $stamp = (new DateTimeImmutable('now'))->format('Ymd\THis_u');
    $backupPath = $backupDir . '/config.' . $source . '.' . $stamp . '.json';
    if (!copy($configFile, $backupPath)) {
        throw new RuntimeException('Failed to backup config.json');
    }

    $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        throw new RuntimeException('Failed to encode config.json: ' . json_last_error_msg());
    }
    $json = $encoded . "\n";

    $tmpFile = $configFile . '.tmp.' . getmypid();
    if (file_put_contents($tmpFile, $json, LOCK_EX) === false) {
        throw new RuntimeException('Failed to write temporary config file');
    }
    if (!rename($tmpFile, $configFile)) {
        @unlink($tmpFile);
        throw new RuntimeException('Failed to atomically update config.json');
    }

    return $backupPath;
}

/**
 * Appends $value to $arr[$key] if not already present.
 *
 * @param array  $arr   Array modified in place (e.g. a project sub-array or $config itself).
 * @param string $key   Key whose value is an array of strings.
 * @param string $value Value to add if absent.
 */
function addUniqueValue(array &$arr, string $key, string $value): void
{
    $arr[$key] = $arr[$key] ?? [];
    if (!in_array($value, $arr[$key], true)) {
        $arr[$key][] = $value;
    }
}

/**
 * Parses a "Workspace / channel" Slack signal label into its two parts.
 *
 * @return array{workspace: string, channel: string}|null  Null when the format is invalid.
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

/**
 * Applies one signal-to-project assignment to the config array in place.
 *
 * Handles all four signal kinds: vscode, browser, slack, apps (including ssh: prefix).
 * No-ops silently when the project is unknown, the value is already present, or the input
 * cannot be parsed (e.g. a malformed slack signal or a browser signal without a host).
 *
 * This is the canonical implementation used by api.php, cli.php (--suggest), and any other
 * caller that mutates project signal lists. Callers that need HTTP error responses (api.php)
 * should validate the input before calling and handle the error themselves — this function
 * is deliberately silent on invalid input so CLI callers do not crash on bad LLM suggestions.
 *
 * @param array<string, mixed> $config  Full config array modified in place.
 * @param string               $kind    One of: vscode, browser, slack, apps.
 * @param string               $value   Signal value (e.g. folder name, hostname, "ssh:host").
 * @param string               $project Exact project name key in $config['projects'].
 */
function applySignalToProject(array &$config, string $kind, string $value, string $project): void
{
    if (!isset($config['projects'][$project])) {
        return;
    }
    $p =& $config['projects'][$project];

    switch ($kind) {
        case 'vscode':
            addUniqueValue($p, 'vscode_dirs', $value);
            break;

        case 'browser':
            if ($value === '' || $value === '(no url)') {
                break;
            }
            addUniqueValue($p, 'domains', $value);
            break;

        case 'slack':
            $parsed = parseSlackSignal($value);
            if ($parsed === null) {
                break;
            }
            $p['slack'] = $p['slack'] ?? [];
            $rule = ['workspace' => $parsed['workspace']];
            if (!in_array($parsed['channel'], ['__threads__', '__activity__', '__huddle__'], true)) {
                $rule['channel_glob'] = $parsed['channel'];
            }
            foreach ($p['slack'] as $existing) {
                if (
                    ($existing['workspace'] ?? null) === $rule['workspace']
                    && ($existing['channel_glob'] ?? null) === ($rule['channel_glob'] ?? null)
                ) {
                    return;
                }
            }
            $p['slack'][] = $rule;
            break;

        case 'apps':
            if (str_starts_with($value, 'ssh:')) {
                addUniqueValue($p, 'ssh_hosts', substr($value, 4));
            } else {
                addUniqueValue($p, 'apps', $value);
            }
            break;
    }
}
