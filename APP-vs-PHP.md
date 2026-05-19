# App vs PHP — Surface Divergence

A side-by-side comparison of the React Native macOS desktop app and the PHP surfaces
(CLI + web UI). Use this as a working reference for identifying feature gaps and deciding
what to build next.

Legend: ✅ present · ⚠️ partial/different · ❌ absent · 🔒 intentionally absent

---

## Report viewing

| Feature                       | PHP web UI                   | PHP CLI                       | Desktop app                        |
| ----------------------------- | ---------------------------- | ----------------------------- | ---------------------------------- |
| Day navigation (prev/next)    | ✅ keyboard + buttons        | ✅ `--from`/`--to` flags      | ✅ buttons                         |
| Date range (multi-day)        | ✅ from/to picker            | ✅ `--days`, `--from`, `--to` | ❌ single day only                 |
| Jump to specific date         | ✅ date picker input         | ✅ flags                      | ❌ no calendar picker              |
| Project filter                | ✅ client-side dropdown      | ✅ `--project NAME`           | ❌ no filter                       |
| Grouping filter               | ✅ group dropdown            | ❌                            | ❌                                 |
| Timeline visualization        | ✅ interactive color bars    | ❌                            | ⚠️ read-only, 7am–9pm              |
| Segment detail on hover       | ✅ tooltip                   | ❌                            | ❌                                 |
| Caching indicator             | ✅ badge (cached/generated)  | ❌                            | ❌                                 |
| Warning banner                | ✅ amber banner              | ✅ STDERR                     | ✅ amber banner                    |
| Rebuild from source           | ✅ button                    | ✅ (every run)                | ✅ button                          |
| Backfill prior 7 days         | ❌                           | ✅ (no-arg default, cron)     | ❌                                 |
| Per-project activity bar      | ❌                           | ❌                            | ✅ input % bar                     |
| Commits list                  | ✅                           | ✅ inline                     | ✅ collapsible (up to 8)           |
| Integration badges            | ❌ (sidebar replaces)        | ✅ entry counts in text       | ✅ Harvest/ClickUp/GitHub/Clockify |
| Harvest time-tracking sidebar | ✅ sticky right panel        | ❌                            | ❌                                 |
| Harvest gap detection         | ✅ highlights unlogged hours | ❌                            | ❌                                 |
| LLM day summary               | ✅ on-demand button          | ✅ auto on single-day run     | ✅ on-demand button                |
| Export (md/json/tsv)          | ❌                           | ✅ `--format`                 | ❌                                 |
| `--show-unmatched` debug      | ❌                           | ✅                            | ❌                                 |
| `--list-projects`             | ❌                           | ✅                            | ⚠️ Config → Projects tab           |

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

| Feature                           | PHP web UI                                 | PHP CLI                          | Desktop app                        |
| --------------------------------- | ------------------------------------------ | -------------------------------- | ---------------------------------- |
| View unmatched signals            | ⚠️ config panel (current report)           | ✅ `--show-unmatched`            | ✅ Signals tab (today)             |
| Reassign signal to project        | ✅ per-signal dropdown                     | ✅ `--suggest` interactive       | ✅ per-signal picker + Assign      |
| Mark signal personal              | ✅ `__personal__` target                   | ❌                               | ❌ no personal bucket target       |
| Mark signal correlated            | ✅ `__correlated__` target                 | ❌                               | ❌                                 |
| Create new project from reassign  | ✅ inline                                  | ❌                               | ❌ must create project first       |
| LLM batch suggestions             | ❌                                         | ✅ `--suggest` (interactive CLI) | ✅ "Suggest with AI" batch display |
| Draft sync on assign              | ❌                                         | ✅ writes config immediately     | ✅ updates draft + calls sidecar   |
| Signal date (only today)          | ❌                                         | ✅ any date                      | ⚠️ today only                      |
| Browse/edit existing signal rules | ⚠️ limited in config panel                 | ❌ manual edit                   | ⚠️ via ProjectDrawer fields        |
| Harvest time-logging suggestions  | ⚠️ via LLM button (`suggest_time_logging`) | ❌                               | ❌ deferred                        |

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
| Cache indicator to user       | ✅ badge             | ❌                   | ❌                                     |
| App log (`reports/app.jsonl`) | ✅                   | ✅                   | ⚠️ engine writes; UI doesn't expose it |

---

## Identified gaps (prioritized)

The following are desktop gaps worth addressing, roughly ordered by user impact:

### High impact

1. **Multi-day date range** — The desktop is day-by-day only. Weekly views and ranged
   reports are the primary use case for review/invoicing. Adding a week/range mode to
   `ReportScreen` would close the biggest functional gap.

2. **Project filter** — Power users routinely filter to a single project. A filter
   dropdown in the Reports toolbar would be a small addition with high daily-use value.

3. **Harvest gap detection** — The web UI's sticky Harvest sidebar with logged-vs-tracked
   comparison is a key accountability feature. The desktop shows Harvest badge counts but
   no gap analysis. Adding a collapsible Harvest summary panel to `DayView` would close
   this.

4. **`__personal__` signal target** — Signal reassignment in the desktop can only assign
   to a named project; there is no equivalent of flagging a signal as personal/ignored.
   `reassign-signal` should accept a `__personal__` target and the Signals tab should
   offer "Mark as personal" alongside the project picker.

### Medium impact

5. **Backfill trigger** — No way to trigger the PHP-style "backfill last 7 days" workflow
   from the desktop. A "Backfill recent days" button in Reports (calling
   `GET /report?rebuild=1` for each of the prior 7 days sequentially) would make the
   desktop usable as a standalone daily driver without needing the cron job.

6. **Inline new project creation from Signals** — When assigning an unmatched signal, the
   user must first go to the Projects tab, add the project, save, then return to Signals.
   An inline "New project…" option in the signal's project picker would close this.

7. **Harvest/ClickUp sync tool in UI** — `sync-integration-projects.php` is the main
   onboarding accelerator for integration users. A "Sync projects from Harvest/ClickUp"
   button in the Integrations tab (calling a new sidecar endpoint) would remove the CLI
   dependency for a common setup task.

8. **Date picker / calendar** — The desktop relies on prev/next buttons only. A tappable
   date label that opens a date picker (or at minimum a text entry) would match the web
   UI's navigation flexibility.

### Lower impact / nice-to-have

9. **Cache status indicator** — Web UI shows a badge for cached vs. freshly generated
   reports. A subtle subtitle in the Reports header ("cached · 2h ago") would add
   transparency.

10. **Cron / backfill status** — No visibility into whether daily backfill has run. A
    small "last updated" timestamp in the Reports header would surface this.

11. **Export from desktop** — `--format json|tsv|md` has no desktop equivalent. A share
    sheet or export action in the Reports toolbar would unlock the spreadsheet/invoice
    workflow from the desktop.

12. **`correlated` signal target** — Same gap as `__personal__`: the desktop Signals tab
    cannot mark a signal as correlated. Low-frequency but needed for parity.

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
