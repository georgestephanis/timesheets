#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Removes per-day source caches and generated report files from reports/YYYY-MM/DD/,
 * leaving config.json, reports/config/ backups, and JSONL index files untouched.
 *
 * Use this when project rules have changed and you want the next run to re-fetch all
 * source data, or after clearing a bad per-day cache that caused stale reports.
 *
 * Usage:
 *   php tools/reset-cache.php                          # preview all caches
 *   php tools/reset-cache.php --apply                  # delete all per-day caches
 *   php tools/reset-cache.php --before 2026-05-01      # only caches for dates before May 1
 *   php tools/reset-cache.php --month 2026-04          # only caches for April 2026
 *   php tools/reset-cache.php --before 2026-05-01 --apply
 */

define('PROJECT_ROOT', dirname(__DIR__));

$args     = $argv ?? [];
$apply    = in_array('--apply', $args, true);
$before   = null; // DateTimeImmutable cutoff — delete days strictly before this
$monthFilter = null; // YYYY-MM string

for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--before' && isset($args[$i + 1])) {
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $args[++$i]);
        if ($dt === false) {
            fwrite(STDERR, "error: --before expects YYYY-MM-DD, got '{$args[$i]}'\n");
            exit(1);
        }
        $before = $dt->setTime(0, 0, 0);
    } elseif ($args[$i] === '--month' && isset($args[$i + 1])) {
        if (!preg_match('/^\d{4}-\d{2}$/', $args[$i + 1])) {
            fwrite(STDERR, "error: --month expects YYYY-MM, got '{$args[$i + 1]}'\n");
            exit(1);
        }
        $monthFilter = $args[++$i];
    }
}

$reportsDir = PROJECT_ROOT . '/reports';

if (!is_dir($reportsDir)) {
    echo "No reports directory found at $reportsDir — nothing to reset.\n";
    exit(0);
}

// ── Discover per-day directories ──────────────────────────────────────────────

// Structure: reports/YYYY-MM/DD/
$dayDirs = [];
foreach (glob($reportsDir . '/????-??') ?: [] as $monthDir) {
    $month = basename($monthDir);
    if ($monthFilter !== null && $month !== $monthFilter) {
        continue;
    }
    foreach (glob($monthDir . '/??') ?: [] as $dayDir) {
        $day = basename($dayDir);
        $dateStr = "$month-$day";
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if ($dt === false) {
            continue;
        }
        if ($before !== null && $dt >= $before) {
            continue;
        }
        $dayDirs[$dateStr] = $dayDir;
    }
}

if (!$dayDirs) {
    echo "No per-day cache directories match the given filters.\n";
    if ($before !== null) {
        echo "Filter: --before " . $before->format('Y-m-d') . "\n";
    }
    if ($monthFilter !== null) {
        echo "Filter: --month $monthFilter\n";
    }
    exit(0);
}

ksort($dayDirs);

// ── Report what would be / is being deleted ───────────────────────────────────

$totalFiles = 0;
$totalBytes = 0;

foreach ($dayDirs as $dateStr => $dir) {
    $files = array_filter(scandir($dir) ?: [], fn($f) => $f !== '.' && $f !== '..');
    $bytes = 0;
    foreach ($files as $f) {
        $bytes += (int)@filesize("$dir/$f");
    }
    $totalFiles += count($files);
    $totalBytes += $bytes;

    $verb = $apply ? 'Removing' : 'Would remove';
    printf("%s  %s  (%d file(s), %s)\n", $verb, $dir, count($files), humanBytes($bytes));
}

echo "\n";
printf(
    "%s %d day director%s, %d file(s), %s total.\n",
    $apply ? 'Removed' : 'Would remove',
    count($dayDirs),
    count($dayDirs) === 1 ? 'y' : 'ies',
    $totalFiles,
    humanBytes($totalBytes)
);

if (!$apply) {
    echo "\nDry run. Pass --apply to perform the deletion.\n";
    echo "config.json and reports/config/ backups are never touched by this tool.\n";
    exit(0);
}

// ── Delete ────────────────────────────────────────────────────────────────────

foreach ($dayDirs as $dir) {
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        @unlink("$dir/$f");
    }
    @rmdir($dir);

    // Remove empty month directories.
    $monthDir = dirname($dir);
    $remaining = array_filter(scandir($monthDir) ?: [], fn($f) => $f !== '.' && $f !== '..');
    if (!$remaining) {
        @rmdir($monthDir);
    }
}

echo "Done. Run 'php activity-report.php' (or use Rebuild in the web UI) to regenerate.\n";

// ── Helpers ───────────────────────────────────────────────────────────────────

function humanBytes(int $bytes): string
{
    if ($bytes >= 1_048_576) {
        return round($bytes / 1_048_576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
