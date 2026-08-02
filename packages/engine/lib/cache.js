/**
 * Caching layer: persists raw source data and generated reports to reports/YYYY-MM/DD/.
 * TypeScript port of src/cache.php.
 */

import { promises as fs } from "fs";
import path from "path";
import { dailyCacheKey, rangeIsHistorical, reportsCacheKey } from "./date-utils.js";

/**
 * Returns the reports sub-directory path for a given date.
 * Structure: {projectRoot}/reports/YYYY-MM/DD
 * Mirrors PHP reportsDir().
 * @param {string} projectRoot  Absolute path to the project root (directory containing config.json).
 * @param {Date}   from         Any date within the target day.
 * @param {string} timezone
 * @returns {string}
 */
export function reportsDir(projectRoot, from, timezone) {
    const fmt = new Intl.DateTimeFormat("en-CA", { timeZone: timezone });
    const ymd = fmt.format(from); // "YYYY-MM-DD"
    const [year, month, day] = ymd.split("-");
    return path.join(projectRoot, "reports", `${year}-${month}`, day);
}

/**
 * Tries to load a cached source bundle from disk.
 * Returns null if any cache file is missing or contains invalid JSON.
 * Mirrors PHP loadCachedSources().
 *
 * @param {string} dir  Absolute path to the per-day reports directory.
 * @param {string} key  Date-range key from reportsCacheKey().
 * @returns {Promise<SourceBundle|null>}
 */
export async function loadCachedSources(dir, key) {
    const names = ["activitywatch", "chrome", "commits", "integrations", "ai-sessions"];
    const paths = Object.fromEntries(names.map((n) => [n, path.join(dir, `${n}-${key}.json`)]));

    for (const p of Object.values(paths)) {
        try {
            await fs.access(p);
        } catch {
            return null;
        }
    }

    /** @type {Record<string, any>} */
    const raw = {};
    for (const [name, p] of Object.entries(paths)) {
        const text = await fs.readFile(p, "utf8");
        let parsed;
        try {
            parsed = JSON.parse(text);
        } catch {
            return null;
        }
        if (!Array.isArray(parsed) && typeof parsed !== "object") return null;
        raw[name] = parsed;
    }

    return {
        events: deserializeEvents(raw.activitywatch),
        chrome: deserializeChrome(raw.chrome),
        commits: deserializeCommits(raw.commits),
        external: deserializeExternal(raw.integrations),
        aiSessions: deserializeExternal(raw["ai-sessions"]),
    };
}

/**
 * Loads a full-day cached source bundle for the given calendar day, if present.
 * @param {string} projectRoot
 * @param {Date}   day
 * @param {string} timezone
 * @returns {Promise<SourceBundle|null>}
 */
export async function loadDailyCachedSources(projectRoot, day, timezone) {
    const dir = reportsDir(projectRoot, day, timezone);
    const key = dailyCacheKey(day, timezone);
    return loadCachedSources(dir, key);
}

/**
 * Serialises and writes source data to the per-day reports directory,
 * then appends a record to reports/cache-data.jsonl.
 * Mirrors PHP saveCachedSources().
 *
 * @param {string}       projectRoot
 * @param {string}       dir
 * @param {string}       key
 * @param {Date}         from
 * @param {Date}         to
 * @param {string}       timezone
 * @param {SourceBundle} bundle
 */
export async function saveCachedSources(projectRoot, dir, key, from, to, timezone, bundle) {
    await fs.mkdir(dir, { recursive: true });

    const flags = { encoding: /** @type {'utf8'} */ ("utf8") };
    const write = (/** @type {string} */ name, /** @type {unknown} */ data) =>
        fs.writeFile(path.join(dir, `${name}-${key}.json`), JSON.stringify(data, null, 2) + "\n", flags);

    await Promise.all([
        write("activitywatch", serializeEvents(bundle.events)),
        write("chrome", serializeChrome(bundle.chrome)),
        write("commits", serializeCommits(bundle.commits)),
        write("integrations", serializeExternal(bundle.external)),
        write("ai-sessions", serializeExternal(bundle.aiSessions ?? [])),
    ]);

    const fmt = new Intl.DateTimeFormat("en-CA", { timeZone: timezone });
    const ymd = fmt.format(from);
    const [year, month, day] = ymd.split("-");
    const relBase = `${year}-${month}/${day}`;

    await appendToIndex(path.join(projectRoot, "reports", "cache-data.jsonl"), {
        cached_at: new Date().toISOString(),
        from: fmt.format(from),
        to: fmt.format(to),
        key,
        files: ["activitywatch", "chrome", "commits", "integrations", "ai-sessions"].map(
            (n) => `${relBase}/${n}-${key}.json`,
        ),
        counts: {
            window_events: bundle.events.window.length,
            afk_events: bundle.events.afk.length,
            input_events: (bundle.events.input ?? []).length,
            chrome_rows: bundle.chrome.length,
            commits: bundle.commits.length,
            external_rows: bundle.external.length,
            ai_sessions: (bundle.aiSessions ?? []).length,
        },
    });
}

