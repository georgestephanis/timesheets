// ── State ─────────────────────────────────────────────────────────────────────
let currentParams = null;
let currentData = null;
let currentBadge = "live";
let currentCacheAgeSec = 0;
let personalProjectQueue = new Set();
let currentAbortController = null;
const responseCache = new Map();
// Keyed by YYYY-MM-DD → list of suggestion objects (null = pending, [] = none found)
const unloggedSuggestions = new Map();

// Config page state
let configDraft = null;
let configDirty = false;
let loadedConfig = null;
let activeConfigTab = "general";

// ── Date helpers ──────────────────────────────────────────────────────────────
function addDays(dateStr, n) {
    // Slice to 10 chars so ISO datetimes like "2026-05-01T00:00:00-04:00" work too.
    // Use UTC to avoid DST-boundary shifts when adding days across a clock-change midnight.
    const [y, m, d] = String(dateStr).slice(0, 10).split("-").map(Number);
    const dt = new Date(Date.UTC(y, m - 1, d + n));
    return `${dt.getUTCFullYear()}-${String(dt.getUTCMonth() + 1).padStart(2, "0")}-${String(dt.getUTCDate()).padStart(2, "0")}`;
}

function paramsFromUrl() {
    const p = new URLSearchParams(location.search);
    const days = p.has("days") ? parseInt(p.get("days"), 10) : null;
    return {
        from: p.get("from") || (days ? null : SITE.today),
        to: p.get("to") || (days ? null : SITE.today),
        days,
        project: p.get("project") || "",
    };
}

function buildApiUrl(params, rebuild = false) {
    const u = new URLSearchParams();
    if (params.from) u.set("from", params.from);
    if (params.to) u.set("to", params.to);
    if (params.days) u.set("days", String(params.days));
    if (rebuild) u.set("rebuild", "1");
    return "api.php?" + u;
}

function buildPageUrl(params) {
    const u = new URLSearchParams();
    if (params.from) u.set("from", params.from);
    if (params.to) u.set("to", params.to);
    if (params.days) u.set("days", String(params.days));
    if (params.project) u.set("project", params.project);
    u.set("format", "html");
    return "?" + u;
}

// Cache key covers only the date range (project filter is client-side and doesn't affect the API response).
function dataCacheKey(params) {
    return `${params.from || ""}|${params.to || ""}|${params.days || ""}`;
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function esc(s) {
    return String(s ?? "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;");
}

// Mirrors PHP fmtDur: 3661→"1h 01m", 90→"1m", 18→"18s"
function fmtDur(sec) {
    const m = Math.floor(sec / 60);
    if (m >= 60) return `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, "0")}m`;
    if (m >= 1) return `${m}m`;
    return `${Math.floor(sec)}s`;
}

function fmtAge(sec) {
    const s = Math.max(0, Math.floor(Number(sec) || 0));
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ${String(m % 60).padStart(2, "0")}m`;
    const d = Math.floor(h / 24);
    return `${d}d ${String(h % 24).padStart(2, "0")}h`;
}

// Converts seconds from local midnight to a 12-hour time string, e.g. 32400 → "9:00 am"
function fmtTime(secFromMidnight) {
    const h24 = Math.floor(secFromMidnight / 3600);
    const min = Math.floor((secFromMidnight % 3600) / 60);
    const h12 = h24 % 12 || 12;
    const ampm = h24 < 12 ? "am" : "pm";
    return `${h12}:${String(min).padStart(2, "0")} ${ampm}`;
}

// ── Grouping helpers ──────────────────────────────────────────────────────────
function resolveGrouping(name) {
    if (!name) return name;
    if (SITE.groupings[name] !== undefined) return name;
    for (const [canonical, def] of Object.entries(SITE.groupings)) {
        if ((def.aliases || []).includes(name)) return canonical;
    }
    return name;
}

function groupingColor(name) {
    // Prefer configDraft when on the config page (fresh from API); fall back to SITE.groupings.
    const fromDraft = configDraft?.groupings?.[name]?.color;
    if (fromDraft) return fromDraft;
    const fromConfig = SITE.groupings[name]?.color;
    if (fromConfig) return fromConfig;
    const palette = ["#6366f1", "#0ea5e9", "#10b981", "#f59e0b", "#ef4444", "#8b5cf6", "#14b8a6", "#f97316"];
    let h = 0;
    for (const c of name) h = (Math.imul(31, h) + c.charCodeAt(0)) | 0;
    return palette[Math.abs(h) % palette.length];
}

function hexToRgba(hex, alpha) {
    const r = parseInt(hex.slice(1, 3), 16);
    const g = parseInt(hex.slice(3, 5), 16);
    const b = parseInt(hex.slice(5, 7), 16);
    return `rgba(${r},${g},${b},${alpha})`;
}

// ── Timeline bar ──────────────────────────────────────────────────────────────
function renderTimeline(date, timelines) {
    const segs = timelines?.[date];
    if (!segs?.length) return "";

    const minS = Math.floor(segs[0].s / 3600) * 3600;
    const maxE = Math.ceil(segs[segs.length - 1].e / 3600) * 3600;
    const span = maxE - minS;
    if (span <= 0) return "";

    const W = 1000;
    const rects = segs
        .map((s) => {
            const x = (((s.s - minS) / span) * W).toFixed(1);
            const w = Math.max(1, ((s.e - s.s) / span) * W).toFixed(1);
            const resolved = resolveGrouping(s.g);
            const color = resolved ? groupingColor(resolved) : "#94a3b8";
            const tip = `${esc(s.p)} ${fmtTime(s.s)}–${fmtTime(s.e)} (${fmtDur(s.e - s.s)})`;
            return `<rect x="${x}" y="0" width="${w}" height="20" fill="${esc(color)}" opacity="0.85"><title>${tip}</title></rect>`;
        })
        .join("");

    const ticks = [];
    for (let h = Math.ceil(minS / 3600); h < maxE / 3600; h++) {
        const x = (((h * 3600 - minS) / span) * W).toFixed(1);
        ticks.push(`<line x1="${x}" y1="0" x2="${x}" y2="20" stroke="#fff" stroke-width="2" opacity="0.4"/>`);
    }

    const svg =
        `<svg class="day-timeline" viewBox="0 0 ${W} 20" preserveAspectRatio="none" aria-hidden="true">` +
        `<rect x="0" y="0" width="${W}" height="20" fill="#e5e7eb"/>${rects}${ticks.join("")}</svg>`;

    const listItems = segs
        .flatMap((s) => {
            const resolved = resolveGrouping(s.g);
            if (!resolved) return [];
            const color = groupingColor(resolved);
            return [
                `<li class="tl-row" style="--tl-color:${esc(color)}" data-proj="${esc(s.p)}">` +
                    `<span class="tl-time">${fmtTime(s.s)}–${fmtTime(s.e)}</span>` +
                    `<span class="tl-project">${esc(s.p)}</span>` +
                    `<span class="tl-dur">${fmtDur(s.e - s.s)}</span>` +
                    `</li>`,
            ];
        })
        .join("");

    return (
        `<details class="timeline-wrap">` +
        `<summary class="timeline-summary">${svg}</summary>` +
        `<ol class="timeline-list">${listItems}</ol>` +
        `</details>`
    );
}

// ── Renderers ─────────────────────────────────────────────────────────────────
function renderCommits(commits) {
    if (!commits.length) return "";
    const items = commits
        .map((c) => {
            const t = new Date(c.time)
                .toLocaleTimeString("en-US", {
                    hour: "numeric",
                    minute: "2-digit",
                    hour12: true,
                    timeZone: SITE.timezone,
                })
                .toLowerCase();
            return `<li><code>${esc(t)}</code> <code>${esc(c.sha.slice(0, 8))}</code> ${esc(c.subj)}</li>`;
        })
        .join("");
    return `<li><em>commits (${commits.length}):</em><ul>${items}</ul></li>`;
}

function renderDetail(detail) {
    return Object.entries(detail)
        .map(([kind, items]) => {
            const top = Object.entries(items)
                .filter(([, s]) => s >= SITE.minSec)
                .sort(([, a], [, b]) => b - a)
                .slice(0, 6);
            if (!top.length) return "";
            return `<li><em>${esc(kind)}:</em> ${top.map(([k, s]) => `${esc(k)} (${fmtDur(s)})`).join(", ")}</li>`;
        })
        .join("");
}

function renderProject(tag, name, rec) {
    const sec = rec.seconds || 0;
    const commits = rec.commits || [];
    if (sec < SITE.minSec && !commits.length) return "";

    const isQueuedPersonal = personalProjectQueue.has(name);
    let action;
    if (isQueuedPersonal) {
        action = '<span class="proj-actions muted">queued for personal</span>';
    } else {
        const currentGroup = resolveGrouping(rec.grouping || "") || "";
        const groupRow = Object.keys(SITE.groupings).length
            ? `<div class="proj-menu-section">
                <select data-menu-group-select aria-label="Group">
                    <option value="">(no group)</option>
                    ${Object.keys(SITE.groupings)
                        .map(
                            (g) =>
                                `<option value="${esc(g)}"${currentGroup === g ? " selected" : ""}>${esc(g)}</option>`,
                        )
                        .join("")}
                </select>
                <button type="button" class="btn" data-menu-save-group="${esc(name)}">Save</button>
               </div>`
            : "";
        action = `<span class="proj-actions"><button type="button" class="proj-menu-btn" aria-haspopup="true" aria-expanded="false" aria-label="Project actions for ${esc(name)}">&#8942;</button><div class="proj-menu-dropdown" hidden>${groupRow}<button type="button" data-flag-project="${esc(name)}">Flag as personal / ignore</button></div></span>`;
    }

    const secStr = sec ? ` <span class="dur">&mdash; ${fmtDur(sec)}</span>` : "";
    const body = renderDetail(rec.detail || {}) + renderCommits(commits);
    return `<${tag}>${esc(name)}${secStr}${action}</${tag}>${body ? `<ul>${body}</ul>` : ""}`;
}

