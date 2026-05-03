<?php
// report_renderer.php
// Serve reports via PHP's built-in web server.
// HTML format: static shell + JS renderer (data fetched async from api.php).
// Other formats: full PHP pipeline rendered server-side.

$format = $_GET['format'] ?? 'html';

define('PROJECT_ROOT', __DIR__);

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
        'timezone'  => $config['timezone'],
        'minSec'    => (int)($config['min_event_seconds_to_show'] ?? 0),
        'projects'  => array_keys($config['projects'] ?? []),
        'today'     => (new DateTimeImmutable('now', $tz))->format('Y-m-d'),
        'yesterday' => (new DateTimeImmutable('yesterday', $tz))->format('Y-m-d'),
    ], JSON_UNESCAPED_UNICODE);

    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Activity Report</title>
<style>
  body { font-family: system-ui, sans-serif; max-width: 900px; margin: 0 auto; padding: 0 1rem 2rem; line-height: 1.6; }
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
</style>
</head>
<body>
<nav id="nav"></nav>
<div id="diff-banner"></div>
<main id="report"><p class="loading">Loading&hellip;</p></main>
<script>
const SITE = <?= $jsConfig ?>;

// ── State ─────────────────────────────────────────────────────────────────────
let currentParams = null;
let currentData   = null;
let currentBadge  = 'live';

// ── Date helpers ──────────────────────────────────────────────────────────────
function addDays(dateStr, n) {
    const [y, m, d] = dateStr.split('-').map(Number);
    return new Date(y, m - 1, d + n).toLocaleDateString('sv'); // sv = YYYY-MM-DD
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
    if (params.project) u.set('project', params.project);
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
    const secStr = sec ? ` <span class="dur">&mdash; ${fmtDur(sec)}</span>` : '';
    const body   = renderDetail(rec.detail || {}) + renderCommits(commits);
    return `<${tag}>${esc(name)}${secStr}</${tag}>${body ? `<ul>${body}</ul>` : ''}`;
}

