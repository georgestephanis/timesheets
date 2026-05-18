# activity-report

A local-first activity reporting tool with multiple UI surfaces sharing a common data store. All surfaces read the same [ActivityWatch](https://activitywatch.net/), Chrome history, Git, and optional integration data; produce the same `config.json`-driven project report; and write to the same `reports/` cache directory.

**Current UI surfaces:**

- **PHP CLI** (`activity-report.php`) — primary interface; backfills daily reports, generates Markdown/JSON/TSV output, LLM-assisted signal tuning
- **PHP web UI** (`apps/web/`) — local browser interface; report browsing, Harvest sidebar, rebuild, config panel
- **React Native macOS desktop** (`apps/desktop/`) — in progress; see [NATIVE.md](NATIVE.md)

The desktop app is built on a TypeScript engine (`packages/engine/`) that will eventually replace the PHP core while keeping the same `config.json` shape and `reports/` cache layout. Switching between the PHP and native interfaces does not require any data migration.

## A Recommendation on Building your `config.json`

The config is a somewhat long and detailed JSON object. Both a `config.example.json` and `config.schema.json` are provided to describe how it should look, but writing it manually is tedious. It is recommended to work with an LLM / AI provider to have it populate the `config.json` for you, describing what you'd like to configure and what credentials you'd like to add.

## Architecture

All UI surfaces converge on the same local data stores:

```
┌─────────────────────┐   ┌─────────────────────┐   ┌─────────────────────────┐
│   PHP CLI           │   │   PHP Web UI         │   │   React Native Desktop  │
│   activity-report   │   │   apps/web/ + api.php     │   │   apps/desktop/         │
│   .php              │   │                      │   │   (in progress)         │
└──────────┬──────────┘   └──────────┬───────────┘   └────────────┬────────────┘
           │                         │                             │
           │              ┌──────────┴───────────┐                │
           └──────────────►   PHP core (src/)    │   ┌────────────▼────────────┐
                          │   config, cache,      │   │  TypeScript engine      │
                          │   loaders, classifiers│   │  packages/engine/       │
                          └──────────┬────────────┘   └────────────┬────────────┘
                                     │                              │
                          ┌──────────▼──────────────────────────────▼────────────┐
                          │                  Shared data stores                   │
                          │                                                        │
                          │  config.json  ·  reports/YYYY-MM/DD/  ·  ActivityWatch │
                          │  Chrome history  ·  Git repos  ·  External APIs        │
                          └────────────────────────────────────────────────────────┘
```

The shared contract between all surfaces:

| Artifact              | Role                                                                                      |
| --------------------- | ----------------------------------------------------------------------------------------- |
| `config.json`         | Single source of truth for projects, signals, and integration credentials                 |
| `reports/YYYY-MM/DD/` | Per-day source caches (activitywatch, chrome, commits, integrations)                      |
| Report JSON shape     | All outputs conform to the same structure (`from`, `to`, `days`, `timelines`, `warnings`) |
| `reports/config/`     | Timestamped config backups — written by every surface that mutates `config.json`          |

The TypeScript engine (`packages/engine/`) is being developed in parallel as the data layer for the native desktop app. It keeps `config.json` and `reports/` layout compatible with the PHP app so both can run against the same local data without conflict.

## Quick start

```bash
# Install dev tools (optional but recommended)
composer install
npm install

# Start the web UI
php -S localhost:8000 apps/web/index.php
```

Then open [http://localhost:8000](http://localhost:8000). The UI loads today's activity, lets you page through previous days, rebuild stale data, and cross-reference what you've logged in Harvest for each day.

### Recommended cron job

Run this once at 4 am each morning to pre-build and cache the prior day's report before you open it:

```
0 4 * * * cd /Users/yourname/code/timesheets && /usr/bin/php activity-report.php >> /tmp/timesheets-backfill.log 2>&1
```

Add it with `crontab -e`. Replace `/Users/yourname/code/timesheets` with your actual path. On macOS, use the full PHP path (`which php` to find it — Homebrew installs to `/opt/homebrew/bin/php`).

With no arguments, `activity-report.php` backfills the prior seven completed calendar days, skipping any day whose report artifact is already current. The cron job keeps source caches warm so the web UI loads instantly.

---

## How it works

Every few seconds, ActivityWatch records which app and window title is in focus. With `aw-watcher-input` enabled, it also records keyboard/mouse/scroll activity slices. This script reads that data, correlates it with Chrome browsing history, Git commits, and optional Harvest/ClickUp/Clockify time-entry feeds, then classifies each event into a named **project** based on rules you define in `config.json`. The result is a per-day, per-project breakdown of where your time went, including active-input ratios and external-integration metrics.

```
## 2026-04-28 (Mon) — 7h 22m active

### Acme Corp — 4h 15m
- vscode: acme-backend (2h 40m), acme-frontend (1h 10m)
- browser: staging.acme.com (18m), docs.acme.com (7m)
- input activity: 2h 58m (70%)
- harvest: 2 entries, 1 activity
- commits (3):
    - `09:14` `a1b2c3d4` Fix null pointer in auth middleware
    - `11:02` `e5f6a7b8` Add unit tests for token refresh
    - `14:38` `c9d0e1f2` Bump API version to 2.1
```

When an LLM is configured, single-day CLI runs also generate a concise bullet-point accomplishment summary from your commits, GitHub PRs/issues, and ClickUp tasks. The summary is embedded in the saved JSON report and displayed in the web UI as a collapsible panel above the project list for each day.

---

## Requirements

- PHP 8.1+
- [ActivityWatch](https://activitywatch.net/) running locally (cross-platform: macOS, Windows, Linux)
- Google Chrome (optional — for browser signal matching)
- Git (optional — for commit attribution)

---

## Installation

```bash
git clone https://github.com/georgestephanis/timesheets.git
cd timesheets
cp config.example.json config.json
```

Edit `config.json` to add your projects, email addresses, and local paths. The file is gitignored so your personal data stays local.

### Dev tools (optional)

```bash
composer install   # PHP_CodeSniffer, PHPStan, PHPUnit
npm install        # Prettier, ESLint
```

---

## Configuration

Copy `config.example.json` to `config.json` and fill in your details. The file is validated against `config.schema.json`, so editors with JSON Schema support (VS Code, JetBrains) will autocomplete and flag errors automatically.

### Top-level fields

| Field                               | Type        | Description                                                                                                        |
| ----------------------------------- | ----------- | ------------------------------------------------------------------------------------------------------------------ |
| `timezone`                          | string      | IANA timezone name for all output (e.g. `America/New_York`)                                                        |
| `paths.activitywatch`               | string      | Path to ActivityWatch data directory                                                                               |
| `paths.chrome`                      | string      | Path to Chrome user-data directory                                                                                 |
| `paths.chrome_profiles`             | array\|null | Profile folders to scan; `null` = auto-discover all                                                                |
| `git_authors`                       | string[]    | Your commit author email address(es)                                                                               |
| `discover_repos`                    | string      | Set to `"github_desktop"` to auto-discover repos from the GitHub Desktop app (see below)                           |
| `chrome_correlation_window_seconds` | int         | How far back (in seconds) to look in Chrome history when back-filling a missing URL (default 120)                  |
| `min_event_seconds_to_show`         | int         | Hide activity segments shorter than this (default 30)                                                              |
| `projects`                          | object      | Named project definitions (see below)                                                                              |
| `personal_hosts`                    | string[]    | Browser hostnames to bucket as personal, not work                                                                  |
| `personal_apps`                     | string[]    | App names (as reported by ActivityWatch) to bucket as personal                                                     |
| `ignored_projects`                  | string[]    | Project names to exclude from classification and reporting                                                         |
| `groupings`                         | object      | Canonical grouping definitions: `color`, `aliases`, and `logo` per grouping; drives UI dropdowns and accent colors |
| `correlated_apps`                   | string[]    | App names whose time is attributed to the most-recently-active project within `app_correlation_window_seconds`     |
| `app_correlation_window_seconds`    | int         | Lookback window (seconds) for correlated-app attribution (default 900)                                             |
| `project_gap_window_seconds`        | int         | Bridge untracked/personal gaps shorter than this back to the surrounding project (default 300)                     |
| `timeline_merge_gap_seconds`        | int         | Merge same-project timeline segments separated by less than this many seconds in the web UI (default 300)          |
| `timeline_min_seconds`              | int         | Drop timeline segments shorter than this from the web UI timeline bar (default 60)                                 |
| `integration_http_timeout_seconds`  | int         | HTTP timeout for Harvest, ClickUp, and Clockify API calls (default 20)                                             |
| `github_command_timeout_seconds`    | int         | Timeout per `gh` CLI command when fetching GitHub activity (default 8)                                             |
| `github_cache_ttl`                  | string      | Cache TTL passed to `gh api --cache` (e.g. `"1h"`, `"30m"`; default `"1h"`)                                        |
| `groupings_map`                     | object      | Rules for `set-integration-groupings` tool: connection glob → grouping label, ClickUp default, priority order      |
| `integrations`                      | object      | Optional external sources (`harvest[]`, `clickup[]`, `clockify[]`, `github[]`, `llm[]`)                            |

### Project signals

Each project in `projects` is an object whose keys are all optional — include only what applies:

```jsonc
"Acme Corp": {
    "grouping": "Acme",                         // groups this project under a named header in reports

    // Git repository paths — commits are pre-attributed at load time
    "repos": ["~/code/acme-backend", "~/code/acme-frontend"],

    // VSCode workspace folder names (case-insensitive exact match)
    "vscode_dirs": ["acme-backend", "acme-frontend"],

    // Browser hostnames — glob * supported
    "domains": ["acme.com", "*.acme.com", "acme.local"],

    // Slack workspaces; omit channel_glob to match any channel
    "slack": [
        {"workspace": "Acme Corp"},
        {"workspace": "Partners", "channel_glob": "acme-*"}
    ],

    // SSH hostnames seen in terminal window titles — glob * supported
    "ssh_hosts": ["acme-prod", "acme-staging*"],

    // Harvest project-name globs mapped into this local project
    "harvest_projects": ["Acme*"],

    // ClickUp task/description globs mapped into this local project
    "clickup_tasks": ["*acme*"],

    // Clockify project-name globs mapped into this local project
    "clockify_projects": ["Acme*", "Client Work"]
}
```

### GitHub Desktop repo discovery

Set `"discover_repos": "github_desktop"` in `config.json` to have the commit loader automatically include all repositories registered in the GitHub Desktop app, without needing to list them explicitly under each project.

Discovered repos are matched to existing projects by directory basename (case-insensitive). Repos that don't match any project name are attributed to a synthetic project using the repo name.

Use the listing tool to preview and apply new repos from GitHub Desktop into config.json:

```bash
php tools/list-github-desktop-repos.php           # preview
php tools/list-github-desktop-repos.php --apply   # write changes + backup
```

The tool sorts repos by most recent commit date and marks ones that appear in GitHub Desktop's active write-ahead log as **RECENT**.

### External integrations (optional)

Multiple personal-access-token connections are supported for each provider:

```jsonc
"integrations": {
    "harvest": [
        {
            "name": "Harvest Main",
            "account_id": "123456",
            "token": "HARVEST_PERSONAL_ACCESS_TOKEN",
            "user_id": "1234567"         // auto-resolved and saved on first run if omitted
        }
    ],
    "clickup": [
        {
            "name": "ClickUp Main",
            "team_id": "1234567",
            "token": "CLICKUP_PERSONAL_ACCESS_TOKEN",
            "assignee": "me"             // auto-resolved and saved on first run if omitted
        }
    ],
    "clockify": [
        {
            "name": "Clockify Main",
            "api_key": "CLOCKIFY_API_KEY",
            "workspace_id": "abc123",   // auto-resolved and saved on first run if omitted
            "user_id": "xyz789"         // auto-resolved and saved on first run if omitted
        }
    ],
    "github": [
        {
            "name": "GitHub via gh",
            "authors": ["you@example.com"]
        }
    ],
    "llm": [
        {
            "name": "Local Ollama",
            "base_url": "http://localhost:11434/v1",  // required; include the /v1 path
            "api_key": "ollama",                       // optional; many local endpoints accept any string
            "model": "llama3",                         // optional; auto-detected from /models if omitted
            "timeout": 30                              // optional; seconds (default 30)
        }
    ]
}
```

Works with any OpenAI-compatible server: Ollama, LM Studio, vLLM, OpenAI, etc.

> **Note:** The GitHub integration (PRs, issues, comments, commit activity) only runs via the CLI. It is skipped during web requests to avoid blocking page loads. Run `php activity-report.php` from the command line, or rely on the daily cron job, to include GitHub data in cached reports.

### Tuning with `--show-unmatched` and `--suggest`

Run with `--show-unmatched` to see which VSCode dirs, browser hosts, and Slack channels weren't matched by any project rule:

```bash
php activity-report.php --show-unmatched
```

Once you have an LLM configured, `--suggest` asks it to recommend project assignments for those unmatched signals and prompts you to accept each one:

```bash
php activity-report.php --days 7 --suggest
```

Accepted suggestions are written directly to `config.json` (with a timestamped backup in `reports/config/`) so they take effect on the next run.

### LLM daily summaries

When an LLM is configured, single-day CLI runs automatically generate a concise accomplishment summary from git commits, GitHub PRs and issues, and ClickUp tasks. The summary is saved into the JSON report and shown in the web UI as a collapsible panel above the project list.

**CLI:** summary is generated automatically on any single-day run and written to STDERR while the report is generated:

```bash
php activity-report.php --from 2026-05-08
# Generating daily summary via LLM for 2026-05-08...
```

**Web UI:** a "Generate day summary" button appears below the timeline for each day. Clicking it sends the cached activity data to the LLM and replaces the button with the summary inline. The result is also persisted to the saved JSON report so subsequent loads serve it from cache.

---

## CLI usage

```
php activity-report.php [options]

    No args              Backfill prior 7 completed days (skips days already current)
    --days N             Look back N days from today
    --from YYYY-MM-DD    Explicit start date (overrides --days)
    --to   YYYY-MM-DD    Explicit end date (default = today)
    --project NAME       Filter output to one project
    --format md|json|tsv Output format (default md)
    --show-unmatched     Append unclassified signals — useful for tuning config
    --suggest            Ask the configured LLM to suggest project assignments for
                         unmatched signals, then prompt to accept each one
    --list-projects      Print configured projects and exit
    -h, --help           Show this message
```

The script is executable, so you can also run it directly:

```bash
chmod +x activity-report.php
./activity-report.php --days 14 --format json > report.json
```

---

## Output formats

| Format   | Flag                    | Use case                                       |
| -------- | ----------------------- | ---------------------------------------------- |
| Markdown | `--format md` (default) | Reading in terminal or pasting into a doc      |
| JSON     | `--format json`         | Piping into `jq`, importing into a spreadsheet |
| TSV      | `--format tsv`          | Opening in Excel / Numbers                     |

---

## Web UI

```bash
php -S localhost:8000 apps/web/index.php
```

The web UI is a single-page app that fetches JSON from `apps/web/api.php` and renders it client-side. Features:

- **Navigation** — page through days or date ranges; jump to any date with the date picker; `←`/`→` keyboard shortcuts for Prev/Next
- **Project filter** — filter to a single project or group; filtering is client-side (no re-fetch)
- **Day summary** — when an LLM is configured, a collapsible accomplishment summary appears above the project list for each day; click "Generate day summary" to create one on demand for any day that doesn't have one yet
- **Timeline** — per-project activity bars shown below the day heading, with hover highlighting and a collapsible segment list
- **Harvest sidebar** — sticky panel on the right showing total Harvest time logged per day, with a per-entry breakdown; hidden when Harvest is not configured
- **Rebuild** — the "Rebuild from source" button re-fetches all data sources (including re-calling integration APIs and overwriting per-day source caches), then shows a diff of what changed
- **Connection warnings** — if any integration fails to connect (bad token, network error, etc.) an amber banner appears at the top of the report listing the specific errors
- **Config panel** — toggle the Config panel to flag projects as personal, reassign unmatched signals to projects, and set project groupings, all without editing `config.json` directly

### Caching

Report data is cached at two levels:

1. **Per-day source caches** (`reports/YYYY-MM/DD/activitywatch-*.json`, `chrome-*.json`, `commits-*.json`, `integrations-*.json`) — raw data per calendar day. Historical days are cached once and reused. Clicking "Rebuild from source" re-fetches and overwrites these.
2. **Report JSON** (`reports/YYYY-MM/DD/report-*.json`) — the rendered JSON for a date range, including any LLM-generated summary. Served directly for repeat loads of historical ranges. Rebuild regenerates this from the source caches (without the summary; re-run the CLI or click "Generate day summary" to restore it).

---

## Maintenance tools

All tools live in `tools/` and write a timestamped backup to `reports/config/` before modifying `config.json`.

| Tool                               | What it does                                                                                    |
| ---------------------------------- | ----------------------------------------------------------------------------------------------- |
| `list-github-desktop-repos.php`    | Lists repos from GitHub Desktop; `--apply` adds unconfigured ones to `config.json`              |
| `sync-integration-projects.php`    | Pulls Harvest/ClickUp project catalogs and creates `harvest_projects`/`clickup_tasks` mappings  |
| `set-integration-groupings.php`    | Assigns `grouping` to projects based on `groupings_map` rules in `config.json`                  |
| `cleanup-integration-projects.php` | Merges high-confidence integration stubs back into existing projects (`--dry-run` or `--apply`) |
| `sync-repo-remotes.php`            | Snapshots `git remote` URLs into `projects[*].repo_remotes`                                     |
| `ensure-github-integration.php`    | Adds a default `integrations.github` entry (via `gh` auth) if missing                           |
| `prune-config-backups.php`         | Caps `reports/config/` at `--keep N` most-recent backups; `--max-lines N` caps JSONL log files  |
| `reset-cache.php`                  | Removes report caches (`--before YYYY-MM-DD`, `--month YYYY-MM`); preserves `config.json`       |

### Application log

All subsystems write structured diagnostic entries to `reports/app.jsonl`. Each line is a JSON object:

```json
{
    "time": "2026-05-09 14:23:45",
    "level": "ERROR",
    "source": "llm",
    "message": "[2026-05-08] request failed: HTTP 401 from https://…"
}
```

Useful one-liners:

```bash
# Stream readable output as tab-separated columns
tail -f reports/app.jsonl | jq -r '[.time, .level, .source, .message] | @tsv'

# Errors only
jq 'select(.level == "ERROR")' reports/app.jsonl

# Just LLM entries
jq 'select(.source == "llm")' reports/app.jsonl

# Last 20 entries, pretty-printed
tail -20 reports/app.jsonl | jq .
```

---

## Development

### Linting and static analysis

```bash
composer lint        # PHP_CodeSniffer (PSR-12)
composer lint:fix    # auto-fix what phpcbf can fix
composer analyze     # PHPStan at level 5
composer test        # PHPUnit (87 tests)
composer check       # lint + analyze + test + Prettier format check in one shot
```

### JavaScript

```bash
npm run lint:js       # ESLint on apps/web/static/app.js
npm run format        # reformat JSON/Markdown files with Prettier
npm run format:check  # dry-run check (used in CI)
```

### CI

GitHub Actions runs `composer check` and `npm run lint:js` on every push and pull request (`.github/workflows/ci.yml`). Dependabot keeps Composer, npm, and Actions dependencies up to date weekly.

A pre-commit hook (`.githooks/pre-commit`) runs the same checks locally. It is installed automatically by `composer install` via the `post-install-cmd` script.

---

## Project structure

```
activity-report.php       — root wrapper (delegates to apps/cli/activity-report.php)
apps/
  cli/
    activity-report.php   — CLI entry point: defines PROJECT_ROOT, loads config, calls main()
  web/
    index.php             — router for php -S
    api.php               — JSON endpoint: report generation + config mutations + generate_summary
    report_renderer.php   — HTML shell + static asset references; non-HTML formats served here
    static/
      app.css             — all styles
      app.js              — client-side renderer, admin panel, timeline, day summary
  desktop/                — @timesheets/desktop: React Native macOS app shell (Phase 1 scaffolding)
src/
  config.php              — saveConfigWithBackup(), applySignalToProject(), parseSlackSignal()
  helpers.php             — expandPath(), fmtDur(), appLog(), warning()
  cache.php               — per-day and report-level caching, serialization helpers
  classifiers.php         — signal matching and aggregation
  renderers.php           — Markdown, JSON (with warnings[], timelines[], summaries[]), TSV
  cli.php                 — main(), parseArgs(), generateReport(), backfillRecentDailyReports()
  loader-activitywatch.php
  loader-chrome.php
  loader-git.php          — loadGitCommits() with optional GitHub Desktop discovery
  loader-github-desktop.php — discoverGitHubDesktopRepos() via LevelDB scanning
  loader-integrations.php — orchestrates Harvest/ClickUp/Clockify/GitHub; collects warnings
  integrations/
    shared.php            — httpGetJson()
    harvest.php           — loadHarvestTimeEntries()
    harvest-catalog.php   — loadHarvestProjectCatalog() (shared by sync and groupings tools)
    clickup.php           — loadClickUpTimeEntries()
    clickup-catalog.php   — loadClickUpProjectTree() (shared by sync and groupings tools)
    clockify.php          — resolveClockifyUserInfo(), loadClockifyTimeEntries()
    clockify-catalog.php  — clockifyFetchProjectNames() (for future sync tooling)
    github.php            — CLI-only; githubFetchCommits/PullRequests/Issues/Comments
    llm.php               — llmSuggestAssignments(), llmDailySummary()
tools/
  list-github-desktop-repos.php
  sync-integration-projects.php
  cleanup-integration-projects.php
  sync-repo-remotes.php
  ensure-github-integration.php
  prune-config-backups.php
  reset-cache.php
packages/                 — TypeScript monorepo workspace; shared by all non-PHP surfaces (see NATIVE.md)
  contracts/              — @timesheets/contracts: JS type definitions matching PHP JSON output shape
  engine/                 — @timesheets/engine: TypeScript engine (replaces PHP core; same config.json + reports/ layout)
  ui/                     — @timesheets/ui: React Native components (peerDep on react-native-macos)
  test-fixtures/          — @timesheets/test-fixtures: golden fixture data for PHP–TypeScript parity tests
reports/                  — gitignored; all generated data lives here
  app.jsonl               — structured application log (all subsystems)
  cache-data.jsonl        — index of per-day source cache files
  generated-reports.jsonl — index of generated report artifacts
  config/                 — timestamped config.json backups
  YYYY-MM/DD/             — per-day source caches and report JSON files
config.json               — your local config (gitignored)
config.example.json       — safe-to-commit template
config.schema.json        — JSON Schema for editor validation
AGENTS.md                 — architecture guide for contributors and AI agents
NATIVE.md                 — native desktop migration plan (React Native + TypeScript engine)
SECURITY.md               — local threat model and token-handling notes
TROUBLESHOOTING.md        — common failures and fixes
```

## License

MIT
