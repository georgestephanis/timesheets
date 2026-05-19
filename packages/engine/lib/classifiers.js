/**
 * Signal classifiers and the main aggregation loop.
 * TypeScript port of src/classifiers.php.
 */

import { fnmatchAny, fnmatchGlob, hostMatchesAnyDomain } from "./helpers.js";

// ─── Event-shape typedefs (match what loaders and cache produce) ──────────────

/**
 * @typedef {{ start: Date; end: Date; app: string; title: string; url: string }} WindowEvent
 * @typedef {{ start: Date; end: Date; status: string }} AfkEvent
 * @typedef {{ start: Date; end: Date; active?: boolean }} InputEvent
 * @typedef {{ window: WindowEvent[]; afk: AfkEvent[]; input: InputEvent[] }} LoadedEvents
 * @typedef {{ start: Date; end: Date; [key: string]: unknown }} ExternalRow
 * @typedef {{ dt: Date; project: string; repo: string; sha: string; subj: string }} CommitRow
 */

// ─── Window-title classifiers ─────────────────────────────────────────────────

/**
 * Extracts the workspace folder name from a macOS VSCode window title.
 * Mirrors PHP classifyVscode().
 * @param {string} title
 * @returns {string|null}
 */
export function classifyVscode(title) {
    if (!title) return null;
    // macOS VSCode title: "filename — project" (em-dash or hyphen) or just "project"
    const parts = title
        .split(/\s+[—\-]\s+/u)
        .map((p) => p.replace(/●/g, "").trim())
        .filter((p) => p !== "" && !["Visual Studio Code", "VS Code", "Code"].includes(p));
    if (!parts.length) return null;
    return parts[parts.length - 1];
}

/**
 * Parses a Slack macOS window title into workspace, channel, and kind components.
 * Returns null for titles that don't match a known Slack pattern.
 * Mirrors PHP classifySlack().
 * @param {string} title
 * @returns {{ workspace: string; channel: string; kind: string }|null}
 */
export function classifySlack(title) {
    let m;
    // "<chan> (Channel|DM|Group) - <Workspace>[ - N new items] - Slack[ [Main]]"
    m = title.match(/^(?:! )?(.+?) \((Channel|DM|Group)\) - (.+?)(?:  - \d+ new items?)? - Slack(?:  \[Main\])?$/u);
    if (m) return { workspace: m[3], channel: m[1], kind: m[2] };
    m = title.match(/^Threads - (.+?)(?:  - \d+ new items?)? - Slack/);
    if (m) return { workspace: m[1], channel: "__threads__", kind: "view" };
    m = title.match(/^Activity - (.+?)(?:  - \d+ new items?)? - Slack/);
    if (m) return { workspace: m[1], channel: "__activity__", kind: "view" };
    m = title.match(/^Huddle(?::.*)? - (.+?) - Slack/);
    if (m) return { workspace: m[1], channel: "__huddle__", kind: "huddle" };
    return null;
}

/**
 * Extracts an SSH hostname from a terminal window title.
 * Mirrors PHP classifySsh().
 * @param {string} title
 * @returns {string|null}
 */
export function classifySsh(title) {
    const m = title.match(/\bssh\s+(\S+)/);
    return m ? m[1] : null;
}

// ─── Project-lookup helpers ───────────────────────────────────────────────────

/**
 * Looks up the first project whose rules match the given signals.
 * Priority: vscode_dir → host → slack → ssh_host → app.
 * Mirrors PHP projectForSignals().
 *
 * @param {{ vscode_dir?: string|null; host?: string; slack?: { workspace: string; channel: string }; ssh_host?: string; app?: string }} sig
 * @param {import('@timesheets/contracts').Config} config
 * @returns {string|null}
 */
export function projectForSignals(sig, config) {
    for (const [name, p] of Object.entries(config.projects ?? {})) {
        if (sig.vscode_dir && p.vscode_dirs?.length) {
            for (const d of p.vscode_dirs) {
                if (d.toLowerCase() === sig.vscode_dir.toLowerCase()) return name;
            }
        }
        if (sig.host && p.domains?.length) {
            if (hostMatchesAnyDomain(sig.host, p.domains)) return name;
        }
        if (sig.slack && p.slack?.length) {
            for (const rule of p.slack) {
                if (rule.workspace.toLowerCase() !== sig.slack.workspace.toLowerCase()) continue;
                if (!rule.channel_glob) return name;
                if (fnmatchGlob(sig.slack.channel, rule.channel_glob)) return name;
            }
        }
        if (sig.ssh_host && p.ssh_hosts?.length) {
            if (fnmatchAny(sig.ssh_host, p.ssh_hosts)) return name;
        }
        if (sig.app && p.apps?.length) {
            if (fnmatchAny(sig.app, p.apps)) return name;
        }
    }
    return null;
}

