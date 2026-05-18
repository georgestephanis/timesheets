/**
 * @timesheets/engine — TypeScript/JS port of the PHP activity-report core.
 *
 * Public API surface mirrors the IPC commands listed in NATIVE.md:
 *   getReport, rebuildReport, getConfig, saveConfig, reassignSignal,
 *   setProjectGrouping, flagProjectsIgnored
 *
 * Phase 2 implements config I/O, date-range resolution, and cache read/write.
 * Source loaders (ActivityWatch, Chrome, Git, integrations) are stubs pending Phase 2 step 6.
 */

import path from "path";
import { loadConfig, saveConfigWithBackup, applySignalToProject } from "./lib/config.js";
import { clearWarnings, getWarnings } from "./lib/helpers.js";
import { loadSourcesForRange, loadCachedLlmSummary } from "./lib/cache.js";
import { classifyAndAggregate } from "./lib/classifiers.js";
import { buildReport } from "./lib/renderer.js";
import { makeLoadFreshFn } from "./lib/loaders.js";

// ─── Re-exports (lib surface available to callers) ────────────────────────────

export { loadConfig, saveConfigWithBackup, applySignalToProject } from "./lib/config.js";
export {
    expandPath,
    fnmatchAny,
    fnmatchGlob,
    hostMatchesDomain,
    hostMatchesAnyDomain,
    fmtDur,
    chromeTime,
    warning,
    getWarnings,
    clearWarnings,
} from "./lib/helpers.js";
export {
    resolveDateRange,
    rangeDays,
    rangeIsHistorical,
    reportsCacheKey,
    dailyCacheKey,
    localMidnight,
} from "./lib/date-utils.js";
export {
    reportsDir,
    loadCachedSources,
    loadDailyCachedSources,
    saveCachedSources,
    saveDailyCachedSources,
    sourceCacheMtime,
    loadCachedLlmSummary,
    saveCachedLlmSummary,
    mergeSourceBundles,
    filterSourcesToRange,
    loadSourcesForRange,
    saveGeneratedReport,
    appendToIndex,
} from "./lib/cache.js";
export {
    classifyVscode,
    classifySlack,
    classifySsh,
    projectForSignals,
    projectForExternal,
    isAfkAt,
    activeInputSecondsDuring,
    classifyAndAggregate,
} from "./lib/classifiers.js";
export { buildReport } from "./lib/renderer.js";
export { makeLoadFreshFn } from "./lib/loaders.js";
export { loadActivityWatch } from "./lib/loader-activitywatch.js";
export { loadChromeHistory, backfillChromeUrls } from "./lib/loader-chrome.js";
export { loadGitCommits } from "./lib/loader-git.js";
export { discoverGitHubDesktopRepos } from "./lib/loader-github-desktop.js";

// ─── Engine class ─────────────────────────────────────────────────────────────

class TimesheetsEngine {
    /**
     * @param {string} configPath  Absolute path to config.json.
     */
    constructor(configPath = "./config.json") {
        this.configPath = path.resolve(configPath);
        this.projectRoot = path.dirname(this.configPath);
        this.cacheDir = path.join(this.projectRoot, "reports");
        /** @type {import('@timesheets/contracts').Config|null} */
        this.config = null;
    }

    /** @returns {Promise<import('@timesheets/contracts').Config>} */
    async loadConfig() {
        this.config = await loadConfig(this.configPath);
        return this.config;
    }

    /**
     * Resolves a date range from YYYY-MM-DD strings.
     * @param {string} from
     * @param {string} to
     * @returns {import('@timesheets/contracts').DateRange}
     */
    resolveDateRange(from, to) {
        return { from, to };
    }

    /**
     * Generates a report for the given date range.
     * Sources are loaded from cache where possible; source loaders are stubs
     * until Phase 2 step 6.
     *
     * @param {import('@timesheets/contracts').DateRange} range
     * @param {{ rebuild?: boolean }} [options]
     * @returns {Promise<import('@timesheets/contracts').Report>}
     */
    async generateReport(range, options = {}) {
        if (!this.config) await this.loadConfig();
        const cfg = /** @type {import('@timesheets/contracts').Config} */ (this.config);
        const tz = cfg.timezone ?? "UTC";

        clearWarnings();

        const from = new Date(range.from + "T00:00:00");
        const to = new Date(range.to + "T23:59:59");

        const { bundle } = await loadSourcesForRange(
            this.projectRoot,
            tz,
            from,
            to,
            makeLoadFreshFn(cfg),
            options.rebuild ?? false,
        );

        const { bucket, unmatched, timeline } = classifyAndAggregate(
            /** @type {any} */ (bundle.events),
            bundle.commits,
            bundle.external,
            cfg,
            tz,
        );

        // Collect LLM summaries from cache.
        const { rangeDays } = await import("./lib/date-utils.js");
        const days = rangeDays(from, to, tz);
        /** @type {Record<string, string>} */
        const summaries = {};
        for (const day of days) {
            const s = await loadCachedLlmSummary(this.projectRoot, day, tz);
            if (s) summaries[new Intl.DateTimeFormat("en-CA", { timeZone: tz }).format(day)] = s;
        }

        return buildReport(bucket, unmatched, from, to, tz, timeline, getWarnings(), summaries);
    }

    /**
     * Forces a full rebuild from live sources, ignoring cache.
     * @param {import('@timesheets/contracts').DateRange} range
     * @returns {Promise<import('@timesheets/contracts').Report>}
     */
    async rebuildReport(range) {
        return this.generateReport(range, { rebuild: true });
    }

    /** @returns {Promise<import('@timesheets/contracts').Config>} */
    async getConfig() {
        return this.loadConfig();
    }

    /**
     * Saves config with atomic write and timestamped backup.
     * @param {import('@timesheets/contracts').Config} newConfig
     * @returns {Promise<void>}
     */
    async saveConfig(newConfig) {
        await saveConfigWithBackup(newConfig, this.configPath, "engine");
        this.config = newConfig;
    }

    /**
     * Reassigns one unmatched signal to a project and persists the config.
     * @param {import('@timesheets/contracts').ReassignSignalPayload} payload
     * @returns {Promise<void>}
     */
    async reassignSignal(payload) {
        if (!this.config) await this.loadConfig();
        const cfg = /** @type {import('@timesheets/contracts').Config} */ (this.config);
        applySignalToProject(cfg, payload.type, payload.key, payload.project);
        await this.saveConfig(cfg);
    }

    /**
     * Sets a project's grouping and persists the config.
     * @param {import('@timesheets/contracts').SetGroupingPayload} payload
     * @returns {Promise<void>}
     */
    async setProjectGrouping(payload) {
        if (!this.config) await this.loadConfig();
        const cfg = /** @type {import('@timesheets/contracts').Config} */ (this.config);
        if (cfg.projects?.[payload.project]) cfg.projects[payload.project].grouping = payload.grouping;
        await this.saveConfig(cfg);
    }

    /**
     * Marks a set of projects as ignored (or un-ignored) and persists the config.
     * @param {import('@timesheets/contracts').FlagIgnoredPayload} payload
     * @returns {Promise<void>}
     */
    async flagProjectsIgnored(payload) {
        if (!this.config) await this.loadConfig();
        const cfg = /** @type {import('@timesheets/contracts').Config} */ (this.config);
        const ignored = new Set(cfg.ignored_projects ?? []);
        for (const p of payload.projects) {
            if (payload.ignored) ignored.add(p);
            else ignored.delete(p);
        }
        cfg.ignored_projects = [...ignored];
        await this.saveConfig(cfg);
    }
}

export { TimesheetsEngine };