function filterProjectsForView(projects, projectFilter) {
    if (!projectFilter) return Object.entries(projects || {});
    if (projectFilter.startsWith("group:")) {
        const grouping = projectFilter.slice(6);
        return Object.entries(projects || {}).filter(([, rec]) => resolveGrouping(rec?.grouping || null) === grouping);
    }
    return Object.entries(projects || {}).filter(([name]) => name === projectFilter);
}

function renderDaySummary(text) {
    const fmt = (s) => esc(s).replace(/\*\*(.+?)\*\*/g, (_, m) => `<strong>${m}</strong>`);
    let html = "";
    for (const raw of String(text).split("\n")) {
        const sub = raw.match(/^\s{2,}[-*]\s+(.*)/);
        const top = !sub && raw.match(/^[-*]\s+(.*)/);
        if (sub) html += `<dd>${fmt(sub[1])}</dd>`;
        else if (top) html += `<dt>${fmt(top[1])}</dt>`;
    }
    if (!html) return "";
    return (
        `<details class="day-summary" open>` +
        `<summary class="day-summary-toggle">Day summary</summary>` +
        `<dl class="day-summary-list">${html}</dl>` +
        `</details>`
    );
}

function renderDay(date, projects, projectFilter, timelines = {}, summaries = {}) {
    const entries = filterProjectsForView(projects, projectFilter).sort(
        ([, a], [, b]) => (b.seconds || 0) - (a.seconds || 0),
    );
    if (!entries.length) return "";
    const dayTotal = entries.reduce((s, [, r]) => s + (r.seconds || 0), 0);
    const dow = new Date(`${date}T12:00:00`).toLocaleDateString("en-US", { weekday: "short" });

    const grouped = {};
    const ungrouped = [];
    for (const [name, rec] of entries) {
        const g = resolveGrouping(rec.grouping);
        g ? (grouped[g] ??= []).push([name, rec]) : ungrouped.push([name, rec]);
    }

    let summarySlot = "";
    if (!projectFilter) {
        if (summaries[date]) {
            summarySlot = renderDaySummary(summaries[date]);
        } else if (SITE.llmConfigured) {
            summarySlot = `<button type="button" class="btn summary-generate-btn" data-generate-summary="${esc(date)}">Generate day summary</button>`;
        }
    }

    let html =
        `<h2>${esc(date)} <span class="dow">(${dow})</span> <span class="dur">&mdash; ${fmtDur(dayTotal)} active</span></h2>` +
        renderTimeline(date, timelines) +
        summarySlot;

    const groupTotals = Object.entries(grouped)
        .map(([g, ps]) => [g, ps.reduce((s, [, r]) => s + (r.seconds || 0), 0)])
        .sort(([, a], [, b]) => b - a);

    for (const [g, gSec] of groupTotals) {
        const color = groupingColor(g);
        const colorLight = hexToRgba(color, 0.35);
        const blocks = grouped[g]
            .map(([n, r]) => {
                const p = renderProject("h4", n, r);
                return p ? `<div class="project-block">${p}</div>` : "";
            })
            .join("");
        if (blocks.trim()) {
            const gSecStr = gSec ? ` <span class="dur">&mdash; ${fmtDur(gSec)}</span>` : "";
            const logo = SITE.groupings[g]?.logo;
            const logoHtml = logo ? `<img src="${esc(logo)}" alt="" class="group-logo" aria-hidden="true">` : "";
            html += `<div class="client-group" style="--accent:${color};--accent-light:${colorLight}"><h3>${logoHtml}${esc(g)}${gSecStr}</h3>${blocks}</div>`;
        }
    }

    for (const [name, rec] of ungrouped) html += renderProject("h3", name, rec);
    return html;
}

function renderReport(data, projectFilter = "") {
    const days = Object.keys(data.days || {})
        .sort()
        .reverse();
    if (!days.length) return "<p><em>No activity recorded for this period.</em></p>";

    const blocks = days
        .map((date) => renderDay(date, data.days[date], projectFilter, data.timelines || {}, data.summaries || {}))
        .filter(Boolean);

    if (!blocks.length) return "<p><em>No activity recorded for this filter in this period.</em></p>";
    return blocks.join("\n");
}

function projectOptions(selected = "", kind = "") {
    const opts = SITE.projects
        .map((p) => `<option value="${esc(p.name)}"${p.name === selected ? " selected" : ""}>${esc(p.name)}</option>`)
        .join("");

    const personalOpt =
        kind === "browser" || kind === "apps"
            ? `<option value="__personal__"${selected === "__personal__" ? " selected" : ""}>Personal</option>`
            : "";

    const correlatedOpt =
        kind === "apps"
            ? `<option value="__correlated__"${selected === "__correlated__" ? " selected" : ""}>Correlated (attribute to active project)</option>`
            : "";

    return `<option value="">Select project</option>${personalOpt}${correlatedOpt}<option value="__new__">+ New project...</option>${opts}`;
}

function getProjectMeta(name) {
    return SITE.projects.find((p) => p.name === name) || null;
}

// renderAdminPanel removed — signals are now in the Config page Signals tab.

async function postApi(payload) {
    const res = await fetch("api.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
    });
    const text = await res.text();
    let data;
    try {
        data = JSON.parse(text);
    } catch {
        throw new Error(text || `HTTP ${res.status}`);
    }
    if (!res.ok || data.error) {
        throw new Error(data.error || `HTTP ${res.status}`);
    }
    return data;
}

function renderUnloggedSuggestions(suggestions) {
    if (!suggestions.length) {
        return '<p class="harvest-suggest-none">No gaps found</p>';
    }
    let html = "";
    for (const s of suggestions) {
        const where =
            s.logging_method === "clickup"
                ? `ClickUp: ${esc(s.clickup_task_name || s.clickup_task_id)}`
                : `Harvest: ${esc(s.harvest_project)}${s.harvest_task ? " / " + esc(s.harvest_task) : ""}`;
        html +=
            `<div class="harvest-suggestion">` +
            `<div class="harvest-suggestion-meta">${esc(s.project)} &mdash; ${fmtDur(Math.round(s.hours * 3600))}</div>` +
            `<div class="harvest-suggestion-where">${where}</div>` +
            `<div class="harvest-suggestion-desc">${esc(s.description)}</div>` +
            `</div>`;
    }
    return html;
}

function triggerSuggestForDate(date) {
    if (!SITE.suggestLoggingConfigured || !currentData) return;
    if (unloggedSuggestions.get(date) !== undefined) return; // already fetched or in flight
    const dayProjects = (currentData.days || {})[date] || {};
    let trackedSec = 0;
    const entryMap = {};
    for (const rec of Object.values(dayProjects)) {
        trackedSec += rec.seconds || 0;
        for (const [label, sec] of Object.entries(rec.detail?.harvest || {})) {
            entryMap[label] = (entryMap[label] || 0) + sec;
        }
    }
    const loggedSec = Object.values(entryMap).reduce((s, v) => s + v, 0);
    if (trackedSec - loggedSec < 900) return;
    unloggedSuggestions.set(date, null); // mark pending
    renderHarvestSidebar(currentData);
    postApi({ action: "suggest_time_logging", date })
        .then(({ suggestions }) => {
            unloggedSuggestions.set(date, suggestions || []);
        })
        .catch(() => {
            unloggedSuggestions.set(date, []);
        })
        .finally(() => renderHarvestSidebar(currentData));
}

function renderHarvestSidebar(data) {
    const el = document.getElementById("harvest-sidebar");
    if (!el) return;

    if (!SITE.harvestConfigured) {
        el.innerHTML = "";
        return;
    }

    const days = Object.keys(data.days || {})
        .sort()
        .reverse();
    if (!days.length) {
        el.innerHTML = "";
        return;
    }

    let html = '<p class="harvest-sidebar-title">Harvest logged</p>';
    for (const date of days) {
        const dayProjects = data.days[date] || {};
        const entryMap = {};
        let trackedSec = 0;
        for (const rec of Object.values(dayProjects)) {
            trackedSec += rec.seconds || 0;
            for (const [label, sec] of Object.entries(rec.detail?.harvest || {})) {
                entryMap[label] = (entryMap[label] || 0) + sec;
            }
        }
        const loggedSec = Object.values(entryMap).reduce((s, v) => s + v, 0);
        const gapSec = trackedSec - loggedSec;
        const dow = new Date(`${date}T12:00:00`).toLocaleDateString("en-US", { weekday: "short" });
        const entries = Object.entries(entryMap).sort(([, a], [, b]) => b - a);

        html += `<div class="harvest-day">`;
        html += `<div class="harvest-day-date">${esc(date)} <span class="dow">(${dow})</span></div>`;
        if (loggedSec > 0) {
            html += `<div class="harvest-day-total">${fmtDur(loggedSec)}</div>`;
            html += `<ul class="harvest-entries">`;
            for (const [label, sec] of entries) {
                html += `<li>${esc(label)}: <span class="dur">${fmtDur(sec)}</span></li>`;
            }
            html += `</ul>`;
        } else {
            html += `<div class="harvest-day-total harvest-day-zero">0m</div>`;
        }

        // Unlogged suggestions: show button or cached results when there's a meaningful gap.
        if (SITE.suggestLoggingConfigured && gapSec >= 900) {
            const cached = unloggedSuggestions.get(date);
            if (cached === undefined) {
                html +=
                    `<button type="button" class="btn harvest-suggest-btn" data-suggest-date="${esc(date)}">` +
                    `Suggest unlogged (${fmtDur(gapSec)} gap)` +
                    `</button>`;
            } else if (cached === null) {
                html += `<p class="harvest-suggesting">Analyzing&hellip;</p>`;
            } else {
                html += renderUnloggedSuggestions(cached);
            }
        }

        html += `</div>`;
    }
    el.innerHTML = html;
}

function renderWarningsBanner(data) {
    const el = document.getElementById("warnings-banner");
    if (!el) return;
    const warnings = data?.warnings || [];
    if (!warnings.length) {
        el.innerHTML = "";
        return;
    }
    const items = warnings.map((w) => `<li>${esc(w)}</li>`).join("");
    el.innerHTML = `<div class="warnings-banner"><strong>Data source warnings:</strong><ul>${items}</ul></div>`;
}

