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
        // HOME covers macOS and Linux; USERPROFILE covers Windows.
        $home = $_SERVER['HOME'] ?? getenv('HOME') ?: getenv('USERPROFILE') ?: '~';
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
 * Returns true when a host matches a configured domain rule.
 *
 * Rules with glob characters (*, ?, []) use fnmatch semantics. A bare domain
 * (e.g. example.com) matches both the apex and any subdomain
 * (e.g. www.example.com, api.example.com).
 */
function hostMatchesDomain(string $host, string $pattern): bool
{
    $host = strtolower(rtrim(trim($host), '.'));
    $pattern = strtolower(rtrim(trim($pattern), '.'));
    if ($host === '' || $pattern === '') {
        return false;
    }

    if (fnmatch($pattern, $host, FNM_CASEFOLD)) {
        return true;
    }

    // Only apply apex+subdomain behavior to non-glob domains.
    if (strpbrk($pattern, '*?[]') !== false) {
        return false;
    }

    return $host === $pattern || str_ends_with($host, '.' . $pattern);
}

/**
 * Returns true if $host matches any configured domain rule.
 *
 * @param string   $host     Hostname to test.
 * @param string[] $patterns Domain rules from config.
 */
function hostMatchesAnyDomain(string $host, array $patterns): bool
{
    foreach ($patterns as $pattern) {
        if (hostMatchesDomain($host, (string)$pattern)) {
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

/**
 * Emits a warning to STDERR (CLI) or error_log (web) and collects it for later retrieval.
 *
 * All warnings are pooled in a single global collector regardless of source, so
 * getWarnings() returns AW warnings, integration warnings, and any other source together.
 *
 * @param string $source  Short tag identifying the emitting subsystem (e.g. 'aw', 'integrations').
 * @param string $message Warning text.
 */
function warning(string $source, string $message): void
{
    global $_warnings;
    $_warnings[] = $message;

    $line = "warning[$source]: $message";
    if (defined('STDERR')) {
        fwrite(STDERR, $line . "\n");
        return;
    }
    error_log($line);
}

/**
 * Returns all warnings collected by warning() during this request.
 *
 * @return list<string>
 */
function getWarnings(): array
{
    global $_warnings;
    return $_warnings ?? [];
}
