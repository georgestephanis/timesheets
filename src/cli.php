<?php

declare(strict_types=1);

/**
 * CLI layer: argument parsing, help text, project listing, date-range resolution, and main().
 */

const VERSION = '0.1.0';

/**
 * Entry point: parses CLI arguments, loads data from all sources (or cache for historical
 * ranges), classifies events into projects, renders the report, writes it to STDOUT, and
 * persists raw data and the generated output to the reports directory.
 *
 * @param array<string, mixed> $config Loaded and validated config array.
 */
function main(array $config): void
{
    $argv = $GLOBALS['argv'] ?? [];
    $opts = parseArgs($argv);
    if ($opts['help']) {
        printHelp();
        exit(0);
    }
    if ($opts['list_projects']) {
        printProjects($config);
        exit(0);
    }

    $tz = new DateTimeZone($config['timezone']);
    if (invokedWithoutOptions($argv)) {
        backfillRecentDailyReports($config, $tz);
        return;
    }

    [$from, $to] = resolveDateRange($opts, $tz);
    echo generateReport($config, $opts, $tz, $from, $to);

    if ($opts['suggest']) {
        runLlmSuggest($config, $opts, $tz, $from, $to);
    }
}

/**
 * Returns true when the script was invoked without any CLI options beyond the script name.
 *
 * @param string[] $argv Raw argument vector, including the script name.
 */
function invokedWithoutOptions(array $argv): bool
{
    return count($argv) <= 1;
}

/**
 * Generates any missing or incomplete single-day reports for the previous seven completed days.
 *
 * A day is considered complete once a full unfiltered report exists with a generation
 * timestamp at or after the following midnight in the configured timezone.
 *
 * @param array<string, mixed> $config Loaded and validated config array.
 */
function backfillRecentDailyReports(array $config, DateTimeZone $tz): void
{
    $today = new DateTimeImmutable('today', $tz);
    $generated = [];
    $skipped = [];

    for ($daysAgo = 7; $daysAgo >= 1; $daysAgo--) {
        $day = $today->sub(new DateInterval("P{$daysAgo}D"));
        if (dailyReportNeedsRefresh($day, $tz)) {
            $opts = [
                'days' => null,
                'from' => $day->format('Y-m-d'),
                'to' => $day->format('Y-m-d'),
                'project' => null,
                'format' => 'md',
                'show_unmatched' => false,
                'list_projects' => false,
                'help' => false,
            ];
            $from = $day->setTime(0, 0, 0);
            $to = $day->setTime(23, 59, 59);
            generateReport($config, $opts, $tz, $from, $to);
            $generated[] = $day->format('Y-m-d');
            continue;
        }

        $skipped[] = $day->format('Y-m-d');
    }

    foreach ($generated as $date) {
        fwrite(STDOUT, "generated $date\n");
    }
    foreach ($skipped as $date) {
        fwrite(STDOUT, "kept $date\n");
    }
}

/**
 * Returns true when a daily report is missing or only has artifacts generated before the day ended.
 */
function dailyReportNeedsRefresh(DateTimeImmutable $day, DateTimeZone $tz): bool
{
    $from = $day->setTime(0, 0, 0);
    $to = $day->setTime(23, 59, 59);
    $dir = reportsDir($from);
    $key = reportsCacheKey($from, $to);
    $latestGeneratedAt = findLatestFullReportGeneratedAt($dir, $key, $tz);
    if ($latestGeneratedAt === null) {
        return true;
    }

    $dayCompletedAt = $from->modify('+1 day');
    return $latestGeneratedAt < $dayCompletedAt;
}

/**
 * Loads, classifies, renders, and persists a report for a concrete date range.
 *
 * @param array<string, mixed> $config Loaded and validated config array.
 * @param array<string, mixed> $opts   Parsed CLI options controlling output.
 */