/**
 * Writes a full-day source cache bundle for the given calendar day.
 * @param {string}       projectRoot
 * @param {Date}         day
 * @param {string}       timezone
 * @param {SourceBundle} bundle
 */
export async function saveDailyCachedSources(projectRoot, day, timezone, bundle) {
    const dir = reportsDir(projectRoot, day, timezone);
    const key = dailyCacheKey(day, timezone);
    const dayStart = new Date(day);
    dayStart.setHours(0, 0, 0, 0);
    const dayEnd = new Date(day);
    dayEnd.setHours(23, 59, 59, 999);
    await saveCachedSources(projectRoot, dir, key, dayStart, dayEnd, timezone, bundle);
}

/**
 * Returns the maximum mtime (in ms) of the four per-day source cache files, or 0 if none exist.
 * @param {string} projectRoot
 * @param {Date}   day
 * @param {string} timezone
 * @returns {Promise<number>}
 */
export async function sourceCacheMtime(projectRoot, day, timezone) {
    const dir = reportsDir(projectRoot, day, timezone);
    const key = dailyCacheKey(day, timezone);
    let mtime = 0;
    for (const src of ["activitywatch", "chrome", "commits", "integrations", "ai-sessions"]) {
        try {
            const stat = await fs.stat(path.join(dir, `${src}-${key}.json`));
            mtime = Math.max(mtime, stat.mtimeMs);
        } catch {
            // file doesn't exist
        }
    }
    return mtime;
}

/**
 * Returns the most recent mtime (in ms) across the past `lookbackDays` days of
 * source cache files, or 0 if no cache files exist in that window.
 * @param {string} projectRoot
 * @param {string} timezone
 * @param {number} [lookbackDays]
 * @returns {Promise<number>}
 */
export async function mostRecentCacheMtime(projectRoot, timezone, lookbackDays = 10) {
    const now = Date.now();
    let latest = 0;
    for (let i = 0; i <= lookbackDays; i++) {
        const day = new Date(now - i * 86_400_000);
        const mtime = await sourceCacheMtime(projectRoot, day, timezone);
        if (mtime > latest) latest = mtime;
    }
    return latest;
}

/**
 * Loads a cached LLM day summary, validating it against the source fingerprint.
 * Returns null when absent, stale, or malformed.
 * Mirrors PHP loadCachedLlmSummary().
 * @param {string} projectRoot
 * @param {Date}   day
 * @param {string} timezone
 * @returns {Promise<string|null>}
 */
export async function loadCachedLlmSummary(projectRoot, day, timezone) {
    const dir = reportsDir(projectRoot, day, timezone);
    const key = dailyCacheKey(day, timezone);
    const p = path.join(dir, `llm-summary-${key}.json`);
    let data;
    try {
        data = JSON.parse(await fs.readFile(p, "utf8"));
    } catch {
        return null;
    }
    if (!data?.summary) return null;
    const currentMtime = await sourceCacheMtime(projectRoot, day, timezone);
    if ((data.source_mtime ?? -1) !== currentMtime) return null;
    return String(data.summary);
}

/**
 * Persists a generated LLM day summary to disk alongside a source fingerprint.
 * Mirrors PHP saveCachedLlmSummary().
 * @param {string} projectRoot
 * @param {Date}   day
 * @param {string} timezone
 * @param {string} summary
 */
export async function saveCachedLlmSummary(projectRoot, day, timezone, summary) {
    const dir = reportsDir(projectRoot, day, timezone);
    const key = dailyCacheKey(day, timezone);
    await fs.mkdir(dir, { recursive: true });
    await fs.writeFile(
        path.join(dir, `llm-summary-${key}.json`),
        JSON.stringify(
            {
                summary,
                source_mtime: await sourceCacheMtime(projectRoot, day, timezone),
                generated_at: new Date().toISOString(),
            },
            null,
            2,
        ) + "\n",
    );
}

