# AGENTS.md — activity-report

Context file for AI agents and future contributors. Keep this up to date when the architecture, tooling, or data model changes.

---

## What this project is

A PHP CLI tool that aggregates local activity data from four source categories and produces a project-attributed time report:

| Source                   | Data                                                                              | Location                                                |
| ------------------------ | --------------------------------------------------------------------------------- | ------------------------------------------------------- |
| ActivityWatch            | App/window focus events + AFK status + input slices (presses/clicks/mouse/scroll) | `~/Library/Application Support/activitywatch/` (SQLite) |
| Chrome history           | Browser visits with URLs and titles                                               | `~/Library/Application Support/Google/Chrome/` (SQLite) |
| Git                      | Commits authored by configured email(s)                                           | All repos listed in `projects[*].repos`                 |
| External APIs (optional) | Harvest + ClickUp time/activity rows (supports multiple PAT connections)          | HTTPS APIs                                              |

Events are classified into named **projects** by matching signals (VSCode window title, browser domain, Slack workspace/channel, SSH hostname) against rules in `config.json`. Anything that doesn't match a project rule falls into catch-all buckets (`Browser (uncategorized)`, `VSCode (uncategorized)`, etc.).

---

## File map

```
activity-report.php         — entry point: config load, PROJECT_ROOT, require_once, main()
src/
  cli.php                   — main(), parseArgs(), printHelp(), printProjects(), resolveDateRange(), VERSION
  helpers.php               — expandPath(), fnmatchAny(), fmtDur(), copyForRead(), pdo(), chromeTime()
  cache.php                 — reportsDir(), reportsCacheKey(), rangeIsHistorical(), loadCachedSources(),
                              saveCachedSources(), saveGeneratedReport(), appendToIndex(),
                              serializeEvents/deserializeEvents, serializeChrome/deserializeChrome,
                              serializeCommits/deserializeCommits
  loader-activitywatch.php  — loadActivityWatch(), loadAwSqlite()
  loader-chrome.php         — loadChromeHistory(), backfillChromeUrls(), bsearchRight()
  loader-git.php            — loadGitCommits()
  loader-integrations.php   — loadIntegrationActivity(), loadHarvestTimeEntries(), loadClickUpTimeEntries()
  classifiers.php           — classifyVscode(), classifySlack(), classifySsh(),
                              projectForSignals(), isAfkAt(), classifyAndAggregate()
  renderers.php             — renderProjectEntry(), renderMarkdown(), renderJson(), renderTsv()
config.json                 — local config, gitignored, never committed
config.example.json         — safe-to-commit template with dummy data
config.schema.json          — JSON Schema (draft 2020-12) for both config files
phpcs.xml.dist              — PHP_CodeSniffer ruleset (PSR-12 + CLI exceptions)
composer.json               — dev dep: squizlabs/php_codesniffer ^3.9
package.json                — dev dep: prettier ^3.0
.prettierrc.json            — 4-space indent, 120-char print width
.prettierignore             — excludes vendor/ and node_modules/
.gitignore                  — excludes config.json, vendor/, node_modules/
tools/
  sync-integration-projects.php    — discovers Harvest/ClickUp project catalogs and merges mappings into config.json
  cleanup-integration-projects.php — conservative merge of newly added integration project stubs back into existing projects
```

`vendor/` and `node_modules/` are installed locally but not committed.

---

## Architecture

Logic is split across `src/` includes with no classes. All code is plain functions grouped by concern. `activity-report.php` is a thin entry point that loads config, defines `PROJECT_ROOT`, requires all includes, and calls `main()`.

