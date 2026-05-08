# TODO

Remaining work as of May 2026. Severity: **P0** ship-blocking, **P1** significant,
**P2** worth doing, **P3** nice-to-have. Effort: **(s)** small, **(m)** medium, **(l)** large.

---

## Current state

The codebase is in good shape. The big structural work is done:

- **`www/report_renderer.php`** is now 159 lines (down from ~920) — JS/CSS fully
  extracted to `www/static/app.js` (1 022 lines) and `www/static/app.css`.
- **`src/config.php`** centralizes `saveConfigWithBackup`, `applySignalToProject`,
  `parseSlackSignal`, `addUniqueValue`. All 6 tools and both `api.php` / `cli.php`
  use it.
- **`src/integrations/github.php`** split into 5 per-resource helpers
  (`githubFetchCommits`, `githubFetchPullRequests`, etc.); coordination loop is ~40
  lines.
- **`src/integrations/harvest-catalog.php`** and **`clickup-catalog.php`** eliminate
  the duplicated project-list / tree-walk between `sync-integration-projects.php`
  and `set-integration-groupings.php`.
- **PHPStan level 5** + baseline, **84 PHPUnit tests**, **ESLint**, **GitHub
  Actions CI**, **pre-commit hooks**, and **Dependabot** are all wired up.

### What still hurts

- **GitHub fetch is still sequential.** 5 endpoints × N repos × M authors. With
  GitHub Desktop discovery (50+ repos), the web UI's 8 s budget is exhausted
  every time; fresh GitHub activity only arrives via CLI. Cache TTL is now
  config-driven, but the underlying loop is unchanged.
- **`www/static/app.js` has 8 scattered module-level mutable globals.** Works
  today, but makes change-tracking and testing harder.
- **Magic strings split between PHP and JS** (`__personal__`, `__new__`, signal
  kinds, `group:` prefix) must be kept in sync manually.
- **`array $config` is still untyped everywhere.** PHPStan baseline carries 7
  known false positives from defensive `?? null` chains. A typed Config value
  object would eliminate this class of bugs and make signatures self-documenting.
- **`src/` flat layout.** The `loader-*.php` files remain at `src/` root;
  `src/loaders/` subdirectory has not been created.

---

## Security

- [ ] **(P2, m)** Flesh out threat model in `SECURITY.md`. The file exists but is
      thin. Document: OAuth tokens for Harvest/ClickUp/GitHub stored plaintext in
      `config.json`, LLM key in same file, local-only server model, recommended
      `chmod 600 config.json`, and that the Origin check in `api.php` is the only
      remote-caller guard.
- [ ] **(P3, l)** Optional macOS Keychain integration: `"token": "@keychain:harvest-main"`
      resolved at config-load time, so credentials are never stored in plaintext.

## Performance

- [ ] **(P2, m)** GitHub fetch: 5 endpoints × N repos × M authors, all sequential.
      Options: GraphQL aggregation (fewest round-trips), parallel `proc_open` of
      `gh api` (fits current architecture), or per-repo last-fetched timestamp so
      subsequent runs only pull deltas. The cache-TTL config knob (`github_cache_ttl`)
      buys time but doesn't fix the root cause.
- [ ] **(P3, m)** Frontend re-renders the entire page on project-filter change. Split
      `renderCurrentView()` into `renderReportOnly()` so the nav, sidebar, admin
      panel, and warnings banner are not redrawn when only the filter changes.
- [ ] **(P3, s)** `renderHarvestSidebar` in `www/static/app.js` does two passes over
      the data. One suffices.
- [ ] **(P3, s)** `Intl.DateTimeFormat` for day-of-week is constructed per-day
      inside `renderDay`. Cache it at module scope.
- [ ] **(P3, m)** Per-day source caches: 4 JSON reads per cached day (activitywatch,
      chrome, commits, integrations). For a 7-day range that is 28 reads; a single
      `daily-{date}.json` envelope would reduce it to 7.

## Modularity / refactoring

- [ ] **(P2, m)** Reorganize `src/` into `src/loaders/`, keeping `src/integrations/`
      and root-level `helpers.php`, `cache.php`, `config.php`. Purely structural —
      touches ~20 `require_once` paths across 15 files, best done as a standalone
      no-functional-change commit.
- [ ] **(P3, m)** `Config` value object (read-only typed accessors). Even a PHPStan
      generic array-shape at call boundaries would collapse the 7 baseline
      false-positives and prevent key-typo bugs. Bigger impact paired with the
      `src/` reorganization above.