function renderCurrentView() {
    if (!currentData) return;
    document.body.classList.remove("config-mode");
    const elReport = document.getElementById("report");
    const elNav = document.getElementById("nav");

    elNav.innerHTML = renderNav(currentParams || {}, currentData.from, currentData.to);
    elReport.innerHTML = renderReport(currentData, currentParams?.project || "");
    bindNavEvents();
    renderHarvestSidebar(currentData);
    renderWarningsBanner(currentData);
}

// ── Diff ──────────────────────────────────────────────────────────────────────
function computeDiff(oldData, newData) {
    if (!oldData?.days) return [];
    const changes = [];
    for (const [date, projects] of Object.entries(newData.days || {})) {
        for (const [name, rec] of Object.entries(projects)) {
            const oldRec = oldData.days[date]?.[name];
            const oldShas = new Set((oldRec?.commits || []).map((c) => c.sha));
            const newCommits = rec.commits || [];
            const addedCmts = newCommits.filter((c) => !oldShas.has(c.sha));
            const secDiff = (rec.seconds || 0) - (oldRec?.seconds || 0);
            if (!oldRec && ((rec.seconds || 0) > 0 || newCommits.length)) {
                changes.push({
                    type: "new_project",
                    date,
                    project: name,
                    seconds: rec.seconds || 0,
                    commits: newCommits.length,
                });
            } else if (oldRec && (secDiff > 0 || addedCmts.length)) {
                changes.push({
                    type: "updated",
                    date,
                    project: name,
                    addedSeconds: secDiff,
                    addedCommits: addedCmts.length,
                });
            }
        }
    }
    return changes;
}

function renderDiffBanner(changes, hadPrior) {
    if (!hadPrior)
        return `<div class="diff-banner diff-banner--info">Rebuilt &mdash; no prior snapshot to compare against.</div>`;
    if (!changes.length)
        return `<div class="diff-banner diff-banner--clean">&#10003; Rebuilt &mdash; no changes found.</div>`;
    const items = changes
        .map((c) => {
            const p = esc(c.project);
            if (c.type === "new_project") {
                const s = c.seconds > 0 ? ` &mdash; ${fmtDur(c.seconds)}` : "";
                const cm = c.commits > 0 ? `, ${c.commits} commit(s)` : "";
                return `<li><strong>${c.date}</strong>: new project <em>${p}</em>${s}${cm}</li>`;
            }
            const s = c.addedSeconds > 0 ? ` +${fmtDur(c.addedSeconds)}` : "";
            const cm = c.addedCommits > 0 ? `, +${c.addedCommits} commit(s)` : "";
            return `<li><strong>${c.date}</strong>: updated <em>${p}</em>${s}${cm}</li>`;
        })
        .join("");
    return `<div class="diff-banner diff-banner--changes"><strong>Rebuilt &mdash; ${changes.length} change(s):</strong><ul>${items}</ul></div>`;
}

// ── Nav ───────────────────────────────────────────────────────────────────────
function renderNav(params, fromRaw, toRaw) {
    // data.from / data.to are full ISO strings ("2026-05-01T00:00:00-04:00"); strip the time part.
    const from = String(fromRaw).slice(0, 10);
    const to = String(toRaw).slice(0, 10);
    const start = from <= to ? from : to;
    const end = from <= to ? to : from;
    const rangeDays = Math.max(
        1,
        Math.round((new Date(`${end}T12:00:00`) - new Date(`${start}T12:00:00`)) / 86400000) + 1,
    );
    const prevFrom = addDays(start, -rangeDays),
        prevTo = addDays(start, -1);
    const nextFrom = addDays(end, 1),
        nextTo = addDays(end, rangeDays);
    const isFuture = nextFrom > SITE.today;
    const hasRange = start !== end;
    const addRangeClass = hasRange ? "is-hidden" : "";
    const rangeClass = hasRange ? "" : "is-hidden";
    const removeRangeClass = hasRange ? "" : "is-hidden";

    const prevUrl = buildPageUrl({ ...params, from: prevFrom, to: prevTo, days: null });
    const nextUrl = buildPageUrl({ ...params, from: nextFrom, to: nextTo, days: null });
    const nextBtn = isFuture
        ? '<button type="button" class="btn" disabled aria-disabled="true">Next &rsaquo;</button>'
        : `<a class="btn" data-nav href="${esc(nextUrl)}">Next &rsaquo;</a>`;
    let rebuildLabel = "Refresh";
    if (currentBadge === "cached") {
        rebuildLabel = `Rebuild from source (cached ${fmtAge(currentCacheAgeSec)} ago)`;
    } else if (currentBadge === "rebuilt") {
        rebuildLabel = "Rebuild again";
    }

    const cur = params.project || "";
    const sel = (v) => (v === cur ? " selected" : "");

    // Bucket projects by grouping, preserving config order.
    const groups = {};
    const ungrouped = [];
    for (const p of SITE.projects) {
        const g = resolveGrouping(p.grouping);
        g ? (groups[g] ??= []).push(p.name) : ungrouped.push(p.name);
    }

    let projOpts = `<option value=""${sel("")}>All projects</option>`;
    for (const [group, names] of Object.entries(groups)) {
        const gVal = `group:${group}`;
        projOpts += `<optgroup label="${esc(group)}">`;
        projOpts += `<option value="${esc(gVal)}"${sel(gVal)}>All ${esc(group)} projects</option>`;
        for (const name of names) {
            projOpts += `<option value="${esc(name)}"${sel(name)}>${esc(name)}</option>`;
        }
        projOpts += `</optgroup>`;
    }
    for (const name of ungrouped) {
        projOpts += `<option value="${esc(name)}"${sel(name)}>${esc(name)}</option>`;
    }

    return `
    <a class="btn" data-nav href="${esc(prevUrl)}">&lsaquo; Prev</a>
      ${nextBtn}
      <span class="sep">|</span>
            <form class="date-form" data-date-form>
                <input type="date" data-date-from value="${esc(start)}" aria-label="Start date" required>
                <button type="button" class="btn ${addRangeClass}" data-add-range>Add end date</button>
                <span class="date-range ${rangeClass}" data-date-range>
                    <span>&rarr;</span>
                    <input type="date" data-date-to value="${esc(hasRange ? end : "")}" aria-label="End date">
                    <button type="button" class="btn ${removeRangeClass}" data-remove-range>Single day</button>
                </span>
                <button class="btn" type="submit">Apply</button>
            </form>
            <span class="sep">|</span>
      <select data-project-select>
        ${projOpts}
      </select>
      <span class="sep">|</span>
            <button type="button" class="btn" data-goto-config>Config</button>
            <span class="sep">|</span>
      <button type="button" class="btn" data-rebuild>${esc(rebuildLabel)}</button>
    `;
}

// ── Data loading ──────────────────────────────────────────────────────────────
async function fetchAndRender(params, isRebuild = false) {
    // Cancel any in-flight request so stale responses never overwrite fresh ones.
    if (currentAbortController) currentAbortController.abort();

    // Serve from the client-side cache for non-rebuild navigations.
    const key = dataCacheKey(params);
    if (!isRebuild && responseCache.has(key)) {
        const cached = responseCache.get(key);
        currentData = cached.data;
        currentBadge = cached.badge;
        currentCacheAgeSec = cached.ageSec;
        document.getElementById("diff-banner").innerHTML = "";
        renderCurrentView();
        return;
    }

    const controller = new AbortController();
    currentAbortController = controller;
    const timeoutId = setTimeout(() => controller.abort(), 15000);

    const elReport = document.getElementById("report");
    const elBanner = document.getElementById("diff-banner");
    const elAdmin = document.getElementById("admin");

    elReport.innerHTML = '<p class="loading">Loading&hellip;</p>';
    elBanner.innerHTML = "";
    if (elAdmin) elAdmin.innerHTML = "";
    const elSidebar = document.getElementById("harvest-sidebar");
    if (elSidebar) elSidebar.innerHTML = "";
    const elWarnings = document.getElementById("warnings-banner");
    if (elWarnings) elWarnings.innerHTML = "";

    const prevData = currentData;

    try {
        const res = await fetch(buildApiUrl(params, isRebuild), { signal: controller.signal });
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch {
            // PHP returned an HTML error page — strip tags for a readable message.
            const plain = text
                .replace(/<[^>]+>/g, " ")
                .replace(/\s+/g, " ")
                .trim()
                .slice(0, 400);
            throw new Error(plain || `HTTP ${res.status}`);
        }
        if (!res.ok || data.error) throw new Error(data.error ?? `HTTP ${res.status}`);

        currentData = data;
        currentBadge = isRebuild ? "rebuilt" : res.headers.get("X-Report-Source") === "cached" ? "cached" : "live";
        currentCacheAgeSec = Number(res.headers.get("X-Report-Age-Seconds") || 0);

        responseCache.set(key, { data: currentData, badge: currentBadge, ageSec: currentCacheAgeSec });

        renderCurrentView();

        if (isRebuild) {
            document.querySelector("[data-rebuild]")?.focus();
            elBanner.innerHTML = renderDiffBanner(computeDiff(prevData, data), prevData !== null);
        }
    } catch (err) {
        // Silently discard errors from requests that were superseded by a newer navigation.
        if (currentAbortController !== controller) return;
        elReport.innerHTML = `<p class="error">Failed to load report: ${esc(err.message)}</p>`;
        if (elAdmin) elAdmin.innerHTML = "";
        // Render nav even on error so the user can navigate away.
        const elNav = document.getElementById("nav");
        elNav.innerHTML = renderNav(params, params.from || SITE.today, params.to || SITE.today);
        bindNavEvents();
    } finally {
        clearTimeout(timeoutId);
        if (currentAbortController === controller) currentAbortController = null;
    }
}

// bindAdminEvents removed — signals are now in the Config page Signals tab.

// ── Config page utilities ─────────────────────────────────────────────────────

