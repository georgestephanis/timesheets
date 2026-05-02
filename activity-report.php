#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * activity-report.php
 *
 * A single-file CLI tool that aggregates time and commit activity across:
 *   - ActivityWatch (local sqlite at ~/Library/Application Support/activitywatch/)
 *   - Chrome history (local sqlite at ~/Library/Application Support/Google/Chrome/)
 *   - Git repositories (via `git log`)
 *
 * Signals are clustered into "projects" via the config block below. A project can
 * pull in any combination of:
 *   - VSCode workspace folder names (matched against window titles)
 *   - Browser URL domains (with glob support, e.g. *.example.com)
 *   - Slack workspaces / channels (with glob support)
 *   - Git repository paths
 *   - SSH hostnames (matched against Terminal window titles)
 *
 * Usage:
 *   php activity-report.php                         # last 7 days, markdown
 *   php activity-report.php --days 3                # last 3 days
 *   php activity-report.php --from 2026-04-15 --to 2026-04-29
 *   php activity-report.php --project "Acme Corp"   # filter to one project
 *   php activity-report.php --format json
 *   php activity-report.php --show-unmatched        # debug: list events the rules missed
 *   php activity-report.php --list-projects
 *   php activity-report.php --help
 */

// =====================================================================
//  CONFIG — loaded from config.json (copy config.example.json to get started)
// =====================================================================

$configFile = __DIR__ . '/config.json';
if (!file_exists($configFile)) {
    fwrite(STDERR, "error: config.json not found. Copy config.example.json to config.json and edit it.\n");
    exit(1);
}
$CONFIG = json_decode(file_get_contents($configFile), true);
if (!is_array($CONFIG)) {
    fwrite(STDERR, "error: config.json is not valid JSON.\n");
    exit(1);
}

// =====================================================================
//  IMPLEMENTATION — you shouldn't need to edit below
// =====================================================================

const VERSION = '0.1.0';

main($CONFIG);

// ---------- entrypoint ----------

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

// ---------- CLI ----------

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

Edit the CONFIG block at the top of the script to define projects and paths.

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

// ---------- helpers ----------

/**
 * Expands a leading ~/ to the current user's home directory.
 */
function expandPath(string $p): string
{
    if (str_starts_with($p, '~/')) {
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: '~';
        return $home . substr($p, 1);
    }
    return $p;
}

/**
 * Returns true if $needle matches any pattern in $patterns using case-insensitive glob rules.
 *
 * @param string   $needle   Value to test (e.g. a hostname).
 * @param string[] $patterns Glob patterns, where * is a wildcard (e.g. *.example.com).
 */
function fnmatchAny(string $needle, array $patterns): bool
{
    foreach ($patterns as $pat) {
        if (fnmatch($pat, $needle, FNM_CASEFOLD)) {
            return true;
        }
    }
    return false;
}

/**
 * Formats a duration in seconds as a compact human-readable string.
 *
 * Examples: 3661 → "1h 01m", 90 → "1m", 18 → "18s".
 */
function fmtDur(int|float $sec): string
{
    $m = (int)floor($sec / 60);
    if ($m >= 60) {
        return sprintf('%dh %02dm', intdiv($m, 60), $m % 60);
    }
    if ($m >= 1) {
        return "{$m}m";
    }
    return ((int)$sec) . 's';
}

/**
 * Copies a file to a temporary path and returns the temp path.
 *
 * SQLite databases cannot be safely queried while the owning process holds a
 * write lock, so callers copy first and query the copy. The caller is responsible
 * for cleaning up the temp file if needed (the OS will reclaim it on reboot).
 *
 * @return string|null  Temp file path, or null if the source does not exist or the copy fails.
 */
function copyForRead(string $src): ?string
{
    if (!file_exists($src)) {
        return null;
    }
    $dst = tempnam(sys_get_temp_dir(), 'arpt_');
    if (!@copy($src, $dst)) {
        return null;
    }
    return $dst;
}

/**
 * Opens a SQLite file at $path and returns a PDO instance configured for exception mode.
 */
function pdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $pdo;
}

/**
 * Converts a Chrome visit timestamp to a DateTimeImmutable in the given timezone.
 *
 * Chrome stores visit_time as microseconds since 1601-01-01 00:00:00 UTC. The
 * constant 11,644,473,600 is the number of seconds between that epoch and Unix epoch.
 *
 * @param int          $ct Chrome microsecond timestamp from the visits table.
 * @param DateTimeZone $tz Target timezone for the returned object.
 */