/**
 * Resolves a project for an external integration row using project mapping rules.
 * Mirrors PHP projectForExternal().
 * @param {ExternalRow} row
 * @param {import('@timesheets/contracts').Config} config
 * @returns {string|null}
 */
export function projectForExternal(row, config) {
    const explicitProject = String(row.project ?? "");
    if (explicitProject && config.projects?.[explicitProject]) return explicitProject;

    const source = String(row.source ?? "");
    const hint = String(row.project_hint ?? "");
    if (!source || !hint) return null;

    const clientName = source === "harvest" ? String(row.client_name ?? "") : "";

    for (const [name, p] of Object.entries(config.projects ?? {})) {
        if (source === "harvest") {
            if (p.harvest_projects?.length && fnmatchAny(hint, p.harvest_projects)) return name;
            if (clientName && p.harvest_client && p.harvest_client.toLowerCase() === clientName.toLowerCase())
                return name;
        }
        if (source === "clickup" && p.clickup_tasks?.length && fnmatchAny(hint, p.clickup_tasks)) return name;
        if (source === "clockify" && p.clockify_projects?.length && fnmatchAny(hint, p.clockify_projects)) return name;
    }
    return null;
}

// ─── AFK / input helpers ──────────────────────────────────────────────────────

/**
 * Returns true if the user was AFK at the given moment.
 * Mirrors PHP isAfkAt().
 * @param {Date} t
 * @param {AfkEvent[]} afk  Must be sorted ascending by start.
 * @returns {boolean}
 */
export function isAfkAt(t, afk) {
    for (const a of afk) {
        if (t < a.start) return false;
        if (t < a.end) return a.status === "afk";
    }
    return false;
}

/**
 * Returns the overlap in seconds between [start, end) and active input slices.
 * cursor.value is advanced past finished input rows for efficiency.
 * Mirrors PHP activeInputSecondsDuring().
 * @param {Date} start
 * @param {Date} end
 * @param {InputEvent[]} input
 * @param {{ value: number }} cursor
 * @returns {number}
 */
export function activeInputSecondsDuring(start, end, input, cursor) {
    if (!input.length) return 0;
    const startTs = start.getTime() / 1000;
    const endTs = end.getTime() / 1000;
    if (endTs <= startTs) return 0;

    while (cursor.value < input.length && input[cursor.value].end.getTime() / 1000 <= startTs) {
        cursor.value++;
    }

    let sum = 0;
    for (let i = cursor.value; i < input.length; i++) {
        const inStart = input[i].start.getTime() / 1000;
        if (inStart >= endTs) break;
        if (!input[i].active) continue;
        const inEnd = input[i].end.getTime() / 1000;
        const overlap = Math.min(endTs, inEnd) - Math.max(startTs, inStart);
        if (overlap > 0) sum += overlap;
    }
    return Math.min(sum, endTs - startTs);
}

// ─── Terminal app names ───────────────────────────────────────────────────────

const TERMINAL_APPS = new Set([
    "Terminal",
    "iTerm2",
    "iTerm",
    "Warp",
    "Ghostty",
    "Windows Terminal",
    "PowerShell",
    "pwsh",
    "cmd",
    "alacritty",
    "kitty",
    "konsole",
    "gnome-terminal",
]);

// ─── Main aggregation ─────────────────────────────────────────────────────────

/**
 * Classifies all ActivityWatch window events and git commits, then aggregates by
 * date and project name.
 *
 * Returns [bucket, unmatched, timeline] — mirrors PHP classifyAndAggregate().
 *
 * @param {LoadedEvents} events
 * @param {CommitRow[]} commits
 * @param {ExternalRow[]} external
 * @param {import('@timesheets/contracts').Config} config
 * @param {string} timezone  IANA timezone identifier.
 * @param {{ project?: string|null }} [opts]
 * @returns {{
 *   bucket: Record<string, Record<string, { seconds: number; active_seconds: number; activity_ratio: number; grouping: string|null; detail: Record<string, Record<string, number>>; commits: CommitRow[]; external: Record<string, { entries: number; activity: number; discussion: number }> }>>;
 *   unmatched: Record<string, Record<string, number>>;
 *   timeline: Record<string, Array<{ s: number; e: number; p: string; g: string|null }>>;
 * }}
 */
