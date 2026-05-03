<?php

declare(strict_types=1);

/**
 * Signal classifiers and the main aggregation loop.
 */

/**
 * Extracts the workspace folder name from a macOS VSCode window title.
 *
 * Handles the "filename — project" (em-dash) pattern and bare "project" titles,
 * strips the unsaved-changes indicator (●), and ignores literal app-name suffixes
 * ("Visual Studio Code", "VS Code", "Code").
 *
 * @return string|null The workspace folder name, or null if the title cannot be parsed.
 */
function classifyVscode(string $title): ?string
{
    if ($title === '') {
        return null;
    }
    // macOS VSCode title: "filename — project" (em-dash) or just "project"
    $parts = preg_split('/\s+[—\-]\s+/u', $title);
    $parts = array_values(array_filter(array_map(
        fn($p) => trim(str_replace('●', '', $p)),
        $parts
    ), fn($p) => $p !== '' && !in_array($p, ['Visual Studio Code','VS Code','Code'], true)));
    if (!$parts) {
        return null;
    }
    return $parts[count($parts) - 1];
}

/**
 * Parses a Slack macOS window title into workspace, channel, and kind components.
 *
 * Handles regular channels/DMs/groups as well as the Threads, Activity, and Huddle
 * views. Returns null for any title that does not match a known Slack pattern.
 *
 * @return array{workspace: string, channel: string, kind: string}|null
 */
function classifySlack(string $title): ?array
{
    // "<chan> (Channel|DM|Group) - <Workspace>[ - N new items] - Slack[ [Main]]"
    if (preg_match('/^(?:! )?(.+?) \((Channel|DM|Group)\) - (.+?)(?: - \d+ new items?)? - Slack(?: \[Main\])?$/u', $title, $m)) {
        return ['workspace' => $m[3], 'channel' => $m[1], 'kind' => $m[2]];
    }
    if (preg_match('/^Threads - (.+?)(?: - \d+ new items?)? - Slack/', $title, $m)) {
        return ['workspace' => $m[1], 'channel' => '__threads__', 'kind' => 'view'];
    }
    if (preg_match('/^Activity - (.+?)(?: - \d+ new items?)? - Slack/', $title, $m)) {
        return ['workspace' => $m[1], 'channel' => '__activity__', 'kind' => 'view'];
    }
    if (preg_match('/^Huddle(?:: .*)? - (.+?) - Slack/', $title, $m)) {
        return ['workspace' => $m[1], 'channel' => '__huddle__', 'kind' => 'huddle'];
    }
    return null;
}

/**
 * Extracts an SSH hostname from a terminal window title.
 *
 * Looks for the pattern "ssh <hostname>" anywhere in the title, as typically
 * shown by Terminal.app, iTerm2, Warp, and Ghostty.
 *
 * @return string|null The hostname argument to ssh, or null if no SSH command is found.
 */
