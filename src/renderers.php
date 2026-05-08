<?php

declare(strict_types=1);

/**
 * Report renderers: Markdown, JSON, and TSV output formatters.
 */

/**
 * Renders a single project entry at the given Markdown heading level.
 *
 * Returns an empty string when the project falls below $minSec with no commits,
 * allowing callers to suppress empty entries and their parent group headers.
 *
 * @param string       $heading Markdown heading prefix ('###' or '####').
 * @param string       $name    Project name.
 * @param array        $rec     Project record: seconds, detail, commits, grouping.
 * @param int          $minSec  Minimum seconds threshold from config.
 * @param DateTimeZone $tz      Display timezone for commit timestamps.
 */
function renderProjectEntry(string $heading, string $name, array $rec, int $minSec, DateTimeZone $tz): string
{
    $sec     = $rec['seconds'] ?? 0;
    $activeSec = $rec['active_seconds'] ?? 0;
    $activityRatio = (float)($rec['activity_ratio'] ?? 0.0);
    $commits = $rec['commits'] ?? [];
    if ($sec < $minSec && !$commits) {
        return '';
    }
    $secStr = $sec ? ' — ' . fmtDur($sec) : '';
    $out    = "$heading $name$secStr\n\n";
    foreach (($rec['detail'] ?? []) as $kind => $items) {
        arsort($items);
        $top = array_slice($items, 0, 6, true);
        if (!$top) {
            continue;
        }
        $bits = [];
        foreach ($top as $label => $s) {
            if ($s < $minSec) {
                continue;
            }
            $bits[] = "$label (" . fmtDur($s) . ")";
        }
        if ($bits) {
            $out .= "- _$kind:_ " . implode(', ', $bits) . "\n";
        }
    }
    if ($sec > 0) {
        $out .= '- _input activity:_ ' . fmtDur($activeSec) . ' (' . (int)round($activityRatio * 100) . "%)\n";
    }
    foreach (($rec['external'] ?? []) as $source => $meta) {
        $entries = (int)($meta['entries'] ?? 0);
        $activity = (int)($meta['activity'] ?? 0);
        $discussion = (int)($meta['discussion'] ?? 0);
        if ($entries > 0 || $activity > 0 || $discussion > 0) {
            $out .= '- _' . $source . ':_ ' . $entries . ' entries';
            if ($activity > 0) {
                $out .= ', ' . $activity . ' activity';
            }
            if ($discussion > 0) {
                $out .= ', ' . $discussion . ' discussion';
            }
            $out .= "\n";
        }
    }
    if ($commits) {
        $out .= "- _commits (" . count($commits) . "):_\n";
        foreach ($commits as $c) {
            $t = $c['dt']->setTimezone($tz)->format('H:i');
            $out .= "    - `$t` `" . substr($c['sha'], 0, 8) . "` " . $c['subj'] . "\n";
        }
    }
    $out .= "\n";
    return $out;
}

/**
 * Renders the activity bucket as a human-readable Markdown report.
 *
 * Days are ordered most-recent first. Within each day, projects that share a
 * 'grouping' value are collected under a ### group header (sorted by total seconds),
 * with each project rendered as ####. Ungrouped projects (no 'grouping' set) are
 * rendered as ### entries after all groups. Projects below min_event_seconds_to_show
 * with no commits are omitted. When --show-unmatched is set, an appendix lists
 * unclassified signals.
 *
 * @param  array             $bucket    Aggregated data from classifyAndAggregate().
 * @param  array             $unmatched Unmatched signal counts from classifyAndAggregate().
 * @param  DateTimeImmutable $from      Report start date.
 * @param  DateTimeImmutable $to        Report end date.
 * @param  DateTimeZone      $tz        Display timezone.
 * @param  array             $opts      Parsed CLI options from parseArgs().
 * @param  array             $config    Loaded config array (used for min_event_seconds_to_show).
 */