function generateReport(
    array $config,
    array $opts,
    DateTimeZone $tz,
    DateTimeImmutable $from,
    DateTimeImmutable $to
): string {
    [
        'events' => $events,
        'commits' => $commits,
        'external' => $external,
        'from_cache' => $fromCache,
    ] = loadSourcesForRange($config, $tz, $from, $to);

    $dir = reportsDir($from);
    $key = reportsCacheKey($from, $to);

    $fullOpts = $opts;
    $fullOpts['project'] = null;
    [$fullBucket, $fullUnmatched, $fullTimeline] = classifyAndAggregate($events, $commits, $external, $config, $tz, $fullOpts);

    $hasProjectFilter = !empty($opts['project']);
    if ($hasProjectFilter) {
        [$bucket, $unmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $opts);
    } else {
        [$bucket, $unmatched] = [$fullBucket, $fullUnmatched];
    }
    $timeline = $hasProjectFilter ? [] : $fullTimeline;

    $format = $opts['format'];
    $out = match ($format) {
        'json' => renderJson($bucket, $unmatched, $from, $to, $tz, [], $timeline),
        'tsv'  => renderTsv($bucket, $from, $to, $tz),
        default => renderMarkdown($bucket ?? [], $unmatched, $from, $to, $tz, $opts, $config),
    };

    $fullOut = match ($format) {
        'json' => renderJson($fullBucket, $fullUnmatched, $from, $to, $tz, [], $fullTimeline),
        'tsv'  => renderTsv($fullBucket, $from, $to, $tz),
        default => renderMarkdown($fullBucket ?? [], $fullUnmatched, $from, $to, $tz, $fullOpts, $config),
    };

    saveGeneratedReport($dir, $key, $from, $to, $format, null, $fromCache, $fullOut);

    if ($format === 'md') {
        $jsonOut = renderJson($fullBucket, $fullUnmatched, $from, $to, $tz, [], $fullTimeline);
        saveGeneratedReport($dir, $key, $from, $to, 'json', null, $fromCache, $jsonOut);
    }

    return $out;
}

/**
 * Loads source data for the requested range using only daily cache buckets.
 *
 * Historical full days are read from or written to one-day caches. Incomplete current-day
 * slices are loaded fresh and are not cached.
 *
 * @param  array<string, mixed> $config Loaded and validated config array.
 * @return array{events: array, chrome: array, commits: array, external: array, from_cache: bool}
 */
function loadSourcesForRange(array $config, DateTimeZone $tz, DateTimeImmutable $from, DateTimeImmutable $to, bool $rebuild = false): array
{
    $bundles = [];
    $fromCache = true;

    foreach (rangeDays($from, $to, $tz) as $day) {
        $dayStart = $day->setTime(0, 0, 0);
        $dayEnd = $day->setTime(23, 59, 59);
        $sliceFrom = $from > $dayStart ? $from : $dayStart;
        $sliceTo = $to < $dayEnd ? $to : $dayEnd;
        $isFullDay = $sliceFrom == $dayStart && $sliceTo == $dayEnd;
        $isHistoricalDay = rangeIsHistorical($dayEnd, $tz);

        if (!$rebuild && $isFullDay && $isHistoricalDay) {
            $cached = loadDailyCachedSources($dayStart);
            if ($cached !== null) {
                $bundles[] = $cached;
                continue;
            }
        }

        $bundles[] = loadFreshSourceSlice($config, $sliceFrom, $sliceTo);
        $fromCache = false;

        if ($isFullDay && $isHistoricalDay) {
            $fullDayBundle = end($bundles);
            saveDailyCachedSources(
                $dayStart,
                $fullDayBundle['events'],
                $fullDayBundle['chrome'],
                $fullDayBundle['commits'],
                $fullDayBundle['external']
            );
        }
    }

    $filtered = filterSourcesToRange(mergeSourceBundles($bundles), $from, $to);

    return [
        'events' => $filtered['events'],
        'chrome' => $filtered['chrome'],
        'commits' => $filtered['commits'],
        'external' => $filtered['external'],
        'from_cache' => $fromCache,
    ];
}

/**
 * Loads a fresh source bundle for one contiguous time slice and applies Chrome backfill.
 *
 * @param  array<string, mixed> $config Loaded and validated config array.
 * @return array{events: array, chrome: array, commits: array, external: array}
 */
function loadFreshSourceSlice(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $events = loadActivityWatch($config, $from, $to);
    $chrome = loadChromeHistory($config, $from, $to);
    $commits = loadGitCommits($config, $from, $to);
    $external = loadIntegrationActivity($config, $from, $to);
    backfillChromeUrls($events, $chrome, (int) $config['chrome_correlation_window_seconds']);

    return [
        'events' => $events,
        'chrome' => $chrome,
        'commits' => $commits,
        'external' => $external,
    ];
}

