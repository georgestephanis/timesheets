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

    foreach (($integrations['harvest'] ?? []) as $conn) {
        if (!is_array($conn)) {
            continue;
        }
        foreach (loadHarvestTimeEntries($conn, $from, $to) as $row) {
            $rows[] = $row;
        }
    }

    foreach (($integrations['clickup'] ?? []) as $conn) {
        if (!is_array($conn)) {
            continue;
        }
        foreach (loadClickUpTimeEntries($conn, $from, $to) as $row) {
            $rows[] = $row;
        }
    }

    usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
    return $rows;
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
        throw new RuntimeException("Invalid JSON from $url");
    }
    if ($status < 200 || $status >= 300) {
        $msg = is_string($decoded['error'] ?? null) ? $decoded['error'] : ($decoded['err'] ?? 'request failed');
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
        if (is_int($userId) || (is_string($userId) && $userId !== '')) {
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
    $teamId = (string)($conn['team_id'] ?? '');
    if ($token === '' || $teamId === '') {
        return [];
    }

    $name = (string)($conn['name'] ?? 'clickup');
    $assignee = $conn['assignee'] ?? null;
    $rows = [];

    $params = [
        'start_date' => (string)($from->setTimezone(new DateTimeZone('UTC'))->getTimestamp() * 1000),
        'end_date' => (string)($to->setTimezone(new DateTimeZone('UTC'))->getTimestamp() * 1000),
    ];
    if (is_string($assignee) && $assignee !== '') {
        $params['assignee'] = $assignee;
    }

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

    return $rows;
}
