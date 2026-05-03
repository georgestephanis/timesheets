<?php

declare(strict_types=1);

/**
 * ActivityWatch data loader: reads window-focus and AFK events from the local SQLite database.
 */

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
    $fallback = ['window' => [], 'afk' => []];

    foreach (['aw-server-rust/sqlite.db', 'aw-server/peewee-sqlite.v2.db'] as $rel) {
        $path = "$base/$rel";
        if (file_exists($path)) {
            $copy = copyForRead($path);
            if (!$copy) {
                fwrite(STDERR, "warning: could not copy $path\n");
                continue;
            }

            try {
                $loaded = loadAwSqlite($copy, $from, $to);
            } catch (Throwable $e) {
                fwrite(STDERR, "warning: could not parse $path ({$e->getMessage()})\n");
                continue;
            }

            if ($loaded['window'] !== [] || $loaded['afk'] !== []) {
                return $loaded;
            }

            $fallback = $loaded;
        }
    }

    if ($fallback['window'] !== [] || $fallback['afk'] !== []) {
        return $fallback;
    }

    fwrite(STDERR, "warning: no ActivityWatch sqlite found under $base\n");
    return ['window' => [], 'afk' => []];
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
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>}
 */
function loadAwSqliteLegacy(PDO $db, DateTimeImmutable $from, DateTimeImmutable $to): array
{
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

/**
 * Reads ActivityWatch events from the rust schema.
 *
 * @return array{window: list<array<string, mixed>>, afk: list<array<string, mixed>>}
 */
function loadAwSqliteRust(PDO $db, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $buckets = $db->query('SELECT id, name FROM buckets')->fetchAll(PDO::FETCH_ASSOC);
    $winIds = [];
    $afkIds = [];

    foreach ($buckets as $b) {
        $name = (string)$b['name'];
        if (str_starts_with($name, 'aw-watcher-window')) {
            $winIds[] = (int)$b['id'];
        }
        if (str_starts_with($name, 'aw-watcher-afk')) {
            $afkIds[] = (int)$b['id'];
        }
    }

    $fetch = function (array $bucketIds) use ($db): array {
        if ($bucketIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($bucketIds), '?'));
        $sql = "SELECT starttime, endtime, data FROM events WHERE bucketrow IN ($in) ORDER BY starttime";
        $st = $db->prepare($sql);
        $st->execute($bucketIds);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    };

    $window = [];
    $afk = [];

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

    usort($window, fn($a, $b) => $a['start'] <=> $b['start']);
    usort($afk, fn($a, $b) => $a['start'] <=> $b['start']);

    return ['window' => $window, 'afk' => $afk];
}
