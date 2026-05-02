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