const IANA_TIMEZONES = [
    "Africa/Cairo",
    "Africa/Johannesburg",
    "Africa/Lagos",
    "America/Anchorage",
    "America/Chicago",
    "America/Denver",
    "America/Los_Angeles",
    "America/Mexico_City",
    "America/New_York",
    "America/Phoenix",
    "America/Sao_Paulo",
    "America/Toronto",
    "America/Vancouver",
    "Asia/Bangkok",
    "Asia/Dubai",
    "Asia/Hong_Kong",
    "Asia/Jakarta",
    "Asia/Jerusalem",
    "Asia/Kolkata",
    "Asia/Seoul",
    "Asia/Shanghai",
    "Asia/Singapore",
    "Asia/Tokyo",
    "Australia/Melbourne",
    "Australia/Perth",
    "Australia/Sydney",
    "Europe/Amsterdam",
    "Europe/Athens",
    "Europe/Berlin",
    "Europe/Helsinki",
    "Europe/Istanbul",
    "Europe/London",
    "Europe/Madrid",
    "Europe/Moscow",
    "Europe/Paris",
    "Europe/Rome",
    "Europe/Stockholm",
    "Pacific/Auckland",
    "Pacific/Honolulu",
    "UTC",
];

function deepClone(obj) {
    return JSON.parse(JSON.stringify(obj));
}

function setNestedValue(obj, parts, value) {
    let cur = obj;
    for (let i = 0; i < parts.length - 1; i++) {
        const p = /^\d+$/.test(parts[i]) ? Number(parts[i]) : parts[i];
        if (cur[p] === undefined || cur[p] === null) cur[p] = {};
        cur = cur[p];
    }
    const last = /^\d+$/.test(parts[parts.length - 1]) ? Number(parts[parts.length - 1]) : parts[parts.length - 1];
    cur[last] = value;
}

function syncSiteFromConfig(cfg) {
    SITE.projects = Object.entries(cfg.projects || {}).map(([name, p]) => ({ name, grouping: p.grouping ?? null }));
    SITE.groupings = cfg.groupings || {};
}

function showConfigToast(msg, type = "success") {
    const el = document.createElement("div");
    el.className = `config-toast config-toast--${type}`;
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 3000);
}

// ── Config nav helpers ────────────────────────────────────────────────────────
function navigateToConfig() {
    history.pushState({ view: "config" }, "", "?view=config");
    renderConfigView();
}

function navigateToReport() {
    const params = currentParams || { from: SITE.today, to: SITE.today };
    history.pushState(params, "", buildPageUrl(params));
    if (currentData) {
        renderCurrentView();
    } else {
        fetchAndRender(params);
    }
}

// ── Config nav rendering ──────────────────────────────────────────────────────
function renderConfigNav() {
    const elNav = document.getElementById("nav");
    const unsavedBadge = configDirty ? `<span class="config-unsaved-badge">Unsaved changes</span>` : "";
    elNav.innerHTML = `
        <button type="button" class="btn" data-back-to-report>&lsaquo; Back to report</button>
        <span class="sep">|</span>
        <span class="config-nav-title">Configuration</span>
        ${unsavedBadge}
        <span style="flex:1"></span>
        <button type="button" class="btn btn--primary" data-config-save>Save Changes</button>
        <button type="button" class="btn" data-config-discard${configDirty ? "" : " disabled"}>Discard</button>
    `;
    elNav.querySelector("[data-back-to-report]")?.addEventListener("click", (e) => {
        e.preventDefault();
        if (configDirty && !confirm("You have unsaved changes. Leave anyway?")) return;
        navigateToReport();
    });
    elNav.querySelector("[data-config-save]")?.addEventListener("click", saveConfig);
    elNav.querySelector("[data-config-discard]")?.addEventListener("click", () => {
        if (!configDirty) return;
        configDraft = deepClone(loadedConfig);
        configDirty = false;
        renderConfigPageContent();
        renderConfigNav();
    });
}

// ── Config page top-level render ──────────────────────────────────────────────
async function renderConfigView() {
    document.body.classList.add("config-mode");
    renderConfigNav();

    const elAdmin = document.getElementById("admin");
    elAdmin.innerHTML = `<div class="config-page"><p class="loading">Loading configuration…</p></div>`;

    if (loadedConfig === null) {
        try {
            const res = await fetch("api.php?action=config");
            const text = await res.text();
            loadedConfig = JSON.parse(text);
            configDraft = deepClone(loadedConfig);
        } catch (err) {
            elAdmin.innerHTML = `<div class="config-page"><p class="error">Failed to load config: ${esc(err.message)}</p></div>`;
            return;
        }
    }

    renderConfigPageContent();
}

function renderConfigPageContent() {
    const elAdmin = document.getElementById("admin");
    const tabs = ["general", "projects", "groupings", "integrations", "signals"];
    const tabLabels = {
        general: "General",
        projects: "Projects",
        groupings: "Groupings",
        integrations: "Integrations",
        signals: "Signals",
    };

    const tabBar = tabs
        .map(
            (t) =>
                `<button type="button" class="config-tab${t === activeConfigTab ? " active" : ""}" data-tab="${esc(t)}">${esc(tabLabels[t])}</button>`,
        )
        .join("");

    elAdmin.innerHTML = `<div class="config-page">
        <div class="config-tabs-bar">${tabBar}</div>
        <div class="config-tab-panel${activeConfigTab === "general" ? "" : " is-hidden"}" data-tab-panel="general">${renderGeneralTab()}</div>
        <div class="config-tab-panel${activeConfigTab === "projects" ? "" : " is-hidden"}" data-tab-panel="projects">${renderProjectsTab()}</div>
        <div class="config-tab-panel${activeConfigTab === "groupings" ? "" : " is-hidden"}" data-tab-panel="groupings">${renderGroupingsTab()}</div>
        <div class="config-tab-panel${activeConfigTab === "integrations" ? "" : " is-hidden"}" data-tab-panel="integrations">${renderIntegrationsTab()}</div>
        <div class="config-tab-panel${activeConfigTab === "signals" ? "" : " is-hidden"}" data-tab-panel="signals">${renderSignalsTab()}</div>
    </div>`;

    bindConfigPageEvents(elAdmin);
}

// ── Schema field helper ───────────────────────────────────────────────────────
function renderField(label, path, value, opts = {}) {
    const {
        help = "",
        type = "text",
        isTextarea = false,
        isNumber = false,
        isCheckbox = false,
        isSelect = false,
        selectOptions = [],
        isPassword = false,
        isJson = false,
        isUrl = false,
        placeholder = "",
        readonly = false,
        min = null,
    } = opts;

    const fieldAttr = `data-config-field="${esc(path)}"`;
    const readonlyAttr = readonly ? " readonly" : "";
    let control;

    if (isCheckbox) {
        control = `<input type="checkbox" class="config-input" ${fieldAttr} ${value ? "checked" : ""}${readonlyAttr}>`;
    } else if (isSelect) {
        const optsHtml = selectOptions
            .map(([v, l]) => `<option value="${esc(v)}"${v === (value ?? "") ? " selected" : ""}>${esc(l)}</option>`)
            .join("");
        control = `<select class="config-input" ${fieldAttr}${readonlyAttr}>${optsHtml}</select>`;
    } else if (isPassword) {
        const pwId = `pw_${path.replace(/\W/g, "_")}`;
        control = `<div class="pw-wrap">
            <input type="password" class="config-input" id="${pwId}" ${fieldAttr} value="${esc(value ?? "")}"${readonlyAttr}>
            <button type="button" class="btn" data-pw-toggle="${pwId}">Show</button>
        </div>`;
    } else if (isTextarea || isJson) {
        const jsonClass = isJson ? " config-json-field" : "";
        const jsonAttr = isJson ? " data-json-field" : "";
        control = `<textarea class="config-input${jsonClass}" ${fieldAttr}${readonlyAttr}${jsonAttr} placeholder="${esc(placeholder)}">${esc(isJson ? JSON.stringify(value ?? {}, null, 2) : Array.isArray(value) ? value.join("\n") : (value ?? ""))}</textarea>`;
    } else if (isNumber) {
        const minAttr = min !== null ? ` min="${min}"` : "";
        control = `<input type="number" class="config-input" step="1"${minAttr} ${fieldAttr} value="${esc(value ?? "")}"${readonlyAttr}>`;
    } else if (isUrl) {
        control = `<input type="url" class="config-input" ${fieldAttr} value="${esc(value ?? "")}" placeholder="${esc(placeholder)}"${readonlyAttr}>`;
    } else {
        const inputType = isPassword ? "password" : type;
        control = `<input type="${inputType}" class="config-input" ${fieldAttr} value="${esc(value ?? "")}" placeholder="${esc(placeholder)}"${readonlyAttr}>`;
    }

    const helpHtml = help ? `<p class="config-help">${esc(help)}</p>` : "";
    return `<div class="config-field">
        <label class="config-label">${esc(label)}</label>
        <div class="config-field-wrap">${control}${helpHtml}</div>
    </div>`;
}

