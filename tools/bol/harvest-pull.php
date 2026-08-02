<?php
/**
 * tools/bol/harvest-pull.php — list Harvest time entries for a date range, live from the API.
 * Reuses the timesheets config + httpGetJson so the token never touches the shell.
 *
 *   php tools/bol/harvest-pull.php --from=2026-06-15 --to=2026-06-30 --connection="<name substring>"
 *
 * Prints:  <day>\t<hours>[ RUN]\t<client> / <project>\t<task>
 * STDERR:  entry count + total hours. Flags is_running timers.
 */
declare(strict_types=1);

define('PROJECT_ROOT', dirname(__DIR__, 2));
require PROJECT_ROOT . '/src/helpers.php';
require PROJECT_ROOT . '/src/integrations/shared.php';

$opts = getopt('', ['from:', 'to:', 'connection::']);
$FROM = $opts['from'] ?? date('Y-m-d', strtotime('-14 days'));
$TO   = $opts['to']   ?? date('Y-m-d');
$match = $opts['connection'] ?? null;
if ($match === null) {
    fwrite(STDERR, "usage: harvest-pull.php --from=YYYY-MM-DD --to=YYYY-MM-DD --connection=\"<name substring>\"\n");
    exit(1);
}

$cfg = json_decode((string)file_get_contents(PROJECT_ROOT . '/config.json'), true);
$conn = null;
foreach (($cfg['integrations']['harvest'] ?? []) as $c) {
    if (stripos((string)($c['name'] ?? ''), $match) !== false) {
        $conn = $c;
        break;
    }
}
if (!$conn) {
    fwrite(STDERR, "no harvest connection matching '$match'\n");
    exit(1);
}

$H = [
    'Authorization: Bearer ' . (string)$conn['token'],
    'Harvest-Account-Id: ' . (string)$conn['account_id'],
    'User-Agent: activity-report',
    'Accept: application/json',
];
$uid = (string)$conn['user_id'];

$rows = [];
$page = 1;
do {
    $params = ['user_id' => $uid, 'from' => $FROM, 'to' => $TO, 'page' => (string)$page, 'per_page' => '100'];
    $json = httpGetJson('https://api.harvestapp.com/v2/time_entries?' . http_build_query($params), $H, 30);
    foreach (($json['time_entries'] ?? []) as $e) {
        $rows[] = [
            'day'     => (string)($e['spent_date'] ?? '?'),
            'hours'   => (float)($e['hours'] ?? 0),
            'running' => (bool)($e['is_running'] ?? false),
            'client'  => (string)($e['client']['name'] ?? ''),
            'project' => (string)($e['project']['name'] ?? ''),
            'task'    => (string)($e['task']['name'] ?? ''),
        ];
    }
    $next = $json['next_page'] ?? null;
    $page = $next ? (int)$next : 0;
} while ($page > 0 && $page < 20);

usort($rows, fn($a, $b) => [$a['day'], -$a['hours']] <=> [$b['day'], -$b['hours']]);
$total = 0.0;
foreach ($rows as $r) {
    $total += $r['hours'];
    printf("%s\t%5.2fh%s\t%s / %s\t%s\n", $r['day'], $r['hours'], $r['running'] ? ' RUN' : '', $r['client'], $r['project'], $r['task']);
}
fwrite(STDERR, sprintf("\nHarvest entries=%d total=%.2fh (%s..%s)\n", count($rows), $total, $FROM, $TO));
