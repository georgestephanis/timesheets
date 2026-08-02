<?php
// Ndizi Project Management integration for timesheets
// Mirrors clockify.php in structure

/**
 * Resolves the authenticated Ndizi (WordPress) user's numeric ID via WordPress core's
 * standard REST "whoami" endpoint — Ndizi itself has no dedicated one.
 *
 * @param array $conn Ndizi connection config
 * @param int $timeout HTTP timeout in seconds
 * @return ?string User ID on success, null on failure
 */
function resolveNdiziUserId(array $conn, int $timeout = 20): ?string
{
    $siteUrl = rtrim((string)($conn['site_url'] ?? ''), '/');
    $username = (string)($conn['username'] ?? '');
    $appPassword = (string)($conn['app_password'] ?? '');

    if ($siteUrl === '' || $username === '' || $appPassword === '') {
        return null;
    }

    try {
        $json = httpGetJson($siteUrl . '/wp-json/wp/v2/users/me', [
            'Authorization: Basic ' . base64_encode("$username:$appPassword"),
            'Accept: application/json',
        ], $timeout);
        $userId = (string)($json['id'] ?? '');
        return $userId !== '' ? $userId : null;
    } catch (RuntimeException) {
        return null;
    }
}

/**
 * Loads Ndizi time entries for a date range
 *
 * @param array $conn Ndizi connection config
 * @param DateTimeImmutable $from Start date
 * @param DateTimeImmutable $to End date
 * @param int $timeout HTTP timeout in seconds
 * @return array List of normalized time entry rows
 */
function loadNdiziTimeEntries(array $conn, DateTimeImmutable $from, DateTimeImmutable $to, int $timeout = 20): array
{
    $siteUrl = rtrim((string)($conn['site_url'] ?? ''), '/');
    $username = (string)($conn['username'] ?? '');
    $appPassword = (string)($conn['app_password'] ?? '');
    $userId = (string)($conn['user_id'] ?? '');

    if ($siteUrl === '' || $username === '' || $appPassword === '') {
        return [];
    }

    $headers = [
        'Authorization: Basic ' . base64_encode("$username:$appPassword"),
        'Accept: application/json',
    ];

    $rows = [];
    $page = 1;
    $pageSize = 100;
    $maxPages = 100;

    do {
        $params = [
            'start_date' => $from->format('Y-m-d'),
            'end_date'   => $to->format('Y-m-d'),
            'per_page'   => $pageSize,
            'page'       => $page,
        ];
        if ($userId !== '') {
            $params['user_id'] = $userId;
        }

        $url = $siteUrl . '/wp-json/ndizi/v1/time?' . http_build_query($params);

        $json = httpGetJson($url, $headers, $timeout);

        // No need to check as httpGetJson always returns an array

        $hasMorePages = false;

        foreach ($json as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $startStr = (string)($entry['start_time'] ?? '');
            $endStr = (string)($entry['end_time'] ?? '');

            // Skip running timers (no end time)
            if ($startStr === '' || $endStr === '') {
                continue;
            }

            $start = new DateTimeImmutable($startStr);
            $end = new DateTimeImmutable($endStr);

            $duration = (int)($entry['duration'] ?? ($end->getTimestamp() - $start->getTimestamp()));

            $projectHint = trim((string)($entry['project_name'] ?? ''));
            $description = trim((string)($entry['description'] ?? ''));

            $label = $projectHint;
            if ($description !== '') {
                $label .= ($label !== '' ? ' / ' : '') . $description;
            }

            $rows[] = [
                'source'           => 'ndizi',
                'connection'       => (string)($conn['name'] ?? 'Ndizi'),
                'start'            => $start,
                'end'              => $end,
                'seconds'          => $duration,
                'project_hint'     => $projectHint,
                'label'            => $label,
                'entry_count'      => 1,
                'activity_count'   => 1,
                'discussion_count' => $description !== '' ? 1 : 0,
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