function renderDay(date, projects) {
    const entries  = Object.entries(projects).sort(([, a], [, b]) => (b.seconds||0) - (a.seconds||0));
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

function renderReport(data) {
    const days = Object.keys(data.days || {}).sort().reverse();
    if (!days.length) return '<p><em>No activity recorded for this period.</em></p>';
    return days.map(date => renderDay(date, data.days[date])).join('\n');
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
function renderNav(params, from, to) {
    const rangeDays = Math.max(1, Math.round((new Date(`${to}T12:00:00`) - new Date(`${from}T12:00:00`)) / 86400000) + 1);
    const prevFrom  = addDays(from, -rangeDays),  prevTo  = addDays(from, -1);
    const nextFrom  = addDays(to,    1),           nextTo  = addDays(to,  rangeDays);
    const isFuture  = nextFrom > SITE.today;

    const prevUrl   = buildPageUrl({ ...params, from: prevFrom, to: prevTo,   days: null });
    const nextUrl   = buildPageUrl({ ...params, from: nextFrom, to: nextTo,   days: null });
    const todayUrl  = buildPageUrl({ ...params, from: SITE.today,     to: SITE.today,     days: null });
    const yestUrl   = buildPageUrl({ ...params, from: SITE.yesterday, to: SITE.yesterday, days: null });
    const w7Url     = buildPageUrl({ ...params, from: null, to: null, days: 7  });
    const w30Url    = buildPageUrl({ ...params, from: null, to: null, days: 30 });

    const badge     = `<span class="badge badge--${currentBadge}">${currentBadge.charAt(0).toUpperCase() + currentBadge.slice(1)}</span>`;
    const nextBtn   = isFuture
        ? '<span class="btn disabled">Next &rsaquo;</span>'
        : `<a class="btn" data-nav href="${esc(nextUrl)}">Next &rsaquo;</a>`;
    const rebuildLabel = currentBadge === 'cached' ? 'Rebuild from source' : currentBadge === 'rebuilt' ? 'Rebuild again' : 'Refresh';

    const projOpts  = ['', ...SITE.projects].map(p =>
        `<option value="${esc(p)}"${p === (params.project||'') ? ' selected' : ''}>${p ? esc(p) : 'All projects'}</option>`
    ).join('');

    // Raw-format links point at the current resolved range via report_renderer.php.
    const rawBase = new URLSearchParams({ from, to, ...(params.project ? { project: params.project } : {}) });

    return `
      ${badge}
      <a class="btn" data-nav href="${esc(prevUrl)}">&lsaquo; Prev</a>
      ${nextBtn}
      <span class="sep">|</span>
      <a class="btn" data-nav href="${esc(todayUrl)}">Today</a>
      <a class="btn" data-nav href="${esc(yestUrl)}">Yesterday</a>
      <a class="btn" data-nav href="${esc(w7Url)}">7 days</a>
      <a class="btn" data-nav href="${esc(w30Url)}">30 days</a>
      <span class="sep">|</span>
      <select data-project-select>
        ${projOpts}
      </select>
      <span class="sep">|</span>
      <a class="btn" href="?${rawBase}&format=md">md</a>
      <a class="btn" href="?${rawBase}&format=json">json</a>
      <a class="btn" href="?${rawBase}&format=tsv">tsv</a>
      <span class="sep">|</span>
      <a class="btn" data-rebuild href="#">${esc(rebuildLabel)}</a>
    `;
}

// ── Data loading ──────────────────────────────────────────────────────────────
async function fetchAndRender(params, isRebuild = false) {
    const elReport = document.getElementById('report');
    const elNav    = document.getElementById('nav');
    const elBanner = document.getElementById('diff-banner');

    elReport.innerHTML = '<p class="loading">Loading&hellip;</p>';
    elBanner.innerHTML = '';

    const prevData = currentData;

    try {
        const res  = await fetch(buildApiUrl(params, isRebuild));
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        if (data.error) throw new Error(data.error);

        currentData  = data;
        currentBadge = isRebuild ? 'rebuilt' : (res.headers.get('X-Report-Source') === 'cached' ? 'cached' : 'live');

        // Use the resolved from/to from the response for nav date arithmetic.
        const from = data.from;
        const to   = data.to;

        elNav.innerHTML    = renderNav(params, from, to);
        elReport.innerHTML = renderReport(data);

        if (isRebuild) {
            elBanner.innerHTML = renderDiffBanner(computeDiff(prevData, data), prevData !== null);
        }

        bindNavEvents();
    } catch (err) {
        elReport.innerHTML = `<p class="error">Failed to load report: ${esc(err.message)}</p>`;
    }
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

    const sel = nav.querySelector('[data-project-select]');
    if (sel) {
        sel.addEventListener('change', () => {
            navigateTo({ project: sel.value, from: currentData?.from, to: currentData?.to, days: null });
        });
    }

    const rebuildBtn = nav.querySelector('[data-rebuild]');
    if (rebuildBtn) {
        rebuildBtn.addEventListener('click', e => {
            e.preventDefault();
            fetchAndRender(currentParams, true);
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
$slug = $opts['project'] !== null ? '--' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $opts['project']) : '';

$cached    = (!$rebuild && rangeIsHistorical($to, $tz)) ? loadCachedSources($dir, $key) : null;
$fromCache = $cached !== null;

if ($fromCache) {
    ['events' => $events, 'chrome' => $chrome, 'commits' => $commits] = $cached;
} else {
    $events  = loadActivityWatch($config, $from, $to);
    $chrome  = loadChromeHistory($config, $from, $to);
    $commits = loadGitCommits($config, $from, $to);
    backfillChromeUrls($events, $chrome, (int)$config['chrome_correlation_window_seconds']);
}

[$bucket, $unmatched] = classifyAndAggregate($events, $commits, $config, $tz, $opts);

$out = match ($opts['format']) {
    'json' => renderJson($bucket, $unmatched, $from, $to, $tz),
    'tsv'  => renderTsv($bucket, $from, $to, $tz),
    default => renderMarkdown($bucket, $unmatched, $from, $to, $tz, $opts, $config),
};

if (!$fromCache) {
    saveCachedSources($dir, $key, $from, $to, $events, $chrome, $commits);
}
saveGeneratedReport($dir, $key, $from, $to, $opts['format'], $opts['project'], $fromCache, $out);
if ($opts['format'] === 'md') {
    $jsonOut = renderJson($bucket, $unmatched, $from, $to, $tz);
    saveGeneratedReport($dir, $key, $from, $to, 'json', $opts['project'], $fromCache, $jsonOut);
}

echo $out;
