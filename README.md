# activity-report

A PHP reporting tool (CLI + local web UI) that aggregates local activity data from [ActivityWatch](https://activitywatch.net/), Chrome history, Git, and optional Harvest/ClickUp APIs into a project-attributed time report.

## How it works

Every few seconds, ActivityWatch records which app and window title is in focus. With `aw-watcher-input` enabled, it also records keyboard/mouse/scroll activity slices. This script reads that data, correlates it with Chrome browsing history, Git commits, and optional Harvest/ClickUp time-entry feeds, then classifies each event into a named **project** based on rules you define in `config.json`. The result is a per-day, per-project breakdown of where your time went, including active-input and external-integration metrics.

```
## 2026-04-28 (Mon) — 7h 22m active

### Acme Corp — 4h 15m
- vscode: acme-backend (2h 40m), acme-frontend (1h 10m)
- browser: staging.acme.com (18m), docs.acme.com (7m)
- input activity: 2h 58m (70%)
- commits (3):
    - `09:14` `a1b2c3d4` Fix null pointer in auth middleware
    - `11:02` `e5f6a7b8` Add unit tests for token refresh
    - `14:38` `c9d0e1f2` Bump API version to 2.1
```

## Requirements

- PHP 8.1+
- [ActivityWatch](https://activitywatch.net/) running locally (macOS)
- Google Chrome (optional — for browser signal matching)
- Git (optional — for commit attribution)

## Installation

```bash
git clone https://github.com/georgestephanis/timesheets.git
cd timesheets
cp config.example.json config.json
```

Edit `config.json` to add your projects, email addresses, and local paths. The file is gitignored so your personal data stays local.

### Dev tools (optional)

```bash
composer install   # installs PHP_CodeSniffer
npm install        # installs Prettier
```

## Usage

```
php activity-report.php [options]

    No args              Generate one full report per day for the prior 7 completed days
    --days N             Look back N days
  --from YYYY-MM-DD    Explicit start date (overrides --days)
  --to   YYYY-MM-DD    Explicit end date (default = today)
  --project NAME       Filter output to one project
  --format md|json|tsv Output format (default md)
  --show-unmatched     Append unclassified signals — useful for tuning config
  --list-projects      Print configured projects and exit
  -h, --help           Show this message
```

The script is executable, so you can also run it directly if `php` is on your PATH:

```bash
chmod +x activity-report.php
./activity-report.php --days 14 --format json > report.json
```

With no flags, the CLI backfills daily artifacts for the previous seven completed calendar days. Each day is generated as its own single-day report. If a day's newest full report was created before midnight at the end of that day, it is regenerated; otherwise the existing artifact is kept.

## Configuration

Copy `config.example.json` to `config.json` and fill in your details. The file is validated against `config.schema.json`, so editors with JSON Schema support (VS Code, JetBrains) will autocomplete and flag errors automatically.

### Top-level fields

| Field                               | Type        | Description                                                                                       |
| ----------------------------------- | ----------- | ------------------------------------------------------------------------------------------------- |
| `timezone`                          | string      | IANA timezone name for all output (e.g. `America/New_York`)                                       |
| `paths.activitywatch`               | string      | Path to ActivityWatch data directory                                                              |
| `paths.chrome`                      | string      | Path to Chrome user-data directory                                                                |
| `paths.chrome_profiles`             | array\|null | Profile folders to scan; `null` = auto-discover all                                               |
| `git_authors`                       | string[]    | Your commit author email address(es)                                                              |
| `chrome_correlation_window_seconds` | int         | How far back (in seconds) to look in Chrome history when back-filling a missing URL (default 120) |
| `min_event_seconds_to_show`         | int         | Hide activity segments shorter than this (default 30)                                             |
| `projects`                          | object      | Named project definitions (see below)                                                             |
| `personal_hosts`                    | string[]    | Browser hostnames to bucket as personal, not work                                                 |
| `personal_apps`                     | string[]    | App names (as reported by ActivityWatch) to bucket as personal                                    |
| `ignored_projects`                  | string[]    | Project names to exclude from classification and reporting                                        |
| `integrations`                      | object      | Optional external sources (`harvest[]`, `clickup[]`)                                              |

### Project signals

Each project in `projects` is an object whose keys are all optional — include only what applies:

```jsonc
"Acme Corp": {
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
    "clickup_tasks": ["*acme*"]
}
```

### External integrations (optional)

Multiple personal-access-token connections are supported for each provider:

```jsonc
"integrations": {
    "harvest": [
        {
            "name": "Harvest Main",
            "account_id": "123456",
            "token": "HARVEST_PERSONAL_ACCESS_TOKEN",
            "user_id": "1234567"
        }
    ],
    "clickup": [
        {
            "name": "ClickUp Main",
            "team_id": "1234567",
            "token": "CLICKUP_PERSONAL_ACCESS_TOKEN",
            "assignee": "me"
        }
    ]
}
```

### Tuning with `--show-unmatched`

Run with `--show-unmatched` to see which VSCode dirs, browser hosts, and Slack channels weren't matched by any project rule. Use this output to fill in gaps in your config.

```bash
php activity-report.php --show-unmatched
```

## Output formats

| Format   | Flag                    | Use case                                       |
| -------- | ----------------------- | ---------------------------------------------- |
| Markdown | `--format md` (default) | Reading in terminal or pasting into a doc      |
| JSON     | `--format json`         | Piping into `jq`, importing into a spreadsheet |
| TSV      | `--format tsv`          | Opening in Excel / Numbers                     |

## HTML Output

If you'd like to start a HTTP webserver locally, run the following:

```bash
php -S localhost:8000 www/index.php
```

This will give you a UI to view the reports more aesthetically than markdown, if desired.

The web UI always fetches and caches full-range JSON snapshots; project/group filtering in the UI is applied client-side to the already-loaded data.

## Report artifacts and filtering

- Generated report artifacts are persisted as full-range snapshots for each date range.
- Raw source caches are persisted only as single-day JSON buckets under `reports/YYYY-MM/DD/`.
- Multi-day reports reuse those daily source caches for completed days instead of writing range-wide source caches.
- CLI `--project` filtering still controls what is printed to STDOUT.
- Non-HTML outputs can still be requested with `--format`, but saved artifacts remain full-range.

## Development

### Linting

```bash
composer lint        # run PHP_CodeSniffer (PSR-12)
composer lint:fix    # auto-fix what phpcbf can fix
```

### Formatting

```bash
npm run format        # reformat JSON files with Prettier
npm run format:check  # dry-run check (used in CI)
```

## Adding a new signal type or output format

The codebase is intentionally function-oriented with no classes. See [AGENTS.md](AGENTS.md) for a full map of functions and the data flow.

## Project structure

```
activity-report.php   — entry point + config/bootstrap
src/                  — functional modules (cli/loaders/classifiers/renderers/cache/helpers)
www/                  — local web UI + API router
config.json           — your local config (gitignored)
config.example.json   — safe-to-commit template
config.schema.json    — JSON Schema for editor validation
AGENTS.md             — architecture guide for contributors and AI agents
phpcs.xml.dist        — PHP_CodeSniffer ruleset
composer.json         — dev dep: PHP_CodeSniffer
package.json          — dev dep: Prettier
```

## License

MIT