| File                           | Functions                                                                                                                                                           |
| ------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `src/cli.php`                  | `main`, `parseArgs`, `printHelp`, `printProjects`, `resolveDateRange`                                                                                               |
| `src/helpers.php`              | `expandPath`, `fnmatchAny`, `fmtDur`, `copyForRead`, `pdo`, `chromeTime`                                                                                            |
| `src/cache.php`                | `reportsDir`, `reportsCacheKey`, `rangeIsHistorical`, `loadCachedSources`, `saveCachedSources`, `saveGeneratedReport`, `appendToIndex`, serialize/deserialize pairs |
| `src/loader-activitywatch.php` | `loadActivityWatch`, `loadAwSqlite`                                                                                                                                 |
| `src/loader-chrome.php`        | `loadChromeHistory`, `backfillChromeUrls`, `bsearchRight`                                                                                                           |
| `src/loader-git.php`           | `loadGitCommits`                                                                                                                                                    |
| `src/loader-integrations.php`  | `loadIntegrationActivity`, `loadHarvestTimeEntries`, `loadClickUpTimeEntries`                                                                                       |
| `src/classifiers.php`          | `classifyVscode`, `classifySlack`, `classifySsh`, `projectForSignals`, `isAfkAt`, `classifyAndAggregate`                                                            |
| `src/renderers.php`            | `renderProjectEntry`, `renderMarkdown`, `renderJson`, `renderTsv`                                                                                                   |

`PROJECT_ROOT` is defined as `__DIR__` in `activity-report.php`. Cache functions in `src/cache.php` use `PROJECT_ROOT` (not `__DIR__`) so that `reports/` always resolves to the project root regardless of include depth.

### Data flow

```
loadActivityWatch ──┐
loadChromeHistory ──┼── backfillChromeUrls ──► classifyAndAggregate ──► render*
loadGitCommits ─────┤
loadIntegrationActivity ─┘
```

`backfillChromeUrls` fills in missing URLs on Chrome ActivityWatch events by correlating them with the Chrome history SQLite within a configurable time window (`chrome_correlation_window_seconds`).

`classifyAndAggregate` returns `[$bucket, $unmatched]`. `$bucket` is indexed `[date][project]` with `seconds`, `detail` (broken down by kind: vscode/browser/slack/ssh/app), and `commits`. `$unmatched` records signals that didn't match any project rule, surfaced via `--show-unmatched`.

External rows are classified by project mapping rules (`harvest_projects`, `clickup_tasks`) and add seconds/detail plus per-source counts (`entries`, `activity`, `discussion`) under each bucket record.

When input buckets (`aw-watcher-input*`) are present, `classifyAndAggregate` also computes `active_seconds` and `activity_ratio` per `[date][project]` by overlapping focused window time with input slices that contain keyboard/mouse/scroll activity.

### Signal matching priority (inside `projectForSignals`)

1. VSCode directory name (case-insensitive exact match)
2. Browser hostname (glob match against `domains`)
3. Slack workspace + optional channel glob
4. SSH hostname (glob match against `ssh_hosts`)

Git commits bypass `projectForSignals` entirely — they are pre-attributed to a project when `loadGitCommits` walks `projects[*].repos`.

---

## Config shape

Defined and validated by `config.schema.json`. Key fields:

```jsonc
{
  "timezone": "America/New_York",       // IANA tz for all output
  "paths": {
    "activitywatch": "~/...",
    "chrome": "~/...",
    "chrome_profiles": null             // null = all profiles; or ["Default", "Profile 1"]
  },
  "git_authors": ["you@example.com"],   // one or more commit-author emails
  "chrome_correlation_window_seconds": 120,
  "min_event_seconds_to_show": 30,
  "projects": {
    "Project Name": {
      "repos":       ["~/path/to/repo"],
      "vscode_dirs": ["folder-name"],
      "domains":     ["*.example.com", "example.com"],
      "slack":       [{"workspace": "Name", "channel_glob": "proj-*"}],
      "ssh_hosts":   ["hostname*"],
      "harvest_projects": ["Project Name*"],
      "clickup_tasks": ["*task keyword*"]
    }
  },
  "personal_hosts": ["youtube.com", ...],
  "personal_apps":  ["Discord", ...],
  "ignored_projects": ["Project Name"],
  "integrations": {
    "harvest": [{"name": "Main", "account_id": "...", "token": "...", "user_id": "..."}],
    "clickup": [{"name": "Main", "team_id": "...", "token": "...", "assignee": "me"}]
  }
}
```

