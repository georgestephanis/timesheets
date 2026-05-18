# AGENTS.md — activity-report

Context file for AI agents and future contributors. Keep this up to date when the architecture, tooling, or data model changes.

---

## What this project is

A local-first activity reporting tool with **multiple UI surfaces that share a common data store**:

- **PHP CLI** (`apps/cli/activity-report.php`) — the primary, stable interface
- **PHP web UI** (`apps/web/`) — browser-based report viewer served by `php -S`
- **React Native macOS desktop** (`apps/desktop/`) — in progress; built on `packages/engine/`

All surfaces read the same local data, write to the same `reports/` cache directory, and use the same `config.json`. The PHP implementation is the behavior oracle during migration; the TypeScript engine is developed in parallel with compatibility as a hard constraint.

The reporting engine aggregates data from four source categories:

| Source                   | Data                                                                              | Location                                                                                                                |
| ------------------------ | --------------------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| ActivityWatch            | App/window focus events + AFK status + input slices (presses/clicks/mouse/scroll) | `~/Library/Application Support/activitywatch/` (SQLite)                                                                 |
| Chrome history           | Browser visits with URLs and titles                                               | `~/Library/Application Support/Google/Chrome/` (SQLite)                                                                 |
| Git                      | Commits authored by configured email(s)                                           | All repos in `projects[*].repos`, plus any discovered via GitHub Desktop when `discover_repos: "github_desktop"` is set |
| External APIs (optional) | Harvest + ClickUp + Clockify time/activity rows; GitHub commit/PR/issue activity  | HTTPS APIs                                                                                                              |

Events are classified into named **projects** by matching signals (VSCode window title, browser domain, Slack workspace/channel, SSH hostname) against rules in `config.json`. Unmatched events fall into catch-all buckets (`Browser (uncategorized)`, `VSCode (uncategorized)`, etc.).

---

## File map