export function classifyAndAggregate(events, commits, external, config, timezone, opts = {}) {
    /** @type {Record<string, Record<string, any>>} */
    const bucket = {};
    /** @type {Record<string, Record<string, number>>} */
    const unmatched = {
        vscode: {},
        browser: {},
        slack: {},
        apps: {},
        harvest: {},
        clickup: {},
        clockify: {},
        github: {},
    };

    const personalHosts = /** @type {string[]} */ (config.personal_hosts ?? []);
    const personalApps = /** @type {string[]} */ (config.personal_apps ?? []);
    const ignoredProjects = new Set(config.ignored_projects ?? []);

    const correlatedApps = /** @type {string[]} */ ([...(config.correlated_apps ?? [])]);
    if (config.discover_repos === "github_desktop" && !correlatedApps.includes("GitHub Desktop")) {
        correlatedApps.push("GitHub Desktop");
    }
    const correlationWindow = Number(config.app_correlation_window_seconds ?? 900);
    const gapWindow = Number(config.project_gap_window_seconds ?? 300);
    let lastKnownProject = /** @type {string|null} */ (null);
    let lastKnownProjectTs = 0;

    /** @type {Array<[string, string, string, string, number, number, {s:number;e:number;p:string;g:string|null}]>} */
    let gapQueue = [];
    let gapStartProj = /** @type {string|null} */ (null);
    let gapTotalSec = 0;

    /** @type {Record<string, Array<{s:number;e:number;p:string;g:string|null}>>} */
    const timelineRaw = {};
    /** @type {Record<string, number>} */
    const dayStarts = {};

    const fmt = new Intl.DateTimeFormat("en-CA", { timeZone: timezone });

    /**
     * @param {string} date
     * @param {string} proj
     * @param {string} kind
     * @param {string} label
     * @param {number} sec
     */
    function bumpDetail(date, proj, kind, label, sec) {
        if (!bucket[date]) bucket[date] = {};
        if (!bucket[date][proj])
            bucket[date][proj] = { seconds: 0, active_seconds: 0, detail: {}, commits: [], external: {} };
        bucket[date][proj].seconds = (bucket[date][proj].seconds ?? 0) + sec;
        if (!bucket[date][proj].detail[kind]) bucket[date][proj].detail[kind] = {};
        bucket[date][proj].detail[kind][label] = (bucket[date][proj].detail[kind][label] ?? 0) + sec;
    }

    /**
     * @param {string} proj
     * @returns {boolean}
     */
    function matchesFilter(proj) {
        const filter = opts.project;
        if (!filter) return true;
        if (filter.startsWith("group:")) {
            return (config.projects?.[proj]?.grouping ?? null) === filter.slice(6);
        }
        return proj === filter;
    }

    /**
     * @param {typeof gapQueue} queue
     * @param {string|null} targetProj
     */
    function flushGap(queue, targetProj) {
        for (const [date, origProj, kind, label, sec, activeSec, tlSeg] of queue) {
            const proj = targetProj ?? origProj;
            if (ignoredProjects.has(proj) || !matchesFilter(proj)) continue;
            bumpDetail(date, proj, kind, label, sec);
            if (!bucket[date][proj])
                bucket[date][proj] = { seconds: 0, active_seconds: 0, detail: {}, commits: [], external: {} };
            bucket[date][proj].active_seconds = (bucket[date][proj].active_seconds ?? 0) + activeSec;
            const seg = { ...tlSeg, p: proj };
            if (!timelineRaw[date]) timelineRaw[date] = [];
            timelineRaw[date].push(seg);
        }
    }

    const inputCursor = { value: 0 };

    for (const ev of events.window) {
        const midTs = (ev.start.getTime() + ev.end.getTime()) / 2;
        const mid = new Date(midTs);
        if (isAfkAt(mid, events.afk)) continue;

        const sec = Math.max(0, (ev.end.getTime() - ev.start.getTime()) / 1000);
        if (sec <= 0) continue;
        const activeSec = activeInputSecondsDuring(ev.start, ev.end, events.input, inputCursor);
        const date = fmt.format(ev.start);

        let sig =
            /** @type {{ vscode_dir?: string|null; host?: string; slack?: { workspace: string; channel: string; kind: string }; ssh_host?: string; app?: string }} */ ({});
        let proj = /** @type {string|null} */ (null);
        let detailKind = "app";
        let detailLabel = ev.app;

        if (ev.app === "Code" || ev.app === "Code - OSS") {
            const dir = classifyVscode(ev.title);
            sig.vscode_dir = dir;
            proj = projectForSignals(sig, config);
            detailKind = "vscode";
            detailLabel = dir ?? "(unknown)";
            if (!proj && dir) {
                unmatched.vscode[dir] = (unmatched.vscode[dir] ?? 0) + 1;
            }
            proj ??= "VSCode (uncategorized)";
        } else if (ev.app === "Google Chrome") {
            const host = new URL(ev.url || "about:blank").hostname ?? "";
            sig.host = host;
            proj = projectForSignals(sig, config);
            if (!proj && host && hostMatchesAnyDomain(host, personalHosts)) {
                proj = "Personal browsing";
            }
            detailKind = "browser";
            detailLabel = host || "(no url)";
            if (!proj) {
                const key = host || "(no url)";
                unmatched.browser[key] = (unmatched.browser[key] ?? 0) + 1;
            }
            proj ??= "Browser (uncategorized)";
        } else if (ev.app === "Slack") {
            const s = classifySlack(ev.title);
            if (s) {
                sig.slack = s;
                proj = projectForSignals(sig, config);
                detailKind = "slack";
                detailLabel = `${s.workspace} / ${s.channel}`;
                if (!proj) {
                    unmatched.slack[detailLabel] = (unmatched.slack[detailLabel] ?? 0) + 1;
                }
            }
            proj ??= "Slack (uncategorized)";
        } else if (TERMINAL_APPS.has(ev.app)) {
            const host = classifySsh(ev.title);
            if (host) {
                sig.ssh_host = host;
                proj = projectForSignals(sig, config);
                detailKind = "ssh";
                detailLabel = host;
                if (!proj) {
                    unmatched.apps[`ssh:${host}`] = (unmatched.apps[`ssh:${host}`] ?? 0) + 1;
                }
            }
            proj ??= "Terminal";
        } else {
            sig.app = ev.app;
            proj = projectForSignals(sig, config);
            if (!proj && personalApps.includes(ev.app)) {
                proj = "Personal apps";
            } else if (!proj && correlatedApps.length > 0 && fnmatchAny(ev.app, correlatedApps)) {
                const gap = ev.start.getTime() / 1000 - lastKnownProjectTs;
                if (lastKnownProject !== null && correlationWindow > 0 && gap <= correlationWindow) {
                    proj = lastKnownProject;
                }
            }
            if (!proj) {
                proj = ev.app ? `App: ${ev.app}` : "Other";
            }
            if (!fnmatchAny(ev.app, personalApps) && !fnmatchAny(ev.app, correlatedApps) && proj.startsWith("App: ")) {
                unmatched.apps[ev.app] = (unmatched.apps[ev.app] ?? 0) + 1;
            }
        }

        const isConfiguredProject = Boolean(config.projects?.[proj]);
        if (isConfiguredProject) {
            lastKnownProject = proj;
            lastKnownProjectTs = ev.end.getTime() / 1000;
        }

        if (!(date in dayStarts)) {
            dayStarts[date] = new Date(`${date}T00:00:00`).getTime() / 1000;
        }
        const tlSeg = {
            s: Math.max(0, ev.start.getTime() / 1000 - dayStarts[date]),
            e: Math.min(86400, ev.end.getTime() / 1000 - dayStarts[date]),
            p: proj,
            g: config.projects?.[proj]?.grouping ?? null,
        };

        // Gap-bridging
        if (gapWindow > 0 && gapQueue.length > 0) {
            if (isConfiguredProject) {
                if (proj === gapStartProj && gapTotalSec <= gapWindow) {
                    flushGap(gapQueue, proj);
                } else {
                    flushGap(gapQueue, null);
                }
                gapQueue = [];
                gapStartProj = null;
                gapTotalSec = 0;
            } else if (gapTotalSec + sec > gapWindow) {
                flushGap(gapQueue, null);
                gapQueue = [];
                gapStartProj = null;
                gapTotalSec = 0;
            }
        }

        if (ignoredProjects.has(proj)) continue;
        if (!matchesFilter(proj)) continue;

        const isUntracked = !isConfiguredProject && !proj.startsWith("Personal");
        const isPersonal = proj === "Personal browsing" || proj === "Personal apps" || proj.startsWith("Personal");

        if (
            gapWindow > 0 &&
            lastKnownProject !== null &&
            (isUntracked || isPersonal) &&
            gapTotalSec + sec <= gapWindow
        ) {
            gapQueue.push([date, proj, detailKind, detailLabel, sec, activeSec, tlSeg]);
            if (gapStartProj === null) gapStartProj = lastKnownProject;
            gapTotalSec += sec;
            continue;
        }

        bumpDetail(date, proj, detailKind, detailLabel, sec);
        if (!bucket[date]) bucket[date] = {};
        if (!bucket[date][proj])
            bucket[date][proj] = { seconds: 0, active_seconds: 0, detail: {}, commits: [], external: {} };
        bucket[date][proj].active_seconds = (bucket[date][proj].active_seconds ?? 0) + activeSec;
        if (!timelineRaw[date]) timelineRaw[date] = [];
        timelineRaw[date].push(tlSeg);
    }

    if (gapQueue.length > 0) flushGap(gapQueue, null);

    // Commits
    for (const c of commits) {
        const date = fmt.format(c.dt);
        const proj = c.project;
        if (!matchesFilter(proj)) continue;
        if (!bucket[date]) bucket[date] = {};
        if (!bucket[date][proj])
            bucket[date][proj] = { seconds: 0, active_seconds: 0, detail: {}, commits: [], external: {} };
        bucket[date][proj].commits.push(c);
    }

    // External integrations
    for (const row of external) {
        const sec = Number(row.seconds ?? 0);
        const source = String(row.source ?? "external");
        const proj = projectForExternal(row, config) ?? `${source.toUpperCase()} (uncategorized)`;
        const hint = String(row.project_hint ?? "");
        const label = String(row.label ?? (hint || source));

        if (!projectForExternal(row, config)) {
            const key = hint || "(unknown)";
            if (unmatched[source] !== undefined) {
                unmatched[source][key] = (unmatched[source][key] ?? 0) + 1;
            }
        }

        if (ignoredProjects.has(proj) || !matchesFilter(proj)) continue;

        const start = row.start instanceof Date ? row.start : new Date(String(row.start));
        const date = fmt.format(start);

        if (!bucket[date]) bucket[date] = {};
        if (!bucket[date][proj])
            bucket[date][proj] = { seconds: 0, active_seconds: 0, detail: {}, commits: [], external: {} };

        if (sec > 0) {
            bucket[date][proj].seconds = (bucket[date][proj].seconds ?? 0) + sec;
            bucket[date][proj].active_seconds = (bucket[date][proj].active_seconds ?? 0) + sec;
            if (!bucket[date][proj].detail[source]) bucket[date][proj].detail[source] = {};
            bucket[date][proj].detail[source][label] = (bucket[date][proj].detail[source][label] ?? 0) + sec;
        }

        if (!bucket[date][proj].external[source]) {
            bucket[date][proj].external[source] = { entries: 0, activity: 0, discussion: 0 };
        }
        bucket[date][proj].external[source].entries += Number(row.entry_count ?? 1);
        bucket[date][proj].external[source].activity += Number(row.activity_count ?? 0);
        bucket[date][proj].external[source].discussion += Number(row.discussion_count ?? 0);
    }

    // Attach grouping and activity_ratio
    for (const date of Object.keys(bucket)) {
        for (const proj of Object.keys(bucket[date])) {
            bucket[date][proj].grouping = config.projects?.[proj]?.grouping ?? null;
            const s = Number(bucket[date][proj].seconds ?? 0);
            const a = Number(bucket[date][proj].active_seconds ?? 0);
            bucket[date][proj].activity_ratio = s > 0 ? Math.min(1, a / s) : 0;
        }
    }

    // Merge and filter timeline segments
    const tlMergeGap = Number(config.timeline_merge_gap_seconds ?? 300);
    const tlMinSec = Number(config.timeline_min_seconds ?? 60);
    /** @type {Record<string, Array<{s:number;e:number;p:string;g:string|null}>>} */
    const timeline = {};

    for (const [date, segs] of Object.entries(timelineRaw)) {
        const sorted = [...segs].sort((a, b) => a.s - b.s);
        /** @type {Array<{s:number;e:number;p:string;g:string|null}>} */
        const merged = [];
        /** @type {Record<string, number>} */
        const lastByProject = {};
        for (const seg of sorted) {
            const lastIdx = lastByProject[seg.p] ?? -1;
            if (lastIdx >= 0 && seg.s - merged[lastIdx].e <= tlMergeGap) {
                merged[lastIdx].e = Math.max(merged[lastIdx].e, seg.e);
            } else {
                merged.push({ ...seg });
                lastByProject[seg.p] = merged.length - 1;
            }
        }
        timeline[date] = merged.filter((s) => s.e - s.s >= tlMinSec);
    }

    return { bucket, unmatched, timeline };
}
