<?php

declare(strict_types=1);

/**
 * Chrome history loader: reads browser visits from the local Chrome SQLite databases.
 */

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
    $profiles = $config['paths']['chrome_profiles'] ?? null;
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
        // Chrome stores visit_time as microseconds since 1601-01-01 (Windows FILETIME epoch).
        // 11,644,473,600 is the offset in seconds between that epoch and Unix epoch.
        $fromCt = (int)(($from->getTimestamp() + 11_644_473_600) * 1_000_000);
        $toCt   = (int)(($to->getTimestamp()   + 11_644_473_600) * 1_000_000);
        $st = $db->prepare('SELECT v.visit_time, v.visit_duration, u.url, u.title
                             FROM visits v JOIN urls u ON v.url = u.id
                             WHERE v.visit_time >= ? AND v.visit_time <= ?');
        $st->execute([$fromCt, $toCt]);
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
 * at or before the event's start time within $windowSec seconds and copies its URL across.
 *
 * @param array $events    ActivityWatch event arrays, passed by reference; window entries may be mutated.
 * @param array $chrome    Sorted Chrome history rows from loadChromeHistory().
 * @param int   $windowSec Maximum seconds between event start and history visit to allow a back-fill.
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
        // Find the latest Chrome visit at or before the event's start time.
        $start = $ev['start']->getTimestamp() + (int)$ev['start']->format('u') / 1_000_000;
        $i = bsearchRight($times, $start) - 1;
        if ($i >= 0 && ($start - $times[$i]) <= $windowSec) {
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