## Frontend — state and coupling

- [ ] **(P2, m)** Wrap the eight module-level mutable globals into a single `state`
      object: `currentParams`, `currentData`, `currentBadge`, `currentCacheAgeSec`,
      `personalProjectQueue`, `showAdminPanel`, `currentAbortController`,
      `responseCache`. Makes change-tracking and a future state-reset path trivial.
- [ ] **(P2, s)** Magic strings shared with the backend (`__personal__`, `__new__`,
      action names, signal kinds, `group:` prefix) must stay in sync manually.
      Centralize them in a `CONSTANTS` block inside `$jsConfig` so the PHP side is
      the single source of truth.
- [ ] **(P3, s)** `data.from` / `data.to` are full ISO datetimes; the frontend slices
      to 10 chars with `String(fromRaw).slice(0, 10)`. Either expose `data.fromDate`
      / `data.toDate` as plain `YYYY-MM-DD` from `renderJson()`, or at least add a
      comment explaining why the slice is safe.

## Features / legacy items

- [ ] **(P2, m)** Static HTML report export: a `--format html` CLI option producing a
      single-file HTML with inline CSS, useful for sharing and archiving without
      running the local server.
- [ ] **(P2, l)** Slack data integration: pull from local Slack cache to classify
      channel comments and DMs by the user.
- [ ] **(P3, l)** Time-anomaly detection: flag days with suspiciously long gaps or
      abnormally short totals.
- [ ] **(P3, l)** Interactive timeline visualization in the web UI (drill-down on the
      per-project timeline bars added in recent commits).
- [ ] **(P3, l)** Jira time-tracking integration.

## New ideas

- [ ] **(P2, m)** Per-repo "last fetched" timestamp stored in `config.json` so GitHub
      runs pull only the delta since the previous fetch rather than re-paginating
      the full history every time.
- [ ] **(P2, m)** Background GitHub fetch from the web UI: kick off
      `php activity-report.php --rebuild` via `proc_open`, return cached data
      immediately, and refresh the UI once the background run completes.
- [ ] **(P3, m)** "Why was this categorized?" tooltip: given a project bar, surface
      the matching rule (vscode_dirs entry, domain glob, slack rule, etc.).
- [ ] **(P3, m)** Multi-LLM fallback: try `integrations.llm[0]`, then `[1]` on
      failure. Useful for local-then-cloud fallback chains.

## Cross-platform

Items still needing a Windows/Linux test environment to close.

- [ ] **(P2, m)** `shell_exec(...'2>/dev/null')` in `loader-git.php` and
      `loader-github-desktop.php` uses a Unix shell redirect. On bare `php.exe`
      with CMD as the shell this fails. Detect `PHP_OS_FAMILY === 'Windows'` and
      switch to `2>NUL`, or rewrite git calls with `proc_open` and explicit pipe
      handles.
- [ ] **(P2, m)** AW and Chrome paths differ per OS. Add platform-specific example
      comments in `config.schema.json`, or detect `PHP_OS_FAMILY` at startup and
      warn when configured paths don't exist on the current OS.
- [ ] **(P2, s)** `expandPath()` converts `~/` via `HOME` or `USERPROFILE` but
      produces mixed separators on Windows (`C:\Users\name/rest/of/path`). Confirm
      `glob()` patterns in `loader-chrome.php` and SQLite DSN strings handle mixed
      separators; normalize if not.
- [ ] **(P3, s)** Add Linux / Windows scheduling example to README (current cron
      snippet is macOS-only).
- [ ] **(P3, s)** Audit all `fnmatch()` / `fnmatchAny()` call sites for consistent
      `FNM_CASEFOLD` usage. Domain and app matching pass it; the `glob()` call in
      `loader-chrome.php` does not.
- [ ] **(P3, m)** Real-world Windows AW testing needed: watcher app names may differ
      from the aliases already added (Windows Terminal, PowerShell, pwsh, cmd,
      alacritty, kitty, konsole, gnome-terminal). VS Code may report as `Code` or
      `Code.exe` depending on AW version.

## LLM integration ideas

The LLM connection already powers `--suggest` (project assignment proposals). The
items below extend it into pattern recognition, narrative intelligence, and
self-improving classification. All assume the existing `integrations.llm` config
block; none require a different provider.

### Smarter classification

