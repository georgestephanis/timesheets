<?php
/**
 * tools/bol/clickup-entries-by-day.php — list ClickUp time entries for a date range, live.
 *
 *   php tools/bol/clickup-entries-by-day.php --from=2026-06-15 --to=2026-06-30
 *
 * Prints:  <day>\t<hours>\t<task-url>\t<task-name>
 * A single entry >= 12h is almost always a runaway timer — eyeball the output.
 * ClickUp task URL is canonical: https://app.clickup.com/t/<task_id>
 */
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__, 2));
require PROJECT_ROOT . '/src/helpers.php';
require PROJECT_ROOT . '/src/integrations/shared.php';

$opts = getopt('', ['from:', 'to:']);
$FROM = $opts['from'] ?? date('Y-m-d', strtotime('-21 days'));
$TO   = $opts['to']   ?? date('Y-m-d');

$cfg  = json_decode((string)file_get_contents(PROJECT_ROOT . '/config.json'), true);
$conn = $cfg['integrations']['clickup'][0];
$H = ['Authorization: ' . (string)$conn['token'], 'Accept: application/json'];
$teams = (array)$conn['team_id'];
$assignee = (string)$conn['assignee'];
$tz = new DateTimeZone($cfg['timezone'] ?? 'America/New_York');
$startMs = (new DateTimeImmutable($FROM . ' 00:00:00', $tz))->getTimestamp() * 1000;
$endMs   = (new DateTimeImmutable($TO   . ' 23:59:59', $tz))->getTimestamp() * 1000;

$rows = [];
foreach ($teams as $teamId) {
    $params = ['start_date' => (string)$startMs, 'end_date' => (string)$endMs, 'assignee' => $assignee];
    try {
        $json = httpGetJson('https://api.clickup.com/api/v2/team/' . rawurlencode((string)$teamId) . '/time_entries?' . http_build_query($params), $H, 30);
    } catch (Throwable $e) {
        fwrite(STDERR, "err team $teamId: " . $e->getMessage() . "\n");
        continue;
    }
    foreach (($json['data'] ?? []) as $e) {
        $sec = (int)round(max(0, (int)($e['duration'] ?? 0)) / 1000);
        $st  = (int)($e['start'] ?? 0);
        $day = $st > 0 ? (new DateTimeImmutable('@' . intdiv($st, 1000)))->setTimezone($tz)->format('Y-m-d') : '?';
        $tid = (string)($e['task']['id'] ?? '');
        $rows[] = [
            'day'   => $day,
            'task'  => (string)($e['task']['name'] ?? ($e['description'] ?? '(no task)')),
            'url'   => $tid !== '' ? 'https://app.clickup.com/t/' . $tid : '',
            'hours' => round($sec / 3600, 2),
        ];
    }
}
usort($rows, fn($a, $b) => [$a['day'], -$a['hours']] <=> [$b['day'], -$b['hours']]);
$total = 0.0;
foreach ($rows as $r) {
    $total += $r['hours'];
    printf("%s\t%.2fh\t%s\t%s\n", $r['day'], $r['hours'], $r['url'], $r['task']);
}
fwrite(STDERR, sprintf("\nClickUp entries=%d total=%.2fh (%s..%s)\n", count($rows), $total, $FROM, $TO));