function chromeTime(int $ct, DateTimeZone $tz): DateTimeImmutable
{
    $unix = $ct / 1_000_000 - 11_644_473_600;
    return (new DateTimeImmutable('@' . (int)floor($unix)))->setTimezone($tz);
}

// ---------- reports / cache ----------

/**
 * Returns the reports sub-directory path for a given FROM date.
 *
 * Structure: {project_root}/reports/YYYY-MM/DD
 */
function reportsDir(DateTimeImmutable $from): string
{
    return __DIR__ . '/reports/' . $from->format('Y-m') . '/' . $from->format('d');
}

/**
 * Returns a string key that uniquely identifies the from-to date range for use in filenames.
 *
 * Single-day: "YYYY-MM-DD". Multi-day: "YYYY-MM-DD--YYYY-MM-DD".
 */
function reportsCacheKey(DateTimeImmutable $from, DateTimeImmutable $to): string
{
    $f = $from->format('Y-m-d');
    $t = $to->format('Y-m-d');
    return $f === $t ? $f : "$f--$t";
}

/**
 * Returns true if the TO date is strictly before today — meaning all data in the range
 * is historical and will not change, making it safe to serve from cache.
 */
function rangeIsHistorical(DateTimeImmutable $to, DateTimeZone $tz): bool
{
    $today = (new DateTimeImmutable('today', $tz))->format('Y-m-d');
    return $to->setTimezone($tz)->format('Y-m-d') < $today;
}

/**
 * Tries to load previously cached source data (AW events, Chrome rows, commits) from disk.
 *
 * Returns null if any of the three cache files is missing or contains invalid JSON,
 * which causes the caller to fall back to fetching fresh data.
 *
 * @param  string $dir Absolute path to the per-range reports directory.
 * @param  string $key Date-range key from reportsCacheKey().
 * @return array{events: array, chrome: array, commits: array}|null
 */
function loadCachedSources(string $dir, string $key): ?array
{
    $paths = [
        'events'  => "$dir/activitywatch-$key.json",
        'chrome'  => "$dir/chrome-$key.json",
        'commits' => "$dir/commits-$key.json",
    ];
    foreach ($paths as $path) {
        if (!file_exists($path)) {
            return null;
        }
    }
    $raw = [];
    foreach ($paths as $name => $path) {
        $decoded = json_decode(file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }
        $raw[$name] = $decoded;
    }
    return [
        'events'  => deserializeEvents($raw['events']),
        'chrome'  => deserializeChrome($raw['chrome']),
        'commits' => deserializeCommits($raw['commits']),
    ];
}

/**
 * Serialises and writes raw source data to the per-range reports directory, then
 * appends a record to reports/cache-data.jsonl.
 *
 * Back-fill must have already been applied to $events before calling this function,
 * so cached events already carry URLs and do not need re-processing on load.
 *
 * @param string            $dir     Absolute path to the per-range reports directory.
 * @param string            $key     Date-range key from reportsCacheKey().
 * @param DateTimeImmutable $from    Report start date (for the index record).
 * @param DateTimeImmutable $to      Report end date (for the index record).
 * @param array             $events  AW events (window + afk) after backfillChromeUrls().
 * @param array             $chrome  Raw Chrome history rows.
 * @param array             $commits Git commit rows.
 */
function saveCachedSources(
    string $dir,
    string $key,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    array $events,
    array $chrome,
    array $commits
): void {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return;
    }
    $flags   = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES;
    $relBase = $from->format('Y-m') . '/' . $from->format('d');
    $files   = [
        'activitywatch' => "activitywatch-$key.json",
        'chrome'        => "chrome-$key.json",
        'commits'       => "commits-$key.json",
    ];
    file_put_contents("$dir/{$files['activitywatch']}", json_encode(serializeEvents($events), $flags) . "\n");
    file_put_contents("$dir/{$files['chrome']}", json_encode(serializeChrome($chrome), $flags) . "\n");
    file_put_contents("$dir/{$files['commits']}", json_encode(serializeCommits($commits), $flags) . "\n");

    appendToIndex(__DIR__ . '/reports/cache-data.jsonl', [
        'cached_at' => (new DateTimeImmutable('now'))->format('c'),
        'from'      => $from->format('Y-m-d'),
        'to'        => $to->format('Y-m-d'),
        'key'       => $key,
        'files'     => array_map(fn($f) => "$relBase/$f", $files),
        'counts'    => [
            'window_events' => count($events['window']),
            'afk_events'    => count($events['afk']),
            'chrome_rows'   => count($chrome),
            'commits'       => count($commits),
        ],
    ]);
}

