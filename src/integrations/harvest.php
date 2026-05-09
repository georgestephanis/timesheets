<?php

declare(strict_types=1);

/**
 * Fetches the authenticated Harvest user's ID from /v2/users/me.
 * Returns null on any failure so callers can treat it as optional.
 *
 * @param  array $conn Harvest connection config (needs token + account_id).
 * @return int|null
 */
function resolveHarvestUserId(array $conn, int $timeout = 20): ?int
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
        ], $timeout);
        $id = $json['id'] ?? null;
        return is_int($id) ? $id : (is_numeric($id) ? (int)$id : null);
    } catch (RuntimeException) {
        return null;
    }
}

/**
 * Loads Harvest time entries for one connection.
 *
 * @param  array             $conn Connection config.
 * @param  DateTimeImmutable $from
 * @param  DateTimeImmutable $to
 * @return list<array<string, mixed>>
 */
function loadHarvestTimeEntries(array $conn, DateTimeImmutable $from, DateTimeImmutable $to, int $timeout = 20): array
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
        ], $timeout);

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
                'client_name' => $clientName,
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
    } while ($page <= max(1, $pageCount) && $page <= 100);

    return $rows;
}

/**
 * Fetches active Harvest task assignments for one connection.
 *
 * Returns a map of harvest_project_name => {client, tasks} covering all active
 * task assignments in the account. Used by llmSuggestTimeLogging() to give the
 * LLM a concrete list of valid project/task names and to correlate BOL projects
 * by Harvest client name.
 *
 * @param  array<string, mixed> $conn
 * @return array<string, array{client: string, tasks: list<string>}>
 */
function fetchHarvestTaskAssignments(array $conn, int $timeout = 20): array
{
    $token     = (string)($conn['token']      ?? '');
    $accountId = (string)($conn['account_id'] ?? '');
    if ($token === '' || $accountId === '') {
        return [];
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Harvest-Account-ID: ' . $accountId,
        'User-Agent: activity-report',
        'Accept: application/json',
    ];

    $result = [];
    $page   = 1;

    do {
        try {
            $json = httpGetJson(
                'https://api.harvestapp.com/v2/task_assignments?is_active=true&per_page=100&page=' . $page,
                $headers,
                $timeout
            );
        } catch (RuntimeException) {
            break;
        }

        foreach (($json['task_assignments'] ?? []) as $ta) {
            if (!is_array($ta)) {
                continue;
            }
            $projName   = trim((string)($ta['project']['name']          ?? ''));
            $clientName = trim((string)($ta['project']['client']['name'] ?? ''));
            $taskName   = trim((string)($ta['task']['name']              ?? ''));
            if ($projName === '' || $taskName === '') {
                continue;
            }
            if (!isset($result[$projName])) {
                $result[$projName] = ['client' => $clientName, 'tasks' => []];
            }
            $result[$projName]['tasks'][] = $taskName;
        }

        $page++;
        $totalPages = (int)($json['total_pages'] ?? 1);
    } while ($page <= $totalPages && $page <= 50);

    return $result;
}
