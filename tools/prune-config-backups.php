#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prunes old config backups and JSONL index files to keep disk usage bounded.
 *
 * Config backups (reports/config/config.<source>.<timestamp>.json) are grouped by
 * source tag; the newest --keep are retained per tag, older ones are deleted.
 *
 * JSONL index files (reports/cache-data.jsonl, reports/generated-reports.jsonl)
 * are trimmed to the last --max-lines lines.
 *
 * Usage:
 *   php tools/prune-config-backups.php               # preview (dry-run)
 *   php tools/prune-config-backups.php --apply        # delete and trim
 *   php tools/prune-config-backups.php --keep 20      # keep 20 per source tag (default 10)
 *   php tools/prune-config-backups.php --max-lines 500 # trim JSONL to 500 lines (default 1000)
 */

define('PROJECT_ROOT', dirname(__DIR__));

$args     = $argv ?? [];
$apply    = in_array('--apply', $args, true);
$keepN    = 10;
$maxLines = 1000;

for ($i = 1; $i < count($args); $i++) {
    if ($args[$i] === '--keep' && isset($args[$i + 1]) && is_numeric($args[$i + 1])) {
        $keepN = max(1, (int)$args[++$i]);
    } elseif ($args[$i] === '--max-lines' && isset($args[$i + 1]) && is_numeric($args[$i + 1])) {
        $maxLines = max(1, (int)$args[++$i]);
    }
}

$backupDir = PROJECT_ROOT . '/reports/config';
$jsonlFiles = [
    PROJECT_ROOT . '/reports/cache-data.jsonl',
    PROJECT_ROOT . '/reports/generated-reports.jsonl',
];

// ── Config backup pruning ─────────────────────────────────────────────────────

if (!is_dir($backupDir)) {
    echo "No backup directory found at $backupDir — nothing to prune.\n";
} else {
    $files = glob($backupDir . '/config.*.json') ?: [];

    // Group by source tag: config.<source>.<timestamp>.json
    $bySource = []; // source => [filename, ...]
    foreach ($files as $path) {
        $name = basename($path);
        // Strip leading "config." and trailing ".<timestamp>.json"
        if (!preg_match('/^config\.(.+)\.\d{8}T\d{6}_\d+\.json$/', $name, $m)) {
            continue;
        }
        $bySource[$m[1]][] = $path;
    }

    $totalDelete = 0;
    $totalKeep   = 0;

    foreach ($bySource as $source => $paths) {
        rsort($paths); // newest first (ISO timestamps sort lexicographically)
        $keep   = array_slice($paths, 0, $keepN);
        $delete = array_slice($paths, $keepN);
        $totalKeep += count($keep);
        $totalDelete += count($delete);

        if ($delete) {
            echo "[$source] keeping " . count($keep) . ", removing " . count($delete) . "\n";
            foreach ($delete as $path) {
                echo "  " . ($apply ? 'delete' : 'would delete') . ": " . basename($path) . "\n";
                if ($apply) {
                    @unlink($path);
                }
            }
        } else {
            echo "[$source] " . count($keep) . " backup(s) — within limit, nothing to prune\n";
        }
    }

    if ($totalDelete === 0) {
        echo "Config backups are within the --keep $keepN limit per source.\n";
    } else {
        $verb = $apply ? 'Deleted' : 'Would delete';
        echo "\n$verb $totalDelete backup(s); kept $totalKeep.\n";
    }
}

echo "\n";

// ── JSONL trimming ────────────────────────────────────────────────────────────

foreach ($jsonlFiles as $path) {
    $label = basename($path);

    if (!is_file($path)) {
        echo "[$label] not found — skipping\n";
        continue;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $count = count($lines);

    if ($count <= $maxLines) {
        echo "[$label] $count line(s) — within --max-lines $maxLines limit\n";
        continue;
    }

    $trimmed = array_slice($lines, $count - $maxLines);
    $removed = $count - count($trimmed);
    echo "[$label] $count lines — " . ($apply ? 'trimming' : 'would trim') . " to last $maxLines (removing $removed)\n";

    if ($apply) {
        $tmp = $path . '.tmp.' . getmypid();
        file_put_contents($tmp, implode("\n", $trimmed) . "\n", LOCK_EX);
        rename($tmp, $path);
    }
}

if (!$apply) {
    echo "\nDry run complete. Pass --apply to perform the deletions and trims.\n";
}
