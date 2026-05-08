<?php
// report_renderer.php
// Serve reports via PHP's built-in web server.
// HTML format: static shell + JS renderer (data fetched async from api.php).
// Other formats: full PHP pipeline rendered server-side.


$format = $_GET['format'] ?? 'html';

define('PROJECT_ROOT', dirname(__DIR__));

$configFile = PROJECT_ROOT . '/config.json';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo $format === 'html' ? '<p>error: config.json not found.</p>' : 'error: config.json not found.';
    exit(1);
}
$config = json_decode(file_get_contents($configFile), true);
if (!is_array($config)) {
    http_response_code(500);
    echo $format === 'html' ? '<p>error: config.json is not valid JSON.</p>' : 'error: config.json is not valid JSON.';
    exit(1);
}

// ── HTML: serve JS shell, all rendering is client-side ────────────────────────
if ($format === 'html') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-cache');

    $tz        = new DateTimeZone($config['timezone']);
    $jsConfig  = json_encode([
        'timezone'          => $config['timezone'],
        'minSec'            => (int)($config['min_event_seconds_to_show'] ?? 0),
        'projects'          => array_map(
            fn($name, $p) => ['name' => $name, 'grouping' => $p['grouping'] ?? null],
            array_keys($config['projects'] ?? []),
            array_values($config['projects'] ?? [])
        ),
        'today'             => (new DateTimeImmutable('now', $tz))->format('Y-m-d'),
        'yesterday'         => (new DateTimeImmutable('yesterday', $tz))->format('Y-m-d'),
        'harvestConfigured' => !empty($config['integrations']['harvest']),
    ], JSON_UNESCAPED_UNICODE);

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activity Report</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 1160px; margin: 0 auto; padding: 0 1rem 2rem; line-height: 1.6; }
  #content-wrap { display: flex; gap: 2rem; align-items: flex-start; }
  #report { flex: 1 1 0; min-width: 0; }
  #harvest-sidebar { flex: 0 0 190px; position: sticky; top: 3.8rem; max-height: calc(100vh - 4.5rem); overflow-y: auto; font-size: 0.82rem; }
  .harvest-sidebar-title { margin: 0 0 0.6rem; font-size: 0.9rem; color: #444; border-bottom: 1px solid #ddd; padding-bottom: 0.3rem; }
  .harvest-day { margin-bottom: 0.9rem; }
  .harvest-day-date { font-weight: 600; color: #333; }
  .harvest-day-total { font-size: 1rem; color: #111; }
  .harvest-entries { margin: 0.2rem 0 0; padding-left: 0; list-style: none; color: #555; }
  .harvest-entries li { margin: 0.15rem 0; }
  .harvest-day-zero { color: #999; font-style: italic; }
  .warnings-banner { margin: 0.6rem 0; padding: 0.6rem 1rem; border-radius: 6px;
                     font-size: 0.85rem; background: #fff7ed; border: 1px solid #fed7aa; color: #7c2d12; }
  .warnings-banner ul { margin: 0.3rem 0 0; padding-left: 1.5em; }
  h1 { margin-top: 0.5em; }
  h2, h3, h4 { margin-top: 1.5em; }
  h2 { border-bottom: 1px solid #ddd; padding-bottom: 0.3em; }
  code { background: #f0f0f0; padding: 0.1em 0.3em; border-radius: 3px; font-size: 0.9em; }
  ul { padding-left: 1.5em; }
  .dur { color: #555; }
  .dow { color: #888; }
  em  { color: #555; }
  .loading { opacity: 0.5; font-style: italic; }
  .error   { color: #b91c1c; }
  nav { position: sticky; top: 0; background: #fff; border-bottom: 1px solid #ddd;
        padding: 0.5rem 0; display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; z-index: 10; min-height: 2.2rem; }
  .btn { display: inline-block; padding: 0.25rem 0.6rem; border: 1px solid #bbb;
         border-radius: 4px; text-decoration: none; color: inherit; font-size: 0.85rem;
         background: #f8f8f8; white-space: nowrap; cursor: pointer; }
  .btn:hover { background: #e8e8e8; }
  .btn.disabled { color: #aaa; border-color: #ddd; pointer-events: none; }
  .sep { color: #ccc; }
  select { padding: 0.25rem 0.4rem; border: 1px solid #bbb; border-radius: 4px;
           font-size: 0.85rem; background: #f8f8f8; }
    .date-form { display: inline-flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    .date-form input[type="date"] { padding: 0.22rem 0.35rem; border: 1px solid #bbb; border-radius: 4px;
                                                                        font-size: 0.85rem; background: #f8f8f8; }
    .date-form .hint { font-size: 0.75rem; color: #666; }
    .date-form .date-range { display: inline-flex; align-items: center; gap: 0.4rem; }
    .date-form .is-hidden { display: none; }
  .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px;
           font-size: 0.75rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
  .badge--live    { background: #d1fae5; color: #065f46; }
  .badge--cached  { background: #fef3c7; color: #92400e; }
  .badge--rebuilt { background: #dbeafe; color: #1e40af; }
  .diff-banner { margin: 1rem 0; padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.9rem; }
  .diff-banner ul { margin: 0.4rem 0 0; padding-left: 1.5em; }
  .diff-banner--info    { background: #f0f9ff; border: 1px solid #bae6fd; color: #0c4a6e; }
  .diff-banner--clean   { background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d; }
  .diff-banner--changes { background: #fffbeb; border: 1px solid #fde68a; color: #78350f; }
    .admin-panel { margin: 0.9rem 0; padding: 0.75rem 1rem; border-radius: 6px; border: 1px solid #e5e7eb; background: #fafafa; }
    .admin-panel h3 { margin: 0 0 0.4rem; font-size: 1rem; }
    .admin-panel h4 { margin: 0.6rem 0 0.4rem; font-size: 0.92rem; color: #374151; }
    .admin-panel p { margin: 0.2rem 0 0.5rem; color: #555; }
    .admin-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem; margin: 0.25rem 0; }
    .admin-row .sig { min-width: 220px; }
    .muted { color: #666; font-size: 0.85rem; }
    .proj-actions { margin-left: 0.6rem; font-size: 0.82rem; }
    .proj-actions a { color: #2563eb; text-decoration: none; }
    .proj-actions a:hover { text-decoration: underline; }
</style>
</head>
<body>
<nav id="nav"></nav>
<div id="warnings-banner"></div>
<div id="diff-banner"></div>
<div id="admin"></div>
<div id="content-wrap">
<main id="report"><p class="loading">Loading&hellip;</p></main>
<aside id="harvest-sidebar"></aside>
</div>
<script>
const SITE = <?= $jsConfig ?>;

// ── State ─────────────────────────────────────────────────────────────────────
let currentParams = null;
let currentData   = null;
let currentBadge  = 'live';
let currentCacheAgeSec = 0;
let personalProjectQueue = new Set();
let showAdminPanel = false;

// ── Date helpers ──────────────────────────────────────────────────────────────
function addDays(dateStr, n) {
    // Slice to 10 chars so ISO datetimes like "2026-05-01T00:00:00-04:00" work too.
    const [y, m, d] = String(dateStr).slice(0, 10).split('-').map(Number);
    const dt = new Date(y, m - 1, d + n);
    return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`;
}

function paramsFromUrl() {
    const p    = new URLSearchParams(location.search);
    const days = p.has('days') ? parseInt(p.get('days'), 10) : null;
    return {
        from:    p.get('from')    || (days ? null : SITE.today),
        to:      p.get('to')      || (days ? null : SITE.today),
        days,
        project: p.get('project') || '',
    };
}

function buildApiUrl(params, rebuild = false) {
    const u = new URLSearchParams();
    if (params.from)    u.set('from',    params.from);
    if (params.to)      u.set('to',      params.to);
    if (params.days)    u.set('days',    String(params.days));
    if (rebuild)        u.set('rebuild', '1');
    return 'api.php?' + u;
}

function buildPageUrl(params) {
    const u = new URLSearchParams();
    if (params.from)    u.set('from',    params.from);
    if (params.to)      u.set('to',      params.to);
    if (params.days)    u.set('days',    String(params.days));
    if (params.project) u.set('project', params.project);
    u.set('format', 'html');
    return '?' + u;
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function esc(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Mirrors PHP fmtDur: 3661→"1h 01m", 90→"1m", 18→"18s"
function fmtDur(sec) {
    const m = Math.floor(sec / 60);
    if (m >= 60) return `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`;
    if (m >= 1)  return `${m}m`;
    return `${Math.floor(sec)}s`;
}

function fmtAge(sec) {
    const s = Math.max(0, Math.floor(Number(sec) || 0));
    if (s < 60) return `${s}s`;
    const m = Math.floor(s / 60);
    if (m < 60) return `${m}m`;
    const h = Math.floor(m / 60);
    if (h < 24) return `${h}h ${String(m % 60).padStart(2, '0')}m`;
    const d = Math.floor(h / 24);
    return `${d}d ${String(h % 24).padStart(2, '0')}h`;
}

// ── Renderers ─────────────────────────────────────────────────────────────────
function renderCommits(commits) {
    if (!commits.length) return '';
    const items = commits.map(c => {
        const t = new Date(c.time).toLocaleTimeString('en-US', {
            hour: '2-digit', minute: '2-digit', hour12: false, timeZone: SITE.timezone,
        });
        return `<li><code>${esc(t)}</code> <code>${esc(c.sha.slice(0, 8))}</code> ${esc(c.subj)}</li>`;
    }).join('');
    return `<li><em>commits (${commits.length}):</em><ul>${items}</ul></li>`;
}

function renderDetail(detail) {
    return Object.entries(detail).map(([kind, items]) => {
        const top = Object.entries(items)
            .filter(([, s]) => s >= SITE.minSec)
            .sort(([, a], [, b]) => b - a)
            .slice(0, 6);
        if (!top.length) return '';
        return `<li><em>${esc(kind)}:</em> ${top.map(([k, s]) => `${esc(k)} (${fmtDur(s)})`).join(', ')}</li>`;
    }).join('');
}

function renderProject(tag, name, rec) {
    const sec     = rec.seconds || 0;
    const commits = rec.commits || [];
    if (sec < SITE.minSec && !commits.length) return '';

    const isQueuedPersonal = personalProjectQueue.has(name);
    const action = isQueuedPersonal
        ? '<span class="proj-actions muted">queued for personal</span>'
        : `<span class="proj-actions"><a href="#" data-flag-project="${esc(name)}">flag project as personal</a></span>`;

    const secStr = sec ? ` <span class="dur">&mdash; ${fmtDur(sec)}</span>` : '';
    const body   = renderDetail(rec.detail || {}) + renderCommits(commits);
    return `<${tag}>${esc(name)}${secStr}${action}</${tag}>${body ? `<ul>${body}</ul>` : ''}`;
}

function filterProjectsForView(projects, projectFilter) {
    if (!projectFilter) return Object.entries(projects || {});
    if (projectFilter.startsWith('group:')) {
        const grouping = projectFilter.slice(6);
        return Object.entries(projects || {}).filter(([, rec]) => (rec?.grouping || null) === grouping);
    }
    return Object.entries(projects || {}).filter(([name]) => name === projectFilter);
}

function renderDay(date, projects, projectFilter) {
    const entries  = filterProjectsForView(projects, projectFilter).sort(([, a], [, b]) => (b.seconds||0) - (a.seconds||0));
    if (!entries.length) return '';
    const dayTotal = entries.reduce((s, [, r]) => s + (r.seconds||0), 0);
    const dow      = new Date(`${date}T12:00:00`).toLocaleDateString('en-US', { weekday: 'short' });

    const grouped   = {};
    const ungrouped = [];
    for (const [name, rec] of entries) {
        rec.grouping ? (grouped[rec.grouping] ??= []).push([name, rec]) : ungrouped.push([name, rec]);
    }

    let html = `<h2>${esc(date)} <span class="dow">(${dow})</span> <span class="dur">&mdash; ${fmtDur(dayTotal)} active</span></h2>`;

    const groupTotals = Object.entries(grouped)
        .map(([g, ps]) => [g, ps.reduce((s, [, r]) => s + (r.seconds||0), 0)])
        .sort(([, a], [, b]) => b - a);

    for (const [g, gSec] of groupTotals) {
        const content = grouped[g].map(([n, r]) => renderProject('h4', n, r)).join('');
        if (content.trim()) {
            const gSecStr = gSec ? ` <span class="dur">&mdash; ${fmtDur(gSec)}</span>` : '';
            html += `<h3>${esc(g)}${gSecStr}</h3>${content}`;
        }
    }

    for (const [name, rec] of ungrouped) html += renderProject('h3', name, rec);
    return html;
}

function renderReport(data, projectFilter = '') {
    const days = Object.keys(data.days || {}).sort().reverse();
    if (!days.length) return '<p><em>No activity recorded for this period.</em></p>';

    const blocks = days
        .map(date => renderDay(date, data.days[date], projectFilter))
        .filter(Boolean);

    if (!blocks.length) return '<p><em>No activity recorded for this filter in this period.</em></p>';
    return blocks.join('\n');
}

function projectOptions(selected = '', kind = '') {
    const opts = SITE.projects
        .map(p => `<option value="${esc(p.name)}"${p.name === selected ? ' selected' : ''}>${esc(p.name)}</option>`)
        .join('');

    const personalOpt = (kind === 'browser' || kind === 'apps')
        ? `<option value="__personal__"${selected === '__personal__' ? ' selected' : ''}>Personal</option>`
        : '';

    return `<option value="">Select project</option>${personalOpt}<option value="__new__">+ New project...</option>${opts}`;
}

function projectOptionsSimple(selected = '') {
    return SITE.projects
        .map(p => `<option value="${esc(p.name)}"${p.name === selected ? ' selected' : ''}>${esc(p.name)}</option>`)
        .join('');
}

function getProjectMeta(name) {
    return SITE.projects.find(p => p.name === name) || null;
}

function renderAdminPanel(data) {
    const elAdmin = document.getElementById('admin');
    if (!elAdmin) return;

    if (!showAdminPanel) {
        elAdmin.innerHTML = '';
        return;
    }

    const queue = Array.from(personalProjectQueue.values()).sort();
    const queueHtml = queue.length
        ? `<div class="admin-row"><span>${queue.map(esc).join(', ')}</span><button class="btn" data-apply-personal>Apply personal flags</button></div>`
        : '<p class="muted">No projects queued for personal-ignore.</p>';

    const unmatched = data?.unmatched || {};
    const firstProject = SITE.projects[0]?.name || '';
    const kinds = ['vscode', 'browser', 'slack', 'apps'];
    const rows = [];
    for (const kind of kinds) {
        const items = Object.entries(unmatched[kind] || {})
            .sort(([, a], [, b]) => b - a)
            .slice(0, 12);
        if (!items.length) continue;

        const sectionRows = items.map(([value, count]) => {
            const encodedValue = encodeURIComponent(value);
            return `<div class="admin-row">
              <span class="sig"><code>${esc(value)}</code> <span class="muted">(${count})</span></span>
                            <select data-reassign-project>${projectOptions('', kind)}</select>
              <button class="btn" data-reassign data-kind="${esc(kind)}" data-value="${encodedValue}">Assign</button>
            </div>`;
        }).join('');
        rows.push(`<h4>${esc(kind)}</h4>${sectionRows}`);
    }

    const unmatchedHtml = rows.length
        ? rows.join('')
        : '<p class="muted">No unmatched signals in this range.</p>';

        elAdmin.innerHTML = `
      <section class="admin-panel">
                <h3>Config Actions</h3>
                <p>Queue projects to ignore as personal, assign unmatched signals, and manage project groupings in config.json.</p>
        <h4>Queued Personal Flags</h4>
        ${queueHtml}
                <h4>Project Grouping</h4>
                <div class="admin-row">
                    <select data-group-project>${projectOptionsSimple(firstProject)}</select>
                    <input type="text" data-group-name placeholder="Group name (blank to clear)">
                    <button class="btn" data-save-group>Save grouping</button>
                </div>
                <p class="muted">Set any new group name to create it automatically.</p>
        <h4>Reassign Unmatched Signals</h4>
        ${unmatchedHtml}
      </section>
    `;

    bindAdminEvents();
}

async function postApi(payload) {
    const res = await fetch('api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
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

function renderHarvestSidebar(data) {
    const el = document.getElementById('harvest-sidebar');
    if (!el) return;

    if (!SITE.harvestConfigured) {
        el.innerHTML = '';
        return;
    }

    const days = Object.keys(data.days || {}).sort().reverse();
    if (!days.length) {
        el.innerHTML = '';
        return;
    }

    let html = '<p class="harvest-sidebar-title">Harvest logged</p>';
    for (const date of days) {
        const entryMap = {};
        for (const rec of Object.values(data.days[date] || {})) {
            for (const [label, sec] of Object.entries(rec.detail?.harvest || {})) {
                entryMap[label] = (entryMap[label] || 0) + sec;
            }
        }
        const totalSec = Object.values(entryMap).reduce((s, v) => s + v, 0);
        const dow = new Date(`${date}T12:00:00`).toLocaleDateString('en-US', { weekday: 'short' });
        const entries = Object.entries(entryMap).sort(([, a], [, b]) => b - a);

        html += `<div class="harvest-day">`;
        html += `<div class="harvest-day-date">${esc(date)} <span class="dow">(${dow})</span></div>`;
        if (totalSec > 0) {
            html += `<div class="harvest-day-total">${fmtDur(totalSec)}</div>`;
            html += `<ul class="harvest-entries">`;
            for (const [label, sec] of entries) {
                html += `<li>${esc(label)}: <span class="dur">${fmtDur(sec)}</span></li>`;
            }
            html += `</ul>`;
        } else {
            html += `<div class="harvest-day-total harvest-day-zero">0m</div>`;
        }
        html += `</div>`;
    }
    el.innerHTML = html;
}

function renderWarningsBanner(data) {
    const el = document.getElementById('warnings-banner');
    if (!el) return;
    const warnings = data?.warnings || [];
    if (!warnings.length) {
        el.innerHTML = '';
        return;
    }
    const items = warnings.map(w => `<li>${esc(w)}</li>`).join('');
    el.innerHTML = `<div class="warnings-banner"><strong>Data source warnings:</strong><ul>${items}</ul></div>`;
}

function renderCurrentView() {
    if (!currentData) return;
    const elReport = document.getElementById('report');
    const elNav = document.getElementById('nav');

    elNav.innerHTML = renderNav(currentParams || {}, currentData.from, currentData.to);
    elReport.innerHTML = renderReport(currentData, currentParams?.project || '');
    bindNavEvents();
    renderAdminPanel(currentData);
    renderHarvestSidebar(currentData);
    renderWarningsBanner(currentData);
}

// ── Diff ──────────────────────────────────────────────────────────────────────
function computeDiff(oldData, newData) {
    if (!oldData?.days) return [];
    const changes = [];
    for (const [date, projects] of Object.entries(newData.days || {})) {
        for (const [name, rec] of Object.entries(projects)) {
            const oldRec     = oldData.days[date]?.[name];
            const oldShas    = new Set((oldRec?.commits || []).map(c => c.sha));
            const newCommits = rec.commits || [];
            const addedCmts  = newCommits.filter(c => !oldShas.has(c.sha));
            const secDiff    = (rec.seconds||0) - (oldRec?.seconds||0);
            if (!oldRec && ((rec.seconds||0) > 0 || newCommits.length)) {
                changes.push({ type: 'new_project', date, project: name, seconds: rec.seconds||0, commits: newCommits.length });
            } else if (oldRec && (secDiff > 0 || addedCmts.length)) {
                changes.push({ type: 'updated', date, project: name, addedSeconds: secDiff, addedCommits: addedCmts.length });
            }
        }
    }
    return changes;
}

function renderDiffBanner(changes, hadPrior) {
    if (!hadPrior) return `<div class="diff-banner diff-banner--info">Rebuilt &mdash; no prior snapshot to compare against.</div>`;
    if (!changes.length) return `<div class="diff-banner diff-banner--clean">&#10003; Rebuilt &mdash; no changes found.</div>`;
    const items = changes.map(c => {
        const p = esc(c.project);
        if (c.type === 'new_project') {
            const s  = c.seconds > 0  ? ` &mdash; ${fmtDur(c.seconds)}` : '';
            const cm = c.commits > 0  ? `, ${c.commits} commit(s)` : '';
            return `<li><strong>${c.date}</strong>: new project <em>${p}</em>${s}${cm}</li>`;
        }
        const s  = c.addedSeconds > 0  ? ` +${fmtDur(c.addedSeconds)}` : '';
        const cm = c.addedCommits > 0  ? `, +${c.addedCommits} commit(s)` : '';
        return `<li><strong>${c.date}</strong>: updated <em>${p}</em>${s}${cm}</li>`;
    }).join('');
    return `<div class="diff-banner diff-banner--changes"><strong>Rebuilt &mdash; ${changes.length} change(s):</strong><ul>${items}</ul></div>`;
}

// ── Nav ───────────────────────────────────────────────────────────────────────
function renderNav(params, fromRaw, toRaw) {
    // data.from / data.to are full ISO strings ("2026-05-01T00:00:00-04:00"); strip the time part.
    const from = String(fromRaw).slice(0, 10);
    const to   = String(toRaw).slice(0, 10);
    const start = from <= to ? from : to;
    const end = from <= to ? to : from;
    const rangeDays = Math.max(1, Math.round((new Date(`${end}T12:00:00`) - new Date(`${start}T12:00:00`)) / 86400000) + 1);
    const prevFrom  = addDays(start, -rangeDays), prevTo = addDays(start, -1);
    const nextFrom  = addDays(end, 1),            nextTo = addDays(end, rangeDays);
    const isFuture  = nextFrom > SITE.today;
    const hasRange = start !== end;
    const addRangeClass = hasRange ? 'is-hidden' : '';
    const rangeClass = hasRange ? '' : 'is-hidden';
    const removeRangeClass = hasRange ? '' : 'is-hidden';

    const prevUrl   = buildPageUrl({ ...params, from: prevFrom, to: prevTo,   days: null });
    const nextUrl   = buildPageUrl({ ...params, from: nextFrom, to: nextTo,   days: null });
    const nextBtn   = isFuture
        ? '<span class="btn disabled">Next &rsaquo;</span>'
        : `<a class="btn" data-nav href="${esc(nextUrl)}">Next &rsaquo;</a>`;
    let rebuildLabel = 'Refresh';
    if (currentBadge === 'cached') {
        rebuildLabel = `Rebuild from source (cached ${fmtAge(currentCacheAgeSec)} ago)`;
    } else if (currentBadge === 'rebuilt') {
        rebuildLabel = 'Rebuild again';
    }
    const configLabel = showAdminPanel ? 'Hide Config' : 'Config';

    const cur = params.project || '';
    const sel = v => v === cur ? ' selected' : '';

    // Bucket projects by grouping, preserving config order.
    const groups    = {};
    const ungrouped = [];
    for (const p of SITE.projects) {
        p.grouping ? (groups[p.grouping] ??= []).push(p.name) : ungrouped.push(p.name);
    }

    let projOpts = `<option value=""${sel('')}>All projects</option>`;
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
                <a href="#" class="btn ${addRangeClass}" data-add-range>Add end date</a>
                <span class="date-range ${rangeClass}" data-date-range>
                    <span>&rarr;</span>
                    <input type="date" data-date-to value="${esc(hasRange ? end : '')}" aria-label="End date">
                    <a href="#" class="btn ${removeRangeClass}" data-remove-range>Single day</a>
                </span>
                <button class="btn" type="submit">Apply</button>
            </form>
            <span class="sep">|</span>
      <select data-project-select>
        ${projOpts}
      </select>
      <span class="sep">|</span>
            <a class="btn" data-toggle-admin href="#">${esc(configLabel)}</a>
            <span class="sep">|</span>
      <a class="btn" data-rebuild href="#">${esc(rebuildLabel)}</a>
    `;
}

// ── Data loading ──────────────────────────────────────────────────────────────
async function fetchAndRender(params, isRebuild = false) {
    const elReport = document.getElementById('report');
    const elNav    = document.getElementById('nav');
    const elBanner = document.getElementById('diff-banner');
    const elAdmin = document.getElementById('admin');

    elReport.innerHTML = '<p class="loading">Loading&hellip;</p>';
    elBanner.innerHTML = '';
    if (elAdmin) elAdmin.innerHTML = '';
    const elSidebar = document.getElementById('harvest-sidebar');
    if (elSidebar) elSidebar.innerHTML = '';
    const elWarnings = document.getElementById('warnings-banner');
    if (elWarnings) elWarnings.innerHTML = '';

    const prevData = currentData;

    try {
        const res  = await fetch(buildApiUrl(params, isRebuild));
        const text = await res.text();
        let data;
        try {
            data = JSON.parse(text);
        } catch {
            // PHP returned an HTML error page — strip tags for a readable message.
            const plain = text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400);
            throw new Error(plain || `HTTP ${res.status}`);
        }
        if (!res.ok || data.error) throw new Error(data.error ?? `HTTP ${res.status}`);

        currentData  = data;
        currentBadge = isRebuild ? 'rebuilt' : (res.headers.get('X-Report-Source') === 'cached' ? 'cached' : 'live');
        currentCacheAgeSec = Number(res.headers.get('X-Report-Age-Seconds') || 0);

        renderCurrentView();

        if (isRebuild) {
            elBanner.innerHTML = renderDiffBanner(computeDiff(prevData, data), prevData !== null);
        }
    } catch (err) {
        elReport.innerHTML = `<p class="error">Failed to load report: ${esc(err.message)}</p>`;
        if (elAdmin) elAdmin.innerHTML = '';
    }
}

function bindAdminEvents() {
    document.querySelectorAll('[data-flag-project]').forEach(el => {
        el.addEventListener('click', e => {
            e.preventDefault();
            const name = el.getAttribute('data-flag-project') || '';
            if (!name) return;
            personalProjectQueue.add(name);
            renderCurrentView();
        });
    });

    const applyBtn = document.querySelector('[data-apply-personal]');
    if (applyBtn) {
        applyBtn.addEventListener('click', async e => {
            e.preventDefault();
            const projects = Array.from(personalProjectQueue.values());
            if (!projects.length) return;
            applyBtn.setAttribute('disabled', 'disabled');
            try {
                await postApi({ action: 'flag_projects_personal', projects });
                personalProjectQueue = new Set();
                await fetchAndRender(currentParams, true);
            } catch (err) {
                alert(`Failed to apply personal flags: ${err.message}`);
            } finally {
                applyBtn.removeAttribute('disabled');
            }
        });
    }

    document.querySelectorAll('[data-reassign]').forEach(btn => {
        btn.addEventListener('click', async e => {
            e.preventDefault();
            const row = btn.closest('.admin-row');
            const select = row?.querySelector('[data-reassign-project]');
            let project = select?.value || '';
            if (!project) {
                alert('Select a project first.');
                return;
            }

            let newProjectName = '';
            if (project === '__new__') {
                const entered = prompt('New project name:');
                if (!entered || !entered.trim()) {
                    return;
                }
                newProjectName = entered.trim();
                project = newProjectName;
            }

            const kind = btn.getAttribute('data-kind') || '';
            const rawValue = btn.getAttribute('data-value') || '';
            const value = decodeURIComponent(rawValue);
            btn.setAttribute('disabled', 'disabled');
            try {
                await postApi({ action: 'reassign_signal', project, kind, value, new_project_name: newProjectName });
                await fetchAndRender(currentParams, true);
            } catch (err) {
                alert(`Failed to reassign signal: ${err.message}`);
            } finally {
                btn.removeAttribute('disabled');
            }
        });
    });

    const groupProjectSel = document.querySelector('[data-group-project]');
    const groupNameInput = document.querySelector('[data-group-name]');
    const saveGroupBtn = document.querySelector('[data-save-group]');

    const syncGroupingField = () => {
        const projectName = groupProjectSel?.value || '';
        const meta = getProjectMeta(projectName);
        if (groupNameInput) {
            groupNameInput.value = meta?.grouping || '';
        }
    };

    groupProjectSel?.addEventListener('change', syncGroupingField);
    syncGroupingField();

    saveGroupBtn?.addEventListener('click', async e => {
        e.preventDefault();
        const project = groupProjectSel?.value || '';
        const grouping = (groupNameInput?.value || '').trim();
        if (!project) {
            alert('Select a project first.');
            return;
        }

        saveGroupBtn.setAttribute('disabled', 'disabled');
        try {
            await postApi({ action: 'set_project_grouping', project, grouping });
            const meta = getProjectMeta(project);
            if (meta) {
                meta.grouping = grouping || null;
            }
            await fetchAndRender(currentParams, true);
        } catch (err) {
            alert(`Failed to save grouping: ${err.message}`);
        } finally {
            saveGroupBtn.removeAttribute('disabled');
        }
    });
}

// ── Navigation ────────────────────────────────────────────────────────────────
function navigateTo(overrides, isRebuild = false) {
    const next = { ...currentParams, ...overrides };
    // Explicit dates take precedence over days shorthand and vice-versa.
    if (next.from || next.to) { delete next.days; } else if (next.days) { delete next.from; delete next.to; }
    currentParams = next;
    history.pushState(next, '', buildPageUrl(next));
    fetchAndRender(next, isRebuild);
}

function bindNavEvents() {
    const nav = document.getElementById('nav');

    nav.querySelectorAll('[data-nav]').forEach(el => {
        el.addEventListener('click', e => {
            e.preventDefault();
            const p = Object.fromEntries(new URL(el.href).searchParams);
            delete p.format;
            navigateTo(p);
        });
    });

    const dateForm = nav.querySelector('[data-date-form]');
    if (dateForm) {
        const fromInput = nav.querySelector('[data-date-from]');
        const toInput = nav.querySelector('[data-date-to]');
        const rangeWrap = nav.querySelector('[data-date-range]');
        const addRangeBtn = nav.querySelector('[data-add-range]');
        const removeRangeBtn = nav.querySelector('[data-remove-range]');

        const showRange = () => {
            rangeWrap?.classList.remove('is-hidden');
            addRangeBtn?.classList.add('is-hidden');
            if (toInput && !toInput.value) {
                toInput.value = fromInput?.value || '';
            }
            toInput?.focus();
        };

        const hideRange = () => {
            rangeWrap?.classList.add('is-hidden');
            addRangeBtn?.classList.remove('is-hidden');
            if (toInput) {
                toInput.value = '';
            }
        };

        addRangeBtn?.addEventListener('click', e => {
            e.preventDefault();
            showRange();
        });

        removeRangeBtn?.addEventListener('click', e => {
            e.preventDefault();
            hideRange();
        });

        dateForm.addEventListener('submit', e => {
            e.preventDefault();
            const from = fromInput?.value || '';
            const to = rangeWrap?.classList.contains('is-hidden') ? from : (toInput?.value || from);
            if (!from) return;
            const nextFrom = from <= to ? from : to;
            const nextTo = from <= to ? to : from;
            navigateTo({ from: nextFrom, to: nextTo, days: null });
        });
    }

    const sel = nav.querySelector('[data-project-select]');
    if (sel) {
        sel.addEventListener('change', () => {
            currentParams = { ...currentParams, project: sel.value };
            history.pushState(currentParams, '', buildPageUrl(currentParams));
            renderCurrentView();
        });
    }

    const rebuildBtn = nav.querySelector('[data-rebuild]');
    if (rebuildBtn) {
        rebuildBtn.addEventListener('click', e => {
            e.preventDefault();
            fetchAndRender(currentParams, true);
        });
    }

    const toggleAdminBtn = nav.querySelector('[data-toggle-admin]');
    if (toggleAdminBtn) {
        toggleAdminBtn.addEventListener('click', e => {
            e.preventDefault();
            showAdminPanel = !showAdminPanel;
            renderCurrentView();
        });
    }
}

window.addEventListener('popstate', e => {
    currentParams = e.state || paramsFromUrl();
    fetchAndRender(currentParams);
});

// ── Init ──────────────────────────────────────────────────────────────────────
currentParams = paramsFromUrl();
fetchAndRender(currentParams);
</script>
</body>
</html>
    <?php
    exit;
}

// ── Non-HTML formats: full PHP pipeline ───────────────────────────────────────
if ($format === 'txt' || $format === 'text' || $format === 'md') {
    header('Content-Type: text/plain; charset=UTF-8');
} elseif ($format === 'json') {
    header('Content-Type: application/json; charset=UTF-8');
} elseif ($format === 'tsv') {
    header('Content-Type: text/tab-separated-values; charset=UTF-8');
} else {
    http_response_code(400);
    echo "Unsupported format: $format";
    exit(1);
}
header('Cache-Control: no-cache');

require_once PROJECT_ROOT . '/src/helpers.php';
require_once PROJECT_ROOT . '/src/cache.php';
require_once PROJECT_ROOT . '/src/loader-activitywatch.php';
require_once PROJECT_ROOT . '/src/loader-chrome.php';
require_once PROJECT_ROOT . '/src/loader-git.php';
require_once PROJECT_ROOT . '/src/loader-integrations.php';
require_once PROJECT_ROOT . '/src/classifiers.php';
require_once PROJECT_ROOT . '/src/renderers.php';
require_once PROJECT_ROOT . '/src/cli.php';

$rebuild   = !empty($_GET['rebuild']);
$daysParam = isset($_GET['days']) ? (int)$_GET['days'] : null;
$opts      = [
    'days'           => $daysParam ?? 1,
    'from'           => $_GET['from'] ?? null,
    'to'             => $_GET['to']   ?? null,
    'project'        => $_GET['project'] ?? null,
    'format'         => $format === 'md' ? 'md' : $format,
    'show_unmatched' => !empty($_GET['show_unmatched']),
    'list_projects'  => false,
    'help'           => false,
];

$tz = new DateTimeZone($config['timezone']);
[$from, $to] = resolveDateRange($opts, $tz);

$dir  = reportsDir($from);
$key  = reportsCacheKey($from, $to);

$cached    = (!$rebuild && rangeIsHistorical($to, $tz)) ? loadCachedSources($dir, $key) : null;
$fromCache = $cached !== null;

if ($fromCache) {
    ['events' => $events, 'chrome' => $chrome, 'commits' => $commits, 'external' => $external] = $cached;
} else {
    $events  = loadActivityWatch($config, $from, $to);
    $chrome  = loadChromeHistory($config, $from, $to);
    $commits = loadGitCommits($config, $from, $to);
    $external = loadIntegrationActivity($config, $from, $to);
    backfillChromeUrls($events, $chrome, (int)$config['chrome_correlation_window_seconds']);
}

$fullOpts = $opts;
$fullOpts['project'] = null;
[$fullBucket, $fullUnmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $fullOpts);

$hasProjectFilter = !empty($opts['project']);
if ($hasProjectFilter) {
    [$bucket, $unmatched] = classifyAndAggregate($events, $commits, $external, $config, $tz, $opts);
} else {
    [$bucket, $unmatched] = [$fullBucket, $fullUnmatched];
}

$out = match ($opts['format']) {
    'json' => renderJson($bucket, $unmatched, $from, $to, $tz),
    'tsv'  => renderTsv($bucket, $from, $to, $tz),
    default => renderMarkdown($bucket, $unmatched, $from, $to, $tz, $opts, $config),
};

$fullOut = match ($opts['format']) {
    'json' => renderJson($fullBucket, $fullUnmatched, $from, $to, $tz),
    'tsv'  => renderTsv($fullBucket, $from, $to, $tz),
    default => renderMarkdown($fullBucket, $fullUnmatched, $from, $to, $tz, $fullOpts, $config),
};

if (!$fromCache) {
    saveCachedSources($dir, $key, $from, $to, $events, $chrome, $commits, $external);
}
saveGeneratedReport($dir, $key, $from, $to, $opts['format'], null, $fromCache, $fullOut);
if ($opts['format'] === 'md') {
    $jsonOut = renderJson($fullBucket, $fullUnmatched, $from, $to, $tz);
    saveGeneratedReport($dir, $key, $from, $to, 'json', null, $fromCache, $jsonOut);
}

echo $out;
