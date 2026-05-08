# TODO

This document captures findings from a full code review and the prioritized
improvements that come out of them. Read the **Critique** section for context,
then the prioritized lists below.

Severity legend: **P0** ship-blocking, **P1** significant, **P2** worth doing,
**P3** nice-to-have. Effort: **(s)** small, **(m)** medium, **(l)** large.

---

## Critique

### What works well

- Plain functions, no autoloader, no DI containers — keeps the project legible
  for the size it is. The `require_once` chain in `activity-report.php` is the
  whole boot sequence. Small projects do not need frameworks.
- Two-level cache (per-day source caches + range-level report caches) is the
  right shape: it reduces both API hits and re-classification cost.
- Backup-before-mutate convention is universal across tools; combined with the
  gitignored `reports/config/` directory it offers a real undo path.
- The integration-warnings pattern (collect to global, render in JSON, banner in
  UI) elegantly surfaces transient API failures without breaking reports.
- Schema-driven config (`config.schema.json`) gives editor-time validation.

### What hurts

- **The 920-line `www/report_renderer.php` is the biggest tech-debt cliff.** It
  mixes PHP, HTML, CSS, and ~700 lines of embedded vanilla JS. There is no JS
  linter, no minification, no source map, no module system. A frontend developer
  cannot work on it without first detangling the layers.
- **Duplication across `api.php`, `cli.php`, and `tools/*.php`.** The
  `reassign_signal` switch in `api.php` is reimplemented as `applySignalToConfig`
  in `cli.php`. The "load → backup → mutate → write" dance is repeated in 6+
  tools, each with subtly different error paths. The Harvest project list +
  ClickUp tree-walk is duplicated verbatim between
  `sync-integration-projects.php` and `set-integration-groupings.php`.
- **Untyped `array $config` everywhere.** Nearly every function takes a config
  array of unknown shape; key typos surface at runtime, not at lint time. A
  `Config` value object (read-only, typed accessors) would prevent a class of
  bugs and make the function signatures self-documenting.
- **No tests.** ~6400 lines of PHP, no PHPUnit, no CI. The pure-function helpers
  (`classifyVscode`, `applySignalToConfig`, `fmtDur`, `bsearchRight`,
  `chromeTime`, `awEpochToDateTime`, `githubRepoFromRemoteUrl`) are easy unit-
  test targets that would catch regressions cheaply.
- **No static analyzer.** PHPStan even at level 3 would flag many of the silent-
  on-failure paths (e.g. `json_decode` with no error check, `?? null` chains
  hiding type confusion).
- **Concurrent writers to `config.json` are not coordinated.** The web UI and
  the CLI can both mutate the file with no locking. `loadIntegrationActivity`
  auto-persists resolved IDs without `LOCK_EX`.
- **`tools/` is excluded from `phpcs`.** That's why the tools have drifted in
  style.

### Architecture moves worth making

1. Extract a `src/config.php` with `loadConfig()`, `saveConfigWithBackup($source)`
   using atomic temp-file rename + `LOCK_EX`, and the shared `applySignalToProject`,
   `addUniqueValue`, `parseSlackSignal` helpers. Rewire `api.php`, `cli.php`, and
   every tool to use it. Eliminates the `saveConfigJson` / `applySignalToConfig` /
   per-tool copy duplication.
2. Move the embedded JS/CSS out of `www/report_renderer.php` to
   `www/static/app.{js,css}`. Keep only the HTML shell + `<?= $jsConfig ?>`
   inline. Add a JS linter (eslint with the recommended config, no plugins
   needed).
3. Split `src/integrations/github.php` (557 lines, deeply nested loops) into
   per-resource helpers: `fetchGitHubCommits`, `fetchGitHubPullRequests`, etc.
   The current shape obscures which paginated request affects which row type.
4. Hoist Harvest project-list and ClickUp tree-walk into
   `src/integrations/harvest-catalog.php` / `clickup-catalog.php`. Both
   `sync-integration-projects.php` and `set-integration-groupings.php` should
   import the same loader.
5. Reorganize `src/`: move `loader-*.php` into `src/loaders/`, integrations stay
   in `src/integrations/`, web concerns into `src/web/`. The flat `src/` is fine
   today but breaks down as more files arrive.

