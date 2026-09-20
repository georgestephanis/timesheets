<?php

declare(strict_types=1);

/**
 * Reconciles Harvest time entries against the ClickUp tasks they reference,
 * flagging entries billed to the wrong client.
 *
 * Harvest notes are expected to lead with the ClickUp task id, e.g.
 *   "#86bbgu5cn - Please rebuild the logos module"
 *
 * For each entry the referenced ClickUp task is resolved to its
 * space/folder/list, mapped to a project via config.json `projects[*].clickup_tasks`
 * globs, and the project's `harvest_client` is compared to the client the time
 * was actually logged against.
 *
 * Usage:
 *   php tools/reconcile-harvest-clickup.php [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]
 *                                           [--format=md|json|tsv] [--refresh]
 *                                           [--all] [--quiet]
 */

/**
 * Locates the timesheets repo root by walking up from this file (or from
 * $TIMESHEETS_ROOT, if set) until a directory containing both config.json and
 * src/integrations/shared.php is found. This lets the tool live inside the
 * packaged skill directory instead of the repo's own tools/ folder.
 */
function rhcLocateRoot(): string {
	$candidates = [];

	$env = getenv( 'TIMESHEETS_ROOT' );
	if ( is_string( $env ) && '' !== $env ) {
		$candidates[] = $env;
	}

	$dir = __DIR__;
	while ( true ) {
		$candidates[] = $dir;
		$parent = dirname( $dir );
		if ( $parent === $dir ) {
			break;
		}
		$dir = $parent;
	}

	$cwd = getcwd();
	if ( is_string( $cwd ) && '' !== $cwd ) {
		$candidates[] = $cwd;
	}

	foreach ( $candidates as $candidate ) {
		if ( is_file( $candidate . '/config.json' )
			&& is_file( $candidate . '/src/integrations/shared.php' ) ) {
			return rtrim( $candidate, '/' );
		}
	}

	fwrite(
		STDERR,
		"Could not locate the timesheets repo root (a directory containing both\n"
		. "config.json and src/integrations/shared.php). Run this from inside the\n"
		. "repo, or set TIMESHEETS_ROOT to its path.\n"
	);
	exit( 1 );
}

define( 'TOOL_ROOT', rhcLocateRoot() );

require_once TOOL_ROOT . '/src/integrations/shared.php';

/**
 * Parses CLI flags into an options array.
 *
 * @param  list<string> $argv
 * @return array<string, mixed>
 */
function rhcParseArgs(array $argv): array
{
    $opts = [
        'from'    => (new DateTimeImmutable('first day of January this year'))->format('Y-m-d'),
        'to'      => (new DateTimeImmutable('today'))->format('Y-m-d'),
        'format'  => 'md',
        'refresh' => false,
        'all'     => false,
        'quiet'   => false,
        'help'    => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--refresh') {
            $opts['refresh'] = true;
        } elseif ($arg === '--all') {
            $opts['all'] = true;
        } elseif ($arg === '--quiet') {
            $opts['quiet'] = true;
        } elseif ($arg === '-h' || $arg === '--help') {
            $opts['help'] = true;
        } elseif (preg_match('/^--(from|to|format)=(.+)$/', $arg, $m)) {
            $opts[$m[1]] = $m[2];
        } elseif (preg_match('/^--days=(\d+)$/', $arg, $m)) {
            $opts['from'] = (new DateTimeImmutable('today'))->modify('-' . $m[1] . ' days')->format('Y-m-d');
        } else {
            fwrite(STDERR, "Unknown argument: $arg\n");
            $opts['help'] = true;
        }
    }

    foreach (['from', 'to'] as $k) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$opts[$k])) {
            throw new RuntimeException("--$k must be YYYY-MM-DD, got: {$opts[$k]}");
        }
    }
    if (!in_array($opts['format'], ['md', 'json', 'tsv', 'html'], true)) {
        throw new RuntimeException("--format must be md, json, tsv, or html");
    }

    return $opts;
}

/**
 * Loads every Harvest time entry in range across all configured connections.
 *
 * @param  array<string, mixed> $config
 * @return list<array<string, mixed>>
 */
