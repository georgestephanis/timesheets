<?php

declare(strict_types=1);

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
        $page = 0;
        do {
            $pageParams = $params + ($page > 0 ? ['page' => $page] : []);
            $url = 'https://api.clickup.com/api/v2/team/' . rawurlencode($teamId) . '/time_entries?' . http_build_query($pageParams);
            $json = httpGetJson($url, [
                'Authorization: ' . $token,
                'Accept: application/json',
            ]);

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
