<?php

declare(strict_types=1);

/**
 * ActivityWatch data loader: reads window-focus, AFK, and input events from the local SQLite database.
 */

/**
 * Finds every `<host>/<uuid>/test.db` produced by ActivityWatch's `aw-sync` tool
 * under a `--sync-dir` root.
 *
 * @return list<string>
 */
function awFindSyncDirDbs(string $syncDir): array
{
    $found = [];
    if (!is_dir($syncDir)) {
        return $found;
    }

    foreach (scandir($syncDir) ?: [] as $hostName) {
        if ($hostName === '.' || $hostName === '..') {
            continue;
        }
        $hostDir = "$syncDir/$hostName";
        if (!is_dir($hostDir)) {
            continue;
        }

        foreach (scandir($hostDir) ?: [] as $uuidName) {
            if ($uuidName === '.' || $uuidName === '..') {
                continue;
            }
            $dbPath = "$hostDir/$uuidName/test.db";
            if (is_dir("$hostDir/$uuidName") && file_exists($dbPath)) {
                $found[] = $dbPath;
            }
        }
    }

    return $found;
}

/**
 * Merges window/afk/input results from multiple sources, dropping exact-duplicate
 * events (same start, end, and payload) that occur when a host's live db and its
 * own aw-sync mirror both cover the same period. Distinct hosts' events are unioned.
 *
 * @param  list<array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>, input: list<array<string, mixed>>}> $results
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>, input: list<array<string, mixed>>}
 */
function awMergeAndDedupe(array $results): array
{
    $merged = ['window' => [], 'afk' => [], 'input' => []];

    foreach (['window', 'afk', 'input'] as $key) {
        $seen = [];
        foreach ($results as $r) {
            foreach ($r[$key] as $ev) {
                $dedupeKey = serialize($ev);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $merged[$key][] = $ev;
            }
        }
        usort($merged[$key], fn($a, $b) => $a['start'] <=> $b['start']);
    }

    return $merged;
}

/**
 * Loads window-focus, AFK, and input events from every discoverable ActivityWatch
 * SQLite database: the local live db plus, if configured, every host's mirror under
 * `paths.activitywatch_sync_dir` (as produced by ActivityWatch's `aw-sync` tool).
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of the query window.
 * @param  DateTimeImmutable $to     End of the query window.
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>, input: list<array<string, mixed>>}
 */
function loadActivityWatch(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $base = expandPath($config['paths']['activitywatch']);
    $syncDirRaw = $config['paths']['activitywatch_sync_dir'] ?? null;
    $syncDir = $syncDirRaw ? expandPath($syncDirRaw) : null;

    $candidates = array_map(fn($rel) => "$base/$rel", ['aw-server-rust/sqlite.db', 'aw-server/peewee-sqlite.v2.db']);
    if ($syncDir) {
        $candidates = [...$candidates, ...awFindSyncDirDbs($syncDir)];
    }

    $results = [];
    foreach ($candidates as $path) {
        if (!file_exists($path)) {
            continue;
        }

        $copy = copyForRead($path);
        if (!$copy) {
            warning('aw', "could not copy $path");
            continue;
        }

        try {
            $loaded = loadAwSqlite($copy, $from, $to);
        } catch (Throwable $e) {
            warning('aw', "could not parse $path ({$e->getMessage()})");
            continue;
        }

        if ($loaded['window'] !== [] || $loaded['afk'] !== [] || $loaded['input'] !== []) {
            $results[] = $loaded;
        }
    }

    if ($results === []) {
        $suffix = $syncDir ? " or $syncDir" : '';
        warning('aw', "no ActivityWatch sqlite found under $base$suffix");
        return ['window' => [], 'afk' => [], 'input' => []];
    }

    return awMergeAndDedupe($results);
}

/**
 * Normalizes an input payload into typed counters and an activity flag.
 *
 * @return array{presses: int, clicks: int, deltaX: float, deltaY: float, scrollX: float, scrollY: float, active: bool}
 */