// ── General tab ───────────────────────────────────────────────────────────────
function renderGeneralTab() {
    const cfg = configDraft;
    const chromeProfIsNull = cfg.paths?.chrome_profiles === null || cfg.paths?.chrome_profiles === undefined;
    const chromeProfVal = chromeProfIsNull ? "" : (cfg.paths?.chrome_profiles || []).join("\n");

    return `
    <div class="config-section">
        <p class="config-section-title">Core</p>
        <div class="config-field">
            <label class="config-label">Timezone</label>
            <div class="config-field-wrap">
                <input type="text" class="config-input" data-config-field="timezone"
                    list="iana-tz-list" value="${esc(cfg.timezone ?? "")}" placeholder="America/New_York" autocomplete="off">
                <datalist id="iana-tz-list">${IANA_TIMEZONES.map((tz) => `<option value="${esc(tz)}">`).join("")}</datalist>
                <p class="config-help">IANA timezone name. Start typing to filter suggestions.</p>
            </div>
        </div>
        ${renderField("ActivityWatch path", "paths.activitywatch", cfg.paths?.activitywatch, { help: "Path to the ActivityWatch data directory. ~ is expanded." })}
        ${renderField("Chrome user data path", "paths.chrome", cfg.paths?.chrome, { help: "Path to the Chrome user-data directory." })}
        <div class="config-field">
            <label class="config-label">Chrome profiles</label>
            <div class="config-field-wrap">
                <label><input type="checkbox" id="chrome_profiles_auto" ${chromeProfIsNull ? "checked" : ""}> Auto-detect all profiles</label>
                <textarea class="config-input" data-config-field="paths.chrome_profiles" placeholder="Default&#10;Profile 1" ${chromeProfIsNull ? "disabled" : ""}>${esc(chromeProfVal)}</textarea>
                <p class="config-help">Chrome profile folder names to scan. Leave auto-detect on to scan all profiles.</p>
            </div>
        </div>
    </div>
    <div class="config-section">
        <p class="config-section-title">Git</p>
        ${renderField("Git author emails", "git_authors", cfg.git_authors, { isTextarea: true, help: "Email addresses for git authorship, one per line." })}
        ${renderField("Discover repos", "discover_repos", cfg.discover_repos ?? "", {
            isSelect: true,
            selectOptions: [
                ["", "(none)"],
                ["github_desktop", "GitHub Desktop"],
            ],
            help: "Auto-discovery strategy for git repositories.",
        })}
    </div>
    <div class="config-section">
        <p class="config-section-title">Timing</p>
        ${renderField("Chrome correlation window (s)", "chrome_correlation_window_seconds", cfg.chrome_correlation_window_seconds ?? 120, { isNumber: true, min: 0 })}
        ${renderField("App correlation window (s)", "app_correlation_window_seconds", cfg.app_correlation_window_seconds ?? 900, { isNumber: true, min: 0 })}
        ${renderField("Project gap window (s)", "project_gap_window_seconds", cfg.project_gap_window_seconds ?? 300, { isNumber: true, min: 0 })}
        ${renderField("Timeline merge gap (s)", "timeline_merge_gap_seconds", cfg.timeline_merge_gap_seconds ?? 300, { isNumber: true, min: 0 })}
        ${renderField("Timeline min segment (s)", "timeline_min_seconds", cfg.timeline_min_seconds ?? 60, { isNumber: true, min: 0 })}
        ${renderField("Integration HTTP timeout (s)", "integration_http_timeout_seconds", cfg.integration_http_timeout_seconds ?? 20, { isNumber: true, min: 1 })}
        ${renderField("GitHub command timeout (s)", "github_command_timeout_seconds", cfg.github_command_timeout_seconds ?? 8, { isNumber: true, min: 1 })}
        ${renderField("GitHub cache TTL", "github_cache_ttl", cfg.github_cache_ttl ?? "1h", { placeholder: "1h" })}
        ${renderField("Min event seconds to show", "min_event_seconds_to_show", cfg.min_event_seconds_to_show ?? 30, { isNumber: true, min: 0 })}
    </div>
    <div class="config-section">
        <p class="config-section-title">Personal &amp; Ignored</p>
        ${renderField("Personal hosts", "personal_hosts", cfg.personal_hosts, { isTextarea: true, help: "Browser hostnames to bucket as personal, one per line." })}
        ${renderField("Personal apps", "personal_apps", cfg.personal_apps, { isTextarea: true, help: "Application names to bucket as personal, one per line." })}
        ${renderField("Correlated apps", "correlated_apps", cfg.correlated_apps, { isTextarea: true, help: "App names attributed to the most recently active project, one per line." })}
        ${renderField("Ignored projects", "ignored_projects", cfg.ignored_projects, { isTextarea: true, help: "Project names to ignore in classification, one per line." })}
    </div>`;
}

// ── Projects tab ──────────────────────────────────────────────────────────────
function renderProjectsTab() {
    const cfg = configDraft;
    const projects = cfg.projects || {};
    const ignored = new Set(cfg.ignored_projects || []);

    const rows = Object.entries(projects)
        .map(([name, p]) => {
            const isIgnored = ignored.has(name);
            const g = p.grouping || "";
            const gColor = g ? groupingColor(resolveGrouping(g) || g) : "#e5e7eb";
            const gText = g
                ? `<span class="grouping-badge" style="background:${gColor}22;color:${gColor};border:1px solid ${gColor}55">${esc(g)}</span>`
                : `<span class="muted">—</span>`;

            const badges = [
                p.vscode_dirs?.length ? `<span class="signal-badge signal-badge--vs">VS</span>` : "",
                p.domains?.length ? `<span class="signal-badge signal-badge--dom">DOM</span>` : "",
                p.repos?.length ? `<span class="signal-badge signal-badge--git">git</span>` : "",
                p.slack?.length ? `<span class="signal-badge signal-badge--slk">Slack</span>` : "",
                p.ssh_hosts?.length ? `<span class="signal-badge signal-badge--ssh">SSH</span>` : "",
                p.apps?.length ? `<span class="signal-badge signal-badge--app">App</span>` : "",
                p.harvest_projects?.length || p.harvest_client
                    ? `<span class="signal-badge signal-badge--h">H</span>`
                    : "",
                p.clickup_tasks?.length ? `<span class="signal-badge signal-badge--cu">CU</span>` : "",
            ]
                .filter(Boolean)
                .join("");

            const ignoredBadge = isIgnored
                ? ` <span class="signal-badge" style="background:#fee2e2;color:#b91c1c">ignored</span>`
                : "";

            return `<tr class="project-row${isIgnored ? " project-row--ignored" : ""}" data-project-name="${esc(name)}">
            <td>${esc(name)}${ignoredBadge}</td>
            <td>${gText}</td>
            <td><div class="signal-badges">${badges || '<span class="muted">—</span>'}</div></td>
            <td>${esc(p.harvest_client || "")}</td>
            <td>
                <button type="button" class="btn" data-project-edit="${esc(name)}">Edit ▾</button>
            </td>
        </tr>
        <tr class="project-drawer is-hidden" data-project-drawer="${esc(name)}">
            <td colspan="5">${renderProjectDrawer(name, p, isIgnored)}</td>
        </tr>`;
        })
        .join("");

    return `<table class="config-projects-table">
        <thead><tr>
            <th>Name</th><th>Grouping</th><th>Signals</th><th>Harvest Client</th><th>Actions</th>
        </tr></thead>
        <tbody>${rows}</tbody>
    </table>
    <button type="button" class="btn config-add-btn" data-add-project>+ Add project</button>`;
}

function renderProjectDrawer(name, p, isIgnored) {
    const cfg = configDraft;
    const groupings = Object.keys(cfg.groupings || {});
    const groupingOpts = [["", "(no grouping)"], ...groupings.map((g) => [g, g])];
    const slackRows = (p.slack || [])
        .map(
            (r, i) =>
                `<div class="config-subform-row">
            <input type="text" class="config-input" placeholder="Workspace" data-config-field="projects.${esc(name)}.slack.${i}.workspace" value="${esc(r.workspace || "")}">
            <input type="text" class="config-input" placeholder="Channel glob (optional)" data-config-field="projects.${esc(name)}.slack.${i}.channel_glob" value="${esc(r.channel_glob || "")}">
            <button type="button" class="btn btn--danger" data-remove-slack="${esc(name)}" data-slack-idx="${i}">✕</button>
        </div>`,
        )
        .join("");

    return `<div class="project-drawer-inner">
        <div class="config-field">
            <label class="config-label">Project name</label>
            <input type="text" class="config-input" data-project-rename="${esc(name)}" value="${esc(name)}">
        </div>
        ${renderField("Grouping", `projects.${name}.grouping`, p.grouping ?? "", { isSelect: true, selectOptions: groupingOpts })}
        ${renderField("Repos", `projects.${name}.repos`, p.repos, { isTextarea: true, help: "Absolute paths to git repos, one per line." })}
        ${renderField("VSCode dirs", `projects.${name}.vscode_dirs`, p.vscode_dirs, { isTextarea: true, help: "VSCode workspace folder names, one per line." })}
        ${renderField("Domains", `projects.${name}.domains`, p.domains, { isTextarea: true, help: "Browser hostnames, one per line." })}
        <div class="config-field">
            <label class="config-label">Slack rules</label>
            <div class="config-field-wrap">
                <div data-slack-rules="${esc(name)}">${slackRows}</div>
                <button type="button" class="btn config-add-btn" data-add-slack="${esc(name)}">+ Add Slack rule</button>
            </div>
        </div>
        ${renderField("SSH hosts", `projects.${name}.ssh_hosts`, p.ssh_hosts, { isTextarea: true, help: "SSH hostnames, one per line." })}
        ${renderField("Apps", `projects.${name}.apps`, p.apps, { isTextarea: true, help: "Application names or globs, one per line." })}
        ${renderField("Harvest projects", `projects.${name}.harvest_projects`, p.harvest_projects, { isTextarea: true, help: "Harvest project-name globs, one per line." })}
        ${renderField("Harvest client", `projects.${name}.harvest_client`, p.harvest_client ?? "", { help: "Exact Harvest client name (case-insensitive)." })}
        ${renderField("ClickUp tasks", `projects.${name}.clickup_tasks`, p.clickup_tasks, { isTextarea: true, help: "ClickUp task-name globs, one per line." })}
        ${renderField("Repo remotes (advanced)", `projects.${name}.repo_remotes`, p.repo_remotes, { isJson: true, help: "Snapshot of git remote names/URLs keyed by repo path." })}
        <div style="margin-top:0.75rem;display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap">
            <button type="button" class="btn" data-toggle-ignored="${esc(name)}" data-is-ignored="${isIgnored ? "1" : "0"}">
                ${isIgnored ? "Remove from ignored" : "Mark as ignored"}
            </button>
            <button type="button" class="btn btn--danger" data-project-delete="${esc(name)}">Delete project…</button>
        </div>
    </div>`;
}

