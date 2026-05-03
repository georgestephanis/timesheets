<?php

declare(strict_types=1);

/**
 * External integrations loader: Harvest + ClickUp.
 */

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
 * Emits a warning in both CLI and web contexts.
 */
function integrationWarning(string $message): void
{
    $line = 'warning: ' . $message;
    if (defined('STDERR')) {
        fwrite(STDERR, $line . "\n");
        return;
    }

    error_log($line);
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

/**
 * True when an ID looks like a numeric account/user identifier.
 */
function idLooksStandard(mixed $value): bool
{
    if (is_int($value)) {
        return $value > 0;
    }

    if (is_string($value)) {
        return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
    }

    return false;
}

/**
 * Fetches the authenticated Harvest user's ID from /v2/users/me.
 * Returns null on any failure so callers can treat it as optional.
 *
 * @param  array $conn Harvest connection config (needs token + account_id).
 * @return int|null
 */
function resolveHarvestUserId(array $conn): ?int
{
    $token = (string)($conn['token'] ?? '');
    $accountId = (string)($conn['account_id'] ?? '');
    if ($token === '' || $accountId === '') {
        return null;
    }

    try {
        $json = httpGetJson('https://api.harvestapp.com/v2/users/me', [
            'Authorization: Bearer ' . $token,
            'Harvest-Account-ID: ' . $accountId,
            'User-Agent: activity-report',
            'Accept: application/json',
        ]);
        $id = $json['id'] ?? null;
        return is_int($id) ? $id : (is_numeric($id) ? (int)$id : null);
    } catch (RuntimeException $e) {
        return null;
    }
}

/**
 * Fetches the authenticated ClickUp user's ID from /api/v2/user.
 * Returns null on failure so callers can treat it as optional.
 *
 * @param  array $conn ClickUp connection config (needs token).
 * @return string|null
 */
function resolveClickUpUserId(array $conn): ?string
{
    $token = (string)($conn['token'] ?? '');
    if ($token === '') {
        return null;
    }

    try {
        $json = httpGetJson('https://api.clickup.com/api/v2/user', [
            'Authorization: ' . $token,
            'Accept: application/json',
        ]);
        $id = $json['user']['id'] ?? null;
        return idLooksStandard($id) ? (string)$id : null;
    } catch (RuntimeException $e) {
        return null;
    }
}

/**
 * Loads GitHub commit activity for repos associated with configured projects.
 *
 * @param  array             $conn   GitHub integration config.
 * @param  array             $config Full app config.
 * @param  DateTimeImmutable $from
 * @param  DateTimeImmutable $to
 * @return list<array<string, mixed>>
 */
function loadGitHubActivity(array $conn, array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $rows = [];
    $seen = [];

    $reposByProject = githubReposByProject($config);
    if ($reposByProject === []) {
        return [];
    }

    $authors = $conn['authors'] ?? ($config['git_authors'] ?? []);
    if (!is_array($authors) || $authors === []) {
        return [];
    }
    $authors = array_values(array_filter(array_map('strval', $authors), fn($a) => $a !== ''));
    if ($authors === []) {
        return [];
    }

    $connection = (string)($conn['name'] ?? 'github');
    foreach ($reposByProject as $project => $repos) {
        foreach (array_keys($repos) as $repoFullName) {
            foreach ($authors as $author) {
                $path = '/repos/' . $repoFullName . '/commits?' . http_build_query([
                    'since' => $from->setTimezone(new DateTimeZone('UTC'))->format('c'),
                    'until' => $to->setTimezone(new DateTimeZone('UTC'))->format('c'),
                    'author' => $author,
                    'per_page' => 100,
                ]);

                $json = githubGetJson($path, $conn);
                if (!is_array($json)) {
                    continue;
                }

                foreach ($json as $commit) {
                    if (!is_array($commit)) {
                        continue;
                    }
                    $sha = (string)($commit['sha'] ?? '');
                    if ($sha === '') {
                        continue;
                    }
                    if (isset($seen[$repoFullName . '#' . $sha])) {
                        continue;
                    }
                    $seen[$repoFullName . '#' . $sha] = true;

                    $iso = (string)($commit['commit']['author']['date'] ?? '');
                    if ($iso === '') {
                        continue;
                    }
                    try {
                        $start = new DateTimeImmutable($iso);
                    } catch (Throwable) {
                        continue;
                    }

                    $message = trim((string)($commit['commit']['message'] ?? 'GitHub commit'));
                    $rows[] = [
                        'source' => 'github',
                        'connection' => $connection,
                        'project' => $project,
                        'start' => $start,
                        'end' => $start,
                        // Keep as 0 so this contributes counts/activity metadata, not tracked time.
                        'seconds' => 0,
                        'project_hint' => $repoFullName,
                        'label' => $repoFullName . ': ' . strtok($message, "\n"),
                        'entry_count' => 1,
                        'activity_count' => 1,
                        'discussion_count' => 0,
                    ];
                }
            }
        }
    }

    return $rows;
}

/**
 * Calls GitHub REST API using gh CLI auth when available, with token fallback.
 *
 * @return array<mixed>
 */
function githubGetJson(string $pathWithQuery, array $conn): array
{
    $token = (string)($conn['token'] ?? '');

    // Prefer explicit token when configured.
    if ($token !== '') {
        $url = 'https://api.github.com' . $pathWithQuery;
        return httpGetJson($url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: activity-report',
        ]);
    }

    $gh = trim((string)shell_exec('command -v gh 2>/dev/null'));
    if ($gh === '') {
        throw new RuntimeException('GitHub integration requires gh CLI auth or integrations.github[*].token');
    }

    $cmd = 'gh api ' . escapeshellarg($pathWithQuery) . ' 2>/dev/null';
    $out = shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        throw new RuntimeException('gh api request failed for ' . $pathWithQuery);
    }

    $json = json_decode($out, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON from gh api for ' . $pathWithQuery);
    }

    return $json;
}

