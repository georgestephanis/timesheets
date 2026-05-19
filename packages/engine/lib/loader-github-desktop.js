/**
 * Discovers git repositories registered in the GitHub Desktop application.
 * TypeScript port of src/loader-github-desktop.php.
 *
 * Scans the GitHub Desktop IndexedDB LevelDB files as raw bytes and extracts
 * absolute repo paths without requiring a LevelDB npm dependency.
 */

import { existsSync, readdirSync, readFileSync } from "fs";
import { homedir } from "os";
import { spawnSync } from "child_process";
import path from "path";

/**
 * Returns the path to GitHub Desktop's LevelDB directory, or null.
 * Mirrors PHP githubDesktopLevelDbPath().
 * @returns {string|null}
 */
function githubDesktopLevelDbPath() {
    const home = homedir();
    const candidates = [
        // macOS
        path.join(home, "Library", "Application Support", "GitHub Desktop", "IndexedDB", "file__0.indexeddb.leveldb"),
        // Windows
        ...(process.env.LOCALAPPDATA
            ? [path.join(process.env.LOCALAPPDATA, "GitHub Desktop", "IndexedDB", "file__0.indexeddb.leveldb")]
            : []),
        // Linux
        path.join(
            process.env.XDG_CONFIG_HOME ?? path.join(home, ".config"),
            "GitHub Desktop",
            "IndexedDB",
            "file__0.indexeddb.leveldb",
        ),
    ];
    for (const c of candidates) {
        if (existsSync(c)) return c;
    }
    return null;
}

/**
 * Scans LevelDB files (.log and .ldb) for raw absolute repo path strings.
 * Mirrors PHP scanLevelDbForPaths().
 * @param {string} dir
 * @returns {Record<string, 'recent'|'archive'>}
 */
function scanLevelDbForPaths(dir) {
    /** @type {Record<string, 'recent'|'archive'>} */
    const paths = {};

    for (const [ext, tag] of /** @type {[string, 'recent'|'archive'][]} */ ([
        ["log", "recent"],
        ["ldb", "archive"],
    ])) {
        let files;
        try {
            files = readdirSync(dir)
                .filter((f) => f.endsWith(`.${ext}`))
                .map((f) => path.join(dir, f));
        } catch {
            continue;
        }
        for (const file of files) {
            let data;
            try {
                data = readFileSync(file, "latin1");
            } catch {
                continue;
            }
            const candidates = [];

            // macOS / Linux paths
            for (const m of data.matchAll(/\/(?:Users|home)\/[^\x00-\x1f"\\]+/g)) {
                candidates.push(m[0]);
            }
            // Windows with forward slashes
            for (const m of data.matchAll(/[A-Za-z]:\/Users\/[^\x00-\x1f"\\]+/g)) {
                candidates.push(m[0]);
            }
            // Windows with backslashes — normalize
            for (const m of data.matchAll(/[A-Za-z]:\\Users\\[^\x00-\x1f"]+/g)) {
                candidates.push(m[0].replace(/\\/g, "/"));
            }

            for (const p of candidates) {
                if (p.includes("�") || p.includes("?")) continue;
                if (!paths[p]) paths[p] = tag;
            }
        }
    }
    return paths;
}

/**
 * Discovers git repositories registered in the GitHub Desktop application.
 * Mirrors PHP discoverGitHubDesktopRepos().
 *
 * @param {boolean} [withTimestamps]
 * @returns {Record<string, { name: string; recent: boolean; last_commit_ts: number }>}
 */
export function discoverGitHubDesktopRepos(withTimestamps = false) {
    const dbPath = githubDesktopLevelDbPath();
    if (!dbPath) return {};

    /** @type {Record<string, { name: string; recent: boolean; last_commit_ts: number }>} */
    const repos = {};
    for (const [repoPath, tag] of Object.entries(scanLevelDbForPaths(dbPath))) {
        if (!existsSync(path.join(repoPath, ".git"))) continue;
        let ts = 0;
        if (withTimestamps) {
            const r = spawnSync("git", ["-C", repoPath, "log", "--max-count=1", "--format=%at", "--all"], {
                encoding: "utf8",
                timeout: 10_000,
            });
            ts = r.stdout ? parseInt(r.stdout.trim(), 10) || 0 : 0;
        }
        repos[repoPath] = { name: path.basename(repoPath), recent: tag === "recent", last_commit_ts: ts };
    }
    return repos;
}