function renderMarkdown(
    array $bucket,
    array $unmatched,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    DateTimeZone $tz,
    array $opts,
    array $config
): string {
    $out  = "# Activity report\n\n";
    $out .= sprintf(
        "_Range: %s → %s (%s). Generated %s._\n\n",
        $from->setTimezone($tz)->format('Y-m-d'),
        $to->setTimezone($tz)->format('Y-m-d'),
        $tz->getName(),
        (new DateTimeImmutable('now', $tz))->format('Y-m-d H:i T')
    );
    if ($opts['project']) {
        $out .= "_Filtered to project: **{$opts['project']}**_\n\n";
    }

    $minSec = (int)$config['min_event_seconds_to_show'];
    $dates  = array_keys($bucket);
    sort($dates);
    $dates = array_reverse($dates);

    foreach ($dates as $date) {
        $weekday  = (new DateTimeImmutable($date, $tz))->format('D');
        $dayTotal = array_sum(array_map(fn($r) => $r['seconds'] ?? 0, $bucket[$date]));
        $out .= "## $date ($weekday) — " . fmtDur($dayTotal) . " active\n\n";

        // Sort projects by seconds descending, preserving keys.
        $dayProjects = $bucket[$date];
        uasort($dayProjects, fn($a, $b) => ($b['seconds'] ?? 0) <=> ($a['seconds'] ?? 0));

        // Split into grouped (keyed by grouping name) and ungrouped.
        $grouped   = []; // [groupName => [projName => rec]]
        $ungrouped = []; // [projName => rec]
        foreach ($dayProjects as $name => $rec) {
            $g = $rec['grouping'] ?? null;
            if ($g !== null) {
                $grouped[$g][$name] = $rec;
            } else {
                $ungrouped[$name] = $rec;
            }
        }

        // Render groups sorted by total seconds descending.
        if ($grouped) {
            $groupTotals = [];
            foreach ($grouped as $g => $projs) {
                $groupTotals[$g] = array_sum(array_map(fn($r) => $r['seconds'] ?? 0, $projs));
            }
            arsort($groupTotals);
            foreach (array_keys($groupTotals) as $g) {
                $groupEntry = '';
                foreach ($grouped[$g] as $name => $rec) {
                    $groupEntry .= renderProjectEntry('####', $name, $rec, $minSec, $tz);
                }
                if ($groupEntry !== '') {
                    $groupSec = $groupTotals[$g];
                    $groupSecStr = $groupSec ? ' — ' . fmtDur($groupSec) : '';
                    $out .= "### $g$groupSecStr\n\n" . $groupEntry;
                }
            }
        }

        // Render ungrouped projects at the ### level.
        foreach ($ungrouped as $name => $rec) {
            $out .= renderProjectEntry('###', $name, $rec, $minSec, $tz);
        }
    }

    if ($opts['show_unmatched']) {
        $out .= "---\n\n## Unmatched signals\n\n";
        foreach ($unmatched as $kind => $items) {
            if (!$items) {
                continue;
            }
            arsort($items);
            $out .= "### $kind\n\n";
            foreach (array_slice($items, 0, 20, true) as $k => $n) {
                $out .= "- `$k` ($n events)\n";
            }
            $out .= "\n";
        }
    }

    return $out;
}

/**
 * Renders the activity bucket as a pretty-printed JSON string.
 *
 * Commits are serialized to {time, sha, subj, repo} objects with RFC 3339 timestamps.
 *
 * Output envelope shape:
 * ```json
 * {
 *   "from": "<RFC 3339>",
 *   "to":   "<RFC 3339>",
 *   "tz":   "<IANA timezone name>",
 *   "days": {
 *     "YYYY-MM-DD": {
 *       "<project name>": {
 *         "grouping":       "<string|null>",
 *         "seconds":        "<int>",
 *         "active_seconds": "<int>",
 *         "activity_ratio": "<float 0–1>",
 *         "detail": { "<kind>": { "<label>": "<seconds int>" } },
 *         "external": {
 *           "<source>": { "entries": "<int>", "activity": "<int>", "discussion": "<int>" }
 *         },
 *         "commits": [{ "time": "<RFC 3339>", "sha": "<string>", "subj": "<string>", "repo": "<string>" }]
 *       }
 *     }
 *   },
 *   "unmatched": { "<kind>": { "<value>": "<event count int>" } },
 *   "warnings":  ["<string>"],
 *   "timelines": {
 *     "YYYY-MM-DD": [{ "s": "<int>", "e": "<int>", "p": "<project>", "g": "<grouping|null>" }]
 *   }
 * }
 * ```
 * `warnings` is omitted when empty. `timelines` is omitted when empty.
 * `s`/`e` in timeline segments are seconds from local midnight.
 *
 * @param  array             $bucket    Aggregated data from classifyAndAggregate().
 * @param  array             $unmatched Unmatched signal counts from classifyAndAggregate().
 * @param  DateTimeImmutable $from      Report start date.
 * @param  DateTimeImmutable $to        Report end date.
 * @param  DateTimeZone      $tz        Display timezone.
 * @param  array             $warnings  Integration warnings from getIntegrationWarnings().
 * @param  array             $timeline  Timeline segments from classifyAndAggregate()[2].
 * @return string Pretty-printed JSON followed by a newline.
 */