/**
 * Merges multiple source bundles into one.
 * Mirrors PHP mergeSourceBundles().
 * @param {SourceBundle[]} bundles
 * @returns {SourceBundle}
 */
export function mergeSourceBundles(bundles) {
    return {
        events: {
            window: bundles.flatMap((b) => b.events.window),
            afk: bundles.flatMap((b) => b.events.afk),
            input: bundles.flatMap((b) => b.events.input ?? []),
        },
        chrome: bundles.flatMap((b) => b.chrome),
        commits: bundles.flatMap((b) => b.commits),
        external: bundles.flatMap((b) => b.external),
        aiSessions: bundles.flatMap((b) => b.aiSessions ?? []),
    };
}

/**
 * Trims a merged source bundle to the exact requested range.
 * Mirrors PHP filterSourcesToRange().
 * @param {SourceBundle} bundle
 * @param {Date}         from
 * @param {Date}         to
 * @returns {SourceBundle}
 */
export function filterSourcesToRange(bundle, from, to) {
    /** @param {AwEvent[]} rows */
    const filterSpan = (rows) => rows.filter((r) => r.end >= from && r.start <= to);

    return {
        events: {
            window: filterSpan(bundle.events.window),
            afk: filterSpan(bundle.events.afk),
            input: filterSpan(bundle.events.input ?? []),
        },
        chrome: bundle.chrome.filter((r) => r.time >= from && r.time <= to),
        commits: bundle.commits.filter((r) => r.dt >= from && r.dt <= to),
        external: bundle.external.filter((r) => r.end >= from && r.start <= to),
        aiSessions: (bundle.aiSessions ?? []).filter((r) => r.end >= from && r.start <= to),
    };
}

/**
 * Loads sources for a date range, using per-day caches where possible.
 * Mirrors PHP loadSourcesForRange().
 *
 * @param {string}   projectRoot
 * @param {string}   timezone
 * @param {Date}     from
 * @param {Date}     to
 * @param {function} loadFreshFn  (from: Date, to: Date) => Promise<SourceBundle>
 * @param {boolean}  [rebuild=false]
 * @returns {Promise<{ bundle: SourceBundle; fromCache: boolean }>}
 */
export async function loadSourcesForRange(projectRoot, timezone, from, to, loadFreshFn, rebuild = false) {
    const { rangeDays } = await import("./date-utils.js");
    const days = rangeDays(from, to, timezone);
    const bundles = [];
    let fromCache = true;

    for (const day of days) {
        const dayStart = new Date(day);
        const dayEnd = new Date(day.getTime() + 24 * 3600 * 1000 - 1000);
        const sliceFrom = from > dayStart ? from : dayStart;
        const sliceTo = to < dayEnd ? to : dayEnd;
        const isFullDay = sliceFrom.getTime() === dayStart.getTime() && sliceTo.getTime() === dayEnd.getTime();
        const isHistorical = rangeIsHistorical(dayEnd, timezone);

        if (!rebuild && isFullDay && isHistorical) {
            const cached = await loadDailyCachedSources(projectRoot, day, timezone);
            if (cached) {
                bundles.push(cached);
                continue;
            }
        }

        const fresh = await loadFreshFn(sliceFrom, sliceTo);
        bundles.push(fresh);
        fromCache = false;

        if (isFullDay && isHistorical) {
            await saveDailyCachedSources(projectRoot, day, timezone, fresh);
        }
    }

    const bundle = filterSourcesToRange(mergeSourceBundles(bundles), from, to);
    return { bundle, fromCache };
}

/**
 * Writes a generated report to the per-day directory and appends to generated-reports.jsonl.
 * Mirrors PHP saveGeneratedReport().
 *
 * @param {string}      projectRoot
 * @param {string}      dir
 * @param {string}      key
 * @param {Date}        from
 * @param {Date}        to
 * @param {string}      timezone
 * @param {string}      format   'json' | 'md' | 'tsv'
 * @param {string|null} project  Active project filter, or null for all.
 * @param {boolean}     fromCache
 * @param {string}      content  Fully rendered report string.
 */