// ── Groupings tab ─────────────────────────────────────────────────────────────
function renderGroupingsTab() {
    const cfg = configDraft;
    const groupings = cfg.groupings || {};
    const cards = Object.entries(groupings)
        .map(([name, g]) => {
            const ttOpts = [
                ["", "(none)"],
                ["clickup", "ClickUp"],
                ["harvest", "Harvest"],
                ["none", "None"],
            ];
            const harvConnVisible = g.time_tracking === "harvest";
            return `<div class="grouping-card" data-grouping-card="${esc(name)}">
            <div class="grouping-card-header">
                <span class="integration-card-title">${esc(name)}</span>
                <button type="button" class="btn btn--danger" data-remove-grouping="${esc(name)}">Remove</button>
            </div>
            <div class="config-field">
                <label class="config-label">Name</label>
                <input type="text" class="config-input" data-grouping-rename="${esc(name)}" value="${esc(name)}">
            </div>
            ${renderField("Color", `groupings.${name}.color`, g.color ?? "#6366f1", { type: "color", isCheckbox: false })}
            ${renderField("Logo URL", `groupings.${name}.logo`, g.logo ?? "", { isUrl: true })}
            ${renderField("Aliases", `groupings.${name}.aliases`, g.aliases, { isTextarea: true, help: "Alternate spellings, one per line." })}
            ${renderField("Time tracking", `groupings.${name}.time_tracking`, g.time_tracking ?? "", { isSelect: true, selectOptions: ttOpts })}
            <div class="config-field harvest-conn-field${harvConnVisible ? " visible" : ""}" data-harvest-conn-field="${esc(name)}">
                <label class="config-label">Harvest connection</label>
                <input type="text" class="config-input" data-config-field="groupings.${esc(name)}.harvest_connection" value="${esc(g.harvest_connection ?? "")}">
            </div>
            <p class="config-soft-note">Renaming a grouping will not auto-update project grouping fields.</p>
        </div>`;
        })
        .join("");

    return `${cards}
    <button type="button" class="btn config-add-btn" data-add-grouping>+ Add grouping</button>`;
}

// ── Integrations tab ──────────────────────────────────────────────────────────
function renderIntegrationsTab() {
    const integ = configDraft.integrations || {};

    function connCards(type, connections, fields) {
        const cards = (connections || [])
            .map((conn, i) => {
                const fieldRows = fields
                    .map(([key, label, fieldOpts]) => {
                        const path = `integrations.${type}.${i}.${key}`;
                        return renderField(label, path, conn[key] ?? "", fieldOpts);
                    })
                    .join("");
                return `<div class="integration-card">
                <div class="integration-card-header">
                    <span class="integration-card-title">${esc(conn.name || `Connection ${i + 1}`)}</span>
                    <button type="button" class="btn btn--danger" data-remove-conn="${esc(type)}" data-conn-idx="${i}">Remove</button>
                </div>
                ${fieldRows}
            </div>`;
            })
            .join("");
        return cards || '<p class="muted">No connections configured.</p>';
    }

    const harvestFields = [
        ["name", "Name", {}],
        ["account_id", "Account ID", {}],
        ["token", "Token", { isPassword: true }],
        ["user_id", "User ID (optional)", {}],
    ];
    const clickupFields = [
        ["name", "Name", {}],
        ["team_id", "Team IDs", { isTextarea: true, help: "One team ID per line." }],
        ["token", "Token", { isPassword: true }],
        ["assignee", "Assignee ID (optional)", {}],
    ];
    const githubFields = [
        ["name", "Name", {}],
        ["token", "Token (optional)", { isPassword: true }],
        ["usernames", "Usernames", { isTextarea: true, help: "GitHub login names, one per line." }],
        [
            "authors",
            "Authors (optional)",
            { isTextarea: true, help: "GitHub usernames/emails for commit filtering, one per line." },
        ],
    ];
    const llmFields = [
        ["name", "Name", {}],
        ["base_url", "Base URL", { isUrl: true, help: "e.g. http://localhost:11434/v1" }],
        ["api_key", "API key", { isPassword: true }],
        ["model", "Model", {}],
        ["timeout", "Timeout (s)", { isNumber: true, min: 1 }],
    ];

    return `
    <div class="integration-section">
        <p class="integration-section-title">Harvest</p>
        ${connCards("harvest", integ.harvest, harvestFields)}
        <button type="button" class="btn config-add-btn" data-add-conn="harvest">+ Add Harvest connection</button>
    </div>
    <div class="integration-section">
        <p class="integration-section-title">ClickUp</p>
        ${connCards("clickup", integ.clickup, clickupFields)}
        <button type="button" class="btn config-add-btn" data-add-conn="clickup">+ Add ClickUp connection</button>
    </div>
    <div class="integration-section">
        <p class="integration-section-title">GitHub</p>
        ${connCards("github", integ.github, githubFields)}
        <button type="button" class="btn config-add-btn" data-add-conn="github">+ Add GitHub connection</button>
    </div>
    <div class="integration-section">
        <p class="integration-section-title">LLM</p>
        ${connCards("llm", integ.llm, llmFields)}
        <button type="button" class="btn config-add-btn" data-add-conn="llm">+ Add LLM connection</button>
    </div>`;
}

// ── Signals tab ───────────────────────────────────────────────────────────────
function renderSignalsTab() {
    if (!currentData) {
        return `<p class="muted">Load a report first to see unmatched signals.</p>
        <button type="button" class="btn" data-back-to-report>Go to report</button>`;
    }

    const queue = Array.from(personalProjectQueue.values()).sort();
    const queueHtml = queue.length
        ? `<div class="admin-row"><span>${queue.map(esc).join(", ")}</span><button class="btn" data-apply-personal>Apply personal flags</button></div>`
        : '<p class="muted">No projects queued for personal-ignore.</p>';

    const unmatched = currentData?.unmatched || {};
    const kinds = ["vscode", "browser", "slack", "apps"];
    const rows = [];
    for (const kind of kinds) {
        const items = Object.entries(unmatched[kind] || {})
            .sort(([, a], [, b]) => b - a)
            .slice(0, 12);
        if (!items.length) continue;
        const sectionRows = items
            .map(([value, count]) => {
                const enc = encodeURIComponent(value);
                return `<div class="admin-row">
                <span class="sig"><code>${esc(value)}</code> <span class="muted">(${count})</span></span>
                <select aria-label="Assign to project" data-reassign-project>${projectOptions("", kind)}</select>
                <button class="btn" data-reassign data-kind="${esc(kind)}" data-value="${enc}">Assign</button>
            </div>`;
            })
            .join("");
        rows.push(`<h4>${esc(kind)}</h4>${sectionRows}`);
    }
    const unmatchedHtml = rows.length ? rows.join("") : '<p class="muted">No unmatched signals in this range.</p>';

    return `<section class="admin-panel">
        <h4>Queued Personal Flags</h4>${queueHtml}
        <h4>Reassign Unmatched Signals</h4>${unmatchedHtml}
    </section>`;
}

// ── Config page save ──────────────────────────────────────────────────────────
async function saveConfig() {
    const jsonFields = document.querySelectorAll("[data-json-field]");
    for (const f of jsonFields) {
        try {
            JSON.parse(f.value);
        } catch {
            showConfigToast(`Invalid JSON in ${f.dataset.configField}: ${f.value.slice(0, 40)}`, "error");
            return;
        }
    }
    const saveBtn = document.querySelector("[data-config-save]");
    if (saveBtn) saveBtn.setAttribute("disabled", "disabled");
    try {
        await postApi({ action: "save_config", config: configDraft });
        loadedConfig = deepClone(configDraft);
        configDirty = false;
        responseCache.clear();
        syncSiteFromConfig(configDraft);
        renderConfigNav();
        showConfigToast("Configuration saved.");
    } catch (err) {
        showConfigToast(`Save failed: ${err.message}`, "error");
    } finally {
        if (saveBtn) saveBtn.removeAttribute("disabled");
    }
}

// ── Config page event binding ─────────────────────────────────────────────────
function handleConfigFieldChange(e) {
    const el = e.target;
    const path = el.dataset.configField;
    if (!path) return;

    const parts = path.split(".");
    let value;

    if (el.type === "checkbox") {
        value = el.checked;
    } else if (el.type === "number") {
        value = Number(el.value);
    } else if (el.tagName === "TEXTAREA" && el.hasAttribute("data-json-field")) {
        // validated on save
        try {
            value = JSON.parse(el.value);
        } catch {
            return;
        }
    } else if (el.tagName === "TEXTAREA") {
        // For array fields, split on newlines; for non-array leave as string
        const topKey = parts[0];
        const isArrayPath =
            topKey === "git_authors" ||
            topKey === "personal_hosts" ||
            topKey === "personal_apps" ||
            topKey === "correlated_apps" ||
            topKey === "ignored_projects" ||
            (parts[1] &&
                [
                    "repos",
                    "vscode_dirs",
                    "domains",
                    "ssh_hosts",
                    "apps",
                    "harvest_projects",
                    "clickup_tasks",
                    "usernames",
                    "authors",
                    "aliases",
                ].includes(parts[parts.length - 1])) ||
            path === "paths.chrome_profiles";
        if (isArrayPath) {
            value = el.value
                .split("\n")
                .map((s) => s.trim())
                .filter(Boolean);
            // special: paths.chrome_profiles can be null
            if (path === "paths.chrome_profiles" && value.length === 0) value = null;
        } else {
            value = el.value;
        }
    } else {
        value = el.value;
    }

    // special: team_id is textarea but stored as array or string
    if (path.endsWith(".team_id") && el.tagName === "TEXTAREA") {
        const lines = el.value
            .split("\n")
            .map((s) => s.trim())
            .filter(Boolean);
        value = lines.length === 1 ? lines[0] : lines;
    }
    // special: aliases stored as array
    if (path.endsWith(".aliases") && el.tagName === "TEXTAREA") {
        value = el.value
            .split("\n")
            .map((s) => s.trim())
            .filter(Boolean);
    }

    setNestedValue(configDraft, parts, value === "" && el.type !== "number" ? value || undefined : value);
    configDirty = true;
    renderConfigNav();
}

function autoResizeTextarea(el) {
    el.style.height = "auto";
    el.style.height = Math.max(72, el.scrollHeight) + "px";
}

