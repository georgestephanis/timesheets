<?php

declare(strict_types=1);

/**
 * Discovers git repositories registered in the GitHub Desktop application.
 *
 * Reads GitHub Desktop's Chromium IndexedDB LevelDB files as raw bytes and
 * extracts /Users/... paths. .log files are the active write-ahead log (most
 * recent writes); .ldb files are compacted sorted-string tables (older data).
 * Paths that appear only in .log are tagged "recent".
 */

/**
 * Returns the path to GitHub Desktop's IndexedDB LevelDB directory, or null.
 */
function githubDesktopLevelDbPath(): ?string
{
    $home = (string)(getenv('HOME') ?: posix_getpwuid(posix_getuid())['dir'] ?? '');
    $path = $home . '/Library/Application Support/GitHub Desktop/IndexedDB/file__0.indexeddb.leveldb';
    return is_dir($path) ? $path : null;
}

/**
 * Scans LevelDB files for raw /Users/... byte strings.
 *
 * @return array<string, 'recent'|'archive'>  absolute path => recency tag
 */
function scanLevelDbForPaths(string $dir): array
{
    $paths = [];

    foreach (['log' => 'recent', 'ldb' => 'archive'] as $ext => $tag) {
        foreach (glob("$dir/*.$ext") ?: [] as $file) {
            $data = file_get_contents($file);
            if ($data === false) {
                continue;
            }
            // Match printable /Users/ paths; stop at control chars, quotes, backslashes.
            preg_match_all('~/Users/[^\x00-\x1f"\\\\]+~', $data, $m);
            foreach ($m[0] as $p) {
                // Drop garbled entries produced by multi-byte boundary splits.
                if (str_contains($p, "\xef\xbf\xbd") || str_contains($p, '?')) {
                    continue;
                }
                // .log wins over .ldb for recency tagging.
                if (!isset($paths[$p])) {
                    $paths[$p] = $tag;
                }
            }
        }
    }

    return $paths;
}

/**
 * Discovers git repositories registered in the GitHub Desktop application.
 *
 * Each returned entry is keyed by the absolute repo path and contains:
 *   name           — directory basename of the repo
 *   recent         — true when the path appeared in the active write-ahead log
 *   last_commit_ts — Unix timestamp of the newest commit across all branches,
 *                    or 0 when $withTimestamps is false or the lookup fails
 *
 * @return array<string, array{name: string, recent: bool, last_commit_ts: int}>
 */
function discoverGitHubDesktopRepos(bool $withTimestamps = false): array
{
    $dbPath = githubDesktopLevelDbPath();
    if ($dbPath === null) {
        return [];
    }

    $repos = [];
    foreach (scanLevelDbForPaths($dbPath) as $path => $tag) {
        if (!is_dir("$path/.git")) {
            continue;
        }
        $ts = 0;
        if ($withTimestamps) {
            $raw = shell_exec('git -C ' . escapeshellarg($path) . ' log --max-count=1 --format=%at --all 2>/dev/null');
            $ts  = $raw ? (int)trim($raw) : 0;
        }
        $repos[$path] = [
            'name'           => basename($path),
            'recent'         => $tag === 'recent',
            'last_commit_ts' => $ts,
        ];
    }

    return $repos;
}