function awNormalizeInputData(array $data): array
{
    $presses = (int)($data['presses'] ?? 0);
    $clicks = (int)($data['clicks'] ?? 0);
    $deltaX = (float)($data['deltaX'] ?? 0.0);
    $deltaY = (float)($data['deltaY'] ?? 0.0);
    $scrollX = (float)($data['scrollX'] ?? 0.0);
    $scrollY = (float)($data['scrollY'] ?? 0.0);

    return [
        'presses' => $presses,
        'clicks' => $clicks,
        'deltaX' => $deltaX,
        'deltaY' => $deltaY,
        'scrollX' => $scrollX,
        'scrollY' => $scrollY,
        'active' => ($presses + $clicks) > 0 || abs($deltaX) > 0 || abs($deltaY) > 0 || abs($scrollX) > 0 || abs($scrollY) > 0,
    ];
}

/**
 * Checks whether a given table exists in the connected SQLite database.
 */
function awHasTable(PDO $db, string $table): bool
{
    $st = $db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
}

/**
 * Converts an integer epoch timestamp (seconds/ms/us/ns) to UTC DateTimeImmutable.
 */
function awEpochToDateTime(mixed $value): ?DateTimeImmutable
{
    if (!is_int($value) && !is_string($value) && !is_float($value)) {
        return null;
    }

    $raw = (float)$value;
    if ($raw <= 0) {
        return null;
    }

    $digits = strlen((string)(int)abs($raw));
    $seconds = $raw;
    if ($digits >= 19) {
        $seconds = $raw / 1_000_000_000;
    } elseif ($digits >= 16) {
        $seconds = $raw / 1_000_000;
    } elseif ($digits >= 13) {
        $seconds = $raw / 1_000;
    }

    $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds), new DateTimeZone('UTC'));
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }

    return (new DateTimeImmutable('@' . (string)(int)$seconds))->setTimezone(new DateTimeZone('UTC'));
}

/**
 * Parses a copied ActivityWatch SQLite database and returns window, AFK, and input event arrays.
 *
 * Window events contain: start, end (DateTimeImmutable), app, title, url (strings).
 * AFK events contain: start, end (DateTimeImmutable), status ('afk' or 'not-afk').
 * Both arrays are sorted ascending by start time.
 *
 * @param  string            $path Path to the copied SQLite file.
 * @param  DateTimeImmutable $from Start of the query window.
 * @param  DateTimeImmutable $to   End of the query window.
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>, input: list<array<string, mixed>>}
 */
function loadAwSqlite(string $path, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $db = pdo($path);

    if (awHasTable($db, 'bucketmodel') && awHasTable($db, 'eventmodel')) {
        return loadAwSqliteLegacy($db, $from, $to);
    }

    if (awHasTable($db, 'buckets') && awHasTable($db, 'events')) {
        return loadAwSqliteRust($db, $from, $to);
    }

    throw new RuntimeException('unrecognized ActivityWatch sqlite schema');
}

/**
 * Reads ActivityWatch events from the legacy peewee schema.
 *
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>, input: list<array<string, mixed>>}
 */
