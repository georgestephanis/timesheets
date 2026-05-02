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
    $opts = parseArgs($GLOBALS['argv'] ?? []);
    if ($opts['help']) {
        printHelp();
        exit(0);
    }
    if ($opts['list_projects']) {
        printProjects($config);
        exit(0);
    }

    $tz  = new DateTimeZone($config['timezone']);
    [$from, $to] = resolveDateRange($opts, $tz);

    $dir    = reportsDir($from);
    $key    = reportsCacheKey($from, $to);
    $cached = rangeIsHistorical($to, $tz) ? loadCachedSources($dir, $key) : null;

    if ($cached) {
        ['events' => $events, 'chrome' => $chrome, 'commits' => $commits] = $cached;
    } else {
        $events  = loadActivityWatch($config, $from, $to);
        $chrome  = loadChromeHistory($config, $from, $to);
        $commits = loadGitCommits($config, $from, $to);
        // Back-fill before caching so cached events already carry URLs.
        backfillChromeUrls($events, $chrome, (int)$config['chrome_correlation_window_seconds']);
    }

    [$bucket, $unmatched] = classifyAndAggregate($events, $commits, $config, $tz, $opts);

    $format = $opts['format'];
    $out = match ($format) {
        'json' => renderJson($bucket, $unmatched, $from, $to, $tz),
        'tsv'  => renderTsv($bucket, $from, $to, $tz),
        default => renderMarkdown($bucket, $unmatched, $from, $to, $tz, $opts, $config),
    };

    if (!$cached) {
        saveCachedSources($dir, $key, $from, $to, $events, $chrome, $commits);
    }
    saveGeneratedReport($dir, $key, $from, $to, $format, $opts['project'], $cached !== null, $out);

    fwrite(STDOUT, $out);
}

/**
 * Parses $argv into a structured options map.
 *
 * Supports both space-separated (--days 3) and equals-sign (--days=3) forms.
 * Exits with code 2 on an unrecognised flag.
 *
 * @param  string[] $argv  Raw argument vector, including the script name at index 0.
 * @return array{days: int|null, from: string|null, to: string|null, project: string|null,
 *               format: string, show_unmatched: bool, list_projects: bool, help: bool}
 */
function parseArgs(array $argv): array
{
    array_shift($argv);
    $opts = [
        'days' => null, 'from' => null, 'to' => null,
        'project' => null, 'format' => 'md',
        'show_unmatched' => false, 'list_projects' => false, 'help' => false,
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

  --days N             Look back N days (default 7).
  --from YYYY-MM-DD    Explicit start date (overrides --days).
  --to   YYYY-MM-DD    Explicit end date (default = today).
  --project NAME       Show only this project.
  --format md|json|tsv Output format (default md).
  --show-unmatched     List app/title/host events that didn't map to a project.
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
        $from = new DateTimeImmutable($opts['from'] . ' 00:00:00', $tz);
        $to = $opts['to']
            ? new DateTimeImmutable($opts['to'] . ' 23:59:59', $tz)
            : $now;
    } else {
        $days = $opts['days'] ?? 7;
        $to = $now;
        $from = $now->sub(new DateInterval("P{$days}D"))->setTime(0, 0, 0);
    }
    return [$from, $to];
}