### Tooling worth adding

- **PHPStan** (`composer require --dev phpstan/phpstan`) at level 5 with a
  baseline file. Type-narrows the config-array bugs.
- **PHPUnit** (`composer require --dev phpunit/phpunit`) covering pure functions
  to start; integration tests later.
- **ESLint** + **Prettier** for the JS in `www/` once it's extracted.
- **GitHub Actions CI** that runs `composer lint`, `phpstan`, `phpunit`,
  `npm run format:check`, `eslint` on push.
- **`.editorconfig`** for consistent indentation across editors.
- **A `composer check` script** that chains lint + analyze + test.
- **Pre-commit hook** (Husky or lefthook) that runs the lint/format checks.

---

## Cleanup (do first)

- [x] **(P0, s)** Delete the stray `config.json.bak.*` files at the repo root —
      they violate the "backups go in `reports/config/`" convention. Three files
      from May 3 are still present.
- [x] **(P1, s)** Add `tools/` to `phpcs.xml.dist`. Currently excluded; tool
      files have drifted in style.
- [x] **(P1, s)** Update README and AGENTS.md to remove references to "vLLM"
      where it really means "any OpenAI-compatible endpoint." The current LLM
      module is provider-agnostic.

## Security

- [x] **(P1, m)** Add `Origin` / `Referer` check to `www/api.php` POST handlers.
      Local-only is not a defense if a malicious page does a DNS rebinding or
      simply targets `localhost:8000` on a developer machine. Reject POSTs whose
      `Origin` is not `http://localhost:*`.
- [x] **(P1, s)** Atomic config writes everywhere. Replace `file_put_contents`
      on `config.json` with: write to `config.json.tmp`, `LOCK_EX`, `rename()`.
      Currently 7+ call sites do unlocked overwrites and races are possible.
      Done in `api.php` (`saveConfigJson`) and `loader-integrations.php`. Tools
      each have their own `saveConfigJson` copies — remaining when `src/config.php`
      is extracted (Modularity P1).
- [x] **(P1, s)** Path traversal in `www/index.php`: `__DIR__ . $path`
      concatenates the URL path without normalization. Reject paths that
      contain `..` or that resolve outside `__DIR__`.
- [x] **(P2, s)** LLM prompt injection hardening: signal values now pass through
      `str_replace(["\n","\r","\t"], ' ', ...)` + `mb_substr(..., 200)` before
      entering the prompt and `$signalSet`. Applied in `llmSuggestAssignments`.
- [x] **(P2, s)** `(no url)` placeholder now skipped in `llmSuggestAssignments`
      signal loop before it reaches the prompt, not just after the LLM suggests it.
- [ ] **(P2, m)** Document the threat model in `SECURITY.md`: this is a local
      tool that holds OAuth tokens for Harvest, ClickUp, GitHub, and an LLM key.
      Note the plaintext config and recommend filesystem permissions
      (`chmod 600 config.json`).
- [ ] **(P3, l)** Optional macOS Keychain integration for tokens, with config
      values like `"token": "@keychain:harvest-main"` resolved at load time.

## Correctness / behavior

- [x] **(P1, m)** Chrome history loader runs `SELECT visit_time, ... FROM visits`
      with **no WHERE clause** then filters in PHP (`loader-chrome.php:45-58`).
      For multi-month history this is slow and uses unbounded memory. Add
      `WHERE visit_time BETWEEN ? AND ?` using Chrome's microsecond-since-1601
      epoch.
- [x] **(P1, s)** AW Rust loader (`loader-activitywatch.php:262-271`) likewise
      has no time filter in SQL — fetches all events per bucket, filters in PHP.
      Add `WHERE starttime >= ? AND endtime <= ?` to the query.
- [x] **(P1, s)** ClickUp `loadClickUpTimeEntries` does not paginate. Anyone
      with >100 entries in the queried range loses data silently. Add page loop
      similar to Harvest.
- [x] **(P1, s)** Harvest `loadHarvestTimeEntries` has no max-page guard. Add a
      sanity cap (e.g. 100 pages = 10k entries) to prevent infinite loops on
      malformed responses.