function bindConfigPageEvents(container) {
    // Auto-resize all textareas to fit their content on initial render.
    container.querySelectorAll("textarea.config-input").forEach(autoResizeTextarea);

    // Re-resize on input so they grow as the user types.
    container.addEventListener("input", (e) => {
        if (e.target.matches("textarea.config-input")) autoResizeTextarea(e.target);
    });

    // Field changes
    container.addEventListener("input", handleConfigFieldChange);
    container.addEventListener("change", handleConfigFieldChange);

    // Tab switching
    container.addEventListener("click", (e) => {
        const tabBtn = e.target.closest("[data-tab]");
        if (tabBtn) {
            activeConfigTab = tabBtn.dataset.tab;
            container
                .querySelectorAll(".config-tab")
                .forEach((t) => t.classList.toggle("active", t.dataset.tab === activeConfigTab));
            container
                .querySelectorAll(".config-tab-panel")
                .forEach((p) => p.classList.toggle("is-hidden", p.dataset.tabPanel !== activeConfigTab));
            return;
        }

        // Project edit drawer toggle
        const editBtn = e.target.closest("[data-project-edit]");
        if (editBtn) {
            const name = editBtn.dataset.projectEdit;
            const drawerRow = container.querySelector(`[data-project-drawer="${CSS.escape(name)}"]`);
            const isOpen = !drawerRow?.classList.contains("is-hidden");
            // close all
            container.querySelectorAll(".project-drawer").forEach((d) => d.classList.add("is-hidden"));
            if (!isOpen && drawerRow) drawerRow.classList.remove("is-hidden");
            return;
        }

        // Project delete
        const delBtn = e.target.closest("[data-project-delete]");
        if (delBtn) {
            const name = delBtn.dataset.projectDelete;
            if (
                !confirm(
                    `Are you sure you want to delete the project "${name}"? This cannot be undone without discarding all changes.`,
                )
            )
                return;
            delete configDraft.projects[name];
            configDraft.ignored_projects = (configDraft.ignored_projects || []).filter((n) => n !== name);
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            return;
        }

        // Add project
        if (e.target.closest("[data-add-project]")) {
            let name = "New Project";
            let i = 2;
            while (configDraft.projects[name]) {
                name = `New Project ${i++}`;
            }
            configDraft.projects[name] = {};
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            // open the new drawer
            const newDrawer = container.querySelector(`[data-project-drawer="${CSS.escape(name)}"]`);
            newDrawer?.classList.remove("is-hidden");
            return;
        }

        // Toggle ignored
        const ignBtn = e.target.closest("[data-toggle-ignored]");
        if (ignBtn) {
            const name = ignBtn.dataset.toggleIgnored;
            const isIgnored = ignBtn.dataset.isIgnored === "1";
            const ignored = new Set(configDraft.ignored_projects || []);
            if (isIgnored) ignored.delete(name);
            else ignored.add(name);
            configDraft.ignored_projects = Array.from(ignored);
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            const newDrawer = container.querySelector(`[data-project-drawer="${CSS.escape(name)}"]`);
            newDrawer?.classList.remove("is-hidden");
            return;
        }

        // Add slack rule
        const addSlack = e.target.closest("[data-add-slack]");
        if (addSlack) {
            const name = addSlack.dataset.addSlack;
            configDraft.projects[name].slack = configDraft.projects[name].slack || [];
            configDraft.projects[name].slack.push({ workspace: "" });
            configDirty = true;
            // just re-render the drawer content
            const drawerRow = container.querySelector(`[data-project-drawer="${CSS.escape(name)}"]`);
            if (drawerRow) {
                drawerRow.querySelector("td").innerHTML = renderProjectDrawer(
                    name,
                    configDraft.projects[name],
                    (configDraft.ignored_projects || []).includes(name),
                );
            }
            renderConfigNav();
            return;
        }

        // Remove slack rule
        const removeSlack = e.target.closest("[data-remove-slack]");
        if (removeSlack) {
            const name = removeSlack.dataset.removeSlack;
            const idx = Number(removeSlack.dataset.slackIdx);
            configDraft.projects[name].slack.splice(idx, 1);
            configDirty = true;
            const drawerRow = container.querySelector(`[data-project-drawer="${CSS.escape(name)}"]`);
            if (drawerRow) {
                drawerRow.querySelector("td").innerHTML = renderProjectDrawer(
                    name,
                    configDraft.projects[name],
                    (configDraft.ignored_projects || []).includes(name),
                );
            }
            renderConfigNav();
            return;
        }

        // Grouping remove
        const removeGrouping = e.target.closest("[data-remove-grouping]");
        if (removeGrouping) {
            const name = removeGrouping.dataset.removeGrouping;
            delete configDraft.groupings[name];
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            return;
        }

        // Add grouping
        if (e.target.closest("[data-add-grouping]")) {
            let name = "New Grouping";
            let i = 2;
            while ((configDraft.groupings || {})[name]) {
                name = `New Grouping ${i++}`;
            }
            configDraft.groupings = configDraft.groupings || {};
            configDraft.groupings[name] = {};
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            return;
        }

        // Integration remove connection
        const removeConn = e.target.closest("[data-remove-conn]");
        if (removeConn) {
            const type = removeConn.dataset.removeConn;
            const idx = Number(removeConn.dataset.connIdx);
            configDraft.integrations = configDraft.integrations || {};
            configDraft.integrations[type] = configDraft.integrations[type] || [];
            configDraft.integrations[type].splice(idx, 1);
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            return;
        }

        // Add integration connection
        const addConn = e.target.closest("[data-add-conn]");
        if (addConn) {
            const type = addConn.dataset.addConn;
            configDraft.integrations = configDraft.integrations || {};
            configDraft.integrations[type] = configDraft.integrations[type] || [];
            const defaults = {
                harvest: { name: "", account_id: "", token: "" },
                clickup: { name: "", team_id: "", token: "" },
                github: { name: "" },
                llm: { name: "", base_url: "" },
            };
            configDraft.integrations[type].push(defaults[type] || { name: "" });
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            return;
        }

        // Signals tab: apply personal, reassign
        const applyPersonalBtn = e.target.closest("[data-apply-personal]");
        if (applyPersonalBtn) {
            const projects = Array.from(personalProjectQueue.values());
            if (!projects.length) return;
            applyPersonalBtn.setAttribute("disabled", "disabled");
            postApi({ action: "flag_projects_personal", projects })
                .then(async () => {
                    personalProjectQueue = new Set();
                    loadedConfig = null; // force re-fetch
                    const res = await fetch("api.php?action=config");
                    loadedConfig = JSON.parse(await res.text());
                    configDraft = deepClone(loadedConfig);
                    renderConfigPageContent();
                })
                .catch((err) => showConfigToast(`Failed: ${err.message}`, "error"))
                .finally(() => applyPersonalBtn.removeAttribute("disabled"));
            return;
        }

        const reassignBtn = e.target.closest("[data-reassign]");
        if (reassignBtn) {
            const row = reassignBtn.closest(".admin-row");
            const select = row?.querySelector("[data-reassign-project]");
            let project = select?.value || "";
            if (!project) {
                showConfigToast("Select a project first.", "error");
                return;
            }
            let newProjectName = "";
            if (project === "__new__") {
                const entered = prompt("New project name:");
                if (!entered?.trim()) return;
                newProjectName = entered.trim();
                project = newProjectName;
            }
            const kind = reassignBtn.dataset.kind || "";
            const value = decodeURIComponent(reassignBtn.dataset.value || "");
            reassignBtn.setAttribute("disabled", "disabled");
            postApi({ action: "reassign_signal", project, kind, value, new_project_name: newProjectName })
                .then(async () => {
                    // Re-sync configDraft from server so General tab reflects the change.
                    const res = await fetch("api.php?action=config");
                    loadedConfig = JSON.parse(await res.text());
                    configDraft = deepClone(loadedConfig);
                    if (currentParams) return fetchAndRender(currentParams, true);
                })
                .then(() => renderConfigPageContent())
                .catch((err) => showConfigToast(`Failed: ${err.message}`, "error"))
                .finally(() => reassignBtn.removeAttribute("disabled"));
            return;
        }

        // Back to report from signals tab
        if (e.target.closest("[data-back-to-report]")) {
            if (configDirty && !confirm("You have unsaved changes. Leave anyway?")) return;
            navigateToReport();
            return;
        }

        // Password show/hide
        const pwToggle = e.target.closest("[data-pw-toggle]");
        if (pwToggle) {
            const inputId = pwToggle.dataset.pwToggle;
            const input = document.getElementById(inputId);
            if (!input) return;
            const isHidden = input.type === "password";
            input.type = isHidden ? "text" : "password";
            pwToggle.textContent = isHidden ? "Hide" : "Show";
            return;
        }
    });

    // Harvest connection field visibility when time_tracking select changes
    container.addEventListener("change", (e) => {
        const ttSelect = e.target.closest("[data-config-field$='.time_tracking']");
        if (!ttSelect) return;
        const path = ttSelect.dataset.configField;
        const groupingName = path.split(".")[1];
        const card = container.querySelector(`[data-grouping-card="${CSS.escape(groupingName)}"]`);
        const harvField = card?.querySelector(`[data-harvest-conn-field="${CSS.escape(groupingName)}"]`);
        if (harvField) harvField.classList.toggle("visible", ttSelect.value === "harvest");
    });

    // Project rename (on blur)
    container.addEventListener("focusout", (e) => {
        const renameInput = e.target.closest("[data-project-rename]");
        if (renameInput) {
            const oldName = renameInput.dataset.projectRename;
            const newName = renameInput.value.trim();
            if (!newName || newName === oldName || configDraft.projects[newName]) return;
            const reordered = {};
            for (const [k, v] of Object.entries(configDraft.projects)) {
                reordered[k === oldName ? newName : k] = v;
            }
            configDraft.projects = reordered;
            configDraft.ignored_projects = (configDraft.ignored_projects || []).map((n) =>
                n === oldName ? newName : n,
            );
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            const newDrawer = container.querySelector(`[data-project-drawer="${CSS.escape(newName)}"]`);
            newDrawer?.classList.remove("is-hidden");
            return;
        }

        const groupingRename = e.target.closest("[data-grouping-rename]");
        if (groupingRename) {
            const oldName = groupingRename.dataset.groupingRename;
            const newName = groupingRename.value.trim();
            if (!newName || newName === oldName || configDraft.groupings[newName]) return;
            const reordered = {};
            for (const [k, v] of Object.entries(configDraft.groupings)) {
                reordered[k === oldName ? newName : k] = v;
            }
            configDraft.groupings = reordered;
            configDirty = true;
            renderConfigPageContent();
            renderConfigNav();
            return;
        }
    });

    // Chrome profiles auto-detect checkbox
    const chromeProfAuto = container.querySelector("#chrome_profiles_auto");
    if (chromeProfAuto) {
        chromeProfAuto.addEventListener("change", () => {
            const ta = container.querySelector("[data-config-field='paths.chrome_profiles']");
            if (!ta) return;
            if (chromeProfAuto.checked) {
                ta.disabled = true;
                ta.value = "";
                configDraft.paths = configDraft.paths || {};
                configDraft.paths.chrome_profiles = null;
            } else {
                ta.disabled = false;
                configDraft.paths = configDraft.paths || {};
                configDraft.paths.chrome_profiles = [];
            }
            configDirty = true;
            renderConfigNav();
        });
    }

    // Color input special: update on input
    container.querySelectorAll("input[type='color'][data-config-field]").forEach((el) => {
        el.addEventListener("input", handleConfigFieldChange);
    });
}