/**
 * Parses $argv into a structured options map.
 *
 * Supports both space-separated (--days 3) and equals-sign (--days=3) forms.
 * Exits with code 2 on an unrecognised flag.
 *
 * @param  string[] $argv  Raw argument vector, including the script name at index 0.
 * @return array{days: int|null, from: string|null, to: string|null, project: string|null,
 *               format: string, show_unmatched: bool, list_projects: bool, help: bool, suggest: bool}
 */
function parseArgs(array $argv): array
{
    array_shift($argv);
    $opts = [
        'days' => null, 'from' => null, 'to' => null,
        'project' => null, 'format' => 'md',
        'show_unmatched' => false, 'list_projects' => false, 'help' => false, 'suggest' => false,
    ];
    while ($a = array_shift($argv)) {
        switch ($a) {
            case '-h':
            case '--help':
                $opts['help'] = true;
                break;
            case '--list-projects':
                $opts['list_projects'] = true;
                break;
            case '--show-unmatched':
                $opts['show_unmatched'] = true;
                break;
            case '--suggest':
                $opts['suggest'] = true;
                break;
            case '--days':
                $opts['days'] = (int)array_shift($argv);
                break;
            case '--from':
                $opts['from'] = array_shift($argv);
                break;
            case '--to':
                $opts['to']   = array_shift($argv);
                break;
            case '--project':
                $opts['project'] = array_shift($argv);
                break;
            case '--format':
                $opts['format']  = array_shift($argv);
                break;
            default:
                if (str_starts_with($a, '--days=')) {
                    $opts['days'] = (int)substr($a, 7);
                } elseif (str_starts_with($a, '--from=')) {
                    $opts['from'] = substr($a, 7);
                } elseif (str_starts_with($a, '--to=')) {
                    $opts['to'] = substr($a, 5);
                } elseif (str_starts_with($a, '--project=')) {
                    $opts['project'] = substr($a, 10);
                } elseif (str_starts_with($a, '--format=')) {
                    $opts['format'] = substr($a, 9);
                } else {
                    fwrite(STDERR, "Unknown arg: $a\n");
                    exit(2);
                }
        }
    }
    return $opts;
}

/**
 * Prints the --help text to STDOUT.
 */
function printHelp(): void
{
    fwrite(STDOUT, <<<TXT
activity-report.php v0.1.0 — clusters local activity by project.

    No args              Generate one full report per day for the prior 7 completed days.

    --days N             Look back N days.
  --from YYYY-MM-DD    Explicit start date (overrides --days).
  --to   YYYY-MM-DD    Explicit end date (default = today).
  --project NAME       Show only this project.
  --format md|json|tsv Output format (default md).
  --show-unmatched     List app/title/host events that didn't map to a project.
  --suggest            Ask the configured LLM to suggest project assignments for unmatched signals,
                       then prompt to accept each one.
  --list-projects      Print configured projects and exit.
  -h, --help           This message.

Edit config.json to define projects and paths.

TXT);
}

/**
 * Prints each configured project and its associated signals to STDOUT.
 *
 * @param array<string, mixed> $config Loaded config array.
 */
function printProjects(array $config): void
{
    foreach ($config['projects'] as $name => $p) {
        echo "$name\n";
        foreach (['repos','vscode_dirs','domains','ssh_hosts'] as $k) {
            if (!empty($p[$k])) {
                echo "  $k: " . implode(', ', $p[$k]) . "\n";
            }
        }
        if (!empty($p['slack'])) {
            echo "  slack:\n";
            foreach ($p['slack'] as $s) {
                $bits = ["workspace={$s['workspace']}"];
                if (!empty($s['channel_glob'])) {
                    $bits[] = "channel_glob={$s['channel_glob']}";
                }
                echo "    - " . implode(' ', $bits) . "\n";
            }
        }
    }
}

/**
 * Loads sources (from cache), re-classifies to collect unmatched signals, sends them to the
 * configured LLM, and interactively prompts the user to accept each suggestion. Accepted
 * suggestions are written to config.json with a timestamped backup.
 *
 * @param array<string, mixed> $config
 * @param array<string, mixed> $opts
 */