/**
 * Builds project => github_repo_full_name set from repo_remotes and local git remotes.
 *
 * @param  array $config Full app config.
 * @return array<string, array<string, bool>>
 */
function githubReposByProject(array $config): array
{
    $result = [];
    foreach (($config['projects'] ?? []) as $project => $p) {
        if (!is_array($p)) {
            continue;
        }

        foreach (($p['repo_remotes'] ?? []) as $repoPath => $remotes) {
            if (!is_array($remotes)) {
                continue;
            }
            foreach ($remotes as $url) {
                if (!is_string($url) || $url === '') {
                    continue;
                }
                $full = githubRepoFromRemoteUrl($url);
                if ($full !== null) {
                    $result[$project][$full] = true;
                }
            }
        }

        // Fallback for projects without repo_remotes snapshots.
        foreach (($p['repos'] ?? []) as $repoPath) {
            if (!is_string($repoPath) || $repoPath === '') {
                continue;
            }
            $abs = expandPath($repoPath);
            if (!is_dir($abs . '/.git')) {
                continue;
            }
            $url = trim((string)shell_exec(
                'git -C ' . escapeshellarg($abs) . ' remote get-url origin 2>/dev/null'
            ));
            if ($url === '') {
                continue;
            }
            $full = githubRepoFromRemoteUrl($url);
            if ($full !== null) {
                $result[$project][$full] = true;
            }
        }
    }

    return $result;
}

/**
 * Parses owner/repo from common GitHub remote URL formats.
 */
function githubRepoFromRemoteUrl(string $url): ?string
{
    if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#i', $url, $m)) {
        return $m[1] . '/' . $m[2];
    }
    return null;
}

/**
 * Performs an HTTP GET request and decodes JSON.
 *
 * @param  string              $url
 * @param  array<int, string>  $headers
 * @return array<string, mixed>
 */
function httpGetJson(string $url, array $headers): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("HTTP request failed: $url");
    }

    $status = 0;
    $line = $http_response_header[0] ?? '';
    if (preg_match('/\s(\d{3})\s/', $line, $m)) {
        $status = (int)$m[1];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON from $url — body: " . substr($raw, 0, 300));
    }
    if ($status < 200 || $status >= 300) {
        // ClickUp wraps errors as {"ECODE":"...","err":"..."}, Harvest uses {"error":"..."}
        $msg = $decoded['error'] ?? $decoded['err'] ?? null;
        if (!is_string($msg) || $msg === '') {
            // Fallback: dump the whole decoded body for context
            $msg = json_encode($decoded);
        }
        throw new RuntimeException("HTTP $status from $url: $msg");
    }

    return $decoded;
}

/**
 * Loads Harvest time entries for one connection.
 *
 * @param  array             $conn Connection config.
 * @param  DateTimeImmutable $from
 * @param  DateTimeImmutable $to
 * @return list<array<string, mixed>>
 */
