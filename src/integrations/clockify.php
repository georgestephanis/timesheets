<?php
// Clockify integration for timesheets
// Mirrors harvest.php in structure

/**
 * Resolves Clockify user info (user ID and workspace ID) from the API
 *
 * @param array $conn Clockify connection config
 * @param int $timeout HTTP timeout in seconds
 * @return ?array ['user_id' => string, 'workspace_id' => string] on success, null on failure
 */
function resolveClockifyUserInfo(array $conn, int $timeout = 20): ?array
{
    $apiKey = (string)($conn['api_key'] ?? '');
    if ($apiKey === '') {
        return null;
    }
    try {
        $json = httpGetJson('https://api.clockify.me/api/v1/user', [
            'X-Api-Key: ' . $apiKey,
            'Accept: application/json',
        ], $timeout);
        $userId      = (string)($json['id']                   ?? '');
        $workspaceId = (string)($json['defaultWorkspace']     ?? '');
        if ($userId === '' || $workspaceId === '') {
            return null;
        }
        return ['user_id' => $userId, 'workspace_id' => $workspaceId];
    } catch (RuntimeException) {
        return null;
    }
}

/**
 * Loads Clockify time entries for a date range
 *
 * @param array $conn Clockify connection config
 * @param DateTimeImmutable $from Start date
 * @param DateTimeImmutable $to End date
 * @param int $timeout HTTP timeout in seconds
 * @return array List of normalized time entry rows
 */
function loadClockifyTimeEntries(array $conn, DateTimeImmutable $from, DateTimeImmutable $to, int $timeout = 20): array
{
    $apiKey = (string)($conn['api_key'] ?? '');
    $userId = (string)($conn['user_id'] ?? '');
    $workspaceId = (string)($conn['workspace_id'] ?? '');

    if ($apiKey === '' || $userId === '' || $workspaceId === '') {
        return [];
    }

    // Fetch all project names upfront as a single bulk request (ID → name map).
    $projectNames = [];
    $projPage = 1;
    $projPageSize = 50;
    do {
        try {
            $projJson = httpGetJson(
                "https://api.clockify.me/api/v1/workspaces/{$workspaceId}/projects?page={$projPage}&pageSize={$projPageSize}",
                ['X-Api-Key: ' . $apiKey, 'Accept: application/json'],
                $timeout
            );
            foreach ($projJson as $p) {
                if (isset($p['id'], $p['name'])) {
                    $projectNames[(string)$p['id']] = (string)$p['name'];
                }
            }
            $projPage++;
        } catch (RuntimeException) {
            break;
        }
    } while (count($projJson) === $projPageSize);

    $rows = [];
    $page = 1;
    $pageSize = 50;
    $maxPages = 100;

    do {
        $url = "https://api.clockify.me/api/v1/workspaces/{$workspaceId}/user/{$userId}/time-entries?" .
               "start=" . urlencode($from->format('c')) . "&" .
               "end=" . urlencode($to->format('c')) . "&" .
               "page={$page}&pageSize={$pageSize}";

        $headers = [
            'X-Api-Key: ' . $apiKey,
            'Accept: application/json',
        ];

        $json = httpGetJson($url, $headers, $timeout);

        // No need to check as httpGetJson always returns an array

        $hasMorePages = false;

        foreach ($json as $entry) {
            // Skip running timers (no end time)
            if (!isset($entry['timeInterval']['end']) || !$entry['timeInterval']['end']) {
                continue;
            }

            $startStr = $entry['timeInterval']['start'] ?? '';
            $endStr = $entry['timeInterval']['end'] ?? '';

            if ($startStr === '' || $endStr === '') {
                continue;
            }

            // Parse timestamps
            $start = new DateTimeImmutable($startStr);
            $end = new DateTimeImmutable($endStr);

            // Parse ISO 8601 duration (e.g. PT1H30M); fall back to end - start.
            $durationStr = (string)($entry['timeInterval']['duration'] ?? '');
            if ($durationStr !== '') {
                try {
                    $iv = new DateInterval($durationStr);
                    $duration = ($iv->y * 31536000) + ($iv->m * 2592000) + ($iv->d * 86400)
                              + ($iv->h * 3600) + ($iv->i * 60) + $iv->s;
                } catch (Exception) {
                    $duration = $end->getTimestamp() - $start->getTimestamp();
                }
            } else {
                $duration = $end->getTimestamp() - $start->getTimestamp();
            }

            // Resolve project name from the pre-fetched map.
            $projectId   = (string)($entry['projectId'] ?? '');
            $projectHint = $projectId !== '' ? ($projectNames[$projectId] ?? '') : '';

            // Format label
            $label = $projectHint;
            if (!empty($entry['description'])) {
                $label .= ($label !== '' ? ' / ' : '') . $entry['description'];
            }

            // Build row
            $rows[] = [
                'source'           => 'clockify',
                'connection'       => (string)($conn['name'] ?? 'Clockify'),
                'start'            => $start,
                'end'              => $end,
                'seconds'          => $duration,
                'project_hint'     => $projectHint,
                'label'            => $label,
                'entry_count'      => 1,
                'activity_count'   => 1,
                'discussion_count' => !empty($entry['description']) ? 1 : 0,
            ];
        }

        // Check if we need to fetch more pages
        $hasMorePages = count($json) === $pageSize;
        $page++;

        // Safety check to prevent infinite loops
        if ($page > $maxPages) {
            break;
        }
    } while ($hasMorePages);

    return $rows;
}
