<?php

declare(strict_types=1);

/**
 * Caching layer: persists raw source data and generated reports to reports/YYYY-MM/DD/.
 *
 * Uses PROJECT_ROOT (defined in the entry-point activity-report.php) so that paths
 * resolve correctly regardless of which directory this file is included from.
 */

/**
 * Returns the reports sub-directory path for a given FROM date.
 *
 * Structure: {project_root}/reports/YYYY-MM/DD
 */
function reportsDir(DateTimeImmutable $from): string
{
    return PROJECT_ROOT . '/reports/' . $from->format('Y-m') . '/' . $from->format('d');
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
 * @return array{events: array, chrome: array, commits: array, external: array}|null
 */
function loadCachedSources(string $dir, string $key): ?array
{
    $paths = [
        'events'  => "$dir/activitywatch-$key.json",
        'chrome'  => "$dir/chrome-$key.json",
        'commits' => "$dir/commits-$key.json",
        'external' => "$dir/integrations-$key.json",
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
        'external' => deserializeExternal($raw['external']),
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
 * @param array             $events  AW events (window + afk + input) after backfillChromeUrls().
 * @param array             $chrome  Raw Chrome history rows.
 * @param array             $commits Git commit rows.
 * @param array             $external External integration rows.
 */
function saveCachedSources(
    string $dir,
    string $key,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    array $events,
    array $chrome,
    array $commits,
    array $external
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
        'integrations'  => "integrations-$key.json",
    ];
    file_put_contents("$dir/{$files['activitywatch']}", json_encode(serializeEvents($events), $flags) . "\n");
    file_put_contents("$dir/{$files['chrome']}", json_encode(serializeChrome($chrome), $flags) . "\n");
    file_put_contents("$dir/{$files['commits']}", json_encode(serializeCommits($commits), $flags) . "\n");
    file_put_contents("$dir/{$files['integrations']}", json_encode(serializeExternal($external), $flags) . "\n");

    appendToIndex(PROJECT_ROOT . '/reports/cache-data.jsonl', [
        'cached_at' => (new DateTimeImmutable('now'))->format('c'),
        'from'      => $from->format('Y-m-d'),
        'to'        => $to->format('Y-m-d'),
        'key'       => $key,
        'files'     => array_map(fn($f) => "$relBase/$f", $files),
        'counts'    => [
            'window_events' => count($events['window']),
            'afk_events'    => count($events['afk']),
            'input_events'  => count($events['input'] ?? []),
            'chrome_rows'   => count($chrome),
            'commits'       => count($commits),
            'external_rows' => count($external),
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
    $ext       = match ($format) {
        'json' => 'json', 'tsv' => 'tsv', default => 'md'
    };
    $slug      = $project !== null ? '--' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $project) : '';
    $timestamp = (new DateTimeImmutable('now'))->format('Ymd\THis');
    $relBase   = $from->format('Y-m') . '/' . $from->format('d');
    $file      = "report-$key$slug--$timestamp.$ext";

    file_put_contents("$dir/$file", $content);

    appendToIndex(PROJECT_ROOT . '/reports/generated-reports.jsonl', [
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
 * Returns the path to the most recently saved report file matching the given key/slug/ext,
 * or null if none exists. Timestamped filenames are ISO-sortable, so the lexicographic
 * last result is the newest.
 *
 * @param string $dir  Absolute path to the per-range reports directory.
 * @param string $key  Date-range key from reportsCacheKey().
 * @param string $slug Project slug (e.g. '--my-project') or empty string.
 * @param string $ext  File extension without leading dot: 'json', 'md', 'tsv'.
 */
function findLatestReport(string $dir, string $key, string $slug, string $ext): ?string
{
    $files = glob("$dir/report-$key$slug--*.$ext") ?: [];
    sort($files);
    return $files ? end($files) : null;
}

/**
 * Returns the generation timestamp of the newest full-range report for the given key,
 * or null if no unfiltered report artifact exists.
 *
 * Full-range reports have filenames like report-YYYY-MM-DD--YYYYMMDDTHHMMSS.md.
 * Project-filtered variants include an extra slug segment and are ignored here.
 *
 * @param string       $dir Absolute path to the per-range reports directory.
 * @param string       $key Date-range key from reportsCacheKey().
 * @param DateTimeZone $tz  Timezone used to interpret the filename timestamp.
 */
function findLatestFullReportGeneratedAt(string $dir, string $key, DateTimeZone $tz): ?DateTimeImmutable
{
    $latest = null;
    foreach (['md', 'json', 'tsv'] as $ext) {
        $pattern = '/^report-' . preg_quote($key, '/') . '--(\d{8}T\d{6})\.' . preg_quote($ext, '/') . '$/';
        foreach (glob("$dir/report-$key--*.$ext") ?: [] as $path) {
            $name = basename($path);
            if (!preg_match($pattern, $name, $matches)) {
                continue;
            }

            $generatedAt = DateTimeImmutable::createFromFormat('Ymd\\THis', $matches[1], $tz);
            if ($generatedAt === false) {
                continue;
            }

            if ($latest === null || $generatedAt > $latest) {
                $latest = $generatedAt;
            }
        }
    }

    return $latest;
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
 * @param  array{window: array, afk: array, input?: array} $events ActivityWatch event arrays.
 * @return array{window: array, afk: array, input: array}
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
    return [
        'window' => $fmt($events['window']),
        'afk'    => $fmt($events['afk']),
        'input'  => $fmt($events['input'] ?? []),
    ];
}

/**
 * Reconstructs AW event arrays from cached JSON by re-hydrating ISO strings to DateTimeImmutable.
 *
 * @param  array{window: array, afk: array, input?: array} $data Decoded JSON data.
 * @return array{window: array, afk: array, input: array}
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
    return [
        'window' => $parse($data['window'] ?? []),
        'afk'    => $parse($data['afk'] ?? []),
        'input'  => $parse($data['input'] ?? []),
    ];
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

/**
 * Converts external integration rows to plain arrays.
 *
 * @param  list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function serializeExternal(array $rows): array
{
    return array_map(function ($r) {
        foreach (['start', 'end'] as $k) {
            if (($r[$k] ?? null) instanceof DateTimeImmutable) {
                $r[$k] = $r[$k]->format('c');
            }
        }
        return $r;
    }, $rows);
}

/**
 * Reconstructs external integration rows from cached JSON.
 *
 * @param  list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function deserializeExternal(array $rows): array
{
    return array_map(function ($r) {
        foreach (['start', 'end'] as $k) {
            if (is_string($r[$k] ?? null)) {
                $r[$k] = new DateTimeImmutable($r[$k]);
            }
        }
        return $r;
    }, $rows);
}