- [ ] **(P2, m)** **Learning from corrections.** When the user reassigns a signal
      via the web UI or CLI, append the `(signal → project)` pair to a
      `reports/corrections.jsonl` log. Feed recent corrections as few-shot examples
      in the `--suggest` prompt so the LLM improves over time without explicit
      retraining. The file doubles as an audit trail.
- [ ] **(P2, m)** **URL/title normalization.** The current classifier matches on
      exact domain globs; novel URLs that don't match any rule land in an uncategorized
      bucket. Pass a batch of unmatched browser titles + URLs to the LLM with the
      known project list and ask it to propose new domain-glob rules (not just a
      one-off assignment). Surface the suggestions as additions to `vscode_dirs` /
      `browser` signal config rather than overrides.
- [ ] **(P2, s)** **App-name normalization.** ActivityWatch watcher app names vary
      by OS and AW version. After the existing classifier switch fails, send the
      unknown app name and its window title to the LLM with the known terminal/IDE/
      browser categories and ask for a mapping. Cache the result in config so the
      LLM is only queried once per novel app name.
- [ ] **(P3, m)** **Contextual session grouping.** Right now each activity event is
      classified independently. Feed the LLM a sliding window of consecutive events
      (e.g. five minutes of AW + browser data) and ask it to name the work session.
      Use the session label as a candidate project assignment, weighted below direct
      signal matches. Helps with context-switching detection when no single signal
      dominates.

### Pattern detection and reporting

- [ ] **(P1, m)** **Daily accomplishment summary.** After loading a day's data,
      serialize the richest intent signals — git commit messages and diffs (from
      `loader-git.php`), GitHub PR/issue/review-comment activity (from
      `src/integrations/github.php`), ClickUp task names and time entries (from
      `src/integrations/clickup.php`), and Slack threads (once that integration
      exists) — and send them to the LLM with a prompt asking it to write a concise
      bullet-point summary of what was actually *accomplished* (not just where time
      was spent). The output differs from the time-allocation narrative: it names
      specific PRs merged, issues closed, tasks completed, and decisions made.
      Store the result in the day's report JSON under a `"summary"` key and render
      it as a collapsible panel at the top of the web UI day view. Expose it via
      `--format summary` on the CLI for piping into standup notes or status emails.
      The main cost is prompt size: on a busy day, commit diffs alone can be large,
      so truncate individual diffs at ~500 chars and cap the total prompt at the
      model's context window, prioritizing commits > PRs > issues > ClickUp > Slack.

- [ ] **(P2, m)** **Weekly / monthly narrative summary.** After generating the JSON
      report, pass the aggregated project totals and day-by-day breakdown to the
      LLM and ask for a short prose summary ("Heavy infrastructure week; GitHub
      reviews dominated Monday and Wednesday; deep work on Project X concentrated
      Thursday afternoon"). Write the output to `reports/summary-{period}.md`.
      Useful for monthly invoices or status emails.
- [ ] **(P2, m)** **Anomaly explanation.** The time-anomaly detection item (P3 above)
      would flag unusual days. Pair it with an LLM step: once a day is flagged,
      send the raw event sequence to the LLM and ask what likely caused the gap or
      spike (calendar event, system sleep, context-switch storm, etc.). Surface the
      explanation in the web UI alongside the flag.
- [ ] **(P3, m)** **Project velocity trends.** Accumulate weekly totals per project
      across the `reports/generated-reports.jsonl` history. Send the time-series to
      the LLM and ask it to identify trends ("GitHub work up 40 % over the past
      month", "Project Y trending toward zero"). Output as a trend report or inline
      sparkline annotations in the web UI.
- [ ] **(P3, s)** **Context-switch cost estimation.** Count transitions between
      distinct projects within each day. Ask the LLM to estimate the cognitive
      overhead based on transition frequency and project dissimilarity, and suggest
      which projects could be time-blocked together. Pure advisory output; no
      config mutation.

### Interactive / query interface

- [ ] **(P3, m)** **Natural-language query over report history.** Expose a CLI
      command (`php activity-report.php --ask "How much time did I spend on
      infrastructure work last month?"`) that serializes recent report data and
      passes it with the question to the LLM. Complement to the structured web UI,
      not a replacement.
- [ ] **(P3, l)** **"Why was this categorized?" explanation.** Already listed under
      New Ideas above as a tooltip. The LLM extension: when no deterministic rule
      matched and the assignment came from `--suggest`, the tooltip text is the
      LLM's own reasoning string, stored at classify time and surfaced verbatim.
      Requires storing per-event `reason` alongside the `project` field in the
      report JSON.