function renderJson(
    array $bucket,
    array $unmatched,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    DateTimeZone $tz,
    array $warnings = [],
    array $timeline = []
): string {
    $clean = [];
    foreach ($bucket as $date => $projs) {
        foreach ($projs as $name => $rec) {
            $clean[$date][$name] = [
                'grouping' => $rec['grouping'] ?? null,
                'seconds'  => $rec['seconds']  ?? 0,
                'active_seconds' => $rec['active_seconds'] ?? 0,
                'activity_ratio' => $rec['activity_ratio'] ?? 0,
                'external' => $rec['external'] ?? [],
                'detail'   => $rec['detail']   ?? [],
                'commits' => array_map(fn($c) => [
                    'time' => $c['dt']->setTimezone($tz)->format('c'),
                    'sha'  => $c['sha'],
                    'subj' => $c['subj'],
                    'repo' => $c['repo'],
                ], $rec['commits'] ?? []),
            ];
        }
    }
    $envelope = [
        'from' => $from->format('c'),
        'to'   => $to->format('c'),
        'tz'   => $tz->getName(),
        'days' => $clean,
        'unmatched' => $unmatched,
    ];
    if ($warnings) {
        $envelope['warnings'] = array_values($warnings);
    }
    if ($timeline) {
        $envelope['timelines'] = $timeline;
    }
    return json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

/**
 * Renders the activity bucket as a tab-separated values (TSV) string.
 *
 * Columns: date, project, seconds, commits. One row per (date, project) pair,
 * sorted ascending by date. No per-signal detail breakdown is included.
 * The $from and $to parameters are accepted for interface symmetry but are not
 * written to the output.
 *
 * @param  array             $bucket Aggregated data from classifyAndAggregate().
 * @param  DateTimeImmutable $from   Report start date (not written to output).
 * @param  DateTimeImmutable $to     Report end date (not written to output).
 * @param  DateTimeZone      $tz     Display timezone (not used; dates are already bucketed).
 */
function renderTsv(array $bucket, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $tz): string
{
    $rows = [
        "date\tgrouping\tproject\tseconds\tactive_seconds\tactivity_ratio\tcommits\t"
        . "harvest_entries\tharvest_discussion\tclickup_entries\tclickup_discussion\tgithub_entries\tgithub_activity\tgithub_discussion",
    ];
    $tsv = static fn(string $s): string => str_replace(["\t", "\r\n", "\r", "\n"], [' ', ' ', ' ', ' '], $s);
    $dates = array_keys($bucket);
    sort($dates);
    foreach ($dates as $date) {
        foreach ($bucket[$date] as $proj => $rec) {
            $grouping = $rec['grouping'] ?? '';
            $harvest = $rec['external']['harvest'] ?? [];
            $clickup = $rec['external']['clickup'] ?? [];
            $github = $rec['external']['github'] ?? [];
            $rows[] = "$date\t" . $tsv($grouping) . "\t" . $tsv((string)$proj) . "\t"
                . (int)($rec['seconds'] ?? 0)
                . "\t" . (int)($rec['active_seconds'] ?? 0)
                . "\t" . sprintf('%.3f', (float)($rec['activity_ratio'] ?? 0))
                . "\t" . count($rec['commits'] ?? [])
                . "\t" . (int)($harvest['entries'] ?? 0)
                . "\t" . (int)($harvest['discussion'] ?? 0)
                . "\t" . (int)($clickup['entries'] ?? 0)
                . "\t" . (int)($clickup['discussion'] ?? 0)
                . "\t" . (int)($github['entries'] ?? 0)
                . "\t" . (int)($github['activity'] ?? 0)
                . "\t" . (int)($github['discussion'] ?? 0);
        }
    }
    return implode("\n", $rows) . "\n";
}