```
activity-report.php              — root wrapper; delegates to apps/cli/activity-report.php
apps/
  cli/
    activity-report.php          — CLI entry point: defines PROJECT_ROOT, loads config, requires src/, calls main()
  web/
    index.php                    — router for `php -S localhost:8000 apps/web/index.php`
    api.php                      — JSON data endpoint; GET = fetch/rebuild report,
                                   POST = config mutations (flag_projects_personal,
                                   reassign_signal, set_project_grouping)
    report_renderer.php          — HTML shell + static asset references;
                                   non-HTML formats also served here via full PHP pipeline
    static/
      app.css                    — all styles
      app.js                     — client-side report renderer, admin panel, nav
  desktop/                       — @timesheets/desktop: React Native macOS app shell (Phase 1 scaffolding)
src/
  cli.php                        — main(), parseArgs(), printHelp(), printProjects(),
                                   resolveDateRange(), generateReport(),
                                   loadSourcesForRange(config, tz, from, to, rebuild=false),
                                   loadFreshSourceSlice(), backfillRecentDailyReports(),
                                   dailyReportNeedsRefresh(), invokedWithoutOptions()
  helpers.php                    — expandPath(), fnmatchAny(), fmtDur(), copyForRead(), pdo(), chromeTime()
  config.php                     — saveConfigWithBackup(), addUniqueValue(), parseSlackSignal(),
                                   applySignalToProject()  [canonical config I/O; used by api.php, cli.php, all tools]
  cache.php                      — reportsDir(), reportsCacheKey(), rangeIsHistorical(),
                                   rangeDays(), mergeSourceBundles(), filterSourcesToRange(),
                                   loadCachedSources(), saveCachedSources(),
                                   loadDailyCachedSources(), saveDailyCachedSources(),
                                   findLatestReport(), findLatestFullReportGeneratedAt(),
                                   saveGeneratedReport(), appendToIndex(),
                                   serialize/deserialize pairs for events/chrome/commits/external
  loader-activitywatch.php       — loadActivityWatch(), loadAwSqlite()
  loader-chrome.php              — loadChromeHistory(), backfillChromeUrls(), bsearchRight()
  loader-git.php                 — loadGitCommits()  (reads projects[*].repos + optional GitHub Desktop discovery)
  loader-github-desktop.php      — githubDesktopLevelDbPath(), scanLevelDbForPaths(),
                                   discoverGitHubDesktopRepos(withTimestamps=false)
  loader-integrations.php        — loadIntegrationActivity(), integrationWarning(),
                                   getIntegrationWarnings(), backupConfigSnapshot()
  integrations/
    shared.php                   — idLooksStandard(), httpGetJson()
    harvest.php                  — resolveHarvestUserId(), loadHarvestTimeEntries()
    harvest-catalog.php          — harvestFetchProjectNames()
    clickup.php                  — resolveClickUpUserId(), loadClickUpTimeEntries()
    clickup-catalog.php          — clickupFetchAllNames()
    clockify.php                 — resolveClockifyUserInfo(), loadClockifyTimeEntries()
    clockify-catalog.php         — clockifyFetchProjectNames()
    github.php                   — loadGitHubActivity(), github* helpers  [CLI-only; skipped in web]
    llm.php                      — llmGetConnection(), llmResolveModel(), llmPostJson(),
                                   llmSuggestAssignments()  [CLI-only; used by --suggest]
  classifiers.php                — classifyVscode(), classifySlack(), classifySsh(),
                                   projectForSignals(), projectForExternal(),
                                   isAfkAt(), activeInputSecondsDuring(),
                                   classifyAndAggregate()
  renderers.php                  — renderProjectEntry(), renderMarkdown(),
                                   renderJson(bucket, unmatched, from, to, tz, warnings=[], timeline=[]),
                                   renderTsv()
tools/
  list-github-desktop-repos.php  — lists GitHub Desktop repos sorted by last commit;
                                   --apply adds unconfigured ones to config.json with backup
  sync-integration-projects.php  — pulls Harvest/ClickUp catalogs → harvest_projects/clickup_tasks mappings
  set-integration-groupings.php  — assigns grouping field to projects via groupings_map config rules
  cleanup-integration-projects.php — merges high-confidence integration stubs into existing projects
  sync-repo-remotes.php          — snapshots git remote URLs into projects[*].repo_remotes
  ensure-github-integration.php  — adds default integrations.github entry (gh-auth) if absent
packages/                        — TypeScript workspace; shared data layer for non-PHP surfaces (see NATIVE.md)
  contracts/                     — @timesheets/contracts: JS type definitions mirroring PHP JSON output + config schema
  engine/                        — @timesheets/engine: TypeScript engine (same config.json + reports/ layout as PHP)
  ui/                            — @timesheets/ui: React Native macOS components (peerDep)
  test-fixtures/                 — @timesheets/test-fixtures: golden fixtures for PHP–TypeScript parity tests
config.json                      — local config, gitignored, never committed
config.example.json              — safe-to-commit template with dummy data
config.schema.json               — JSON Schema (draft 2020-12) for both config files
phpcs.xml.dist                   — PHP_CodeSniffer ruleset (PSR-12 + CLI exceptions)
composer.json                    — dev dep: squizlabs/php_codesniffer ^3.9
package.json                     — dev dep: prettier ^3.0
NATIVE.md                        — native desktop migration plan and phased roadmap
```

`vendor/` and `node_modules/` are installed locally but not committed. The root `package.json` declares `"workspaces": ["packages/*", "apps/*"]` — run `npm install` from the repo root to link the packages to each other.

---

## Architecture

Logic is split across `src/` includes with no classes. All code is plain functions grouped by concern. `apps/cli/activity-report.php` is a thin entry point that loads config, defines `PROJECT_ROOT`, requires all includes, and calls `main()`.

