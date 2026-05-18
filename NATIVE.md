# Native Desktop Plan

## Goal

Add a native desktop UI surface to a tool that already has a PHP CLI and a PHP web UI —
all three sharing the same `config.json`, `reports/` cache directory, and JSON report
shape. The desktop app is not a replacement that removes the others; it is an additional
interface to the same local data store.

The broader multi-UI architecture:

```
PHP CLI (activity-report.php)  ─┐
PHP Web UI (www/)               ├── config.json + reports/ + local data sources
React Native Desktop (apps/)   ─┘
```

The desktop app brings:

- local-only data processing
- project-attributed daily reports
- config editing
- cache-backed report generation
- optional Harvest, ClickUp, Clockify, GitHub, and LLM features

The TypeScript engine (`packages/engine/`) is the shared data layer for the desktop app.
It is developed with compatibility as a hard constraint — the same `config.json` shape and
`reports/` cache layout as the PHP implementation, so users can run any surface against
the same local data without migration.

## Hard Recommendation

Use React Native for Desktop for the UI layer, but do not try to make React Native itself
directly own filesystem, SQLite, shell, and cache orchestration.

This app reads local ActivityWatch databases, Chrome history, Git repositories, GitHub
Desktop metadata, and config files. That is a local systems application, not just a view
layer. A pure React Native Desktop app will become awkward quickly.

Recommended architecture:

- React Native macOS **first** for the desktop UI
- React Native Windows later, once the macOS app is stable
- a local TypeScript data engine packaged with the app and invoked through a narrow IPC
  boundary

That keeps the UI native while still replacing PHP completely.

## Architecture

```
┌─────────────────┐    ┌─────────────────┐    ┌─────────────────┐
│   UI Layer      │    │   Engine Layer  │    │   Data Sources  │
│                 │    │                 │    │                 │
│  React Native   │───▶│  TypeScript     │───▶│  ActivityWatch  │
│  Components     │    │  Engine         │    │  Chrome         │
│  (macOS first)  │    │  (@timesheets/  │    │  Git            │
│                 │    │   engine)       │    │  Integrations   │
└─────────────────┘    └─────────────────┘    └─────────────────┘
```

## What Exists Today

The current product surface is larger than a report viewer. The native app must plan
around all of these existing behaviors:

- report navigation by day and date range
- project filtering, including filtering by grouping
- grouped project display with colors and optional logos
- per-day timeline bars
- warning banners for failing integrations
- rebuild-from-source flow and cache-age awareness
- Harvest sidebar with logged-time totals
- LLM-backed day summaries
- LLM-backed unlogged-time suggestions
- config editing across general settings, projects, groupings, integrations, and signals
- config mutations such as reassigning signals, marking projects ignored, and changing
  grouping

Those features currently live across `README.md`, `www/static/app.js`, `www/api.php`,
`src/cli.php`, `src/classifiers.php`, and `src/cache.php`.

## Repository Shape

The workspace separates UI and engine explicitly:

```
apps/
  desktop/              React Native macOS app (entry point + native shell)
packages/
  contracts/            @timesheets/contracts — shared JS/TS type definitions
  engine/               @timesheets/engine — data loading, classification, caching
  ui/                   @timesheets/ui — shared presentational components
  test-fixtures/        @timesheets/test-fixtures — golden report JSON and config fixtures
```

The PHP tree remains during migration as the behavior oracle. Remove it only after parity
checks pass.

## IPC Surface

The engine exposes a narrow command surface. The desktop app calls these; it does not
reach into engine internals.

```ts
getReport(range: DateRange, options?: ReportOptions): Promise<Report>
rebuildReport(range: DateRange, options?: ReportOptions): Promise<Report>
getConfig(): Promise<Config>
saveConfig(config: Config): Promise<void>
reassignSignal(payload: ReassignSignalPayload): Promise<void>
setProjectGrouping(payload: SetGroupingPayload): Promise<void>
flagProjectsIgnored(payload: FlagIgnoredPayload): Promise<void>
generateDaySummary(date: string): Promise<string>
suggestTimeLogging(date: string): Promise<Suggestion[]>
```

Keep these APIs close to the current PHP JSON shapes so migration is incremental and
testable.

## Storage and Compatibility

Start by keeping compatibility where it buys leverage:

- keep `config.json` shape compatible with the existing JSON Schema
- keep `reports/` cache structure compatible initially
- keep report JSON close to the current `renderJson()` output
- keep config backups under `reports/config/`

That lets the desktop app reuse existing user data and makes parity testing
straightforward. Format changes are a phase-2 cleanup, not a phase-1 blocker.

## Migration Plan

### Phase 0: Freeze the Contract _(not started)_

Before rewriting anything, treat the current PHP app as the behavior oracle.

Deliverables:

- document the current JSON report contract from `renderJson()`
- document config mutation actions currently exposed in `www/api.php`
- capture golden fixtures for a handful of real or synthetic days
- list which behaviors are required for the first desktop release and which can wait

Acceptance criteria:

- one sample day, one multi-day range, one filtered report, and one config round-trip are
  captured as fixtures in `packages/test-fixtures/`
- `packages/contracts/` types match the captured fixture shapes exactly

### Phase 1: Bootstrap the Desktop Workspace _(in progress)_

Set up the new JS/TS foundation without removing PHP yet.

Deliverables:

- [x] create the workspace structure under `apps/` and `packages/`
- [x] initialize package scaffolding for contracts, engine, ui, test-fixtures
- [ ] wire `workspaces` in root `package.json`
- [ ] initialize React Native macOS app shell in `apps/desktop/`
- [ ] define shared `Report`, `DayReport`, `ProjectReport`, `Config`, and mutation payload
      types in `packages/contracts/`
- [ ] set up linting, formatting, unit tests, and fixture tests for the new TS packages

Acceptance criteria:

- the desktop shell launches locally on macOS
- the app can render mocked fixture data without any PHP dependency

### Phase 2: Port the Engine Core _(not started)_

Rewrite the PHP core in TypeScript in the safest order: pure logic first, data adapters
second.

Port in this order:

1. config load/save/backup logic
2. date-range resolution
3. cache key and cache read/write logic
4. classifiers and aggregation
5. JSON report generation contract
6. source loaders

Acceptance criteria:

- fixture-based parity tests pass for classification and JSON output
- source cache and generated report cache can be read and written by the new engine

### Phase 3: Native Report UI _(not started)_

Replace the current browser UI with native screens, using the same reporting model.

Initial report-view scope:

- report list grouped by day
- previous/next day navigation and date-range selection
- project and grouping filters
- grouped project cards with durations, detail rows, and commits
- warning banner
- rebuild action with cache status
- timeline visualization

Acceptance criteria:

- a user can do everything in the current report view without opening a browser
- report rendering works against the local TypeScript engine

### Phase 4: Native Config UI _(not started)_

Port the config page as a first-class desktop workflow.

Required tabs for parity: General, Projects, Groupings, Integrations, Signals.

Required behaviors:

- dirty-state tracking, save and discard
- add, edit, rename, and delete projects
- add and remove Slack rules
- edit groupings, colors, logos, aliases, and time-tracking settings
- edit integration credentials and metadata
- reassign signals and ignore projects

### Phase 5: Advanced Features _(not started)_

After report and config parity:

1. LLM day summary generation
2. unlogged-time suggestions
3. GitHub integration activity
4. GitHub Desktop repo discovery surfaced in-app

### Phase 6: Packaging and Cutover _(not started)_

- signed macOS desktop build
- packaged engine process or embedded runtime
- migration path for existing `config.json` and `reports/`
- remove PHP server entrypoints once the native engine has test parity

## Technology Stack

- **UI Framework**: React Native macOS (react-native-macos)
- **Language**: TypeScript
- **Build Tools**: Metro bundler, React Native CLI
- **State Management**: React hooks; Zustand or Redux Toolkit for app-level state
- **Forms/Validation**: react-hook-form + zod for config editing
- **Timelines**: react-native-svg
- **Testing**: Jest + React Testing Library; fixture-based parity tests

## Testing Strategy

The migration must be driven by parity tests, not visual confidence.

Test layers:

- unit tests for classifiers, date math, cache logic, and config mutation helpers
- fixture tests that compare engine output to known-good report JSON
- integration tests against sample SQLite and git fixtures where practical
- UI tests for report navigation, rebuild flow, and config save/discard behavior

Generate golden fixtures from the current PHP implementation **before** deleting it, then
use those fixtures to prove the TypeScript engine matches behavior exactly.

## Risks

### React Native Desktop Is Not the Hard Part

The UI rewrite is manageable. The expensive work is replacing local data access and report
generation. The migration will fail if it is scoped as only a frontend rewrite.

### Cross-Platform Desktop Needs a Real Decision

ActivityWatch paths, Chrome paths, GitHub Desktop data locations, shell behavior, and
packaging differ by OS. **Do not start simultaneous macOS and Windows support** unless
that requirement is real today. macOS first.

### Config and Cache Churn Can Break Trust

This tool is valuable because it is inspectable and local. Sudden format changes to config
and cache will make debugging harder and increase migration risk. Prefer compatibility
first, cleanup second.

## Definition of Done

The PHP app can be considered replaced when all of the following are true:

- the desktop app covers report viewing and config editing for normal daily use
- the TypeScript engine can generate reports without calling PHP
- config backups and report caches are still reliable
- core classification behavior matches fixture expectations
- the supported user workflow no longer requires `php -S` or `php activity-report.php`
