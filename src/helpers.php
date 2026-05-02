<?php

declare(strict_types=1);

/**
 * General-purpose utility functions shared across all modules.
 */

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