| File                                    | Key functions                                                                                                                                                                                                                                                                                                                             |
| --------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/cli.php`                           | `main`, `parseArgs`, `printHelp`, `printProjects`, `resolveDateRange`, `generateReport`, `loadSourcesForRange`, `loadFreshSourceSlice`, `backfillRecentDailyReports`                                                                                                                                                                      |
| `src/helpers.php`                       | `expandPath`, `fnmatchAny`, `fmtDur`, `copyForRead`, `pdo`, `chromeTime`                                                                                                                                                                                                                                                                  |
| `src/config.php`                        | `saveConfigWithBackup`, `addUniqueValue`, `parseSlackSignal`, `applySignalToProject`                                                                                                                                                                                                                                                      |
| `src/cache.php`                         | `reportsDir`, `reportsCacheKey`, `rangeIsHistorical`, `rangeDays`, `mergeSourceBundles`, `filterSourcesToRange`, `loadCachedSources`, `saveCachedSources`, `loadDailyCachedSources`, `saveDailyCachedSources`, `findLatestReport`, `findLatestFullReportGeneratedAt`, `saveGeneratedReport`, `appendToIndex`, serialize/deserialize pairs |
| `src/loader-activitywatch.php`          | `loadActivityWatch`, `loadAwSqlite`                                                                                                                                                                                                                                                                                                       |
| `src/loader-chrome.php`                 | `loadChromeHistory`, `backfillChromeUrls`, `bsearchRight`                                                                                                                                                                                                                                                                                 |
| `src/loader-git.php`                    | `loadGitCommits`                                                                                                                                                                                                                                                                                                                          |
| `src/loader-github-desktop.php`         | `githubDesktopLevelDbPath`, `scanLevelDbForPaths`, `discoverGitHubDesktopRepos`                                                                                                                                                                                                                                                           |
| `src/loader-integrations.php`           | `loadIntegrationActivity`, `integrationWarning`, `getIntegrationWarnings`, `backupConfigSnapshot`                                                                                                                                                                                                                                         |
| `src/integrations/shared.php`           | `idLooksStandard`, `httpGetJson`                                                                                                                                                                                                                                                                                                          |
| `src/integrations/harvest.php`          | `resolveHarvestUserId`, `loadHarvestTimeEntries`                                                                                                                                                                                                                                                                                          |
| `src/integrations/clickup.php`          | `resolveClickUpUserId`, `loadClickUpTimeEntries`                                                                                                                                                                                                                                                                                          |
| `src/integrations/clockify.php`         | `resolveClockifyUserInfo`, `loadClockifyTimeEntries`                                                                                                                                                                                                                                                                                      |
| `src/integrations/clockify-catalog.php` | `clockifyFetchProjectNames`                                                                                                                                                                                                                                                                                                               |
| `src/integrations/github.php`           | `loadGitHubActivity`, `githubActorLogins`, `githubPaginatedGet`, `githubDateInRange`, `githubGetJson`, `githubReposByProject`, `githubRepoFromRemoteUrl`                                                                                                                                                                                  |
| `src/classifiers.php`                   | `classifyVscode`, `classifySlack`, `classifySsh`, `projectForSignals`, `projectForExternal`, `isAfkAt`, `activeInputSecondsDuring`, `classifyAndAggregate`                                                                                                                                                                                |
| `src/renderers.php`                     | `renderProjectEntry`, `renderMarkdown`, `renderJson`, `renderTsv`                                                                                                                                                                                                                                                                         |

`PROJECT_ROOT` is set via `define('PROJECT_ROOT', dirname(__DIR__, 2))` in `apps/cli/activity-report.php` — two levels up from `apps/cli/` to reach the project root. `const` cannot be used here because `dirname()` is a function call, not a compile-time constant expression. Cache functions in `src/cache.php` use `PROJECT_ROOT` so `reports/` always resolves to the project root regardless of include depth.

### Data flow

```
                        ┌─ CLI path ──────────────────────────────────────────────┐
                        │  activity-report.php → main() → generateReport()        │
                        └─────────────────────────────┬───────────────────────────┘
                                                       │
                        ┌─ Web path ──────────────────┐│
                        │  apps/web/index.php → api.php     ││
                        └─────────────────────────────┬┘
                                                       │
                              loadSourcesForRange()    │
                                    │                  │
                    ┌───────────────▼──────────────────▼──────────────────┐
                    │           Per-day source cache                       │
                    │       reports/YYYY-MM/DD/*.json                      │
                    │  (skipped when rebuild=true or cache miss)           │
                    └──────┬───────────────────────────────────────────────┘
                           │ cache miss / rebuild
                           ▼
          ┌────────────────────────────────────────────┐
          │           loadFreshSourceSlice()            │
          │                                            │
          │  loadActivityWatch() ──────────────────┐  │
          │  loadChromeHistory()  ─► backfill ──────┤  │
          │  loadGitCommits() ─────────────────────►├──┼──► saveDailyCachedSources()
          │  loadIntegrationActivity() ────────────►│  │
          └────────────────────────────────────────┘  │
                           │                           │
                           ▼                           │
              mergeSourceBundles() (across days)       │
                           │                           │
                           ▼                           │
              classifyAndAggregate()                   │
              → [$bucket, $unmatched, $timeline]       │
                           │                           │
              ┌────────────┼─────────────┐             │
              ▼            ▼             ▼             │
        renderMarkdown  renderJson   renderTsv         │
                            │                          │
                            ▼                          │
                  ┌──────────────────────┐             │
                  │   Report file cache  │             │
                  │  reports/YYYY-MM/    │◄────────────┘
                  │  (saveGeneratedReport)│
                  └──────────────────────┘
```

**`loadSourcesForRange(config, tz, from, to, rebuild=false)`** coordinates multi-day fetching. For each calendar day in the range:

- If the day is a complete historical day and `$rebuild` is `false`, it attempts to load a per-day source cache from `reports/YYYY-MM/DD/`. On a cache hit, that day's raw data is reused without hitting any APIs.
- On a cache miss (or when `$rebuild=true`), it calls `loadFreshSourceSlice`, which calls all four loaders and runs `backfillChromeUrls`. The result is saved as the per-day source cache for future requests.

Passing `rebuild=true` bypasses **and overwrites** the per-day source caches. This is how "Rebuild from source" in the web UI ensures stale or empty caches (e.g. caches written before an integration was configured) get refreshed.

**`backfillChromeUrls`** fills in missing URLs on Chrome ActivityWatch events by correlating window-focus times with the Chrome history SQLite within `chrome_correlation_window_seconds`.

**`classifyAndAggregate`** returns `[$bucket, $unmatched, $timeline]`. `$bucket` is indexed `[date][project]` with `seconds`, `active_seconds`, `activity_ratio`, `detail` (broken down by kind: vscode/browser/slack/ssh/app/harvest/clickup/clockify/github), `external` (per-source entry/activity/discussion counts), and `commits`. `$unmatched` records signals that didn't match any project rule. `$timeline` is a per-date list of `{s, e, p, g}` segments (seconds from local midnight) for the day-timeline SVG bar in the web UI; segments shorter than `timeline_min_seconds` (default 60 s) are dropped, and same-project segments separated by less than `timeline_merge_gap_seconds` (default 300 s) are merged before return.

### Signal matching priority (inside `projectForSignals`)

1. VSCode directory name (case-insensitive exact match against `vscode_dirs`)
2. Browser hostname (glob match against `domains`)
3. Slack workspace + optional `channel_glob`
4. SSH hostname (glob match against `ssh_hosts`)
5. App name (glob match against `apps`)

Git commits bypass `projectForSignals` entirely — they are pre-attributed at load time by `loadGitCommits` walking `projects[*].repos`.

External integration rows (Harvest, ClickUp, Clockify) are matched by `projectForExternal` using `harvest_projects`, `clickup_tasks`, and `clockify_projects` globs. GitHub rows are attributed via `repo_remotes`/`repos` lookups at fetch time.

### Integration warnings

`integrationWarning(message)` collects messages in a request-scoped global (`$_integrationWarnings`) in addition to writing them to `STDERR` (CLI) or `error_log` (web). Call `getIntegrationWarnings()` after the loading phase to retrieve them. In `api.php`, warnings are appended to the JSON response under `"warnings": [...]` for live/rebuild requests. Cached responses served via `readfile()` do not carry warnings — they reflect transient connection state at generation time, not at cache-serve time. The web UI renders an amber banner when `data.warnings` is non-empty.

### GitHub Desktop repo discovery

`discoverGitHubDesktopRepos(withTimestamps=false)` reads GitHub Desktop's Chromium IndexedDB as raw bytes from:

```
~/Library/Application Support/GitHub Desktop/IndexedDB/file__0.indexeddb.leveldb/
```

`scanLevelDbForPaths` extracts `/Users/...` strings via regex from `.log` and `.ldb` files. Paths appearing in `.log` (the active write-ahead log, containing the most recent writes) are tagged `recent`; paths only in `.ldb` (older compacted sorted-string tables) are tagged `archive`. After filtering to paths that have a `.git` directory, the results are returned as `[path => [name, recent, last_commit_ts]]`.

`loadGitCommits` checks `config['discover_repos']`; when it equals `'github_desktop'`, it calls `discoverGitHubDesktopRepos()` and appends discovered repos to the explicit `repos` map. Explicitly configured repos take precedence — discovered repos that are already mapped to a project are not re-mapped.

### LLM-assisted signal classification (`--suggest`)

`llmSuggestAssignments(unmatched, config)` in `src/integrations/llm.php` is called by `runLlmSuggest()` in `src/cli.php` when `--suggest` is passed. It is **CLI-only** and never loaded by `api.php`.

Flow:

1. `runLlmSuggest` calls `loadSourcesForRange` (hits the per-day cache, no extra API calls) and `classifyAndAggregate` to collect the `$unmatched` map.
2. `llmSuggestAssignments` builds two prompt sections: a concise project list (name, grouping, repo basenames, vscode dirs, domains) and the unmatched signals (kind, value, event count).
3. It calls `/chat/completions` via `llmPostJson`. The model is resolved from `conn['model']` or, if absent, by calling `GET /models` and taking the first entry.
4. The response is parsed as a JSON array of `{kind, value, project, reason}` objects. Each suggestion is validated: kind must be one of `vscode/browser/slack/apps`, value must be in the actual unmatched set, project must be a known non-ignored project name.
5. `runLlmSuggest` prints each suggestion with its reason and reads `y/N` from STDIN. Accepted suggestions are applied via `applySignalToProject()` from `src/config.php` (same logic as `api.php`'s `reassign_signal` handler) and written to `config.json` with a timestamped backup in `reports/config/`.

`llmPostJson` uses `stream_context_create` (no curl), consistent with `httpGetJson`. The model auto-discovery path (`GET /models`) is used when `model` is not set in the connection config — useful for Ollama and vLLM endpoints where model names vary per installation.

---

## Config shape

Defined and validated by `config.schema.json`. Key fields:

```jsonc
{
    "timezone": "America/New_York",
    "paths": {
        "activitywatch": "~/...",
        "chrome": "~/...",
        "chrome_profiles": null, // null = all profiles; or ["Default", "Profile 1"]
    },
    "git_authors": ["you@example.com"],
    "discover_repos": "github_desktop", // optional; auto-discovers repos from GitHub Desktop
    "chrome_correlation_window_seconds": 120,
    "min_event_seconds_to_show": 30,
    "projects": {
        "Project Name": {
            "grouping": "Group Label", // optional; groups related projects under a shared header
            "repos": ["~/path/to/repo"],
            "vscode_dirs": ["folder-name"],
            "domains": ["*.example.com"],
            "slack": [{ "workspace": "Name", "channel_glob": "proj-*" }],
            "ssh_hosts": ["hostname*"],
            "apps": ["AppName"],
            "harvest_projects": ["Project Name*"],
            "clickup_tasks": ["*task keyword*"],
            "repo_remotes": {
                // written by sync-repo-remotes.php; used by GitHub integration
                "~/path/to/repo": { "origin": "git@github.com:org/repo.git" },
            },
        },
    },
    "personal_hosts": ["youtube.com"],
    "personal_apps": ["Discord"],
    "ignored_projects": ["Project Name"],
    "groupings": {
        // canonical grouping registry; keys are authoritative names
        "Group Label": {
            "color": "#ED683C", // optional CSS color for accent bars in the web UI
            "aliases": ["Old Name"], // alternate spellings resolved at render time
            "logo": "https://...", // optional logo URL shown in group headers
        },
    },
    "correlated_apps": ["ClickUp", "Claude", "Terminal", "Cyberduck"],
    // ^ App names whose idle time is attributed to the most-recently-active project
    //   within app_correlation_window_seconds. GitHub Desktop is auto-added when
    //   discover_repos is "github_desktop".
    "app_correlation_window_seconds": 900,
    // ^ How far back to look for a matching project when attributing a correlated app (default 900).
    "project_gap_window_seconds": 300,
    // ^ If the user switches to untracked/personal activity for < this many seconds
    //   and then returns to the same project, the gap is bridged into that project (default 300).
    "timeline_merge_gap_seconds": 300,
    // ^ Same-project timeline segments separated by less than this are merged in the web UI (default 300).
    "timeline_min_seconds": 60,
    // ^ Timeline segments shorter than this are dropped from the web UI timeline bar (default 60).
    "integration_http_timeout_seconds": 20,
    // ^ HTTP request timeout in seconds for Harvest and ClickUp API calls (default 20).
    "github_command_timeout_seconds": 8,
    // ^ Timeout in seconds per gh CLI command when fetching GitHub activity (default 8).
    "github_cache_ttl": "1h",
    // ^ Cache TTL string passed to gh api --cache (e.g. "1h", "30m"; default "1h").
    "groupings_map": {
        // used by set-integration-groupings.php to auto-assign grouping fields
        "connections": { "*pattern*": "Grouping Label" }, // fnmatch globs against connection names
        "clickup_default": "Grouping Label", // fallback for ClickUp connections
        "priority": ["Grouping Label"], // first match wins when multiple groupings apply
    },
    "integrations": {
        "harvest": [{ "name": "Main", "account_id": "...", "token": "...", "user_id": "..." }],
        "clickup": [{ "name": "Main", "team_id": "...", "token": "...", "assignee": "123456" }],
        "clockify": [{ "name": "Main", "api_key": "...", "workspace_id": "...", "user_id": "..." }],
        "github": [{ "name": "GitHub via gh", "authors": ["you@example.com"] }],
        "llm": [
            {
                "name": "Local Ollama", // human label
                "base_url": "http://host/v1", // required; OpenAI-compatible base URL including /v1
                "api_key": "ollama", // optional Bearer token
                "model": "llama3", // optional default model name
                "timeout": 30, // optional; seconds (default 30)
            },
        ],
    },
}
```

`user_id` (Harvest) and `assignee` (ClickUp) are auto-resolved from `/v2/users/me` / `/api/v2/user` on first run and written back to `config.json` automatically. Clockify `user_id` and `workspace_id` are likewise auto-resolved from `GET /v1/user` and persisted on first run.

All project keys are optional — list only the signals that apply. Glob `*` is supported in `domains`, `slack[*].channel_glob`, `ssh_hosts`, `harvest_projects`, `clickup_tasks`, `clockify_projects`, and `apps`.

---

## JSON output shape

`renderJson` produces:

```jsonc
{
    "from": "2026-05-08T00:00:00-04:00",
    "to": "2026-05-08T23:59:59-04:00",
    "tz": "America/New_York",
    "days": {
        "2026-05-08": {
            "Project Name": {
                "grouping": "Group Label",
                "seconds": 3600,
                "active_seconds": 2700,
                "activity_ratio": 0.75,
                "detail": {
                    "vscode": { "folder-name": 3600 },
                    "browser": { "example.com": 900 },
                    "harvest": { "Client / Project / Task": 3600 }, // Harvest seconds appear here
                },
                "external": {
                    "harvest": { "entries": 1, "activity": 1, "discussion": 0 },
                    "clickup": { "entries": 0, "activity": 0, "discussion": 0 },
                    "clockify": { "entries": 1, "activity": 1, "discussion": 1 },
                    "github": { "entries": 2, "activity": 1, "discussion": 3 },
                },
                "commits": [
                    { "time": "2026-05-08T09:14:00-04:00", "sha": "a1b2c3d4...", "subj": "...", "repo": "~/..." },
                ],
            },
        },
    },
    "unmatched": {
        "vscode": { "unknown-dir": 5 },
        "browser": { "example.com": 3 },
    },
    "warnings": ["[Harvest Main] HTTP 401 from api.harvestapp.com: Invalid token"],
    // "warnings" key only present when non-empty; only in live responses, not cached files
    "timelines": {
        "2026-05-08": [
            { "s": 32400, "e": 34200, "p": "Project Name", "g": "Group Label" },
            // s/e = seconds from local midnight; p = project name; g = grouping (or null)
            // sub-minute segments dropped; same-project gaps ≤ 60 s merged
        ],
    },
    // "timelines" key only present when non-empty (omitted for project-filtered responses)
}
```

The Harvest sidebar in the web UI sums `detail.harvest[*]` values per day across all projects — this covers both categorized entries and the `HARVEST (uncategorized)` bucket.

---

## CLI flags

```
No args               Backfill prior 7 completed days (skips days already current)
--days N              Look back N days
--from YYYY-MM-DD     Explicit start (overrides --days)
--to   YYYY-MM-DD     Explicit end (default = now)
--project NAME        Filter output to one project
--format md|json|tsv  Output format (default md)
--show-unmatched      Append unmatched signal counts (debug)
--suggest             Ask the configured LLM for project assignments for unmatched signals,
                      then prompt to accept each one (writes to config.json with backup)
--list-projects       Print project names and their signals, then exit
-h, --help            Usage
```

---

## Web UI

Served by `php -S localhost:8000 apps/web/index.php`. `apps/web/index.php` routes all requests to `apps/web/report_renderer.php`.

- **HTML requests** (`?format=html`, the default): `report_renderer.php` returns a static HTML shell with an inline `SITE` config object and `<link>`/`<script>` tags pointing to `apps/web/static/app.css` and `apps/web/static/app.js`. Data is fetched async from `api.php`.
- **Non-HTML requests** (`?format=json|md|tsv`): the full PHP pipeline runs server-side and streams the result directly.
- **`api.php` GET**: accepts `from`, `to`, `days`, `project`, `rebuild`. Serves cached JSON with `X-Report-Source: cached` when available; generates fresh data with `X-Report-Source: generated` otherwise. `rebuild=1` bypasses both the report cache and per-day source caches.
- **`api.php` POST**: `action` field dispatches to `flag_projects_personal`, `reassign_signal`, or `set_project_grouping`, all of which mutate `config.json` with a backup.

### SITE config object (injected by report_renderer.php)

```js
const SITE = {
    timezone: "America/New_York",
    minSec: 30,
    projects: [{ name: "...", grouping: "..." }],
    today: "2026-05-08",
    yesterday: "2026-05-07",
    harvestConfigured: true, // true when integrations.harvest[] is non-empty in config.json
    groupings: {
        // mirrors config.json groupings; keys are canonical names
        "Group Label": { color: "#ED683C", aliases: ["Old Name"], logo: "https://..." },
    },
};
```

`harvestConfigured` controls whether the Harvest sidebar renders. When `true`, every day in the report gets a sidebar entry — 0m for days with no logged Harvest time.

`groupings` drives the grouping dropdown in the admin panel (`<select>` instead of free-text `<input>` when non-empty), the `resolveGrouping(name)` alias lookup, and the `groupingColor(name)` function which checks `SITE.groupings[name]?.color` before falling back to the deterministic hash palette.

---

## Tooling

### PHP linting + analysis

```bash
composer lint        # phpcs — PSR-12 across src/, apps/web/, tools/
composer lint:fix    # phpcbf auto-fix
composer analyze     # phpstan level 5 (phpstan.neon + phpstan-baseline.neon)
composer check       # lint + analyze together
```

Ruleset: PSR-12 via `phpcs.xml.dist`. Sniffs excluded: `PSR1.Files.SideEffects` and `PSR12.Files.FileHeader` (shebang). Line limit raised to 160.

PHPStan is configured in `phpstan.neon` with `treatPhpDocTypesAsCertain: false`. Known false positives from defensive guards are captured in `phpstan-baseline.neon`. Do not add `@phpstan-ignore` annotations to suppress new errors — fix the root cause or discuss updating the baseline.

### JSON formatting (prettier)

```bash
npm run format        # rewrite JSON files in place
npm run format:check  # dry-run, exits non-zero if anything would change
```

Covers `config.example.json`, `config.schema.json`, `composer.json`, `package.json`. `config.json` is gitignored so prettier touches it locally but it is never committed.

### Maintenance tools

All tools in `tools/` back up `config.json` to `reports/config/config.<tool>.<timestamp>.json` before writing.

**`list-github-desktop-repos.php`**

- Reads GitHub Desktop's IndexedDB LevelDB for repo paths.
- Sorts by last commit date; marks repos from the active `.log` file as RECENT.
- Dry-run by default; `--apply` adds unconfigured repos to `config.json`.
- Repos matching an existing project by basename go into that project's `repos` list; others create new project stubs.

**`sync-integration-projects.php`**

- Queries Harvest projects via `GET /v2/projects` (falls back to time-entry scan if unauthorized).
- Queries ClickUp names via team → spaces → folders → lists.
- Case-insensitive matches reuse existing projects; new names create stubs.
- Adds `harvest_projects` (exact names) and `clickup_tasks` (`*Name*` globs).

**`set-integration-groupings.php`**

- Reads `groupings_map` from `config.json` (exits with an error if absent).
- Queries Harvest and ClickUp APIs to build a map of known project/task names per grouping.
- For each project in `config.json`, matches `harvest_projects` and `clickup_tasks` globs against the discovered names; assigns `grouping` based on `groupings_map.priority` (first match wins).
- Run after `sync-integration-projects.php` to auto-assign groupings to newly synced stubs.

**`cleanup-integration-projects.php`** (`--baseline <backup> --dry-run|--apply`)

- Merges high-confidence name-matched stubs back into existing projects.
- Leaves ambiguous additions untouched.

**`sync-repo-remotes.php`**

- Reads all `projects[*].repos` paths; queries `git remote` / `git remote get-url`.
- Writes `repo_remotes` in config; removes stale entries.

**`ensure-github-integration.php`**

- Adds a default `integrations.github` entry using `gh` auth if none exists.

**`prune-config-backups.php`** (`--keep N`, `--max-lines N`, `--apply`)

- Groups `reports/config/config.<source>.<timestamp>.json` files by source tag; keeps the `--keep` most recent per tag (default 10) and deletes the rest.
- Trims `reports/cache-data.jsonl` and `reports/generated-reports.jsonl` to the last `--max-lines` entries (default 1000).
- Dry-run by default; pass `--apply` to perform deletions and trims.

**`reset-cache.php`** (`--before YYYY-MM-DD`, `--month YYYY-MM`, `--apply`)

- Removes per-day source cache directories (`reports/YYYY-MM/DD/`) so the next run re-fetches and re-classifies all data.
- Preserves `config.json`, `reports/config/` backups, and JSONL index files.
- Dry-run by default; pass `--apply` to delete. Optional `--before` / `--month` flags scope the deletion to a date range.

---

## Conventions

- **No classes.** Plain functions only. Introduce a class only if complexity genuinely demands it after discussion.
- **No autoloader.** Runtime is dependency-free (`vendor/` contains only dev tools). New modules go in `src/` with a `require_once` in `apps/cli/activity-report.php`.
- **Schema stays in sync.** Whenever a config key is added or its shape changes, update `config.schema.json` and `config.example.json` in the same commit.
- **Run linters before committing.** `composer lint` must exit 0. `npm run format:check` must exit 0.
- **`config.json` is never committed.** It contains real email addresses, tokens, repo paths, and workspace names. It is in `.gitignore`.
- **Config backups live in `reports/config/`.** Any code path that mutates `config.json` must call `saveConfigWithBackup()` from `src/config.php` — it handles the atomic write and timestamped backup in one step. Never create root-level `config.json.bak*` files.
- **Warnings are collected, not just logged.** `integrationWarning()` writes to STDERR/error_log AND appends to `$_integrationWarnings`. Always call `getIntegrationWarnings()` after the loading phase and include the result in JSON responses. Do not include warnings in files written to the report cache — they reflect transient state.
- **Rebuild clears source caches.** Pass `rebuild=true` to `loadSourcesForRange` whenever the caller intends a full refresh. This ensures per-day source caches can't silently persist stale or empty data indefinitely.
- **Cache stays flat JSON, not SQLite.** Raw source caches are per-day JSON files under `reports/YYYY-MM/DD/`. Wider date ranges compose daily buckets rather than writing range-wide source caches. Files are transparent, trivially inspectable, and selectively invalidated with `rm -rf reports/YYYY-MM/DD/`. If cross-range aggregate queries become a priority, build a thin read layer over existing report files rather than introducing SQLite for raw event storage.
- **GitHub integration is CLI-only.** `loadGitHubActivity` is skipped when `PHP_SAPI !== 'cli'` to avoid blocking web page loads. Rely on the daily cron job or direct CLI invocation to populate GitHub data into per-day source caches.

---

## Multi-UI architecture and native desktop migration

The long-term goal is a common data layer shared by all UIs: PHP CLI, PHP web, and native desktop. The TypeScript engine (`packages/engine/`) is the shared foundation for non-PHP surfaces. See `NATIVE.md` for the full migration plan; current state is **Phase 1: workspace scaffolded**.

### Shared data contract (all UIs must respect this)

| Artifact              | How all UIs use it                                                                                                               |
| --------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `config.json`         | Single config for all surfaces; same JSON Schema; same backup convention (`reports/config/config.<source>.<ts>.json`)            |
| `reports/YYYY-MM/DD/` | Per-day source caches (activitywatch, chrome, commits, integrations) — read and written by PHP and TypeScript engine alike       |
| Report JSON shape     | `{from, to, tz, days: {date: {project: ProjectReport}}, timelines, unmatched, warnings}` — all surfaces produce and consume this |

### What exists in `packages/`

| Package                   | npm name                    | Status | Purpose                                                                                                                                                                                   |
| ------------------------- | --------------------------- | ------ | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `packages/contracts/`     | `@timesheets/contracts`     | Active | JS type definitions mirroring the PHP JSON output shape and config schema (`Report`, `Config`, `ProjectConfig`, all connection types, IPC payload types)                                  |
| `packages/engine/`        | `@timesheets/engine`        | Stub   | TypeScript engine — same `config.json` + `reports/` layout as PHP; placeholder implementations of `generateReport`, `loadSourcesForRange`, `classifyAndAggregate`, `saveConfigWithBackup` |
| `packages/ui/`            | `@timesheets/ui`            | Stub   | React Native macOS UI; exports `TimesheetsApp` with day navigation and mocked data; `peerDependencies` on `react` and `react-native-macos`                                                |
| `packages/test-fixtures/` | `@timesheets/test-fixtures` | Empty  | Will hold golden report JSON and config fixtures for PHP–TypeScript parity tests (Phase 0 capture)                                                                                        |
| `apps/desktop/`           | `@timesheets/desktop`       | Shell  | React Native macOS app scaffold; not yet runnable (React Native project not yet initialized)                                                                                              |

### Guiding constraints for all UI work

- **PHP remains the behavior oracle** until parity tests pass. Do not remove PHP entrypoints.
- **Keep `config.json` and `reports/` layout compatible** with the existing PHP app so users can run PHP and native interfaces against the same local data simultaneously.
- **The engine boundary is narrow.** Expose report generation via the IPC surface in NATIVE.md; don't let UI components read databases or config files directly.
- **macOS first.** `react-native-macos` is the initial target. `react-native-windows` is deferred until macOS is stable.
- **Format changes are phase-2.** Do not change `config.json` shape or `reports/` layout in phase-1 work — compatibility first, cleanup later.