function runLlmSuggest(array $config, array $opts, DateTimeZone $tz, DateTimeImmutable $from, DateTimeImmutable $to): void
{
    if (llmGetConnection($config) === null) {
        fwrite(STDERR, "No LLM connection configured — add one under integrations.llm in config.json.\n");
        return;
    }

    // Sources are already cached from generateReport; this re-uses the per-day cache.
    [
        'events'   => $events,
        'commits'  => $commits,
        'external' => $external,
    ] = loadSourcesForRange($config, $tz, $from, $to);

    $allOpts            = $opts;
    $allOpts['project'] = null;
    [, $unmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $allOpts);

    $total = 0;
    foreach (['vscode', 'browser', 'slack', 'apps'] as $kind) {
        $total += count($unmatched[$kind] ?? []);
    }

    if ($total === 0) {
        fwrite(STDOUT, "\nNo unmatched signals to classify.\n");
        return;
    }

    fwrite(STDOUT, "\nAsking LLM to suggest project assignments for $total unmatched signal(s)...\n");

    try {
        $suggestions = llmSuggestAssignments($unmatched, $config);
    } catch (\Throwable $e) {
        fwrite(STDERR, 'LLM error: ' . $e->getMessage() . "\n");
        return;
    }

    if (!$suggestions) {
        fwrite(STDOUT, "LLM returned no confident suggestions.\n");
        return;
    }

    $n        = count($suggestions);
    $accepted = [];

    fwrite(STDOUT, "$n suggestion(s):\n\n");
    foreach ($suggestions as $i => $s) {
        fwrite(STDOUT, sprintf(
            "  [%d/%d] %s \"%s\" → \"%s\"\n        %s\n        Accept? [y/N]: ",
            $i + 1,
            $n,
            $s['kind'],
            $s['value'],
            $s['project'],
            $s['reason']
        ));
        $answer = fgets(STDIN);
        if ($answer !== false && strtolower(trim($answer)) === 'y') {
            $accepted[] = $s;
        }
        fwrite(STDOUT, "\n");
    }

    if (!$accepted) {
        fwrite(STDOUT, "No suggestions accepted.\n");
        return;
    }

    $configFile = PROJECT_ROOT . '/config.json';
    $current = json_decode((string)file_get_contents($configFile), true);
    if (!is_array($current)) {
        fwrite(STDERR, "error: config.json is invalid JSON\n");
        return;
    }

    foreach ($accepted as $s) {
        applySignalToProject($current, $s['kind'], $s['value'], $s['project']);
    }

    try {
        $backupPath = saveConfigWithBackup($current, $configFile, 'llm-suggest');
    } catch (RuntimeException $e) {
        fwrite(STDERR, "error: " . $e->getMessage() . "\n");
        return;
    }

    fwrite(STDOUT, count($accepted) . " assignment(s) saved to config.json.\n");
    fwrite(STDOUT, "Backup: $backupPath\n");
}

/**
 * Resolves --from / --to / --days options into a concrete [from, to] date range.
 *
 * When --from is given, --to defaults to now. When neither --from nor --days is
 * given, the range is the past 7 days starting at midnight.
 *
 * @param  array        $opts  Parsed options map from parseArgs().
 * @param  DateTimeZone $tz    Timezone used to interpret bare YYYY-MM-DD strings.
 * @return array{DateTimeImmutable, DateTimeImmutable}  [from, to]
 */
function resolveDateRange(array $opts, DateTimeZone $tz): array
{
    $now = new DateTimeImmutable('now', $tz);
    if ($opts['from']) {
        $fromInput = (string)$opts['from'];
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromInput)
            ? new DateTimeImmutable($fromInput . ' 00:00:00', $tz)
            : new DateTimeImmutable($fromInput, $tz);

        if ($opts['to']) {
            $toInput = (string)$opts['to'];
            $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $toInput)
                ? new DateTimeImmutable($toInput . ' 23:59:59', $tz)
                : new DateTimeImmutable($toInput, $tz);
        } else {
            $to = $now;
        }
    } else {
        $days = $opts['days'] ?? 7;
        $to = $now;
        $from = $now->sub(new DateInterval("P{$days}D"))->setTime(0, 0, 0);
    }
    return [$from, $to];
}
