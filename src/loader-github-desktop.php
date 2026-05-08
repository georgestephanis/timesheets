<?php

declare(strict_types=1);

/**
 * Discovers git repositories registered in the GitHub Desktop application.
 *
 * Reads GitHub Desktop's Chromium IndexedDB LevelDB files as raw bytes and
 * extracts absolute repo paths. .log files are the active write-ahead log (most
 * recent writes); .ldb files are compacted sorted-string tables (older data).
 * Paths that appear only in .log are tagged "recent".
 *
 * Supported platforms:
 *   macOS   — ~/Library/Application Support/GitHub Desktop/
 *   Windows — %LOCALAPPDATA%/GitHub Desktop/
 *   Linux   — $XDG_CONFIG_HOME/GitHub Desktop/ (or ~/.config/GitHub Desktop/)
 */

/**
 * Returns the path to GitHub Desktop's IndexedDB LevelDB directory, or null.
 *
 * Does not use posix_* functions so it is safe on Windows.
 */
function githubDesktopLevelDbPath(): ?string
{
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');

    // macOS
    if ($home !== '') {
        $p = $home . '/Library/Application Support/GitHub Desktop/IndexedDB/file__0.indexeddb.leveldb';
        if (is_dir($p)) {
            return $p;
        }
    }

    // Windows (%LOCALAPPDATA%/GitHub Desktop/...)
    $localAppData = getenv('LOCALAPPDATA') ?: '';
    if ($localAppData !== '') {
        $p = str_replace('\\', '/', $localAppData) . '/GitHub Desktop/IndexedDB/file__0.indexeddb.leveldb';
        if (is_dir($p)) {
            return $p;
        }
    }

    // Linux (Electron via XDG or ~/.config)
    $configHome = getenv('XDG_CONFIG_HOME') ?: ($home !== '' ? $home . '/.config' : '');
    if ($configHome !== '') {
        $p = $configHome . '/GitHub Desktop/IndexedDB/file__0.indexeddb.leveldb';
        if (is_dir($p)) {
            return $p;
        }
    }

    return null;
}

/**
 * Scans LevelDB files for raw absolute repo path strings.
 *
 * Handles macOS (/Users/...), Linux (/home/...), and Windows (C:/Users/...)
 * path formats. Windows paths stored with backslashes are normalised to
 * forward slashes so callers can use them uniformly.
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

            $candidates = [];

            // macOS: /Users/...
            // Linux: /home/...
            preg_match_all('~/(?:Users|home)/[^\x00-\x1f"\\\\]+~', $data, $m);
            $candidates = array_merge($candidates, $m[0]);

            // Windows paths stored with forward slashes: C:/Users/...
            preg_match_all('~[A-Za-z]:/Users/[^\x00-\x1f"\\\\]+~', $data, $m);
            $candidates = array_merge($candidates, $m[0]);

            // Windows paths stored with backslashes: C:\Users\...
            // Normalise to forward slashes immediately.
            preg_match_all('~[A-Za-z]:\\\\Users\\\\[^\x00-\x1f"]+~', $data, $m);
            foreach ($m[0] as $raw) {
                $candidates[] = str_replace('\\', '/', $raw);
            }

            foreach ($candidates as $p) {
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