export async function saveGeneratedReport(
    projectRoot,
    dir,
    key,
    from,
    to,
    timezone,
    format,
    project,
    fromCache,
    content,
) {
    await fs.mkdir(dir, { recursive: true });

    const ext = format === "json" ? "json" : format === "tsv" ? "tsv" : "md";
    const slug = project ? "--" + project.replace(/[^a-zA-Z0-9_-]+/g, "-") : "";
    const timestamp = new Date()
        .toISOString()
        .replace(/[-:]/g, "")
        .replace("T", "T")
        .replace(/\.\d+Z$/, "");
    const file = `report-${key}${slug}--${timestamp}.${ext}`;

    await fs.writeFile(path.join(dir, file), content);

    const fmtDate = new Intl.DateTimeFormat("en-CA", { timeZone: timezone });
    const ymd = fmtDate.format(from);
    const [year, month, day] = ymd.split("-");

    await appendToIndex(path.join(projectRoot, "reports", "generated-reports.jsonl"), {
        generated_at: new Date().toISOString(),
        from: fmtDate.format(from),
        to: fmtDate.format(to),
        key,
        format: ext,
        project: project ?? null,
        file: `${year}-${month}/${day}/${file}`,
        size_bytes: Buffer.byteLength(content),
        from_cache: fromCache,
    });
}

/**
 * Appends a single JSON object as a new line to a JSONL index file.
 * Mirrors PHP appendToIndex().
 * @param {string} filePath
 * @param {object} record
 */
export async function appendToIndex(filePath, record) {
    await fs.mkdir(path.dirname(filePath), { recursive: true });
    await fs.appendFile(filePath, JSON.stringify(record) + "\n");
}

// ─── Serialization helpers ────────────────────────────────────────────────────

/** @param {AwEvents} events */
function serializeEvents(events) {
    const fmt = (/** @type {AwEvent[]} */ rows) =>
        rows.map((r) => ({
            ...r,
            start: r.start instanceof Date ? r.start.toISOString() : r.start,
            end: r.end instanceof Date ? r.end.toISOString() : r.end,
        }));
    return {
        window: fmt(events.window),
        afk: fmt(events.afk),
        input: fmt(events.input ?? []),
    };
}

/** @param {any} data */
function deserializeEvents(data) {
    const parse = (/** @type {any[]} */ rows) =>
        (rows ?? []).map((/** @type {any} */ r) => ({
            ...r,
            start: typeof r.start === "string" ? new Date(r.start) : r.start,
            end: typeof r.end === "string" ? new Date(r.end) : r.end,
        }));
    return {
        window: parse(data?.window),
        afk: parse(data?.afk),
        input: parse(data?.input),
    };
}

/** @param {ChromeRow[]} rows */
function serializeChrome(rows) {
    return rows.map((r) => ({
        ...r,
        time: r.time instanceof Date ? r.time.toISOString() : r.time,
    }));
}

/** @param {any[]} rows */
function deserializeChrome(rows) {
    return (rows ?? []).map((r) => ({
        ...r,
        time: typeof r.time === "string" ? new Date(r.time) : r.time,
    }));
}

/** @param {CommitRow[]} rows */
function serializeCommits(rows) {
    return rows.map((c) => ({
        ...c,
        dt: c.dt instanceof Date ? c.dt.toISOString() : c.dt,
    }));
}

/** @param {any[]} rows */
function deserializeCommits(rows) {
    return (rows ?? []).map((c) => ({
        ...c,
        dt: typeof c.dt === "string" ? new Date(c.dt) : c.dt,
    }));
}

/** @param {ExternalRow[]} rows */
function serializeExternal(rows) {
    return rows.map((r) => ({
        ...r,
        start: r.start instanceof Date ? r.start.toISOString() : r.start,
        end: r.end instanceof Date ? r.end.toISOString() : r.end,
    }));
}

/** @param {any[]} rows */
function deserializeExternal(rows) {
    return (rows ?? []).map((r) => ({
        ...r,
        start: typeof r.start === "string" ? new Date(r.start) : r.start,
        end: typeof r.end === "string" ? new Date(r.end) : r.end,
    }));
}

// ─── JSDoc type stubs ─────────────────────────────────────────────────────────

/**
 * @typedef {{ start: Date; end: Date; data: Record<string, unknown> }} AwEvent
 * @typedef {{ window: AwEvent[]; afk: AwEvent[]; input: AwEvent[] }} AwEvents
 * @typedef {{ time: Date; host: string; url: string; title: string }} ChromeRow
 * @typedef {{ dt: Date; project: string; repo: string; sha: string; subj: string }} CommitRow
 * @typedef {{ start: Date; end: Date; [key: string]: unknown }} ExternalRow
 * @typedef {{ start: Date; end: Date; project: string; source: string; label: string; detail: string; approximate_timing?: boolean }} AiSessionRow
 * @typedef {{ events: AwEvents; chrome: ChromeRow[]; commits: CommitRow[]; external: ExternalRow[]; aiSessions: AiSessionRow[] }} SourceBundle
 */