// ── Navigation ────────────────────────────────────────────────────────────────
function navigateTo(overrides, isRebuild = false) {
    const next = { ...currentParams, ...overrides };
    // Explicit dates take precedence over days shorthand and vice-versa.
    if (next.from || next.to) {
        delete next.days;
    } else if (next.days) {
        delete next.from;
        delete next.to;
    }
    currentParams = next;
    history.pushState(next, "", buildPageUrl(next));
    fetchAndRender(next, isRebuild);
}

function bindNavEvents() {
    const nav = document.getElementById("nav");

    nav.querySelectorAll("[data-nav]").forEach((el) => {
        el.addEventListener("click", (e) => {
            e.preventDefault();
            const p = Object.fromEntries(new URL(el.href).searchParams);
            delete p.format;
            navigateTo(p);
        });
    });

    const dateForm = nav.querySelector("[data-date-form]");
    if (dateForm) {
        const fromInput = nav.querySelector("[data-date-from]");
        const toInput = nav.querySelector("[data-date-to]");
        const rangeWrap = nav.querySelector("[data-date-range]");
        const addRangeBtn = nav.querySelector("[data-add-range]");
        const removeRangeBtn = nav.querySelector("[data-remove-range]");

        const showRange = () => {
            rangeWrap?.classList.remove("is-hidden");
            addRangeBtn?.classList.add("is-hidden");
            if (toInput && !toInput.value) {
                toInput.value = fromInput?.value || "";
            }
            toInput?.focus();
        };

        const hideRange = () => {
            rangeWrap?.classList.add("is-hidden");
            addRangeBtn?.classList.remove("is-hidden");
            if (toInput) {
                toInput.value = "";
            }
        };

        addRangeBtn?.addEventListener("click", (e) => {
            e.preventDefault();
            showRange();
        });

        removeRangeBtn?.addEventListener("click", (e) => {
            e.preventDefault();
            hideRange();
        });

        dateForm.addEventListener("submit", (e) => {
            e.preventDefault();
            const from = fromInput?.value || "";
            const to = rangeWrap?.classList.contains("is-hidden") ? from : toInput?.value || from;
            if (!from) return;
            const nextFrom = from <= to ? from : to;
            const nextTo = from <= to ? to : from;
            navigateTo({ from: nextFrom, to: nextTo, days: null });
        });
    }

    const sel = nav.querySelector("[data-project-select]");
    if (sel) {
        sel.addEventListener("change", () => {
            currentParams = { ...currentParams, project: sel.value };
            history.pushState(currentParams, "", buildPageUrl(currentParams));
            renderCurrentView();
        });
    }

    const rebuildBtn = nav.querySelector("[data-rebuild]");
    if (rebuildBtn) {
        rebuildBtn.addEventListener("click", (e) => {
            e.preventDefault();
            rebuildBtn.setAttribute("disabled", "");
            fetchAndRender(currentParams, true);
        });
    }

    const gotoConfigBtn = nav.querySelector("[data-goto-config]");
    if (gotoConfigBtn) {
        gotoConfigBtn.addEventListener("click", (e) => {
            e.preventDefault();
            navigateToConfig();
        });
    }
}

document.addEventListener("mouseover", (e) => {
    const row = e.target.closest(".tl-row");
    if (!row) return;
    const list = row.closest(".timeline-list");
    if (!list) return;
    const proj = row.dataset.proj;
    for (const r of list.querySelectorAll(".tl-row")) {
        r.style.opacity = r.dataset.proj === proj ? "" : "0.3";
    }
});

document.addEventListener("mouseout", (e) => {
    const row = e.target.closest(".tl-row");
    if (!row) return;
    const list = row.closest(".timeline-list");
    if (!list || list.contains(e.relatedTarget)) return;
    for (const r of list.querySelectorAll(".tl-row")) {
        r.style.opacity = "";
    }
});

window.addEventListener("popstate", (e) => {
    if (e.state?.view === "config") {
        renderConfigView();
    } else {
        currentParams = e.state || paramsFromUrl();
        fetchAndRender(currentParams);
    }
});

document.addEventListener("keydown", (e) => {
    // Don't intercept when focus is inside a form element or a modifier key is held.
    const tag = document.activeElement?.tagName;
    if (tag === "INPUT" || tag === "TEXTAREA" || tag === "SELECT") return;
    if (e.altKey || e.ctrlKey || e.metaKey) return;
    const navLinks = document.querySelectorAll("[data-nav]");
    if (e.key === "ArrowLeft" && navLinks[0]) {
        navLinks[0].click();
    } else if (e.key === "ArrowRight" && navLinks[1]) {
        navLinks[1].click();
    }
});

// ── Project menus ─────────────────────────────────────────────────────────────
function closeAllProjectMenus() {
    document.querySelectorAll(".proj-menu-dropdown:not([hidden])").forEach((d) => {
        d.hidden = true;
        d.closest(".proj-actions")?.querySelector(".proj-menu-btn")?.setAttribute("aria-expanded", "false");
    });
}

document.addEventListener("click", (e) => {
    const menuBtn = e.target.closest(".proj-menu-btn");
    if (menuBtn) {
        const wrapper = menuBtn.closest(".proj-actions");
        const drop = wrapper?.querySelector(".proj-menu-dropdown");
        if (!drop) return;
        const wasHidden = drop.hidden;
        closeAllProjectMenus();
        if (wasHidden) {
            drop.hidden = false;
            menuBtn.setAttribute("aria-expanded", "true");
        }
        return;
    }

    const flagBtn = e.target.closest("[data-flag-project]");
    if (flagBtn) {
        closeAllProjectMenus();
        const name = flagBtn.getAttribute("data-flag-project") || "";
        if (name) {
            personalProjectQueue.add(name);
            renderCurrentView();
        }
        return;
    }

    const saveGroupBtn = e.target.closest("[data-menu-save-group]");
    if (saveGroupBtn) {
        const project = saveGroupBtn.getAttribute("data-menu-save-group") || "";
        const section = saveGroupBtn.closest(".proj-menu-section");
        const select = section?.querySelector("[data-menu-group-select]");
        const grouping = select?.value || "";
        if (!project) return;
        section?.querySelector(".menu-error")?.remove();
        saveGroupBtn.setAttribute("disabled", "disabled");
        postApi({ action: "set_project_grouping", project, grouping })
            .then(() => {
                const meta = getProjectMeta(project);
                if (meta) meta.grouping = grouping || null;
                return fetchAndRender(currentParams, true);
            })
            .catch((err) => {
                saveGroupBtn.removeAttribute("disabled");
                const errEl = document.createElement("span");
                errEl.className = "menu-error error";
                errEl.textContent = err.message;
                section?.appendChild(errEl);
            });
        return;
    }

    const genSummaryBtn = e.target.closest("[data-generate-summary]");
    if (genSummaryBtn) {
        closeAllProjectMenus();
        const date = genSummaryBtn.getAttribute("data-generate-summary") || "";
        if (!date || genSummaryBtn.disabled) return;
        genSummaryBtn.disabled = true;
        genSummaryBtn.textContent = "Generating…";
        postApi({ action: "generate_summary", date })
            .then(({ summary }) => {
                if (currentData) {
                    currentData.summaries = currentData.summaries || {};
                    currentData.summaries[date] = summary;
                }
                responseCache.clear();
                renderCurrentView();
                triggerSuggestForDate(date);
            })
            .catch((err) => {
                genSummaryBtn.disabled = false;
                genSummaryBtn.textContent = "Generate day summary";
                let errEl = genSummaryBtn.nextElementSibling;
                if (!errEl || !errEl.classList.contains("summary-error")) {
                    errEl = document.createElement("span");
                    errEl.className = "summary-error error";
                    genSummaryBtn.after(errEl);
                }
                errEl.textContent = `Failed: ${err.message}`;
            });
        return;
    }

    const suggestBtn = e.target.closest("[data-suggest-date]");
    if (suggestBtn) {
        const date = suggestBtn.getAttribute("data-suggest-date") || "";
        if (!date) return;
        triggerSuggestForDate(date);
        return;
    }

    if (!e.target.closest(".proj-menu-dropdown")) {
        closeAllProjectMenus();
    }
});

// ── Init ──────────────────────────────────────────────────────────────────────
if (new URLSearchParams(location.search).get("view") === "config") {
    renderConfigView();
} else {
    currentParams = paramsFromUrl();
    history.replaceState(currentParams, "", buildPageUrl(currentParams));
    fetchAndRender(currentParams);
}