/**
 * Writes the generated report string to the per-range reports directory, then
 * appends a record to reports/generated-reports.jsonl.
 *
 * Filename pattern: report-{key}[--{project-slug}].{ext}
 *
 * @param string            $dir       Absolute path to the per-range reports directory.
 * @param string            $key       Date-range key from reportsCacheKey().
 * @param DateTimeImmutable $from      Report start date (for the index record).
 * @param DateTimeImmutable $to        Report end date (for the index record).
 * @param string            $format    Output format: 'md', 'json', or 'tsv'.
 * @param string|null       $project   Active --project filter, or null for all projects.
 * @param bool              $fromCache Whether source data was loaded from cache.
 * @param string            $content   The fully rendered report string.
 */
function saveGeneratedReport(
    string $dir,
    string $key,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $format,
    ?string $project,
    bool $fromCache,
    string $content
): void {
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return;
    }
    $ext     = match ($format) {
        'json' => 'json', 'tsv' => 'tsv', default => 'md'
    };
    $slug    = $project !== null ? '--' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $project) : '';
    $relBase = $from->format('Y-m') . '/' . $from->format('d');
    $file    = "report-$key$slug.$ext";

    file_put_contents("$dir/$file", $content);

    appendToIndex(__DIR__ . '/reports/generated-reports.jsonl', [
        'generated_at' => (new DateTimeImmutable('now'))->format('c'),
        'from'         => $from->format('Y-m-d'),
        'to'           => $to->format('Y-m-d'),
        'key'          => $key,
        'format'       => $ext,
        'project'      => $project,
        'file'         => "$relBase/$file",
        'size_bytes'   => strlen($content),
        'from_cache'   => $fromCache,
    ]);
}

/**
 * Appends a single JSON object as a new line to a JSONL index file.
 *
 * Creates the file (and its parent reports/ directory) if they do not yet exist.
 * Uses LOCK_EX to prevent interleaved writes if the script is ever run in parallel.
 * Silently skips if the directory cannot be created.
 *
 * @param string               $path   Absolute path to the .jsonl file.
 * @param array<string, mixed> $record Data to encode and append.
 */