function loadAwSqliteLegacy(PDO $db, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $buckets = $db->query('SELECT key, id FROM bucketmodel')->fetchAll(PDO::FETCH_ASSOC);
    $winIds = [];
    $afkIds = [];
    $inputIds = [];
    foreach ($buckets as $b) {
        if (str_starts_with($b['id'], 'aw-watcher-window')) {
            $winIds[] = (int)$b['key'];
        }
        if (str_starts_with($b['id'], 'aw-watcher-afk')) {
            $afkIds[] = (int)$b['key'];
        }
        if (str_starts_with($b['id'], 'aw-watcher-input')) {
            $inputIds[] = (int)$b['key'];
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
    $input = [];
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
    foreach ($fetch($inputIds) as $r) {
        $data = json_decode($r['datastr'], true) ?: [];
        $start = new DateTimeImmutable($r['timestamp']);
        $end   = $start->modify('+' . (int)round((float)$r['duration'] * 1000) . ' milliseconds');
        $input[] = ['start' => $start, 'end' => $end] + awNormalizeInputData($data);
    }
    usort($window, fn($a, $b) => $a['start'] <=> $b['start']);
    usort($afk, fn($a, $b) => $a['start'] <=> $b['start']);
    usort($input, fn($a, $b) => $a['start'] <=> $b['start']);
    return ['window' => $window, 'afk' => $afk, 'input' => $input];
}

/**
 * Reads ActivityWatch events from the rust schema.
 *
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>, input: list<array<string, mixed>>}
 */
function loadAwSqliteRust(PDO $db, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $buckets = $db->query('SELECT id, name FROM buckets')->fetchAll(PDO::FETCH_ASSOC);
    $winIds = [];
    $afkIds = [];
     $inputIds = [];

    foreach ($buckets as $b) {
        $name = (string)$b['name'];
        if (str_starts_with($name, 'aw-watcher-window')) {
            $winIds[] = (int)$b['id'];
        }
        if (str_starts_with($name, 'aw-watcher-afk')) {
            $afkIds[] = (int)$b['id'];
        }
        if (str_starts_with($name, 'aw-watcher-input')) {
            $inputIds[] = (int)$b['id'];
        }
    }

    $fetch = function (array $bucketIds) use ($db, $from, $to): array {
        if ($bucketIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($bucketIds), '?'));
        // aw-server-rust stores starttime/endtime as nanoseconds since Unix epoch (19-digit integers).
        // Use < / > (not <=) so overlapping events at the boundary are included.
        $fromNs = $from->getTimestamp() * 1_000_000_000;
        $toNs   = $to->getTimestamp()   * 1_000_000_000;
        $sql = "SELECT starttime, endtime, data FROM events
                WHERE bucketrow IN ($in) AND starttime < ? AND endtime > ?
                ORDER BY starttime";
        $st = $db->prepare($sql);
        $st->execute([...$bucketIds, $toNs, $fromNs]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    $window = [];
    $afk = [];
    $input = [];

    foreach ($fetch($winIds) as $r) {
        $start = awEpochToDateTime($r['starttime'] ?? null);
        $end = awEpochToDateTime($r['endtime'] ?? null);
        if (!$start || !$end || $end <= $from || $start >= $to) {
            continue;
        }

        $data = json_decode($r['data'] ?? '{}', true) ?: [];
        $window[] = [
            'start' => $start,
            'end' => $end,
            'app' => $data['app'] ?? '',
            'title' => $data['title'] ?? '',
            'url' => $data['url'] ?? '',
        ];
    }

    foreach ($fetch($afkIds) as $r) {
        $start = awEpochToDateTime($r['starttime'] ?? null);
        $end = awEpochToDateTime($r['endtime'] ?? null);
        if (!$start || !$end || $end <= $from || $start >= $to) {
            continue;
        }

        $data = json_decode($r['data'] ?? '{}', true) ?: [];
        $afk[] = [
            'start' => $start,
            'end' => $end,
            'status' => $data['status'] ?? 'unknown',
        ];
    }

    foreach ($fetch($inputIds) as $r) {
        $start = awEpochToDateTime($r['starttime'] ?? null);
        $end = awEpochToDateTime($r['endtime'] ?? null);
        if (!$start || !$end || $end <= $from || $start >= $to) {
            continue;
        }

        $data = json_decode($r['data'] ?? '{}', true) ?: [];
        $input[] = ['start' => $start, 'end' => $end] + awNormalizeInputData($data);
    }

    usort($window, fn($a, $b) => $a['start'] <=> $b['start']);
    usort($afk, fn($a, $b) => $a['start'] <=> $b['start']);
    usort($input, fn($a, $b) => $a['start'] <=> $b['start']);

    return ['window' => $window, 'afk' => $afk, 'input' => $input];
}
