<?php

declare(strict_types=1);

/**
 * Antigravity IDE loader: reads conversation logs from ~/.gemini/antigravity-ide/conversations/*.db.
 */

/**
 * Loads Antigravity IDE sessions attributable to a configured project, from every
 * *.db conversation database under paths.antigravity_logs.
 *
 * The workspace path isn't stored as a plain column — it's recovered from the
 * trajectory_metadata_blob protobuf blob by regexing for the first file:// URI it
 * contains. There is no reliable per-event timestamp in these databases either, so
 * timing is approximated from the file's birthtime (session start) and mtime (last
 * update); rows are marked approximate_timing so callers can caveat the display.
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of the query window.
 * @param  DateTimeImmutable $to     End of the query window.
 * @return list<array{
 *     start: DateTimeImmutable, end: DateTimeImmutable, project: string, source: string,
 *     label: string, detail: string, approximate_timing: bool
 * }>
 */
function loadAntigravitySessions(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $dir = expandPath($config['paths']['antigravity_logs'] ?? '~/.gemini/antigravity-ide/conversations');
    if (!is_dir($dir)) {
        return [];
    }

    $tz = new DateTimeZone('UTC');
    $rows = [];
    foreach (glob("$dir/*.db") ?: [] as $file) {
        $mtime = @filemtime($file);
        if ($mtime === false) {
            continue;
        }
        $end = (new DateTimeImmutable('@' . $mtime))->setTimezone($tz);
        if ($end < $from) {
            continue; // cheap pre-filter before opening SQLite
        }

        $birth = antigravityFileBirthtime($file) ?? $mtime;
        $start = (new DateTimeImmutable('@' . $birth))->setTimezone($tz);
        if ($start > $to) {
            continue;
        }

        $workspace = antigravityWorkspacePath($file);
        if ($workspace === null) {
            continue;
        }
        $project = projectForLocalPath($config, $workspace);
        if ($project === null) {
            continue;
        }

        $label = basename($workspace);
        $rows[] = [
            'start'               => $start,
            'end'                 => $end,
            'project'             => $project,
            'source'              => 'antigravity',
            'label'               => $label,
            // Antigravity's payload is an opaque protobuf blob — no message text is
            // recoverable, so detail just echoes the label for a uniform row shape.
            'detail'              => $label,
            'approximate_timing'  => true,
        ];
    }

    usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
    return $rows;
}

/**
 * Extracts the workspace filesystem path from a conversation database's
 * trajectory_metadata_blob, by regexing the raw blob bytes for the first file:// URI.
 */
function antigravityWorkspacePath(string $dbFile): ?string
{
    $copy = copyForRead($dbFile);
    if (!$copy) {
        return null;
    }

    try {
        $db = pdo($copy);
        $st = $db->query('SELECT data FROM trajectory_metadata_blob LIMIT 1');
        $blob = $st !== false ? $st->fetchColumn() : false;
    } catch (Throwable) {
        $blob = false;
    } finally {
        @unlink($copy);
    }

    if (!is_string($blob) || $blob === '') {
        return null;
    }

    if (preg_match('#file:///[^"\x00-\x1f]+#', $blob, $m) !== 1) {
        return null;
    }

    return substr($m[0], strlen('file://'));
}

/**
 * Returns a file's creation time (birthtime) on macOS, or null if unavailable.
 *
 * PHP's stat()/filectime() expose inode-change time, not birthtime, on macOS/BSD —
 * `stat -f %B` is the only reliable way to get true file creation time here.
 */
function antigravityFileBirthtime(string $path): ?int
{
    $out = @shell_exec('stat -f %B ' . escapeshellarg($path) . ' 2>/dev/null');
    if ($out === null) {
        return null;
    }
    $out = trim($out);
    return ctype_digit($out) ? (int)$out : null;
}