function appendToIndex(string $path, array $record): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return;
    }
    file_put_contents($path, json_encode($record, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}

/**
 * Converts AW event arrays (containing DateTimeImmutable values) to plain JSON-serialisable arrays.
 *
 * @param  array{window: array, afk: array} $events ActivityWatch event arrays.
 * @return array{window: array, afk: array}
 */
function serializeEvents(array $events): array
{
    $fmt = fn(array $rows) => array_map(function ($r) {
        foreach (['start', 'end'] as $k) {
            if ($r[$k] instanceof DateTimeImmutable) {
                $r[$k] = $r[$k]->format('c');
            }
        }
        return $r;
    }, $rows);
    return ['window' => $fmt($events['window']), 'afk' => $fmt($events['afk'])];
}

/**
 * Reconstructs AW event arrays from cached JSON by re-hydrating ISO strings to DateTimeImmutable.
 *
 * @param  array{window: array, afk: array} $data Decoded JSON data.
 * @return array{window: array, afk: array}
 */
function deserializeEvents(array $data): array
{
    $parse = fn(array $rows) => array_map(function ($r) {
        foreach (['start', 'end'] as $k) {
            if (is_string($r[$k])) {
                $r[$k] = new DateTimeImmutable($r[$k]);
            }
        }
        return $r;
    }, $rows);
    return ['window' => $parse($data['window'] ?? []), 'afk' => $parse($data['afk'] ?? [])];
}

/**
 * Converts Chrome history rows (containing DateTimeImmutable 'time' values) to plain arrays.
 *
 * @param  list<array{time: DateTimeImmutable, host: string, url: string, title: string}> $chrome
 * @return list<array{time: string, host: string, url: string, title: string}>
 */
function serializeChrome(array $chrome): array
{
    return array_map(function ($r) {
        if ($r['time'] instanceof DateTimeImmutable) {
            $r['time'] = $r['time']->format('c');
        }
        return $r;
    }, $chrome);
}

/**
 * Reconstructs Chrome history rows from cached JSON by re-hydrating the 'time' field.
 *
 * @param  list<array{time: string, host: string, url: string, title: string}> $data
 * @return list<array{time: DateTimeImmutable, host: string, url: string, title: string}>
 */
function deserializeChrome(array $data): array
{
    return array_map(function ($r) {
        if (is_string($r['time'])) {
            $r['time'] = new DateTimeImmutable($r['time']);
        }
        return $r;
    }, $data);
}

/**
 * Converts git commit rows (containing DateTimeImmutable 'dt' values) to plain arrays.
 *
 * @param  list<array{dt: DateTimeImmutable, project: string, repo: string, sha: string, subj: string}> $commits
 * @return list<array{dt: string, project: string, repo: string, sha: string, subj: string}>
 */
function serializeCommits(array $commits): array
{
    return array_map(function ($c) {
        if ($c['dt'] instanceof DateTimeImmutable) {
            $c['dt'] = $c['dt']->format('c');
        }
        return $c;
    }, $commits);
}

/**
 * Reconstructs git commit rows from cached JSON by re-hydrating the 'dt' field.
 *
 * @param  list<array{dt: string, project: string, repo: string, sha: string, subj: string}> $data
 * @return list<array{dt: DateTimeImmutable, project: string, repo: string, sha: string, subj: string}>
 */
function deserializeCommits(array $data): array
{
    return array_map(function ($c) {
        if (is_string($c['dt'])) {
            $c['dt'] = new DateTimeImmutable($c['dt']);
        }
        return $c;
    }, $data);
}

// ---------- ActivityWatch ----------

/**
 * Loads window-focus and AFK events from the local ActivityWatch SQLite database.
 *
 * Tries the aw-server-rust and legacy aw-server database paths in order and returns
 * data from the first one found. Emits a STDERR warning and returns empty arrays if
 * neither path exists.
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of the query window.
 * @param  DateTimeImmutable $to     End of the query window.
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>}
 */
function loadActivityWatch(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $base = expandPath($config['paths']['activitywatch']);
    foreach (['aw-server-rust/sqlite.db', 'aw-server/peewee-sqlite.v2.db'] as $rel) {
        $path = "$base/$rel";
        if (file_exists($path)) {
            $copy = copyForRead($path);
            if (!$copy) {
                fwrite(STDERR, "warning: could not copy $path\n");
                continue;
            }
            return loadAwSqlite($copy, $from, $to);
        }
    }
    fwrite(STDERR, "warning: no ActivityWatch sqlite found under $base\n");
    return ['window' => [], 'afk' => []];
}

/**
 * Parses a copied ActivityWatch SQLite database and returns window and AFK event arrays.
 *
 * Window events contain: start, end (DateTimeImmutable), app, title, url (strings).
 * AFK events contain: start, end (DateTimeImmutable), status ('afk' or 'not-afk').
 * Both arrays are sorted ascending by start time.
 *
 * @param  string            $path Path to the copied SQLite file.
 * @param  DateTimeImmutable $from Start of the query window.
 * @param  DateTimeImmutable $to   End of the query window.
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>}
 */
function loadAwSqlite(string $path, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $db = pdo($path);
    $buckets = $db->query('SELECT key, id FROM bucketmodel')->fetchAll(PDO::FETCH_ASSOC);
    $winIds = [];
    $afkIds = [];
    foreach ($buckets as $b) {
        if (str_starts_with($b['id'], 'aw-watcher-window')) {
            $winIds[] = (int)$b['key'];
        }
        if (str_starts_with($b['id'], 'aw-watcher-afk')) {
            $afkIds[] = (int)$b['key'];
        }
    }

    $fromIso = $from->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    $toIso   = $to->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');

    $fetch = function (array $bids) use ($db, $fromIso, $toIso): array {
        if (!$bids) {
            return [];
        }
        $in = implode(',', array_fill(0, count($bids), '?'));
        $sql = "SELECT timestamp, duration, datastr FROM eventmodel
                WHERE bucket_id IN ($in) AND timestamp BETWEEN ? AND ?
                ORDER BY timestamp";
        $st = $db->prepare($sql);
        $st->execute([...$bids, $fromIso, $toIso]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    $window = [];
    $afk = [];
    foreach ($fetch($winIds) as $r) {
        $data = json_decode($r['datastr'], true) ?: [];
        $start = new DateTimeImmutable($r['timestamp']);
        $end   = $start->modify('+' . (int)round((float)$r['duration'] * 1000) . ' milliseconds');
        $window[] = [
            'start' => $start, 'end' => $end,
            'app'   => $data['app']   ?? '',
            'title' => $data['title'] ?? '',
            'url'   => $data['url']   ?? '',
        ];
    }
    foreach ($fetch($afkIds) as $r) {
        $data = json_decode($r['datastr'], true) ?: [];
        $start = new DateTimeImmutable($r['timestamp']);
        $end   = $start->modify('+' . (int)round((float)$r['duration'] * 1000) . ' milliseconds');
        $afk[] = ['start' => $start, 'end' => $end, 'status' => $data['status'] ?? 'unknown'];
    }
    usort($afk, fn($a, $b) => $a['start'] <=> $b['start']);
    return ['window' => $window, 'afk' => $afk];
}

// ---------- Chrome history ----------

/**
 * Loads all Chrome history visits across all configured (or auto-discovered) profiles.
 *
 * When chrome_profiles is null in config, every subdirectory under the Chrome user-data
 * directory that contains a History file is treated as a profile. Rows are sorted
 * ascending by visit time.
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of the query window.
 * @param  DateTimeImmutable $to     End of the query window.
 * @return list<array{time: DateTimeImmutable, host: string, url: string, title: string}>
 */
function loadChromeHistory(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $base = expandPath($config['paths']['chrome']);
    $profiles = $config['paths']['chrome_profiles'];
    if ($profiles === null) {
        $profiles = [];
        foreach (glob("$base/*", GLOB_ONLYDIR) ?: [] as $d) {
            if (file_exists("$d/History")) {
                $profiles[] = basename($d);
            }
        }
    }
    $tz = new DateTimeZone('UTC');
    $rows = [];
    foreach ($profiles as $prof) {
        $src = "$base/$prof/History";
        if (!file_exists($src)) {
            continue;
        }
        $copy = copyForRead($src);
        if (!$copy) {
            continue;
        }
        $db = pdo($copy);
        $st = $db->query('SELECT v.visit_time, v.visit_duration, u.url, u.title
                          FROM visits v JOIN urls u ON v.url = u.id');
        foreach ($st as $r) {
            $dt = chromeTime((int)$r['visit_time'], $tz);
            if ($dt < $from || $dt > $to) {
                continue;
            }
            $rows[] = [
                'time' => $dt,
                'host' => parse_url($r['url'], PHP_URL_HOST) ?: '',
                'url'  => $r['url'],
                'title' => $r['title'] ?? '',
            ];
        }
    }
    usort($rows, fn($a, $b) => $a['time'] <=> $b['time']);
    return $rows;
}

/**
 * Back-fills missing URLs on Chrome ActivityWatch events using Chrome history.
 *
 * ActivityWatch's Chrome watcher sometimes records window events without a URL.
 * For each such event, this function finds the most recent Chrome history visit
 * within $windowSec seconds of the event's midpoint and copies its URL across.
 *
 * @param array $events    ActivityWatch event arrays, passed by reference; window entries may be mutated.
 * @param array $chrome    Sorted Chrome history rows from loadChromeHistory().
 * @param int   $windowSec Maximum seconds between event midpoint and history visit to allow a back-fill.
 */
function backfillChromeUrls(array &$events, array $chrome, int $windowSec): void
{
    if (!$chrome) {
        return;
    }
    // Build sorted timestamps for binary search.
    $times = array_map(fn($r) => $r['time']->getTimestamp() + (int)$r['time']->format('u') / 1_000_000, $chrome);
    foreach ($events['window'] as &$ev) {
        if ($ev['app'] !== 'Google Chrome' || $ev['url'] !== '') {
            continue;
        }
        // Use midpoint of window event.
        $mid = ($ev['start']->getTimestamp() + $ev['end']->getTimestamp()) / 2;
        $i = bsearchRight($times, $mid) - 1;
        if ($i >= 0 && ($mid - $times[$i]) <= $windowSec) {
            $ev['url'] = $chrome[$i]['url'];
        }
    }
}

/**
 * Returns the rightmost insertion index for $key in a sorted array (upper-bound binary search).
 *
 * All elements at indices strictly less than the returned index are <= $key.
 * Used by backfillChromeUrls() to locate the most recent Chrome visit before a given timestamp.
 *
 * @param  float[] $sorted Ascending-sorted array of numeric values.
 * @param  float   $key    Value to locate.
 * @return int  Upper-bound insertion index.
 */
function bsearchRight(array $sorted, float $key): int
{
    $lo = 0;
    $hi = count($sorted);
    while ($lo < $hi) {
        $mid = ($lo + $hi) >> 1;
        if ($sorted[$mid] <= $key) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo;
}

// ---------- Git ----------

/**
 * Loads git commits from every repository listed across all configured projects.
 *
 * Commits are pre-attributed to their project at load time by walking projects[*].repos,
 * so the classifier does not need to re-examine repository paths. Rows are sorted
 * ascending by author date.
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of the query window.
 * @param  DateTimeImmutable $to     End of the query window.
 * @return list<array{dt: DateTimeImmutable, project: string, repo: string, sha: string, subj: string}>
 */
function loadGitCommits(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $authors = $config['git_authors'] ?? [];
    if (!$authors) {
        return [];
    }

    $repos = [];
    foreach ($config['projects'] as $proj => $p) {
        foreach ($p['repos'] ?? [] as $r) {
            $repos[expandPath($r)] = $proj;
        }
    }
    if (!$repos) {
        return [];
    }

    $sinceArg = '--since=' . escapeshellarg($from->format('c'));
    $untilArg = '--until=' . escapeshellarg($to->format('c'));
    $authorPat = implode('|', array_map('preg_quote', $authors));
    $authorArg = '--author=' . escapeshellarg($authorPat);

    $rows = [];
    foreach ($repos as $repo => $project) {
        if (!is_dir("$repo/.git")) {
            continue;
        }
        $cmd = "git -C " . escapeshellarg($repo)
             . " log --all $sinceArg $untilArg $authorArg"
             . ' --pretty=tformat:"%aI%x09%H%x09%s" 2>/dev/null';
        $out = shell_exec($cmd);
        if (!$out) {
            continue;
        }
        foreach (preg_split("/\r?\n/", trim($out)) as $line) {
            if (!$line) {
                continue;
            }
            $parts = explode("\t", $line, 3);
            if (count($parts) < 3) {
                continue;
            }
            [$iso, $sha, $subj] = $parts;
            try {
                $dt = new DateTimeImmutable($iso);
            } catch (Throwable) {
                continue;
            }
            $rows[] = ['dt' => $dt, 'project' => $project, 'repo' => $repo, 'sha' => $sha, 'subj' => $subj];
        }
    }
    usort($rows, fn($a, $b) => $a['dt'] <=> $b['dt']);
    return $rows;
}

// ---------- Classifiers ----------

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

    $bumpDetail = function (string $date, string $proj, string $kind, string $label, float $sec) use (&$bucket) {
        $bucket[$date][$proj]['seconds'] = ($bucket[$date][$proj]['seconds'] ?? 0) + $sec;
        $bucket[$date][$proj]['detail'][$kind][$label] = ($bucket[$date][$proj]['detail'][$kind][$label] ?? 0) + $sec;
    };

    foreach ($events['window'] as $ev) {
        // afk filter (use mid-point)
        $midTs = ($ev['start']->getTimestamp() + $ev['end']->getTimestamp()) / 2;
        $mid = (new DateTimeImmutable('@' . (int)$midTs))->setTimezone($tz);
        if (isAfkAt($mid, $events['afk'])) {
            continue;
        }

        $sec = max(0, $ev['end']->getTimestamp() - $ev['start']->getTimestamp());
        if ($sec <= 0) {
            continue;
        }
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
                if (in_array($ev['app'], $personalApps, true)) {
                    $proj = 'Personal apps';
                } else {
                    $proj = $ev['app'] ? "App: {$ev['app']}" : 'Other';
                }
                if (!fnmatchAny($ev['app'], $personalApps) && str_starts_with($proj, 'App: ')) {
                    $unmatched['apps'][$ev['app']] = ($unmatched['apps'][$ev['app']] ?? 0) + 1;
                }
        }

        if ($opts['project'] && $proj !== $opts['project']) {
            continue;
        }
        $bumpDetail($date, $proj, $detailKind, $detailLabel, $sec);
    }

    // Commits
    foreach ($commits as $c) {
        $date = $c['dt']->setTimezone($tz)->format('Y-m-d');
        $proj = $c['project'];
        if ($opts['project'] && $proj !== $opts['project']) {
            continue;
        }
        $bucket[$date][$proj]['commits'][] = $c;
    }

    return [$bucket, $unmatched];
}

// ---------- Renderers ----------

/**
 * Renders the activity bucket as a human-readable Markdown report.
 *
 * Days are ordered most-recent first. Within each day, projects are sorted by
 * total active seconds descending. The top 6 detail items per signal kind are
 * shown. Projects below min_event_seconds_to_show with no commits are omitted.
 * When --show-unmatched is set, an appendix lists the unclassified signals.
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
        $weekday = (new DateTimeImmutable($date, $tz))->format('D');
        $dayTotal = 0;
        foreach ($bucket[$date] as $proj => $rec) {
            $dayTotal += $rec['seconds'] ?? 0;
        }

        $out .= "## $date ($weekday) — " . fmtDur($dayTotal) . " active\n\n";
        // Sort projects by activity time, then commits as tiebreaker
        $proj = $bucket[$date];
        uksort($proj, function ($a, $b) use ($proj) {
            return ($proj[$b]['seconds'] ?? 0) <=> ($proj[$a]['seconds'] ?? 0);
        });

        foreach ($proj as $name => $rec) {
            $sec = $rec['seconds'] ?? 0;
            $commits = $rec['commits'] ?? [];
            if ($sec < $minSec && !$commits) {
                continue;
            }
            $secStr = $sec ? ' — ' . fmtDur($sec) : '';
            $out .= "### $name$secStr\n\n";
            // Detail breakdown (top items)
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
            if ($commits) {
                $out .= "- _commits (" . count($commits) . "):_\n";
                foreach ($commits as $c) {
                    $t = $c['dt']->setTimezone($tz)->format('H:i');
                    $out .= "    - `$t` `" . substr($c['sha'], 0, 8) . "` " . $c['subj'] . "\n";
                }
            }
            $out .= "\n";
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
 * The top-level envelope includes from, to (RFC 3339), tz (IANA name), days (the bucket),
 * and unmatched signal counts.
 *
 * @param  array             $bucket    Aggregated data from classifyAndAggregate().
 * @param  array             $unmatched Unmatched signal counts from classifyAndAggregate().
 * @param  DateTimeImmutable $from      Report start date.
 * @param  DateTimeImmutable $to        Report end date.
 * @param  DateTimeZone      $tz        Display timezone.
 */
function renderJson(array $bucket, array $unmatched, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeZone $tz): string
{
    $clean = [];
    foreach ($bucket as $date => $projs) {
        foreach ($projs as $name => $rec) {
            $clean[$date][$name] = [
                'seconds' => $rec['seconds'] ?? 0,
                'detail'  => $rec['detail']  ?? [],
                'commits' => array_map(fn($c) => [
                    'time' => $c['dt']->setTimezone($tz)->format('c'),
                    'sha'  => $c['sha'],
                    'subj' => $c['subj'],
                    'repo' => $c['repo'],
                ], $rec['commits'] ?? []),
            ];
        }
    }
    return json_encode([
        'from' => $from->format('c'),
        'to'   => $to->format('c'),
        'tz'   => $tz->getName(),
        'days' => $clean,
        'unmatched' => $unmatched,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
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
    $rows = ["date\tproject\tseconds\tcommits"];
    $dates = array_keys($bucket);
    sort($dates);
    foreach ($dates as $date) {
        foreach ($bucket[$date] as $proj => $rec) {
            $rows[] = "$date\t$proj\t" . (int)($rec['seconds'] ?? 0) . "\t" . count($rec['commits'] ?? []);
        }
    }
    return implode("\n", $rows) . "\n";
}
