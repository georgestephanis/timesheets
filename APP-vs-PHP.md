# App vs PHP — Surface Divergence

A side-by-side comparison of the React Native macOS desktop app and the PHP surfaces
(CLI + web UI). Use this as a working reference for identifying feature gaps and deciding
what to build next.

Legend: ✅ present · ⚠️ partial/different · ❌ absent · 🔒 intentionally absent

---

## Report viewing

| Feature                       | PHP web UI                   | PHP CLI                       | Desktop app                               |
| ----------------------------- | ---------------------------- | ----------------------------- | ----------------------------------------- |
| Day navigation (prev/next)    | ✅ keyboard + buttons        | ✅ `--from`/`--to` flags      | ✅ buttons                                |
| Date range (multi-day)        | ✅ from/to picker            | ✅ `--days`, `--from`, `--to` | ✅ Day/Week toggle, 7-day range           |
| Jump to specific date         | ✅ date picker input         | ✅ flags                      | ✅ tap date label → YYYY-MM-DD input      |
| Project filter                | ✅ client-side dropdown      | ✅ `--project NAME`           | ✅ toolbar dropdown                       |
| Grouping filter               | ✅ group dropdown            | ❌                            | ❌                                        |
| Timeline visualization        | ✅ interactive color bars    | ❌                            | ⚠️ read-only, 7am–9pm                     |
| Segment detail on hover       | ✅ tooltip                   | ❌                            | ❌                                        |
| Caching indicator             | ✅ badge (cached/generated)  | ❌                            | ✅ "cached · Xh Ym ago" badge in toolbar  |
| Warning banner                | ✅ amber banner              | ✅ STDERR                     | ✅ amber banner                           |
| Rebuild from source           | ✅ button                    | ✅ (every run)                | ✅ button                                 |
| Backfill prior 7 days         | ❌                           | ✅ (no-arg default, cron)     | ✅ toolbar button (rebuilds prior 7 days) |
| Per-project activity bar      | ❌                           | ❌                            | ✅ input % bar                            |
| Commits list                  | ✅                           | ✅ inline                     | ✅ collapsible (up to 8)                  |
| Integration badges            | ❌ (sidebar replaces)        | ✅ entry counts in text       | ✅ Harvest/ClickUp/GitHub/Clockify        |
| Harvest time-tracking sidebar | ✅ sticky right panel        | ❌                            | ❌                                        |
| Harvest gap detection         | ✅ highlights unlogged hours | ❌                            | ✅ collapsible panel with gap badge       |
| LLM day summary               | ✅ on-demand button          | ✅ auto on single-day run     | ✅ on-demand button                       |
| Export (md/json/tsv)          | ❌                           | ✅ `--format`                 | ❌                                        |
| `--show-unmatched` debug      | ❌                           | ✅                            | ❌                                        |
| `--list-projects`             | ❌                           | ✅                            | ⚠️ Config → Projects tab                  |

---

## Config editing

| Feature                         | PHP web UI                       | PHP CLI                            | Desktop app                       |
| ------------------------------- | -------------------------------- | ---------------------------------- | --------------------------------- |
| Edit paths (AW, Chrome)         | ⚠️ limited config panel          | ❌ manual edit                     | ✅ General tab                    |
| Edit git authors                | ❌                               | ❌ manual edit                     | ✅ General tab                    |
| Chrome profile auto-detect      | ❌                               | ❌                                 | ✅ checkbox in General tab        |
| Edit timing / threshold fields  | ❌                               | ❌ manual edit                     | ✅ General tab                    |
| Add / edit / delete projects    | ⚠️ reassign only; no full editor | ❌ manual edit                     | ✅ Projects tab + ProjectDrawer   |
| GitHub Desktop repo discovery   | 🔒                               | ✅ `list-github-desktop-repos.php` | ✅ discover panel in Projects tab |
| Delete / ignore project         | ❌                               | ❌                                 | ✅ buttons in ProjectDrawer       |
| Rename project                  | ❌                               | ❌                                 | ✅ inline rename in ProjectDrawer |
| Edit project signals inline     | ❌                               | ❌                                 | ✅ ProjectDrawer fields           |
| Groupings CRUD                  | ⚠️ set grouping only             | ❌                                 | ✅ Groupings tab                  |
| Grouping color / logo / aliases | ❌                               | ❌                                 | ✅ Groupings tab cards            |
| Integrations CRUD               | ❌                               | ❌ manual edit                     | ✅ Integrations tab               |
| First-launch config path picker | 🔒                               | 🔒                                 | ✅ NSOpenPanel                    |
| Switch config directory         | 🔒                               | 🔒                                 | ✅ button in header               |
| Save with backup                | ✅ (all mutations)               | ✅ `--suggest` accepts             | ✅ Save button                    |
| Discard / revert draft          | ❌                               | ❌                                 | ✅ Discard button                 |
| Unsaved-change guard            | ❌                               | ❌                                 | ✅ popstate + beforeunload        |
| Schema validation before save   | ❌                               | ❌                                 | ✅ zod                            |

---

## Signal management

