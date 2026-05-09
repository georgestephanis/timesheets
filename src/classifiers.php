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
 *   2. host       — domain rule match against projects[*].domains (bare domains include subdomains)
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
            if (hostMatchesAnyDomain((string)$sig['host'], $p['domains'])) {
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
 * Resolves a project for an external integration row using project mapping rules.
 */
function projectForExternal(array $row, array $config): ?string
{
    $explicitProject = (string)($row['project'] ?? '');
    if ($explicitProject !== '' && isset($config['projects'][$explicitProject])) {
        return $explicitProject;
    }

    $source = (string)($row['source'] ?? '');
    $hint = (string)($row['project_hint'] ?? '');
    if ($source === '' || $hint === '') {
        return null;
    }

    $clientName = $source === 'harvest' ? (string)($row['client_name'] ?? '') : '';

    foreach ($config['projects'] as $name => $p) {
        if ($source === 'harvest') {
            if (!empty($p['harvest_projects']) && fnmatchAny($hint, $p['harvest_projects'])) {
                return $name;
            }
            if (
                $clientName !== '' && !empty($p['harvest_client'])
                && strcasecmp((string)$p['harvest_client'], $clientName) === 0
            ) {
                return $name;
            }
        }
        if ($source === 'clickup' && !empty($p['clickup_tasks']) && fnmatchAny($hint, $p['clickup_tasks'])) {
            return $name;
        }
    }

    return null;
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
 * @param  array        $external External integration rows from loadIntegrationActivity().
 * @param  array        $config   Loaded config array.
 * @param  DateTimeZone $tz       Timezone used to bucket events into calendar dates.
 * @param  array        $opts     Parsed CLI options from parseArgs().
 * @return array{
 *     0: array<string, array<string, array<string, mixed>>>,
 *     1: array<string, array<string, int>>,
 *     2: array<string, list<array{s: int, e: int, p: string, g: string|null}>>
 * }  [$bucket, $unmatched, $timeline]
 */
function classifyAndAggregate(array $events, array $commits, array $external, array $config, DateTimeZone $tz, array $opts): array
{
    $bucket = []; // [date_iso][project] = ['seconds' => int, 'commits' => [...], 'detail' => [...]]
    $unmatched = ['vscode' => [], 'browser' => [], 'slack' => [], 'apps' => [], 'harvest' => [], 'clickup' => [], 'github' => []];

    $personalHosts = $config['personal_hosts'] ?? [];
    $personalApps  = $config['personal_apps']  ?? [];
    $ignoredProjects = array_fill_keys($config['ignored_projects'] ?? [], true);

    $correlatedApps = (array)($config['correlated_apps'] ?? []);
    if (($config['discover_repos'] ?? '') === 'github_desktop' && !in_array('GitHub Desktop', $correlatedApps, true)) {
        $correlatedApps[] = 'GitHub Desktop';
    }
    $correlationWindow = (int)($config['app_correlation_window_seconds'] ?? 900);
    $gapWindow = (int)($config['project_gap_window_seconds'] ?? 300);
    $lastKnownProject = null;
    $lastKnownProjectTs = 0;

    // Pending gap queue for sticky-project bridging: when the user leaves a known project
    // for untracked/personal activity, we buffer those events. If the same project resumes
    // within $gapWindow seconds, the buffered time is attributed to that project. If a
    // different configured project appears, or the gap exceeds the window, we flush normally.
    $gapQueue    = []; // list of [date, proj, kind, label, sec, activeSec, tlSeg]
    $gapStartProj = null;
    $gapTotalSec  = 0.0;

    $timelineRaw = [];
    $dayStarts   = [];

    $bumpDetail = function (string $date, string $proj, string $kind, string $label, float $sec) use (&$bucket) {
        $bucket[$date][$proj]['seconds'] = ($bucket[$date][$proj]['seconds'] ?? 0) + $sec;
        $bucket[$date][$proj]['detail'][$kind][$label] = ($bucket[$date][$proj]['detail'][$kind][$label] ?? 0) + $sec;
    };

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

    // Flush buffered gap events into $targetProj (or their original project if null).
    // $queue is passed by value so PHPStan can see the concrete type at each call site.
    $flushGap = function (
        array $queue,
        ?string $targetProj
    ) use (
        &$bucket,
        &$timelineRaw,
        $bumpDetail,
        $ignoredProjects,
        $matchesFilter
    ): void {
        foreach ($queue as $entry) {
            [$date, $origProj, $kind, $label, $sec, $activeSec, $tlSeg] = $entry;
            $proj = $targetProj ?? $origProj;
            if (isset($ignoredProjects[$proj]) || !$matchesFilter($proj)) {
                continue;
            }
            $bumpDetail($date, $proj, $kind, $label, $sec);
            $bucket[$date][$proj]['active_seconds'] = ($bucket[$date][$proj]['active_seconds'] ?? 0) + $activeSec;
            $tlSeg['p'] = $proj;
            $timelineRaw[$date][] = $tlSeg;
        }
    };

    $input = $events['input'] ?? [];
    $inputCursor = 0;

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
            case 'Code':          // macOS / Windows
            case 'Code - OSS':    // Linux (AW reports this for VSCodium/snap builds)
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
                if (!$proj && $host && hostMatchesAnyDomain($host, $personalHosts)) {
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

            case 'Terminal':          // macOS built-in
            case 'iTerm2':            // macOS iTerm2
            case 'iTerm':             // macOS iTerm (legacy)
            case 'Warp':              // macOS/Linux Warp
            case 'Ghostty':           // macOS/Linux Ghostty
            case 'Windows Terminal':  // Windows Terminal (wt)
            case 'PowerShell':        // Windows PowerShell
            case 'pwsh':              // Windows PowerShell Core
            case 'cmd':               // Windows Command Prompt
            case 'alacritty':         // Linux/Windows Alacritty
            case 'kitty':             // Linux/macOS kitty
            case 'konsole':           // Linux KDE Konsole
            case 'gnome-terminal':    // Linux GNOME Terminal
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
                } elseif (!$proj && $correlatedApps !== [] && fnmatchAny($ev['app'], $correlatedApps)) {
                    $gap = $ev['start']->getTimestamp() - $lastKnownProjectTs;
                    if ($lastKnownProject !== null && $correlationWindow > 0 && $gap <= $correlationWindow) {
                        $proj = $lastKnownProject;
                    }
                }
                if (!$proj) {
                    $proj = $ev['app'] ? "App: {$ev['app']}" : 'Other';
                }
                if (
                    !fnmatchAny($ev['app'], $personalApps)
                    && !fnmatchAny($ev['app'], $correlatedApps)
                    && str_starts_with($proj, 'App: ')
                ) {
                    $unmatched['apps'][$ev['app']] = ($unmatched['apps'][$ev['app']] ?? 0) + 1;
                }
        }

        // Update correlation context before filter/gap checks so all activity is reflected.
        $isConfiguredProject = array_key_exists($proj, $config['projects'] ?? []);
        if ($isConfiguredProject) {
            $lastKnownProject  = $proj;
            $lastKnownProjectTs = $ev['end']->getTimestamp();
        }

        // Build the timeline segment now (proj may be reassigned by gap-bridging below).
        $dayStarts[$date] ??= (new DateTimeImmutable($date, $tz))->getTimestamp();
        $tlSeg = [
            's' => max(0, $ev['start']->getTimestamp() - $dayStarts[$date]),
            'e' => min(86400, $ev['end']->getTimestamp() - $dayStarts[$date]),
            'p' => $proj,
            'g' => $config['projects'][$proj]['grouping'] ?? null,
        ];

        // Gap-bridging: decide whether to queue, flush, or emit directly.
        if ($gapWindow > 0 && $gapQueue !== []) {
            if ($isConfiguredProject) {
                if ($proj === $gapStartProj && $gapTotalSec <= $gapWindow) {
                    // Returned to the same project within the window — bridge the gap.
                    $flushGap($gapQueue, $proj);
                } else {
                    // Different configured project filled the gap — flush unattributed.
                    $flushGap($gapQueue, null);
                }
                $gapQueue    = [];
                $gapStartProj = null;
                $gapTotalSec  = 0.0;
            } elseif ($gapTotalSec + $sec > $gapWindow) {
                // Gap has grown past the window — flush unattributed and stop queuing.
                $flushGap($gapQueue, null);
                $gapQueue    = [];
                $gapStartProj = null;
                $gapTotalSec  = 0.0;
            }
        }

        if (isset($ignoredProjects[$proj])) {
            continue;
        }
        if (!$matchesFilter($proj)) {
            continue;
        }

        // Determine if this event should be queued (potential bridge gap) or emitted.
        $isUntracked = !$isConfiguredProject && !str_starts_with($proj, 'Personal');
        $isPersonal  = $proj === 'Personal browsing' || $proj === 'Personal apps'
            || str_starts_with($proj, 'Personal');

        if (
            $gapWindow > 0 && $lastKnownProject !== null && ($isUntracked || $isPersonal)
            && $gapTotalSec + $sec <= $gapWindow
        ) {
            // Queue this event as a candidate gap.
            $gapQueue[]   = [$date, $proj, $detailKind, $detailLabel, $sec, $activeSec, $tlSeg];
            $gapStartProj ??= $lastKnownProject;
            $gapTotalSec  += $sec;
            continue;
        }

        // Emit immediately.
        $bumpDetail($date, $proj, $detailKind, $detailLabel, $sec);
        $bucket[$date][$proj]['active_seconds'] = ($bucket[$date][$proj]['active_seconds'] ?? 0) + $activeSec;
        $timelineRaw[$date][] = $tlSeg;
    }

    // Flush any remaining gap queue at end of event stream (no project resumed).
    if ($gapQueue !== []) {
        $flushGap($gapQueue, null);
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

    // External integrations
    foreach ($external as $row) {
        $sec = (float)($row['seconds'] ?? 0);
        $source = (string)($row['source'] ?? 'external');
        $proj = projectForExternal($row, $config);
        $hint = (string)($row['project_hint'] ?? '');
        $label = (string)($row['label'] ?? ($hint !== '' ? $hint : $source));
        if (!$proj) {
            $unmatched[$source][$hint !== '' ? $hint : '(unknown)'] = ($unmatched[$source][$hint !== '' ? $hint : '(unknown)'] ?? 0) + 1;
            $proj = strtoupper($source) . ' (uncategorized)';
        }

        if (isset($ignoredProjects[$proj])) {
            continue;
        }
        if (!$matchesFilter($proj)) {
            continue;
        }

        $start = $row['start'] instanceof DateTimeImmutable
            ? $row['start']
            : new DateTimeImmutable((string)$row['start']);
        $date = $start->setTimezone($tz)->format('Y-m-d');

        if ($sec > 0) {
            $bucket[$date][$proj]['seconds'] = ($bucket[$date][$proj]['seconds'] ?? 0) + $sec;
            $bucket[$date][$proj]['active_seconds'] = ($bucket[$date][$proj]['active_seconds'] ?? 0) + $sec;
            $bucket[$date][$proj]['detail'][$source][$label] = ($bucket[$date][$proj]['detail'][$source][$label] ?? 0) + $sec;
        }

        $bucket[$date][$proj]['external'][$source]['entries'] = ($bucket[$date][$proj]['external'][$source]['entries'] ?? 0)
            + (int)($row['entry_count'] ?? 1);
        $bucket[$date][$proj]['external'][$source]['activity'] = ($bucket[$date][$proj]['external'][$source]['activity'] ?? 0)
            + (int)($row['activity_count'] ?? 0);
        $bucket[$date][$proj]['external'][$source]['discussion'] = ($bucket[$date][$proj]['external'][$source]['discussion'] ?? 0)
            + (int)($row['discussion_count'] ?? 0);
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

    // Merge adjacent same-project timeline segments and drop very short ones.
    // $lastByProject tracks the index of the most recent merged entry per project so that
    // a segment from a different project sorting between two same-project entries doesn't
    // prevent them from being merged.
    $tlMergeGap = (int)($config['timeline_merge_gap_seconds'] ?? 300);
    $tlMinSec   = (int)($config['timeline_min_seconds'] ?? 60);
    $timeline = [];
    foreach ($timelineRaw as $date => $segs) {
        usort($segs, fn($a, $b) => $a['s'] <=> $b['s']);
        $merged = [];
        $lastByProject = [];
        foreach ($segs as $seg) {
            $lastIdx = $lastByProject[$seg['p']] ?? -1;
            if ($lastIdx >= 0 && $seg['s'] - $merged[$lastIdx]['e'] <= $tlMergeGap) {
                $merged[$lastIdx]['e'] = max($merged[$lastIdx]['e'], $seg['e']);
            } else {
                $merged[] = $seg;
                $lastByProject[$seg['p']] = count($merged) - 1;
            }
        }
        $timeline[$date] = array_values(array_filter($merged, fn($s) => ($s['e'] - $s['s']) >= $tlMinSec));
    }

    return [$bucket, $unmatched, $timeline];
}