- [x] **(P1, s)** Race condition in `loadIntegrationActivity` config persistence
      (`loader-integrations.php:92-110`): re-reads `$existing` from disk, then
      indexes by integer `$idx` from the in-memory array. If a user reorders
      the array between reads, the wrong slot is updated. Match by connection
      `name` instead of array index.
- [x] **(P2, s)** `backfillChromeUrls` matched by event midpoint. Changed to use
      the event start time — finds the latest Chrome visit at or before start
      within `$windowSec`. Three new PHPUnit tests cover the match, post-start
      miss, and window-exceeded cases.
- [x] **(P2, s)** `renderTsv` did not escape tabs or newlines in project names.
      Added a `$tsv` closure that replaces `\t`, `\r\n`, `\r`, `\n` with space;
      applied to `$grouping` and `$proj` fields.
- [x] **(P2, s)** Frontend re-fetches on every Prev/Next even when the date is
      the same as before. Added `responseCache` (Map keyed by `from|to|days`);
      non-rebuild navigations are served from cache instantly.
- [x] **(P2, s)** Frontend `fetchAndRender` clears `#report` on error but does
      not re-render `nav` — first-fetch-fails leaves the page navless. Error path
      now calls `renderNav(params, ...)` + `bindNavEvents()` directly.
- [x] **(P2, s)** Frontend has no `fetch()` timeout. Added a 15-second
      `setTimeout` that aborts the current `AbortController`.
- [x] **(P2, s)** Frontend admin actions (`flag_projects_personal`, etc.) use
      `alert()` for errors; replaced with `showAdminError()` — inline `.error`
      span appended to the triggering `.admin-row`.
- [x] **(P2, s)** Rebuild button is not disabled while a fetch is in flight.
      Click handler now sets `disabled` before calling `fetchAndRender`; re-render
      on completion resets it.
- [x] **(P2, s)** No request coordination: rapid Prev/Next clicks race. Added
      `currentAbortController`; each `fetchAndRender` call aborts the previous
      one. Stale AbortErrors are silently discarded.
- [x] **(P3, s)** `addDays` uses local-time `Date` arithmetic which can shift
      across DST transitions. Switched to `Date.UTC` + `getUTC*` getters.
- [x] **(P3, s)** Slack signal parsing duplicated between `api.php`
      (`parseSlackSignal`) and `cli.php` (`applySignalToConfig`). Move to a
      shared helper. Both now live in `src/config.php`.

## Performance

- [x] **(P2, m)** Chrome and AW SQL queries: see Correctness P1 items. Adding
      `WHERE` clauses is the single biggest cold-start improvement. Done (both
      loaders now filter by date range in SQL).
- [x] **(P2, m)** `mergeSourceBundles` called `array_merge` per bundle (O(N²)).
      Replaced with `array_push($arr, ...(array)$slice)` — extends in place, O(N) total.
- [ ] **(P2, m)** GitHub fetch is 5 endpoints × N repos × M authors. With
      GitHub-Desktop discovery enabled (which can be 50+ repos), this exhausts
      the 8s web budget every time. Options: GraphQL aggregation, parallel
      `proc_open` of `gh api`, or a per-repo last-checked timestamp so we only
      pull deltas.
- [ ] **(P3, m)** Frontend re-renders the whole report on filter change. Split
      `renderCurrentView()` into `renderReportOnly()` so a project-filter
      change doesn't redraw nav, sidebar, and admin panel.
- [ ] **(P3, s)** Frontend builds harvest sidebar in two passes
      (`renderHarvestSidebar` in `www/static/app.js`). One pass suffices.
- [ ] **(P3, s)** `Intl.DateTimeFormat` for DOW formatting should be cached at
      module scope rather than constructed per-day in `renderDay`.
- [ ] **(P3, m)** Per-day source caches: 4 JSON file reads per day. For 7-day
      backfill that's 28 reads; consider a single `daily-{date}.json` envelope.

## Modularity / refactoring

- [x] **(P1, l)** Extract embedded JS/CSS from `www/report_renderer.php` into
      `www/static/app.js` and `www/static/app.css`. Keep `report_renderer.php`
      to ~100 lines: HTML shell + `<?= $jsConfig ?>` + `<script src="...">`.
      Done — `report_renderer.php` is now 159 lines; JS in `www/static/app.js`,
      CSS in `www/static/app.css`. ESLint remains to be added.
