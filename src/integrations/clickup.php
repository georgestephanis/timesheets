<?php

declare(strict_types=1);

/**
 * Fetches the authenticated ClickUp user's ID from /api/v2/user.
 * Returns null on failure so callers can treat it as optional.
 *
 * @param  array $conn ClickUp connection config (needs token).
 * @return string|null
 */
function resolveClickUpUserId(array $conn, int $timeout = 20): ?string
{
    $token = (string)($conn['token'] ?? '');
    if ($token === '') {
        return null;
    }

    try {
        $json = httpGetJson('https://api.clickup.com/api/v2/user', [
            'Authorization: ' . $token,
            'Accept: application/json',
        ], $timeout);
        $id = $json['user']['id'] ?? null;
        return idLooksStandard($id) ? (string)$id : null;
    } catch (RuntimeException) {
        return null;
    }
}

/**
 * Loads ClickUp time entries for one connection.
 *
 * @param  array             $conn Connection config.
 * @param  DateTimeImmutable $from
 * @param  DateTimeImmutable $to
 * @return list<array<string, mixed>>
 */
function loadClickUpTimeEntries(array $conn, DateTimeImmutable $from, DateTimeImmutable $to, int $timeout = 20): array
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
        $page = 0;
        do {
            $pageParams = $params + ($page > 0 ? ['page' => $page] : []);
            $url = 'https://api.clickup.com/api/v2/team/' . rawurlencode($teamId) . '/time_entries?' . http_build_query($pageParams);
            $json = httpGetJson($url, [
                'Authorization: ' . $token,
                'Accept: application/json',
            ], $timeout);

            $entries = $json['data'] ?? [];
            foreach ($entries as $e) {
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
            $page++;
        } while (count($entries) >= 100 && $page < 100);
    }

    return $rows;
}

/**
 * Fetches ClickUp tasks relevant to the configured user around a given date.
 *
 * Includes:
 *   - Tasks currently assigned to the user (open and recently closed/completed).
 *   - Tasks the user is watching, discovered via the notifications endpoint —
 *     this catches tasks that were reassigned away or moved to QA/waiting but
 *     that the user is still following.
 *
 * Used by llmSuggestTimeLogging() to identify candidate tasks for unlogged time entries.
 * Returns tasks across all configured team IDs; the LLM is responsible for selecting
 * the best match per project.
 *
 * @param  array<string, mixed> $conn
 * @param  int    $timeout
 * @param  string $aroundDate  YYYY-MM-DD — anchors the recency window for closed tasks
 *                             and notification filtering. Defaults to today.
 * @return list<array{id: string, name: string, space: string, folder: string, list: string, status: string}>
 */
function fetchAssignedClickUpTasks(array $conn, int $timeout = 20, string $aroundDate = ''): array
{
    $token    = (string)($conn['token'] ?? '');
    $rawTeams = $conn['team_id'] ?? [];
    $teamIds  = is_array($rawTeams) ? $rawTeams : [$rawTeams];
    $teamIds  = array_values(array_filter(array_map('strval', $teamIds), fn($v) => $v !== ''));
    $assignee = (string)($conn['assignee'] ?? '');

    if ($token === '' || $teamIds === [] || $assignee === '') {
        return [];
    }

    $headers = ['Authorization: ' . $token, 'Accept: application/json'];
    $tasks   = [];
    $seenIds = [];

    // Limit closed-task lookback to 30 days before the target date so we don't
    // drown the LLM in ancient completed work.
    $cutoffMs = '';
    if ($aroundDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $aroundDate)) {
        $cutoff   = (new DateTimeImmutable($aroundDate . ' 00:00:00', new DateTimeZone('UTC')))->modify('-30 days');
        $cutoffMs = (string)($cutoff->getTimestamp() * 1000);
    }

    foreach ($teamIds as $teamId) {
        $page = 0;
        do {
            $params = [
                'assignees[]'    => $assignee,
                'include_closed' => 'true',
                'subtasks'       => 'true',
                'page'           => (string)$page,
            ];
            if ($cutoffMs !== '') {
                $params['date_updated_gt'] = $cutoffMs;
            }
            try {
                $json = httpGetJson(
                    'https://api.clickup.com/api/v2/team/' . rawurlencode($teamId) . '/task?' . http_build_query($params),
                    $headers,
                    $timeout
                );
            } catch (RuntimeException) {
                break;
            }
            $items = $json['tasks'] ?? [];
            foreach ($items as $t) {
                if (!is_array($t)) {
                    continue;
                }
                $id   = (string)($t['id']   ?? '');
                $name = trim((string)($t['name'] ?? ''));
                if ($id === '' || $name === '' || isset($seenIds[$id])) {
                    continue;
                }
                $seenIds[$id] = true;
                $tasks[] = [
                    'id'     => $id,
                    'name'   => $name,
                    'space'  => trim((string)($t['space']['name']  ?? '')),
                    'folder' => trim((string)($t['folder']['name'] ?? '')),
                    'list'   => trim((string)($t['list']['name']   ?? '')),
                    'status' => trim((string)($t['status']['status'] ?? '')),
                ];
            }
            $page++;
        } while (count($items) >= 100 && $page < 20);
    }

    // Fetch tasks the user is watching via the notifications endpoint. This catches
    // tasks that were reassigned away, moved to QA/waiting, or otherwise dropped off
    // the assignee list but that the user is still receiving updates for.
    try {
        $notifJson = httpGetJson('https://api.clickup.com/api/v2/notification', $headers, $timeout);
        $notifs    = $notifJson['notifications'] ?? [];
        $needFetch = [];
        foreach ($notifs as $n) {
            if (!is_array($n)) {
                continue;
            }
            // The notification payload may embed task info under 'task' or as flat fields.
            $taskId   = (string)($n['task']['id']   ?? $n['task_id']   ?? '');
            $taskName = trim((string)($n['task']['name'] ?? $n['task_name'] ?? ''));
            if ($taskId === '' || isset($seenIds[$taskId])) {
                continue;
            }
            if ($taskName !== '') {
                // Enough info in the notification itself — add directly.
                $seenIds[$taskId] = true;
                $tasks[] = [
                    'id'     => $taskId,
                    'name'   => $taskName,
                    'space'  => trim((string)($n['task']['space']['name']  ?? '')),
                    'folder' => trim((string)($n['task']['folder']['name'] ?? '')),
                    'list'   => trim((string)($n['task']['list']['name']   ?? '')),
                    'status' => trim((string)($n['task']['status']['status'] ?? '')),
                ];
            } else {
                $needFetch[] = $taskId;
            }
        }
        // Fall back to individual task fetches for notifications without embedded task details.
        foreach (array_unique($needFetch) as $taskId) {
            if (isset($seenIds[$taskId])) {
                continue;
            }
            try {
                $t    = httpGetJson('https://api.clickup.com/api/v2/task/' . rawurlencode($taskId), $headers, $timeout);
                $name = trim((string)($t['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $seenIds[$taskId] = true;
                $tasks[] = [
                    'id'     => $taskId,
                    'name'   => $name,
                    'space'  => trim((string)($t['space']['name']  ?? '')),
                    'folder' => trim((string)($t['folder']['name'] ?? '')),
                    'list'   => trim((string)($t['list']['name']   ?? '')),
                    'status' => trim((string)($t['status']['status'] ?? '')),
                ];
            } catch (RuntimeException) {
                // Skip tasks whose details we cannot fetch.
            }
        }
    } catch (RuntimeException) {
        // Notifications endpoint unavailable — not fatal, assigned tasks still returned.
    }

    return $tasks;
}
