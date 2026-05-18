/**
 * Git loader: reads commits from all project repositories via `git log`.
 * TypeScript port of src/loader-git.php.
 */

import { spawnSync } from "child_process";
import { existsSync } from "fs";
import path from "path";
import { expandPath } from "./helpers.js";
import { discoverGitHubDesktopRepos } from "./loader-github-desktop.js";

/**
 * Loads git commits from every repository across all configured projects.
 * Mirrors PHP loadGitCommits().
 *
 * @param {import('@timesheets/contracts').Config} config
 * @param {Date} from
 * @param {Date} to
 * @returns {Array<{ dt: Date; project: string; repo: string; sha: string; subj: string }>}
 */
export function loadGitCommits(config, from, to) {
    const authors = config.git_authors ?? [];
    if (!authors.length) return [];

    /** @type {Record<string, string>} absolute repo path → project name */
    const repos = {};

    for (const [proj, p] of Object.entries(config.projects ?? {})) {
        for (const r of p.repos ?? []) {
            repos[expandPath(r)] = proj;
        }
    }

    // Auto-discover repos from GitHub Desktop when requested.
    if (config.discover_repos === "github_desktop") {
        for (const [repoPath, info] of Object.entries(discoverGitHubDesktopRepos())) {
            if (repos[repoPath]) continue; // explicit config wins
            // Try to match by project name (case-insensitive).
            const matched =
                Object.keys(config.projects ?? {}).find((name) => name.toLowerCase() === info.name.toLowerCase()) ??
                info.name;
            repos[repoPath] = matched;
        }
    }

    if (!Object.keys(repos).length) return [];

    const since = `--since=${from.toISOString()}`;
    const until = `--until=${to.toISOString()}`;
    const authorArgs = authors.map((a) => `--author=${a}`);

    const rows = [];
    for (const [repo, project] of Object.entries(repos)) {
        if (!existsSync(path.join(repo, ".git"))) continue;
        const result = spawnSync(
            "git",
            ["-C", repo, "log", "--all", since, until, ...authorArgs, "--pretty=tformat:%aI%x09%H%x09%s"],
            { encoding: "utf8", timeout: 30_000 },
        );
        if (result.status !== 0 || !result.stdout) continue;
        for (const line of result.stdout.trim().split(/\r?\n/)) {
            if (!line) continue;
            const parts = line.split("\t");
            if (parts.length < 3) continue;
            const [iso, sha, ...subjParts] = parts;
            const subj = subjParts.join("\t");
            const dt = new Date(iso);
            if (isNaN(dt.getTime())) continue;
            rows.push({ dt, project, repo, sha, subj });
        }
    }

    rows.sort((a, b) => a.dt.getTime() - b.dt.getTime());
    return rows;
}