- [x] **(P1, m)** Create `src/config.php` with the canonical helpers used by
      `api.php`, `cli.php`, and every tool: `saveConfigWithBackup`, `addUniqueValue`,
      `parseSlackSignal`, `applySignalToProject`. Duplicate copies removed from
      `api.php` (`saveConfigJson`, `addUniqueValue`, `parseSlackSignal`) and
      `cli.php` (`applySignalToConfig`). All 6 tools now use `saveConfigWithBackup`.

- [x] **(P1, m)** Split `src/integrations/github.php` (557 lines, one
      ~270-line function) into per-resource helpers: `githubFetchCommits`,
      `githubFetchPullRequests`, `githubFetchIssues`, `githubFetchIssueComments`,
      `githubFetchReviewComments`. `loadGitHubActivity` is now a short coordination
      loop (~40 lines).
- [x] **(P2, m)** Hoist Harvest project-list + ClickUp tree-walk into
      `src/integrations/harvest-catalog.php` and `clickup-catalog.php`. Both
      `sync-integration-projects.php` and `set-integration-groupings.php` now
      import a single source of truth (~200 lines deduplicated).
- [x] **(P2, m)** Consolidate warning helpers: `awWarning` and
      `integrationWarning` replaced with `warning(string $source, string $message)`
      in `src/helpers.php`. `getIntegrationWarnings()` kept as an alias for
      `getWarnings()` for backwards compatibility. AW warnings now collected into
      the same global pool and visible in the web UI warnings banner.
- [ ] **(P2, m)** Reorganize `src/` into `src/loaders/`, `src/integrations/`,
      `src/web/`, `src/cli/`. Keep `helpers.php` and `cache.php` at root.
      Deferred: purely structural, touches 20+ require_once paths across 15 files,
      best as a standalone refactor with no functional change.
- [ ] **(P3, m)** Introduce a `Config` value object (read-only) for the typed
      access paths. Even a typed array shape (PHPStan generic) at boundaries
      would be a big improvement.
- [x] **(P3, s)** `set-integration-groupings.php` hardcodes client names.
      Externalized to `groupings_map` config field with `connections` glob map,
      `clickup_default`, and `priority` list. Tool is now fully config-driven.

## Tooling / infrastructure

- [x] **(P1, s)** Add **PHPStan** at level 5 with a baseline: `phpstan.neon` +
      `phpstan-baseline.neon` (7 known false positives from defensive guards).
      Fixed two real bugs in the process: json_encode error check in `config.php`,
      and `@phpstan-impure` on `githubBudgetExceeded`. Added `composer analyze` script.
- [x] **(P1, m)** Add **PHPUnit**: installed `phpunit/phpunit ^13.1`. 84 tests covering
      `classifyVscode`, `classifySlack`, `classifySsh`, `fmtDur`, `bsearchRight`,
      `chromeTime`, `awEpochToDateTime`, `applySignalToProject`, `parseSlackSignal`,
      `githubRepoFromRemoteUrl`, `fnmatchAny`, `hostMatchesDomain`, and all
      serialize/deserialize round-trips in `cache.php`. Added `composer test` script
      and `@test` to `composer check`.
- [x] **(P1, s)** Add **GitHub Actions CI** that runs on push/PR:
      `composer lint`, `composer analyze`, `composer test`, `npm run format:check`,
      `npm run lint:js`. Workflow at `.github/workflows/ci.yml`.
- [x] **(P2, s)** Add **`.editorconfig`** for indentation and line-ending
      consistency.
- [x] **(P2, s)** Add a `composer check` script chaining lint + analyze + test.
- [x] **(P2, s)** Add **ESLint** to `www/static/app.js`. Flat config with
      `@eslint/js` recommended + browser globals. Fixed one real unused-variable
      bug (`elNav` in `fetchAndRender`). Added `npm run lint:js` and CI step.
- [x] **(P3, s)** Pre-commit hook at `.githooks/pre-commit`; `composer install`
      auto-configures `core.hooksPath` via `post-install-cmd`.
- [x] **(P3, s)** **Dependabot config** for `composer.json`, `package.json`,
      and GitHub Actions — weekly updates for all three ecosystems.