All project keys are optional — list only the signals that apply. Glob `*` is supported in `domains`, `slack[*].channel_glob`, and `ssh_hosts`.

---

## CLI flags

```
--days N              Look back N days (default 7)
--from YYYY-MM-DD     Explicit start (overrides --days)
--to   YYYY-MM-DD     Explicit end (default = now)
--project NAME        Filter output to one project
--format md|json|tsv  Output format (default md)
--show-unmatched      Append unmatched signal counts (debug)
--list-projects       Print project names and their signals, then exit
-h, --help            Usage
```

---

## Tooling

### PHP linting (phpcs / phpcbf)

```bash
composer lint        # check — exits non-zero if violations found
composer lint:fix    # auto-fix what phpcs can fix
```

Ruleset: PSR-12 via `phpcs.xml.dist`. Two sniffs are excluded:

- `PSR1.Files.SideEffects` — the shebang CLI script intentionally mixes declarations and a top-level `main()` call.
- `PSR12.Files.FileHeader` — the shebang line before `<?php` confuses the header-order check.

Line limit is raised to 160 (some function signatures are legitimately long).

### JSON formatting (prettier)

```bash
npm run format        # rewrite JSON files in place
npm run format:check  # dry-run, exits non-zero if anything would change
```

Covers `config.example.json`, `config.schema.json`, `composer.json`, `package.json`.
`config.json` is gitignored so prettier touches it locally but it is never committed.

### JSON Schema validation

`config.json` and `config.example.json` both carry a `"$schema": "./config.schema.json"` pointer. Editors that support JSON Schema (VS Code, JetBrains) will validate and autocomplete config files automatically.

### Integration project sync tools

Use these scripts to keep external project names linked into local `projects` mappings:

```bash
php tools/sync-integration-projects.php
```

What this does:

- Queries Harvest projects via `GET /v2/projects` (active projects).
- If Harvest project listing is unauthorized for a token/account, falls back to `GET /v2/time_entries` and extracts `project.name` values seen in the past year.
- Queries ClickUp names via team → spaces → folders → lists (including folderless lists).
- Reuses an existing local project on case-insensitive name match; otherwise creates a new project stub.
- Adds Harvest mappings under `harvest_projects` as exact names.
- Adds ClickUp mappings under `clickup_tasks` as `*Name*` globs.

Conservative cleanup (optional):

```bash
php tools/cleanup-integration-projects.php --baseline reports/config/config.sync.<timestamp>.json --dry-run
php tools/cleanup-integration-projects.php --baseline reports/config/config.sync.<timestamp>.json --apply
```

Cleanup only merges high-confidence name matches back into existing projects and leaves ambiguous additions untouched.

---

## Conventions

- **No classes.** Keep everything as plain functions. Only introduce a class if the complexity genuinely demands it and you've discussed it first.
- **No autoloader.** The project is intentionally dependency-free at runtime — `vendor/` contains only dev tools. New modules go in `src/` and get a `require_once` line in `activity-report.php`.
- **Schema stays in sync.** Whenever a new config key is added or an existing key's shape changes, update `config.schema.json` and `config.example.json` in the same change.
- **Run linters before committing.** `composer lint` must exit 0. `npm run format:check` must exit 0.
- **`config.json` is never committed.** It contains real email addresses, repo paths, and workspace names. It is in `.gitignore`.
- **Config backups live in `reports/config/`.** Any tool or runtime path that mutates `config.json` must write a timestamped backup into `reports/config/` first (do not create root-level `config.json.bak*` files).
- **Cache stays flat JSON, not SQLite.** Each cached range is a small set of per-date JSON files. The volumes are tiny (one person, one day), files are transparent and easy to inspect or delete, and selective invalidation is just `rm -rf reports/YYYY-MM/DD/`. A SQLite cache would add complexity without meaningful benefit. If cross-range aggregate queries become a priority in future, build a thin read layer over the already-generated report files rather than re-doing raw event storage in SQLite.
