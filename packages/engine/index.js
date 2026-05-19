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
import { loadSourcesForRange, loadCachedLlmSummary, saveCachedLlmSummary } from "./lib/cache.js";
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
     * Generates (or returns cached) an LLM day summary for the given date.
     * Uses the first configured LLM connection from config.integrations.llm.
     * The result is cached to disk and will be included in subsequent generateReport calls.
     *
     * @param {string} date  YYYY-MM-DD
     * @returns {Promise<string>}
     */
    async generateSummary(date) {
        if (!this.config) await this.loadConfig();
        const cfg = /** @type {import('@timesheets/contracts').Config} */ (this.config);
        const tz = cfg.timezone ?? "UTC";

        const llm = cfg.integrations?.llm?.[0];
        if (!llm) throw new Error("No LLM connection configured in config.integrations.llm");

        const dayDate = new Date(date + "T12:00:00");

        // Return cached summary if it's still fresh.
        const cached = await loadCachedLlmSummary(this.projectRoot, dayDate, tz);
        if (cached) return cached;

        // Build a compact prompt from the day's report.
        const report = await this.generateReport({ from: date, to: date });
        const projects = report.days[date] ?? {};
        const lines = Object.entries(projects)
            .sort(([, a], [, b]) => b.seconds - a.seconds)
            .map(([name, p]) => {
                const h = Math.floor(p.seconds / 3600);
                const m = Math.floor((p.seconds % 3600) / 60);
                const subjects = (p.commits ?? []).map((c) => c.subj).join("; ");
                return `- ${name}: ${h}h ${m}m${subjects ? ` (${subjects})` : ""}`;
            });

        if (lines.length === 0) throw new Error("No project data available for this day");

        const prompt =
            `Summarize my work on ${date}:\n${lines.join("\n")}\n\n` +
            "Write 2-3 concise sentences. Focus on what was accomplished, not the time.";

        const baseUrl = llm.base_url.replace(/\/$/, "");
        const authHeaders = {
            "Content-Type": "application/json",
            Authorization: `Bearer ${llm.api_key ?? "sk-no-key"}`,
        };
        const timeout = (llm.timeout ?? 30) * 1000;

        // Resolves the model to use, fetching /models if not set, and persists it
        // back to config so future calls skip the extra round-trip.
        const resolveModel = async () => {
            if (llm.model) return llm.model;
            const modelsRes = await fetch(`${baseUrl}/models`, {
                headers: authHeaders,
                signal: AbortSignal.timeout(timeout),
            });
            if (!modelsRes.ok) throw new Error(`Could not fetch model list: ${modelsRes.status}`);
            const modelsData = /** @type {any} */ (await modelsRes.json());
            const discovered = modelsData?.data?.[0]?.id;
            if (!discovered) throw new Error("LLM /models returned no models");
            // Persist so subsequent calls use it directly.
            llm.model = discovered;
            await this.saveConfig(cfg);
            return discovered;
        };

        let model = await resolveModel();

        // Call the OpenAI-compatible chat completions endpoint, retrying once if
        // the model is no longer available (e.g. it was replaced on the server).
        let res = await fetch(`${baseUrl}/chat/completions`, {
            method: "POST",
            headers: authHeaders,
            body: JSON.stringify({
                model,
                messages: [{ role: "user", content: prompt }],
                max_tokens: 200,
            }),
            signal: AbortSignal.timeout(timeout),
        });

        if (!res.ok && res.status === 404) {
            // Model gone — clear it, rediscover, and retry once.
            delete llm.model;
            await this.saveConfig(cfg);
            model = await resolveModel();
            res = await fetch(`${baseUrl}/chat/completions`, {
                method: "POST",
                headers: authHeaders,
                body: JSON.stringify({
                    model,
                    messages: [{ role: "user", content: prompt }],
                    max_tokens: 200,
                }),
                signal: AbortSignal.timeout(timeout),
            });
        }

        if (!res.ok) {
            const text = await res.text().catch(() => "");
            throw new Error(`LLM API error ${res.status}: ${text}`);
        }

        const data = /** @type {any} */ (await res.json());
        const summary = data?.choices?.[0]?.message?.content?.trim();
        if (!summary) throw new Error("LLM returned an empty response");

        await saveCachedLlmSummary(this.projectRoot, dayDate, tz, summary);
        return summary;
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