## Documentation

- [x] **(P2, s)** `SECURITY.md` documenting the local threat model, token
      handling, file permissions recommendation.
- [x] **(P2, s)** `TROUBLESHOOTING.md` for the common failures: AW not running,
      Chrome SQLite locked, gh CLI unauthenticated, LLM endpoint unreachable.
- [x] **(P3, s)** Architecture diagram in `AGENTS.md` showing data flow
      (loaders → classifier → renderer → cache). ASCII art diagram at line 117.
- [x] **(P3, s)** Inline contract for the JSON shape produced by `renderJson`
      and consumed by the frontend — documented in `AGENTS.md` under
      "JSON output shape".

## Maintenance / housekeeping

- [x] **(P2, s)** `tools/prune-config-backups.php --keep N` to cap the
      `reports/config/` directory at N most-recent backups per source tag. The
      directory grows unbounded today.
- [x] **(P2, s)** Same idea for `reports/cache-data.jsonl` and
      `reports/generated-reports.jsonl` — monthly rotation or N-line cap.
      Added `--max-lines N` (default 1000) to `prune-config-backups.php`.
- [x] **(P3, s)** "Reset" tool that removes report caches but preserves
      `config.json` and `reports/config/` backups.
      `tools/reset-cache.php` with `--before YYYY-MM-DD`, `--month YYYY-MM`,
      and `--apply` flags.

## Frontend (extracted findings)

These all live in `www/static/app.js` (and `app.css`). The JS/CSS extraction
is complete; most of these are now standard JS-project work.

### Accessibility

- [x] **(P1, s)** Replace `<a class="btn" href="#">` action triggers with
      `<button type="button">` throughout. Done for: Add end date, Single day,
      Config/admin toggle, Rebuild, and "flag project as personal". CSS updated
      to match `.proj-actions button` alongside `.proj-actions a`.
- [x] **(P2, s)** `.btn.disabled` uses `pointer-events: none` instead of the
      `disabled` attribute. Disabled Next button changed from `<span class="btn disabled">`
      to `<button disabled aria-disabled="true">`. CSS now covers both `.btn.disabled`
      and `.btn:disabled` with `cursor: default`.
- [x] **(P2, s)** Add `aria-live="polite"` to `#report` so screen readers
      announce content changes after navigation/rebuild.
- [x] **(P2, s)** `<select>` and `<input>` elements in the admin panel had no
      labels. Added `aria-label` to all three: "Project", "Grouping" (select/input),
      and per-signal "Assign X to project" on reassign selects.
- [x] **(P2, s)** `.dow` text was `#888` (~3.5:1 contrast). Darkened to `#666`
      (≥4.5:1, WCAG AA). `.dur` was already `#555`, unchanged.
- [x] **(P3, s)** Restore focus to the Rebuild button after a rebuild completes
      (`document.querySelector("[data-rebuild]")?.focus()` after `renderCurrentView`).
- [x] **(P3, s)** `←`/`→` keyboard shortcuts for Prev/Next nav. Skipped when
      focus is in INPUT/TEXTAREA/SELECT or a modifier key is held.

### State / coupling

- [ ] **(P2, m)** Wrap the six top-level mutable globals into a single `state`
      object. Easier to reason about and to add change-tracking later.
- [ ] **(P2, s)** Magic strings shared with the backend (`__personal__`,
      `__new__`, action names, signal kinds, `group:` prefix) must stay in
      sync. Centralize them in a constants block exposed via `$jsConfig`.
- [ ] **(P3, s)** `data.from`/`data.to` are full ISO datetimes that the client
      slices to 10 chars. Either expose `data.fromDate` server-side or parse
      consistently with `Date`.

## Legacy items (from prior TODO)

- [ ] **(P2, m)** Static HTML report export (no JS dependency, embeddable).
      The web UI fills most of this niche, but a `--format html` CLI option
      that produces a single-file HTML with inline CSS would be useful for
      sharing/archiving.
- [ ] **(P2, l)** Slack data integration: pull from local Slack cache to
      classify channel comments and interactions made by the user. Last
      remaining legacy TODO not addressed.
- [ ] **(P3, l)** Time-anomaly detection (flag days with abnormal patterns).
- [ ] **(P3, l)** Interactive timeline visualization in the web UI.
- [ ] **(P3, l)** Jira time-tracking integration.