function classifySsh(string $title): ?string
{
    if (preg_match('/\bssh\s+(\S+)/', $title, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Looks up the first project whose rules match the given signals.
 *
 * Signals is a sparse map; only the keys present are checked. Matching priority:
 *   1. vscode_dir — case-insensitive exact match against projects[*].vscode_dirs
 *   2. host       — glob match against projects[*].domains
 *   3. slack      — workspace match (case-insensitive), then optional channel_glob
 *   4. ssh_host   — glob match against projects[*].ssh_hosts
 *   5. app        — glob match against projects[*].apps
 *
 * @param  array<string, mixed> $sig    Signals extracted from the current event (vscode_dir, host, slack, ssh_host).
 * @param  array<string, mixed> $config Loaded config array.
 * @return string|null Project name, or null if no rule matches.
 */
function projectForSignals(array $sig, array $config): ?string
{
    foreach ($config['projects'] as $name => $p) {
        // VSCode dir
        if (!empty($sig['vscode_dir']) && !empty($p['vscode_dirs'])) {
            foreach ($p['vscode_dirs'] as $d) {
                if (strcasecmp($d, $sig['vscode_dir']) === 0) {
                    return $name;
                }
            }
        }
        // Domain
        if (!empty($sig['host']) && !empty($p['domains'])) {
            if (fnmatchAny($sig['host'], $p['domains'])) {
                return $name;
            }
        }
        // Slack
        if (!empty($sig['slack']) && !empty($p['slack'])) {
            foreach ($p['slack'] as $rule) {
                if (strcasecmp($rule['workspace'], $sig['slack']['workspace']) !== 0) {
                    continue;
                }
                if (empty($rule['channel_glob'])) {
                    return $name; // any channel
                }
                if (fnmatch($rule['channel_glob'], $sig['slack']['channel'], FNM_CASEFOLD)) {
                    return $name;
                }
            }
        }
        // SSH host
        if (!empty($sig['ssh_host']) && !empty($p['ssh_hosts'])) {
            if (fnmatchAny($sig['ssh_host'], $p['ssh_hosts'])) {
                return $name;
            }
        }
        // App name
        if (!empty($sig['app']) && !empty($p['apps'])) {
            if (fnmatchAny($sig['app'], $p['apps'])) {
                return $name;
            }
        }
    }
    return null;
}

/**
 * Returns true if the user was AFK (away from keyboard) at the given moment.
 *
 * Uses a linear scan over the AFK event list (which must be sorted ascending by start)
 * and short-circuits as soon as an event's start time exceeds $t, making it efficient
 * for sequential calls over ordered window events.
 *
 * @param DateTimeImmutable                                                              $t   Moment to test.
 * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, status: string}> $afk AFK event list.
 */
function isAfkAt(DateTimeImmutable $t, array $afk): bool
{
    // Simple linear scan; afk events are typically <1k. Optimize if needed.
    foreach ($afk as $a) {
        if ($t < $a['start']) {
            return false;
        }
        if ($t < $a['end']) {
            return $a['status'] === 'afk';
        }
    }
    return false;
}

/**
 * Converts a DateTimeImmutable to a Unix timestamp float with microsecond precision.
 */
function dtToFloatTs(DateTimeImmutable $dt): float
{
    return $dt->getTimestamp() + ((int)$dt->format('u') / 1_000_000);
}

/**
 * Returns the overlap (seconds) between a window event and active input slices.
 *
 * $cursor is advanced past finished input rows to keep repeated calls efficient
 * while iterating chronologically ordered window events.
 *
 * @param list<array{start: DateTimeImmutable, end: DateTimeImmutable, active?: bool}> $input
 */
function activeInputSecondsDuring(DateTimeImmutable $start, DateTimeImmutable $end, array $input, int &$cursor): float
{
    if ($input === []) {
        return 0.0;
    }

    $startTs = dtToFloatTs($start);
    $endTs = dtToFloatTs($end);
    if ($endTs <= $startTs) {
        return 0.0;
    }

    $n = count($input);
    while ($cursor < $n && dtToFloatTs($input[$cursor]['end']) <= $startTs) {
        $cursor++;
    }

    $sum = 0.0;
    for ($i = $cursor; $i < $n; $i++) {
        $inStart = dtToFloatTs($input[$i]['start']);
        if ($inStart >= $endTs) {
            break;
        }
        if (empty($input[$i]['active'])) {
            continue;
        }
        $inEnd = dtToFloatTs($input[$i]['end']);
        $overlap = min($endTs, $inEnd) - max($startTs, $inStart);
        if ($overlap > 0) {
            $sum += $overlap;
        }
    }

    return min($sum, $endTs - $startTs);
}

/**
 * Classifies all ActivityWatch window events and git commits, then aggregates them
 * by date and project name.
 *
 * AFK periods are excluded from window events. Events matching no project rule are
 * placed in generic catch-all buckets and also tallied in $unmatched for optional
 * display via --show-unmatched.
 *
 * The returned $bucket is a nested map: [YYYY-MM-DD][project name] => {
 *   seconds: int,
 *   detail:  [kind => [label => seconds]],
 *   commits: list<commit row>
 * }
 *
 * @param  array        $events   Window and AFK events from loadActivityWatch() after backfillChromeUrls().
 * @param  array        $commits  Commit rows from loadGitCommits().
 * @param  array        $config   Loaded config array.
 * @param  DateTimeZone $tz       Timezone used to bucket events into calendar dates.
 * @param  array        $opts     Parsed CLI options from parseArgs().
 * @return array{0: array<string, array<string, array<string, mixed>>>, 1: array<string, array<string, int>>}
 *         [$bucket, $unmatched]
 */
function classifyAndAggregate(array $events, array $commits, array $config, DateTimeZone $tz, array $opts): array
{
    $bucket = []; // [date_iso][project] = ['seconds' => int, 'commits' => [...], 'detail' => [...]]
    $unmatched = ['vscode' => [], 'browser' => [], 'slack' => [], 'apps' => []];

    $personalHosts = $config['personal_hosts'] ?? [];
    $personalApps  = $config['personal_apps']  ?? [];
    $ignoredProjects = array_fill_keys($config['ignored_projects'] ?? [], true);

    $bumpDetail = function (string $date, string $proj, string $kind, string $label, float $sec) use (&$bucket) {
        $bucket[$date][$proj]['seconds'] = ($bucket[$date][$proj]['seconds'] ?? 0) + $sec;
        $bucket[$date][$proj]['detail'][$kind][$label] = ($bucket[$date][$proj]['detail'][$kind][$label] ?? 0) + $sec;
    };

    $input = $events['input'] ?? [];
    $inputCursor = 0;

    // Returns true if $proj should be included given the --project / group: filter.
    $matchesFilter = static function (string $proj) use ($opts, $config): bool {
        $filter = $opts['project'];
        if (!$filter) {
            return true;
        }
        if (str_starts_with($filter, 'group:')) {
            return ($config['projects'][$proj]['grouping'] ?? null) === substr($filter, 6);
        }
        return $proj === $filter;
    };

    foreach ($events['window'] as $ev) {
        // afk filter (use mid-point)
        $midTs = ($ev['start']->getTimestamp() + $ev['end']->getTimestamp()) / 2;
        $mid = (new DateTimeImmutable('@' . (int)$midTs))->setTimezone($tz);
        if (isAfkAt($mid, $events['afk'])) {
            continue;
        }

        $sec = max(0.0, dtToFloatTs($ev['end']) - dtToFloatTs($ev['start']));
        if ($sec <= 0) {
            continue;
        }
        $activeSec = activeInputSecondsDuring($ev['start'], $ev['end'], $input, $inputCursor);
        $date = $ev['start']->setTimezone($tz)->format('Y-m-d');

        $sig = [];
        $proj = null;
        $detailKind = 'app';
        $detailLabel = $ev['app'];
        switch ($ev['app']) {
            case 'Code':
                $dir = classifyVscode($ev['title']);
                $sig['vscode_dir'] = $dir;
                $proj = projectForSignals($sig, $config);
                $detailKind = 'vscode';
                $detailLabel = $dir ?: '(unknown)';
                if (!$proj && $dir) {
                    $unmatched['vscode'][$dir] = ($unmatched['vscode'][$dir] ?? 0) + 1;
                }
                $proj ??= 'VSCode (uncategorized)';
                break;

            case 'Google Chrome':
                $host = parse_url($ev['url'], PHP_URL_HOST) ?: '';
                $sig['host'] = $host;
                $proj = projectForSignals($sig, $config);
                if (!$proj && $host && fnmatchAny($host, $personalHosts)) {
                    $proj = 'Personal browsing';
                }
                $detailKind = 'browser';
                $detailLabel = $host ?: '(no url)';
                if (!$proj) {
                    $unmatched['browser'][$host ?: '(no url)'] = ($unmatched['browser'][$host ?: '(no url)'] ?? 0) + 1;
                }
                $proj ??= 'Browser (uncategorized)';
                break;

            case 'Slack':
                $s = classifySlack($ev['title']);
                if ($s) {
                    $sig['slack'] = $s;
                    $proj = projectForSignals($sig, $config);
                    $detailKind = 'slack';
                    $detailLabel = "{$s['workspace']} / {$s['channel']}";
                    if (!$proj) {
                        $unmatched['slack']["{$s['workspace']} / {$s['channel']}"] =
                        ($unmatched['slack']["{$s['workspace']} / {$s['channel']}"] ?? 0) + 1;
                    }
                }
                $proj ??= 'Slack (uncategorized)';
                break;

            case 'Terminal':
            case 'iTerm2':
            case 'iTerm':
            case 'Warp':
            case 'Ghostty':
                $host = classifySsh($ev['title']);
                if ($host) {
                    $sig['ssh_host'] = $host;
                    $proj = projectForSignals($sig, $config);
                    $detailKind = 'ssh';
                    $detailLabel = $host;
                    if (!$proj) {
                        $unmatched['apps']["ssh:$host"] = ($unmatched['apps']["ssh:$host"] ?? 0) + 1;
                    }
                }
                $proj ??= 'Terminal';
                break;

            default:
                $sig['app'] = $ev['app'];
                $proj = projectForSignals($sig, $config);
                if (!$proj && in_array($ev['app'], $personalApps, true)) {
                    $proj = 'Personal apps';
                } elseif (!$proj) {
                    $proj = $ev['app'] ? "App: {$ev['app']}" : 'Other';
                }
                if (!fnmatchAny($ev['app'], $personalApps) && str_starts_with($proj, 'App: ')) {
                    $unmatched['apps'][$ev['app']] = ($unmatched['apps'][$ev['app']] ?? 0) + 1;
                }
        }

        if (isset($ignoredProjects[$proj])) {
            continue;
        }

        if (!$matchesFilter($proj)) {
            continue;
        }
        $bumpDetail($date, $proj, $detailKind, $detailLabel, $sec);
        $bucket[$date][$proj]['active_seconds'] = ($bucket[$date][$proj]['active_seconds'] ?? 0) + $activeSec;
    }

    // Commits
    foreach ($commits as $c) {
        $date = $c['dt']->setTimezone($tz)->format('Y-m-d');
        $proj = $c['project'];
        if (!$matchesFilter($proj)) {
            continue;
        }
        $bucket[$date][$proj]['commits'][] = $c;
    }

    // Attach grouping label from config so renderers don't need to re-inspect config.
    foreach (array_keys($bucket) as $date) {
        foreach (array_keys($bucket[$date]) as $proj) {
            $bucket[$date][$proj]['grouping'] = $config['projects'][$proj]['grouping'] ?? null;
            $sec = (float)($bucket[$date][$proj]['seconds'] ?? 0);
            $activeSec = (float)($bucket[$date][$proj]['active_seconds'] ?? 0);
            $bucket[$date][$proj]['activity_ratio'] = $sec > 0 ? min(1.0, $activeSec / $sec) : 0.0;
        }
    }

    return [$bucket, $unmatched];
}
