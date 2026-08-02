<?php
/**
 * tools/bol/clickup-index.php — index ClickUp tasks assigned to me & updated since a date
 * (including closed), correlated to logged time. Writes JSON next to --out.
 *
 *   php tools/bol/clickup-index.php --from=2026-06-01 --to=2026-07-01 --out=/tmp/clickup-index.json
 *
 * STDERR summary: task count, closed/done, with-time, logged hours, orphan hours, runaway count.
 * "orphan" = time logged against a task not in the assigned/updated set (reassigned/older).
 * "runaway" = a single time entry >= 6h.
 */
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__, 2));
require PROJECT_ROOT . '/src/helpers.php';
require PROJECT_ROOT . '/src/integrations/shared.php';

$opts = getopt('', ['from:', 'to:', 'out::']);
$FROM = $opts['from'] ?? date('Y-m-d', strtotime('-21 days'));
$TO   = $opts['to']   ?? date('Y-m-d', strtotime('+1 day'));
$OUT  = $opts['out']  ?? (sys_get_temp_dir() . '/clickup-index.json');

$cfg  = json_decode((string)file_get_contents(PROJECT_ROOT . '/config.json'), true);
$conn = $cfg['integrations']['clickup'][0];
$H = ['Authorization: ' . (string)$conn['token'], 'Accept: application/json'];
$teams = (array)$conn['team_id'];
$assignee = (string)$conn['assignee'];
$tz = new DateTimeZone($cfg['timezone'] ?? 'America/New_York');
$startMs = (new DateTimeImmutable($FROM . ' 00:00:00', $tz))->getTimestamp() * 1000;
$endMs   = (new DateTimeImmutable($TO   . ' 00:00:00', $tz))->getTimestamp() * 1000;

/* 1. Tasks assigned to me, updated in window, incl. closed */
$tasks = [];
foreach ($teams as $teamId) {
    $page = 0;
    do {
        $params = [
            'assignees[]' => $assignee, 'include_closed' => 'true', 'subtasks' => 'true',
            'date_updated_gt' => (string)$startMs, 'page' => (string)$page,
        ];
        try {
            $json = httpGetJson('https://api.clickup.com/api/v2/team/' . rawurlencode((string)$teamId) . '/task?' . http_build_query($params), $H, 30);
        } catch (Throwable $e) {
            fwrite(STDERR, "task err team $teamId p$page: " . $e->getMessage() . "\n");
            break;
        }
        $items = $json['tasks'] ?? [];
        foreach ($items as $t) {
            $id = (string)($t['id'] ?? '');
            if ($id === '' || isset($tasks[$id])) {
                continue;
            }
            $tasks[$id] = [
                'id' => $id, 'name' => trim((string)($t['name'] ?? '')),
                'status' => trim((string)($t['status']['status'] ?? '')),
                'status_type' => trim((string)($t['status']['type'] ?? '')),
                'list' => trim((string)($t['list']['name'] ?? '')),
                'url' => (string)($t['url'] ?? ('https://app.clickup.com/t/' . $id)),
                'date_closed' => isset($t['date_closed']) && $t['date_closed'] ? (int)$t['date_closed'] : 0,
                'logged_s' => 0, 'entries' => 0,
            ];
        }
        $page++;
    } while (count($items) >= 100 && $page < 20);
}

/* 2. My time entries in window */
$orphans = [];
$bigEntries = [];
foreach ($teams as $teamId) {
    $params = ['start_date' => (string)$startMs, 'end_date' => (string)$endMs, 'assignee' => $assignee];
    try {
        $json = httpGetJson('https://api.clickup.com/api/v2/team/' . rawurlencode((string)$teamId) . '/time_entries?' . http_build_query($params), $H, 30);
    } catch (Throwable $e) {
        fwrite(STDERR, "time err team $teamId: " . $e->getMessage() . "\n");
        continue;
    }
    foreach (($json['data'] ?? []) as $e) {
        $sec = (int)round(max(0, (int)($e['duration'] ?? 0)) / 1000);
        $tid = (string)($e['task']['id'] ?? '');
        $tnm = (string)($e['task']['name'] ?? ($e['description'] ?? '(no task)'));
        $st  = (int)($e['start'] ?? 0);
        $day = $st > 0 ? (new DateTimeImmutable('@' . intdiv($st, 1000)))->setTimezone($tz)->format('Y-m-d') : '?';
        if ($sec >= 6 * 3600) {
            $bigEntries[] = ['day' => $day, 'task' => $tnm, 'url' => $tid ? 'https://app.clickup.com/t/' . $tid : '', 'hours' => round($sec / 3600, 1)];
        }
        if ($tid !== '' && isset($tasks[$tid])) {
            $tasks[$tid]['logged_s'] += $sec;
            $tasks[$tid]['entries']++;
        } else {
            $key = $tid !== '' ? $tid : $tnm;
            $orphans[$key]['name'] = $tnm;
            $orphans[$key]['url'] = $tid ? 'https://app.clickup.com/t/' . $tid : '';
            $orphans[$key]['logged_s'] = ($orphans[$key]['logged_s'] ?? 0) + $sec;
            $orphans[$key]['entries'] = ($orphans[$key]['entries'] ?? 0) + 1;
        }
    }
}

file_put_contents($OUT, json_encode(
    ['from' => $FROM, 'to' => $TO, 'tasks' => array_values($tasks), 'orphan_time' => $orphans, 'runaway_timers' => $bigEntries],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
));

$withTime = count(array_filter($tasks, fn($t) => $t['logged_s'] > 0));
$closed = count(array_filter($tasks, fn($t) => in_array($t['status_type'], ['closed', 'done'], true) || $t['date_closed'] > 0));
fwrite(STDERR, sprintf(
    "tasks=%d closed/done=%d with_time=%d logged=%.1fh orphan=%.1fh runaway=%d -> %s\n",
    count($tasks),
    $closed,
    $withTime,
    array_sum(array_map(fn($t) => $t['logged_s'], $tasks)) / 3600,
    array_sum(array_map(fn($o) => $o['logged_s'], $orphans)) / 3600,
    count($bigEntries),
    $OUT
));