## New ideas surfaced by the review

- [ ] **(P2, m)** Per-repo "last fetched" timestamp for GitHub so subsequent
      runs do incremental fetches instead of full per-repo pagination.
- [ ] **(P2, m)** Background fetch for GitHub from the web UI: kick off a
      `proc_open` to `php activity-report.php --rebuild` for the requested
      day, return immediately with cached data, refresh once it completes.
      Currently the web UI just skips GitHub.
- [ ] **(P3, m)** "Why was this categorized?" tooltip in the web UI — given a
      project bar, surface the matching rule (vscode_dirs entry, domain glob,
      slack rule, etc).
- [ ] **(P3, m)** Multi-LLM fallback: try `integrations.llm[0]`, then `[1]` if
      the first fails. Useful for local-then-cloud cascades.

## Cross-platform

The following issues were identified when assessing Windows/Linux portability.
The changes already made (May 2026): `USERPROFILE` fallback in `expandPath()`;
`posix_*`-free `githubDesktopLevelDbPath()` with macOS/Windows/Linux path
discovery; generalised `scanLevelDbForPaths()` regex for `/home/`, Windows
forward-slash, and backslash paths; Windows/Linux terminal and VSCode app-name
aliases added to the classifier switch. The items below are the observable
remaining gaps that are too complex to fix without a Windows test environment.

- [ ] **(P2, m)** `shell_exec(...'2>/dev/null')` in `loader-git.php` and
      `loader-github-desktop.php` suppresses stderr using a Unix shell redirect.
      On Windows CMD this becomes `2>NUL`; Git Bash/WSL handle the Unix form,
      but bare `php.exe` with cmd as the shell will leave an unredirected error
      stream. Detect `PHP_OS_FAMILY === 'Windows'` and switch the redirect
      suffix, or wrap git calls in `proc_open` with explicit pipe handles so
      stderr is contained.

- [ ] **(P2, m)** ActivityWatch and Chrome paths differ per OS. AW defaults to
      `%APPDATA%/activitywatch/` on Windows and `~/.config/activitywatch/` on
      Linux. Chrome on Windows lives at `%LOCALAPPDATA%/Google/Chrome/User Data/`;
      on Linux at `~/.config/google-chrome/`. Either add platform-specific example
      comments in the schema descriptions, or detect `PHP_OS_FAMILY` at startup
      and emit a warning when the configured paths don't exist on the current OS.

- [ ] **(P2, s)** `expandPath()` converts `~/` using either `HOME` or
      `USERPROFILE`. On Windows the expanded path will contain a backslash-based
      prefix (`C:\Users\name`) followed by forward-slash suffixes from config
      values. PHP accepts mixed separators on Windows for most `file_*`
      operations, but `glob()` patterns in `loader-chrome.php` and SQLite DSN
      strings may need normalization. Needs a Windows test corpus to confirm.

- [ ] **(P3, s)** Add a Linux/Windows scheduling example to the README (current
      cron snippet uses macOS paths and `crontab -e`; Windows users need Task
      Scheduler or a WSL cron entry).

- [ ] **(P3, s)** `fnmatch()` is available on all platforms in PHP, but on
      Windows it does not set `FNM_CASEFOLD` by default (the constant is
      defined, but Windows filenames are inherently case-insensitive at the OS
      level while `fnmatch` itself may still be case-sensitive depending on
      the build). Audit all `fnmatch()`/`fnmatchAny()` call sites and confirm
      `FNM_CASEFOLD` is passed consistently — it is for domain and app matching,
      but not for the `glob()` call in `loader-chrome.php`.

- [ ] **(P3, m)** The web server invocation (`php -S localhost:8000`) works on
      all platforms, but the ActivityWatch watcher app names on Windows may
      differ from the macOS names already in the classifier switch. Only the
      most common Windows terminal apps were added (Windows Terminal, PowerShell,
      pwsh, cmd, alacritty, kitty, konsole, gnome-terminal). Real-world testing
      on a Windows ActivityWatch installation is needed to find any remaining
      gaps (e.g. VS Code may report as `Code` or `Code.exe` depending on AW
      version and OS).
