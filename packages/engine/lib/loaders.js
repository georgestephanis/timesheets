/**
 * Source loader orchestrator — wires ActivityWatch, Chrome, and Git loaders
 * into the SourceBundle shape required by loadSourcesForRange().
 *
 * Integration API loaders (Harvest, ClickUp, Clockify, GitHub REST) are
 * stubbed; they return empty arrays until implemented.
 */

import { loadActivityWatch } from "./loader-activitywatch.js";
import { loadChromeHistory, backfillChromeUrls } from "./loader-chrome.js";
import { loadGitCommits } from "./loader-git.js";
import { loadClaudeCodeSessions } from "./loader-claude-code.js";
import { loadAntigravitySessions } from "./loader-antigravity.js";
import { warning } from "./helpers.js";

/**
 * Loads a fresh SourceBundle covering the given date range.
 *
 * Matches the `loadFreshFn` signature expected by loadSourcesForRange():
 *   (from: Date, to: Date) => Promise<SourceBundle>
 *
 * @param {import('@timesheets/contracts').Config} config
 * @returns {(from: Date, to: Date) => Promise<import('./cache.js').SourceBundle>}
 */
export function makeLoadFreshFn(config) {
    return async function loadFresh(from, to) {
        // ActivityWatch
        /** @type {{ window: any[]; afk: any[]; input: any[] }} */
        let events = { window: [], afk: [], input: [] };
        try {
            events = loadActivityWatch(config, from, to);
        } catch (err) {
            warning("aw", `loadActivityWatch failed: ${err instanceof Error ? err.message : String(err)}`);
        }

        // Chrome history
        /** @type {Array<{ time: Date; host: string; url: string; title: string }>} */
        let chrome = [];
        try {
            chrome = loadChromeHistory(config, from, to);
            const backfillWindowSec = 30;
            backfillChromeUrls(events, chrome, backfillWindowSec);
        } catch (err) {
            warning("chrome", `loadChromeHistory failed: ${err instanceof Error ? err.message : String(err)}`);
        }

        // Git commits
        /** @type {Array<{ dt: Date; project: string; repo: string; sha: string; subj: string }>} */
        let commits = [];
        try {
            commits = loadGitCommits(config, from, to);
        } catch (err) {
            warning("git", `loadGitCommits failed: ${err instanceof Error ? err.message : String(err)}`);
        }

        // Integration APIs — stubbed (Harvest, ClickUp, Clockify, GitHub REST).
        const external = /** @type {any[]} */ ([]);

        // AI coding sessions (Claude Code, Antigravity) — display-only, like commits.
        // Each row keeps its own start/end, so overlapping sessions from concurrent
        // windows (e.g. two Claude Code sessions running at once) are simply
        // concatenated rather than merged into one.
        /** @type {Array<{ start: Date; end: Date; project: string; source: string; label: string; detail: string; approximate_timing?: boolean }>} */
        let aiSessions = [];
        try {
            const [claude, antigravity] = await Promise.all([
                loadClaudeCodeSessions(config, from, to),
                Promise.resolve(loadAntigravitySessions(config, from, to)),
            ]);
            aiSessions = [...claude, ...antigravity];
        } catch (err) {
            warning(
                "ai-sessions",
                `loadClaudeCodeSessions/loadAntigravitySessions failed: ${err instanceof Error ? err.message : String(err)}`,
            );
        }

        return { events, chrome, commits, external, aiSessions };
    };
}