| Feature                           | PHP web UI                                 | PHP CLI                          | Desktop app                           |
| --------------------------------- | ------------------------------------------ | -------------------------------- | ------------------------------------- |
| View unmatched signals            | ⚠️ config panel (current report)           | ✅ `--show-unmatched`            | ✅ Signals tab (today)                |
| Reassign signal to project        | ✅ per-signal dropdown                     | ✅ `--suggest` interactive       | ✅ per-signal picker + Assign         |
| Mark signal personal              | ✅ `__personal__` target                   | ❌                               | ✅ Personal (ignore) option in picker |
| Mark signal correlated            | ✅ `__correlated__` target                 | ❌                               | ✅ Correlated option in picker        |
| Create new project from reassign  | ✅ inline                                  | ❌                               | ✅ "+ New project…" inline in picker  |
| LLM batch suggestions             | ❌                                         | ✅ `--suggest` (interactive CLI) | ✅ "Suggest with AI" batch display    |
| Draft sync on assign              | ❌                                         | ✅ writes config immediately     | ✅ updates draft + calls sidecar      |
| Signal date (only today)          | ❌                                         | ✅ any date                      | ⚠️ today only                         |
| Browse/edit existing signal rules | ⚠️ limited in config panel                 | ❌ manual edit                   | ⚠️ via ProjectDrawer fields           |
| Harvest time-logging suggestions  | ⚠️ via LLM button (`suggest_time_logging`) | ❌                               | ❌ deferred                           |

---

## LLM features

| Feature                             | PHP web UI                | PHP CLI                      | Desktop app                    |
| ----------------------------------- | ------------------------- | ---------------------------- | ------------------------------ |
| Daily accomplishment summary        | ✅ on-demand button       | ✅ auto on single-day run    | ✅ "✦ Generate Summary" button |
| Summary saved to report cache       | ✅                        | ✅                           | ✅                             |
| Signal assignment suggestions       | ❌                        | ✅ `--suggest` (interactive) | ✅ Signals tab (batch)         |
| Harvest/ClickUp logging suggestions | ✅ `suggest_time_logging` | ❌                           | ❌                             |
| Weekly/monthly narrative            | ❌                        | ❌                           | ❌                             |
| Learning from corrections           | ❌                        | ❌                           | ❌                             |

---

## Maintenance tooling (PHP CLI only)

These tools exist only in `tools/` and have no desktop equivalent. Each writes a
timestamped backup to `reports/config/` before modifying `config.json`.

| Tool                               | What it does                                                                     |
| ---------------------------------- | -------------------------------------------------------------------------------- |
| `sync-integration-projects.php`    | Pulls Harvest/ClickUp catalogs; creates `harvest_projects`/`clickup_tasks` globs |
| `set-integration-groupings.php`    | Assigns `grouping` to projects via `groupings_map` rules                         |
| `cleanup-integration-projects.php` | Merges high-confidence integration stubs into existing projects                  |
| `sync-repo-remotes.php`            | Snapshots `git remote` URLs into `projects[*].repo_remotes`                      |
| `ensure-github-integration.php`    | Adds default `integrations.github` entry via `gh auth`                           |
| `prune-config-backups.php`         | Caps `reports/config/` backups; trims JSONL indexes                              |
| `reset-cache.php`                  | Removes per-day source caches by date range                                      |

None of these have desktop equivalents yet. The most impactful gap is
`sync-integration-projects.php` — without it, users must manually write
`harvest_projects` and `clickup_tasks` globs.

---

## Data / IPC

| Aspect                        | PHP web UI           | PHP CLI              | Desktop app                            |
| ----------------------------- | -------------------- | -------------------- | -------------------------------------- |
| Reads ActivityWatch SQLite    | ✅ via PHP PDO       | ✅ via PHP PDO       | ✅ via Node `better-sqlite3`           |
| Reads Chrome SQLite           | ✅                   | ✅                   | ✅                                     |
| Reads Git commits             | ✅                   | ✅                   | ✅                                     |
| Reads GitHub (PRs, issues)    | 🔒 CLI-only          | ✅                   | ✅ (via engine sidecar)                |
| Harvest API                   | ✅                   | ✅                   | ✅ (via engine sidecar)                |
| ClickUp API                   | ✅                   | ✅                   | ✅ (via engine sidecar)                |
| Clockify API                  | ✅                   | ✅                   | ✅ (via engine sidecar)                |
| Config path resolution        | cwd or `php -S` dir  | cwd                  | NSUserDefaults + picker                |
| Reports cache location        | `reports/` under cwd | `reports/` under cwd | same as PHP (same path)                |
| Per-day source cache          | ✅                   | ✅                   | ✅                                     |
| Cache indicator to user       | ✅ badge             | ❌                   | ✅ "cached · Xh Ym ago" toolbar badge  |
| App log (`reports/app.jsonl`) | ✅                   | ✅                   | ⚠️ engine writes; UI doesn't expose it |

---

## Identified gaps (prioritized)

The following are desktop gaps worth addressing, roughly ordered by user impact:

### High impact

1. **Harvest/ClickUp sync tool in UI** — `sync-integration-projects.php` is the main
   onboarding accelerator for integration users. A "Sync projects from Harvest/ClickUp"
   button in the Integrations tab (calling a new sidecar endpoint) would remove the CLI
   dependency for a common setup task.

### Lower impact / nice-to-have

2. **Cron / backfill status** — No visibility into whether daily backfill has run. A
   small "last updated" timestamp in the Reports header would surface this.

3. **Export from desktop** — `--format json|tsv|md` has no desktop equivalent. A share
   sheet or export action in the Reports toolbar would unlock the spreadsheet/invoice
   workflow from the desktop.

---

## PHP-only by design

These are intentionally absent from the desktop and do not need to be ported:

- **`--format md|json|tsv`** CLI pipe output — the desktop is a GUI app; export (if added)
  would be a different UX than stdout piping.
- **`composer serve` / `php -S`** — replaced by the native sidecar.
- **`apps/web/static/app.js` web UI** — the web UI remains the browser-based surface;
  the desktop is a separate native app, not a web wrapper.
- **GitHub integration CLI-only restriction** — the web UI skips GitHub fetches to avoid
  blocking page loads. The desktop sidecar has no such constraint; GitHub data flows
  normally via the engine.