function rhcLoadHarvestEntries(array $config, string $from, string $to, bool $quiet): array
{
    $entries = [];

    foreach (($config['integrations']['harvest'] ?? []) as $conn) {
        $token = (string)($conn['token'] ?? '');
        $acct  = (string)($conn['account_id'] ?? '');
        $name  = (string)($conn['name'] ?? 'harvest');
        if ($token === '' || $acct === '') {
            rhcLog($quiet, "  skipping $name (missing credentials)");
            continue;
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Harvest-Account-ID: ' . $acct,
            'User-Agent: activity-report',
            'Accept: application/json',
        ];

        $page = 1;
        do {
            $params = ['from' => $from, 'to' => $to, 'page' => $page, 'per_page' => 100];
            if (!empty($conn['user_id'])) {
                $params['user_id'] = (string)$conn['user_id'];
            }
            $json = httpGetJson(
                'https://api.harvestapp.com/v2/time_entries?' . http_build_query($params),
                $headers,
                30
            );

            foreach (($json['time_entries'] ?? []) as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $notes = trim((string)($e['notes'] ?? ''));
                $entries[] = [
                    'connection' => $name,
                    'id'         => $e['id'] ?? null,
                    'date'       => (string)($e['spent_date'] ?? ''),
                    'hours'      => (float)($e['hours'] ?? 0),
                    'client'     => trim((string)($e['client']['name'] ?? '')),
                    'project'    => trim((string)($e['project']['name'] ?? '')),
                    'task'       => trim((string)($e['task']['name'] ?? '')),
                    'notes'      => $notes,
                    'clickup_id' => rhcExtractClickUpId($notes),
                ];
            }

            $totalPages = (int)($json['total_pages'] ?? 1);
            $page++;
        } while ($page <= $totalPages && $page <= 200);
    }

    usort($entries, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $entries;
}

/**
 * Pulls the ClickUp task id out of a Harvest note.
 *
 * Accepts a leading "#abc123", a bare "CU-abc123", or a full task URL.
 */
function rhcExtractClickUpId(string $notes): ?string
{
    if ($notes === '') {
        return null;
    }
    if (preg_match('~app\.clickup\.com/t/(?:\d+/)?([a-z0-9]{6,12})~i', $notes, $m)) {
        return strtolower($m[1]);
    }
    // Require an explicit marker. A bare alphanumeric fallback would match ordinary
    // words in the note text and fabricate task ids.
    if (preg_match('/(?:^|\s)(?:#|CU-)([a-z0-9]{6,12})\b/i', $notes, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/**
 * Resolves ClickUp task ids to their space/folder/list, using a disk cache.
 *
 * Task placement effectively never changes, so cached entries are reused
 * indefinitely unless --refresh is passed.
 *
 * @param  list<string> $ids
 * @return array<string, array<string, mixed>>
 */
function rhcResolveClickUpTasks(array $config, array $ids, bool $refresh, bool $quiet): array
{
    $cacheFile = TOOL_ROOT . '/tmp/clickup-task-cache.json';
    @mkdir(dirname($cacheFile), 0775, true);

    $cache = (!$refresh && is_file($cacheFile))
        ? (json_decode((string)file_get_contents($cacheFile), true) ?: [])
        : [];

    $conn  = ($config['integrations']['clickup'] ?? [])[0] ?? [];
    $token = (string)($conn['token'] ?? '');
    if ($token === '') {
        rhcLog($quiet, '  no ClickUp token configured — cannot resolve tasks');
        return $cache;
    }
    $headers = ['Authorization: ' . $token, 'Accept: application/json'];

    $pending = array_values(array_filter($ids, fn($id) => !isset($cache[$id])));
    if ($pending === []) {
        rhcLog($quiet, '  all ' . count($ids) . ' ClickUp tasks served from cache');
        return $cache;
    }
    rhcLog($quiet, '  fetching ' . count($pending) . ' ClickUp tasks (' . (count($ids) - count($pending)) . ' cached)');

    $done = 0;
    foreach ($pending as $id) {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                $task = httpGetJson('https://api.clickup.com/api/v2/task/' . rawurlencode($id), $headers, 25);
                $cache[$id] = [
                    'name'   => trim((string)($task['name'] ?? '')),
                    'space'  => trim((string)($task['space']['name'] ?? '')),
                    'folder' => trim((string)($task['folder']['name'] ?? '')),
                    'list'   => trim((string)($task['list']['name'] ?? '')),
                    'status' => trim((string)($task['status']['status'] ?? '')),
                    'url'    => (string)($task['url'] ?? 'https://app.clickup.com/t/' . $id),
                ];
                break;
            } catch (RuntimeException $ex) {
                if (str_contains($ex->getMessage(), 'HTTP 429')) {
                    sleep(12);
                    continue;
                }
                $cache[$id] = ['error' => substr($ex->getMessage(), 0, 200)];
                break;
            }
        }

        if (++$done % 25 === 0) {
            file_put_contents($cacheFile, json_encode($cache));
            rhcLog($quiet, "    …$done/" . count($pending));
        }
        usleep(120000);
    }

    file_put_contents($cacheFile, json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $cache;
}

/**
 * Builds glob rules mapping ClickUp names to a project + expected Harvest client.
 *
 * @return list<array{glob: string, project: string, client: string}>
 */
function rhcBuildRules(array $config): array
{
    $rules = [];
    foreach (($config['projects'] ?? []) as $project => $meta) {
        foreach (($meta['clickup_tasks'] ?? []) as $glob) {
            $rules[] = [
                'glob'    => strtolower((string)$glob),
                'project' => (string)$project,
                'client'  => trim((string)($meta['harvest_client'] ?? '')),
            ];
        }
    }
    return $rules;
}

/**
 * Maps one ClickUp task to its expected Harvest client.
 *
 * Matches most-specific container first: a task in folder "Oldways" / list
 * "Whole Grains Council" belongs to Whole Grains Council, not Oldways.
 *
 * @return array{project: string, client: string, matched_on: string}|null
 */
function rhcExpectedClient(array $task, array $rules): ?array
{
    foreach (['list', 'folder', 'space'] as $field) {
        $value = strtolower(trim((string)($task[$field] ?? '')));
        if ($value === '') {
            continue;
        }
        foreach ($rules as $rule) {
            if ($rule['glob'] !== '' && fnmatch($rule['glob'], $value)) {
                return [
                    'project'    => $rule['project'],
                    'client'     => $rule['client'],
                    'matched_on' => $field . '="' . $task[$field] . '"',
                ];
            }
        }
    }
    return null;
}

/**
 * Returns true when two client names are declared equivalent in config.
 *
 * `reconcile.client_families` holds groups of names that bill as one relationship
 * (e.g. ["Oldways", "Whole Grains Council"]). Differences within a family are
 * reported at low severity rather than as cross-client conflation.
 */
function rhcSameFamily(array $config, string $a, string $b): bool
{
    foreach (($config['reconcile']['client_families'] ?? []) as $family) {
        if (!is_array($family)) {
            continue;
        }
        $lower = array_map(fn($v) => strtolower(trim((string)$v)), $family);
        if (in_array(strtolower(trim($a)), $lower, true) && in_array(strtolower(trim($b)), $lower, true)) {
            return true;
        }
    }
    return false;
}

/**
 * Heuristic for unmapped tasks: does the ClickUp container name look like a
 * different client than the one the time was billed to?
 *
 * Only fires when the container name is a confident, non-generic signal, so
 * generic lists such as "Support" never trigger it.
 */
function rhcUnmappedLooksWrong(array $task, string $loggedClient): ?string
{
    $generic = ['support', 'internal tasks', 'hidden', 'backlog', 'general', 'misc', 'inbox'];

    foreach (['folder', 'space', 'list'] as $field) {
        $name = trim((string)($task[$field] ?? ''));
        if ($name === '' || in_array(strtolower($name), $generic, true)) {
            continue;
        }
        $a = preg_replace('/[^a-z0-9]/', '', strtolower($name)) ?? '';
        $b = preg_replace('/[^a-z0-9]/', '', strtolower($loggedClient)) ?? '';
        if ($a === '' || $b === '') {
            continue;
        }
        // Confident signal only: neither name contains the other.
        if (!str_contains($a, $b) && !str_contains($b, $a)) {
            return $name;
        }
        return null;
    }
    return null;
}

/**
 * Classifies every entry into findings buckets.
 *
 * @return array<string, mixed>
 */
function rhcAnalyze(array $entries, array $tasks, array $rules, array $config): array
{
    $out = [
        'matched'        => 0,
        'matched_hours'  => 0.0,
        'mismatch'       => [],
        'family'         => [],
        'unmapped_wrong' => [],
        'unmapped'       => [],
        'no_id'          => [],
        'lookup_error'   => [],
    ];

    foreach ($entries as $entry) {
        $id = $entry['clickup_id'];
        if ($id === null) {
            $out['no_id'][] = $entry;
            continue;
        }

        $task = $tasks[$id] ?? null;
        if ($task === null || isset($task['error'])) {
            $out['lookup_error'][] = $entry + ['error' => $task['error'] ?? 'not found'];
            continue;
        }

        $entry['clickup_task'] = $task;
        $expected = rhcExpectedClient($task, $rules);

        if ($expected === null || $expected['client'] === '') {
            $suspect = rhcUnmappedLooksWrong($task, $entry['client']);
            if ($suspect !== null) {
                $out['unmapped_wrong'][] = $entry + ['suspect_client' => $suspect];
            } else {
                $out['unmapped'][] = $entry;
            }
            continue;
        }

        $entry['expected'] = $expected;

        if (strcasecmp($expected['client'], $entry['client']) === 0) {
            $out['matched']++;
            $out['matched_hours'] += $entry['hours'];
        } elseif (rhcSameFamily($config, $expected['client'], $entry['client'])) {
            $out['family'][] = $entry;
        } else {
            $out['mismatch'][] = $entry;
        }
    }

    return $out;
}

/** Sums the `hours` column of a finding list. */
function rhcHours(array $rows): float
{
    return array_sum(array_column($rows, 'hours'));
}

/** Writes a progress line to STDERR unless suppressed. */
function rhcLog(bool $quiet, string $message): void
{
    if (!$quiet) {
        fwrite(STDERR, $message . "\n");
    }
}

/** Renders the findings as Markdown. */
function rhcRenderMarkdown(array $r, array $entries, string $from, string $to): string
{
    $total = count($entries);
    $o  = "# Harvest ↔ ClickUp client reconciliation\n\n";
    $o .= "Range: **$from → $to** · **$total** Harvest entries · "
        . sprintf('%.2f h total', rhcHours($entries)) . "\n\n";

    $o .= "| Result | Entries | Hours |\n|---|---:|---:|\n";
    $rows = [
        'Client matches ClickUp'                  => [$r['matched'], $r['matched_hours']],
        'Cross-client mismatch'                   => [count($r['mismatch']), rhcHours($r['mismatch'])],
        'Within client family'                    => [count($r['family']), rhcHours($r['family'])],
        'Unmapped — container suggests other client' => [count($r['unmapped_wrong']), rhcHours($r['unmapped_wrong'])],
        'Unmapped — no config rule'               => [count($r['unmapped']), rhcHours($r['unmapped'])],
        'No ClickUp id in notes'                  => [count($r['no_id']), rhcHours($r['no_id'])],
        'ClickUp lookup failed'                   => [count($r['lookup_error']), rhcHours($r['lookup_error'])],
    ];
    foreach ($rows as $label => [$n, $h]) {
        $o .= sprintf("| %s | %d | %.2f |\n", $label, $n, $h);
    }

    $section = function (string $title, array $rows, callable $right) use (&$o): void {
        if ($rows === []) {
            return;
        }
        $o .= "\n## $title — " . count($rows) . ' entries, ' . sprintf('%.2f h', rhcHours($rows)) . "\n\n";
        $o .= "| Date | Hours | Logged to | " . $right('header') . " | ClickUp task |\n|---|---:|---|---|---|\n";
        foreach ($rows as $row) {
            $task = $row['clickup_task'] ?? [];
            $o .= sprintf(
                "| %s | %.2f | %s | %s | [#%s](%s) %s |\n",
                $row['date'],
                $row['hours'],
                $row['client'],
                $right($row),
                $row['clickup_id'],
                $task['url'] ?? '',
                str_replace('|', '\\|', substr((string)($task['name'] ?? ''), 0, 70))
            );
        }
    };

    $section(
        '🚨 Cross-client mismatches',
        $r['mismatch'],
        fn($row) => $row === 'header' ? 'Should be' : $row['expected']['client'] . ' <br><sub>' . $row['expected']['matched_on'] . '</sub>'
    );
    $section(
        '⚠️ Unmapped, container suggests another client',
        $r['unmapped_wrong'],
        fn($row) => $row === 'header' ? 'ClickUp container' : $row['suspect_client']
    );
    $section(
        'ℹ️ Within client family',
        $r['family'],
        fn($row) => $row === 'header' ? 'Task belongs to' : $row['expected']['client']
    );

    if ($r['unmapped'] !== []) {
        $agg = [];
        foreach ($r['unmapped'] as $row) {
            $task = $row['clickup_task'];
            $key = trim(($task['folder'] ?: $task['space']) . ' / ' . $task['list']) . ' → ' . $row['client'];
            $agg[$key][] = $row['hours'];
        }
        uasort($agg, fn($a, $b) => array_sum($b) <=> array_sum($a));
        $o .= "\n## Unmapped containers (add a `clickup_tasks` rule + `harvest_client` to config.json)\n\n";
        $o .= "| ClickUp container → Harvest client | Entries | Hours |\n|---|---:|---:|\n";
        foreach ($agg as $key => $hours) {
            $o .= sprintf("| %s | %d | %.2f |\n", str_replace('|', '\\|', $key), count($hours), array_sum($hours));
        }
    }

    if ($r['no_id'] !== []) {
        $agg = [];
        foreach ($r['no_id'] as $row) {
            $agg[$row['client']][] = $row['hours'];
        }
        arsort($agg);
        $o .= "\n## Entries with no ClickUp reference (not verifiable)\n\n";
        $o .= "| Harvest client | Entries | Hours |\n|---|---:|---:|\n";
        foreach ($agg as $client => $hours) {
            $o .= sprintf("| %s | %d | %.2f |\n", $client ?: '(none)', count($hours), array_sum($hours));
        }
    }

    return $o;
}

/** Renders mismatch findings as TSV for spreadsheet triage. */
function rhcRenderTsv(array $r): string
{
    $o = implode("\t", ['severity', 'date', 'hours', 'logged_client', 'expected_client', 'harvest_project', 'clickup_id', 'clickup_folder', 'clickup_list', 'clickup_task', 'url']) . "\n";
    $emit = function (string $sev, array $rows, string $field) use (&$o): void {
        foreach ($rows as $row) {
            $task = $row['clickup_task'] ?? [];
            $expected = $field === 'expected'
                ? ($row['expected']['client'] ?? '')
                : ($row['suspect_client'] ?? '');
            $o .= implode("\t", [
                $sev, $row['date'], sprintf('%.2f', $row['hours']), $row['client'], $expected,
                $row['project'], (string)$row['clickup_id'],
                (string)($task['folder'] ?? ''), (string)($task['list'] ?? ''),
                str_replace(["\t", "\n"], ' ', (string)($task['name'] ?? '')),
                (string)($task['url'] ?? ''),
            ]) . "\n";
        }
    };
    $emit('cross_client', $r['mismatch'], 'expected');
    $emit('unmapped_suspect', $r['unmapped_wrong'], 'suspect');
    $emit('family', $r['family'], 'expected');
    return $o;
}

/**
 * Resolves the Harvest account's web base URI (e.g. https://acme.harvestapp.com)
 * so day-view deep links can be built. Falls back to the generic platform host.
 */
function rhcHarvestBaseUri(array $config): string
{
    $conn  = ($config['integrations']['harvest'] ?? [])[0] ?? [];
    $token = (string)($conn['token'] ?? '');
    $acct  = (string)($conn['account_id'] ?? '');
    if ($token === '' || $acct === '') {
        return 'https://platform.harvestapp.com';
    }
    try {
        $json = httpGetJson('https://api.harvestapp.com/v2/company', [
            'Authorization: Bearer ' . $token,
            'Harvest-Account-ID: ' . $acct,
            'User-Agent: activity-report',
            'Accept: application/json',
        ], 20);
        $base = rtrim((string)($json['base_uri'] ?? ''), '/');
        return $base !== '' ? $base : 'https://platform.harvestapp.com';
    } catch (RuntimeException) {
        return 'https://platform.harvestapp.com';
    }
}

/**
 * Renders findings as a standalone, self-contained HTML worklist grouped by date.
 *
 * Each date heading deep-links to the Harvest day view holding the entry, and each
 * row links to the ClickUp task, so entries can be corrected without hunting.
 * Checkbox state persists per-viewer via localStorage.
 */
function rhcRenderHtml(array $r, array $entries, string $from, string $to, string $harvestBase): string
{
    $esc = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    /** Groups findings by spent date, newest first. */
    $groupByDate = static function (array $rows): array {
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[$row['date']][] = $row;
        }
        krsort($byDate);
        return $byDate;
    };

    /** Renders one findings section: date headings, then a card per entry. */
    $renderSection = function (string $id, string $title, string $blurb, array $rows, string $expectedLabel, callable $expected) use ($esc, $groupByDate, $harvestBase): string {
        if ($rows === []) {
            return '';
        }
        $hours = rhcHours($rows);
        $html  = '<section id="' . $esc($id) . '">';
        $html .= '<h2>' . $esc($title) . ' <span class="count">' . count($rows) . ' entries · ' . sprintf('%.2f h', $hours) . '</span></h2>';
        $html .= '<p class="blurb">' . $esc($blurb) . '</p>';

        foreach ($groupByDate($rows) as $date => $dateRows) {
            [$y, $m, $d] = explode('-', $date);
            $dayUrl = $harvestBase . '/time/day/' . $y . '/' . $m . '/' . $d;
            $dayHours = rhcHours($dateRows);
            $html .= '<div class="daygroup">';
            $html .= '<h3><a class="day" href="' . $esc($dayUrl) . '" target="_blank" rel="noopener">' . $esc($date) . '</a>'
                   . '<span class="daymeta">' . count($dateRows) . ' · ' . sprintf('%.2f h', $dayHours) . '</span></h3>';

            foreach ($dateRows as $row) {
                $task = $row['clickup_task'] ?? [];
                $key  = $esc($id . ':' . ($row['id'] ?? $row['clickup_id']) . ':' . $date);
                $html .= '<div class="row" data-key="' . $key . '">';
                $html .= '<input type="checkbox" class="done" id="cb-' . $key . '"><label class="cbl" for="cb-' . $key . '"></label>';
                $html .= '<div class="body">';
                $html .= '<div class="line1"><span class="hours">' . sprintf('%.2f h', $row['hours']) . '</span>'
                       . '<span class="from">' . $esc($row['client']) . '</span>'
                       . '<span class="arrow">→</span>'
                       . '<span class="to">' . $esc($expected($row)) . '</span></div>';
                $html .= '<div class="task">' . $esc($task['name'] ?? '') . '</div>';
                $html .= '<div class="meta">';
                $html .= '<span class="k">Harvest project</span> ' . $esc($row['project']);
                if (($row['task'] ?? '') !== '') {
                    $html .= ' <span class="sep">·</span> ' . $esc($row['task']);
                }
                $html .= '</div>';
                $html .= '<div class="meta"><span class="k">ClickUp</span> '
                       . $esc(trim(((string)($task['folder'] ?? '')) . ' / ' . ((string)($task['list'] ?? '')), ' /'));
                if (isset($row['expected']['matched_on'])) {
                    $html .= ' <span class="sep">·</span> matched on ' . $esc($row['expected']['matched_on']);
                }
                if (($task['status'] ?? '') !== '') {
                    $html .= ' <span class="sep">·</span> ' . $esc($task['status']);
                }
                $html .= '</div>';
                $html .= '<div class="links">';
                $html .= '<a href="' . $esc($dayUrl) . '" target="_blank" rel="noopener">Harvest ' . $esc($date) . '</a>';
                if (($task['url'] ?? '') !== '') {
                    $html .= '<a href="' . $esc($task['url']) . '" target="_blank" rel="noopener">ClickUp #' . $esc($row['clickup_id']) . '</a>';
                }
                $html .= '</div>';
                $html .= '</div></div>';
            }
            $html .= '</div>';
        }
        return $html . '</section>';
    };

    $confirmed = $renderSection(
        'confirmed',
        'Cross-client mismatches',
        'The ClickUp task belongs to a different client than the time was billed to. Re-assign the Harvest entry to the client on the right.',
        $r['mismatch'],
        'Should be',
        fn($row) => $row['expected']['client']
    );

    $suspect = $renderSection(
        'suspect',
        'Unmapped — container suggests another client',
        'These ClickUp containers have no mapping in config.json, but the folder name clearly differs from the billed client. Verify each one by hand, then add a clickup_tasks rule.',
        $r['unmapped_wrong'],
        'Looks like',
        fn($row) => $row['suspect_client']
    );

    $family = $renderSection(
        'family',
        'Within declared client family',
        'Billed to a related entity. Informational only.',
        $r['family'],
        'Task belongs to',
        fn($row) => $row['expected']['client']
    );

    $totalFlagged = count($r['mismatch']) + count($r['unmapped_wrong']);
    $flaggedHours = sprintf('%.2f', rhcHours($r['mismatch']) + rhcHours($r['unmapped_wrong']));

    $stats = [
        ['Cross-client mismatch', count($r['mismatch']), rhcHours($r['mismatch']), 'bad'],
        ['Container suggests other', count($r['unmapped_wrong']), rhcHours($r['unmapped_wrong']), 'warn'],
        ['Unverifiable — no ClickUp id', count($r['no_id']), rhcHours($r['no_id']), 'mute'],
        ['Unmapped — no rule', count($r['unmapped']), rhcHours($r['unmapped']), 'mute'],
        ['Client matches', $r['matched'], $r['matched_hours'], 'good'],
    ];
    $cards = '';
    foreach ($stats as [$label, $n, $h, $tone]) {
        $cards .= '<div class="stat ' . $tone . '"><div class="n">' . $n . '</div>'
                . '<div class="h">' . sprintf('%.2f h', $h) . '</div>'
                . '<div class="l">' . $esc($label) . '</div></div>';
    }

    $generated = (new DateTimeImmutable('now'))->format('Y-m-d H:i');

    return <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Harvest / ClickUp mismatches {$from} to {$to}</title>
    <style>
    :root{--bg:#f7f7f5;--card:#fff;--fg:#1c1b19;--mut:#6b6864;--line:#e3e1dd;
    --bad:#b4341f;--badbg:#fdf0ed;--warn:#8a5a00;--warnbg:#fdf6e6;--good:#2f6a3f;--accent:#1a5fb4;}
    @media (prefers-color-scheme:dark){:root{--bg:#16161a;--card:#1f1f24;--fg:#eceae6;--mut:#9b9791;
    --line:#33333a;--bad:#ff8a70;--badbg:#2e1b17;--warn:#e8b04b;--warnbg:#2c2415;--good:#7dd39b;--accent:#7aa9f7;}}
    *{box-sizing:border-box}
    body{margin:0;padding:2rem 1rem 4rem;background:var(--bg);color:var(--fg);
    font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
    .wrap{max-width:940px;margin:0 auto}
    h1{font-size:1.5rem;margin:0 0 .25rem}
    .sub{color:var(--mut);margin:0 0 1.5rem;font-size:.9rem}
    .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.6rem;margin-bottom:2rem}
    .stat{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.75rem .9rem}
    .stat .n{font-size:1.5rem;font-weight:650;line-height:1}
    .stat .h{font-size:.85rem;color:var(--mut);margin-top:.15rem}
    .stat .l{font-size:.75rem;color:var(--mut);margin-top:.35rem;text-transform:uppercase;letter-spacing:.04em}
    .stat.bad .n{color:var(--bad)}.stat.warn .n{color:var(--warn)}.stat.good .n{color:var(--good)}
    .stat.mute .n{color:var(--mut)}
    .progress{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:.7rem .9rem;
    margin-bottom:2rem;font-size:.9rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap}
    .bar{flex:1;min-width:160px;height:7px;background:var(--line);border-radius:99px;overflow:hidden}
    .bar>i{display:block;height:100%;width:0;background:var(--good);transition:width .2s}
    button{font:inherit;background:none;border:1px solid var(--line);color:var(--mut);
    border-radius:7px;padding:.25rem .6rem;cursor:pointer}
    button:hover{color:var(--fg)}
    h2{font-size:1.1rem;margin:2.5rem 0 .3rem;display:flex;align-items:baseline;gap:.6rem;flex-wrap:wrap}
    h2 .count{font-size:.8rem;font-weight:400;color:var(--mut)}
    .blurb{color:var(--mut);font-size:.87rem;margin:0 0 1.2rem;max-width:68ch}
    .daygroup{margin-bottom:1.4rem}
    h3{font-size:.9rem;margin:0 0 .5rem;display:flex;align-items:baseline;gap:.6rem;
    border-bottom:1px solid var(--line);padding-bottom:.35rem}
    a.day{color:var(--accent);text-decoration:none;font-variant-numeric:tabular-nums}
    a.day:hover{text-decoration:underline}
    .daymeta{color:var(--mut);font-weight:400;font-size:.8rem}
    .row{display:flex;gap:.7rem;background:var(--card);border:1px solid var(--line);
    border-radius:10px;padding:.75rem .9rem;margin-bottom:.5rem;align-items:flex-start}
    .row.is-done{opacity:.45}
    .row.is-done .task,.row.is-done .line1{text-decoration:line-through}
    input.done{position:absolute;opacity:0;pointer-events:none}
    .cbl{flex:none;width:17px;height:17px;margin-top:.15rem;border:1.5px solid var(--line);
    border-radius:5px;cursor:pointer;display:block}
    input.done:checked+.cbl{background:var(--good);border-color:var(--good)}
    .body{min-width:0;flex:1}
    .line1{display:flex;gap:.5rem;align-items:baseline;flex-wrap:wrap;margin-bottom:.2rem}
    .hours{font-weight:650;font-variant-numeric:tabular-nums}
    .from{color:var(--bad);background:var(--badbg);border-radius:5px;padding:.05rem .4rem;font-size:.85rem}
    .arrow{color:var(--mut)}
    .to{color:var(--good);font-weight:600;font-size:.85rem}
    .task{font-size:.95rem;margin-bottom:.3rem;overflow-wrap:anywhere}
    .meta{font-size:.8rem;color:var(--mut);overflow-wrap:anywhere}
    .meta .k{text-transform:uppercase;letter-spacing:.04em;font-size:.68rem;
    border:1px solid var(--line);border-radius:4px;padding:0 .3rem;margin-right:.2rem}
    .sep{opacity:.5;margin:0 .15rem}
    .links{margin-top:.45rem;display:flex;gap:.5rem;flex-wrap:wrap}
    .links a{font-size:.8rem;color:var(--accent);text-decoration:none;
    border:1px solid var(--line);border-radius:6px;padding:.15rem .5rem}
    .links a:hover{border-color:var(--accent)}
    footer{margin-top:3rem;color:var(--mut);font-size:.8rem;border-top:1px solid var(--line);padding-top:1rem}
    </style>
    </head>
    <body><div class="wrap">
    <h1>Harvest &harr; ClickUp mismatches</h1>
    <p class="sub">{$from} &rarr; {$to} · {$totalFlagged} entries flagged · {$flaggedHours} h to review · generated {$generated}</p>
    <div class="stats">{$cards}</div>
    <div class="progress"><span><b id="pdone">0</b> of <b id="ptotal">0</b> rectified</span>
    <span class="bar"><i id="pbar"></i></span><button id="reset">Reset</button></div>
    {$confirmed}{$suspect}{$family}
    <footer>Generated by <code>reconcile-harvest-clickup.php --format=html</code>.
    Checkbox state is stored in this browser only. Entries with no ClickUp id in their notes
    cannot be verified by this report.</footer>
    </div>
    <script>
    (function(){
      var KEY='hcr-done-{$from}-{$to}', done={};
      try{done=JSON.parse(localStorage.getItem(KEY)||'{}')||{}}catch(e){done={}}
      var boxes=[].slice.call(document.querySelectorAll('input.done'));
      function save(){try{localStorage.setItem(KEY,JSON.stringify(done))}catch(e){}}
      function paint(){
        var n=0;
        boxes.forEach(function(b){
          var row=b.closest('.row');
          if(b.checked){n++;row.classList.add('is-done')}else{row.classList.remove('is-done')}
        });
        document.getElementById('pdone').textContent=n;
        document.getElementById('ptotal').textContent=boxes.length;
        document.getElementById('pbar').style.width=(boxes.length?(n/boxes.length*100):0)+'%';
      }
      boxes.forEach(function(b){
        var k=b.closest('.row').getAttribute('data-key');
        b.checked=!!done[k];
        b.addEventListener('change',function(){
          if(b.checked){done[k]=1}else{delete done[k]}
          save();paint();
        });
      });
      document.getElementById('reset').addEventListener('click',function(){
        done={};save();boxes.forEach(function(b){b.checked=false});paint();
      });
      paint();
    })();
    </script>
    </body></html>
    HTML;
}

// ---------------------------------------------------------------- main

$opts = rhcParseArgs($argv);

if ($opts['help']) {
    fwrite(STDOUT, <<<TXT
    Reconcile Harvest time entries against their referenced ClickUp tasks.

      --from=YYYY-MM-DD   Start date (default: Jan 1 of current year)
      --to=YYYY-MM-DD     End date (default: today)
      --days=N            Shorthand for --from=N days ago
      --format=md|json|tsv|html  Output format (default: md)
      --refresh           Ignore the ClickUp task cache and refetch
      --all               Include full detail in JSON output
      --quiet             Suppress progress output on STDERR

    TXT);
    exit(0);
}

$configPath = TOOL_ROOT . '/config.json';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.json not found at $configPath\n");
    exit(1);
}
$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    fwrite(STDERR, "config.json is not valid JSON\n");
    exit(1);
}

try {
    rhcLog($opts['quiet'], "Loading Harvest entries {$opts['from']} → {$opts['to']}…");
    $entries = rhcLoadHarvestEntries($config, $opts['from'], $opts['to'], $opts['quiet']);
    rhcLog($opts['quiet'], '  ' . count($entries) . ' entries');

    $ids = array_values(array_unique(array_filter(array_column($entries, 'clickup_id'))));
    $tasks = rhcResolveClickUpTasks($config, $ids, $opts['refresh'], $opts['quiet']);

    $result = rhcAnalyze($entries, $tasks, rhcBuildRules($config), $config);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}

echo match ($opts['format']) {
    'json' => json_encode(
        $opts['all'] ? $result + ['entries' => $entries] : $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
    ) . "\n",
    'tsv'  => rhcRenderTsv($result),
    'html' => rhcRenderHtml($result, $entries, $opts['from'], $opts['to'], rhcHarvestBaseUri($config)),
    default => rhcRenderMarkdown($result, $entries, $opts['from'], $opts['to']),
};

// Non-zero exit when genuine cross-client conflation is present, so this can gate CI/cron.
exit($result['mismatch'] === [] && $result['unmapped_wrong'] === [] ? 0 : 2);