function loadHarvestTimeEntries(array $conn, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $token = (string)($conn['token'] ?? '');
    $accountId = (string)($conn['account_id'] ?? '');
    if ($token === '' || $accountId === '') {
        return [];
    }

    $name = (string)($conn['name'] ?? 'harvest');
    $userId = $conn['user_id'] ?? null;
    $rows = [];
    $page = 1;

    do {
        $params = [
            'from' => $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
            'to' => $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'),
            'page' => (string)$page,
        ];
        if (idLooksStandard($userId)) {
            $params['user_id'] = (string)$userId;
        }

        $url = 'https://api.harvestapp.com/v2/time_entries?' . http_build_query($params);
        $json = httpGetJson($url, [
            'Authorization: Bearer ' . $token,
            'Harvest-Account-ID: ' . $accountId,
            'User-Agent: activity-report',
            'Accept: application/json',
        ]);

        foreach (($json['time_entries'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }

            $spentDate = (string)($e['spent_date'] ?? '');
            if ($spentDate === '') {
                continue;
            }

            $hours = (float)($e['hours'] ?? 0);
            $seconds = (int)round(max(0.0, $hours * 3600));
            if ($seconds <= 0) {
                continue;
            }

            $projectName = (string)($e['project']['name'] ?? '');
            $clientName = (string)($e['client']['name'] ?? '');
            $taskName = (string)($e['task']['name'] ?? '');
            $notes = trim((string)($e['notes'] ?? ''));

            $start = new DateTimeImmutable($spentDate . ' 12:00:00', new DateTimeZone('UTC'));
            $end = $start->modify('+' . $seconds . ' seconds');

            $labelBits = array_values(array_filter([$clientName, $projectName, $taskName], fn($v) => $v !== ''));
            $label = $labelBits ? implode(' / ', $labelBits) : 'Harvest entry';

            $rows[] = [
                'source' => 'harvest',
                'connection' => $name,
                'start' => $start,
                'end' => $end,
                'seconds' => $seconds,
                'project_hint' => $projectName !== '' ? $projectName : $label,
                'label' => $label,
                'entry_count' => 1,
                'activity_count' => 1,
                'discussion_count' => $notes !== '' ? 1 : 0,
            ];
        }

        $nextPage = (int)($json['next_page'] ?? 0);
        if ($nextPage > 0) {
            $page = $nextPage;
        } else {
            $page++;
        }
        $pageCount = (int)($json['total_pages'] ?? 1);
    } while ($page <= max(1, $pageCount));

    return $rows;
}

/**
 * Loads ClickUp time entries for one connection.
 *
 * @param  array             $conn Connection config.
 * @param  DateTimeImmutable $from
 * @param  DateTimeImmutable $to
 * @return list<array<string, mixed>>
 */
function loadClickUpTimeEntries(array $conn, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $token = (string)($conn['token'] ?? '');
    $rawTeamId = $conn['team_id'] ?? '';
    $teamIds = is_array($rawTeamId) ? $rawTeamId : [$rawTeamId];
    $teamIds = array_values(array_filter(array_map('strval', $teamIds), fn($v) => $v !== ''));
    if ($token === '' || $teamIds === []) {
        return [];
    }

    $name = (string)($conn['name'] ?? 'clickup');
    $assignee = $conn['assignee'] ?? null;
    $rows = [];

    $params = [
        'start_date' => (string)($from->setTimezone(new DateTimeZone('UTC'))->getTimestamp() * 1000),
        'end_date' => (string)($to->setTimezone(new DateTimeZone('UTC'))->getTimestamp() * 1000),
    ];
    if (idLooksStandard($assignee)) {
        $params['assignee'] = $assignee;
    }

    foreach ($teamIds as $teamId) {
        $url = 'https://api.clickup.com/api/v2/team/' . rawurlencode($teamId) . '/time_entries?' . http_build_query($params);
        $json = httpGetJson($url, [
            'Authorization: ' . $token,
            'Accept: application/json',
        ]);

        foreach (($json['data'] ?? []) as $e) {
            if (!is_array($e)) {
                continue;
            }

            $startMs = (int)($e['start'] ?? 0);
            $endMs = (int)($e['end'] ?? 0);
            $durationMs = (int)($e['duration'] ?? 0);
            if ($durationMs <= 0 && $endMs > $startMs) {
                $durationMs = $endMs - $startMs;
            }
            $seconds = (int)round(max(0, $durationMs) / 1000);
            if ($seconds <= 0) {
                continue;
            }

            $start = (new DateTimeImmutable('@' . (int)floor($startMs / 1000)))->setTimezone(new DateTimeZone('UTC'));
            $end = $start->modify('+' . $seconds . ' seconds');

            $taskName = (string)($e['task']['name'] ?? '');
            $description = trim((string)($e['description'] ?? ''));
            $label = $taskName !== '' ? $taskName : ($description !== '' ? $description : 'ClickUp time entry');

            $rows[] = [
                'source' => 'clickup',
                'connection' => $name,
                'start' => $start,
                'end' => $end,
                'seconds' => $seconds,
                'project_hint' => $label,
                'label' => $label,
                'entry_count' => 1,
                'activity_count' => 1,
                'discussion_count' => $description !== '' ? 1 : 0,
            ];
        }
    }

    return $rows;
}
