# Native Desktop Plan

## Goal

Add a native desktop UI surface to a tool that already has a PHP CLI and a PHP web UI —
all three sharing the same `config.json`, `reports/` cache directory, and JSON report
shape. The desktop app is not a replacement that removes the others; it is an additional
interface to the same local data store.

```
PHP CLI (apps/cli/)                  ─┐
PHP Web UI (apps/web/)               ─┤── config.json + reports/ + local data sources
React Native Desktop (apps/desktop/) ─┘
```

The desktop app brings:

- local-only data processing
- project-attributed daily reports
- config editing
- cache-backed report generation
- optional Harvest, ClickUp, Clockify, GitHub, and LLM features

The TypeScript engine (`packages/engine/`) is the shared data layer. It is developed with
compatibility as a hard constraint — the same `config.json` shape and `reports/` cache
layout as the PHP implementation.

## Repository Shape

```
apps/
  cli/          PHP CLI entry point
  web/          PHP web UI (vanilla JS + PHP; behavior oracle during migration)
  desktop/      React Native macOS app (entry point + native shell + native modules)
src/            PHP core (shared by cli/ and web/)
packages/
  contracts/    @timesheets/contracts — JSDoc typedefs for Report, Config, and all payloads
  engine/       @timesheets/engine — data loading, classification, caching (Node.js)
  ui/           @timesheets/ui — shared React Native components and hooks
  test-fixtures/ @timesheets/test-fixtures — golden report JSON and config fixtures
tools/
  desktop-dev.sh  dev workflow: start Metro, pre-warm bundle, launch app, stream logs
```

The PHP tree remains during migration as the behavior oracle. Remove it only after parity
checks pass.

## Architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│  apps/desktop (React Native macOS)                                   │
│                                                                      │
│  ┌─────────────────┐   JS calls    ┌──────────────────────────────┐ │
│  │  UI screens      │──────────────▶│  Native Module bridge        │ │
│  │  (packages/ui   │               │  (TimesheetsEngineModule.mm) │ │
│  │   components)   │◀──────────────│                              │ │
│  └─────────────────┘   callbacks   └──────────┬───────────────────┘ │
│                                               │ file I/O / IPC      │
└───────────────────────────────────────────────┼─────────────────────┘
                                                │
              ┌─────────────────────────────────┼──────────────────┐
              │  IPC boundary                   │                  │
              │                                 ▼                  │
              │  ┌──────────────────────────────────────────────┐  │
              │  │  Tier 1: Native file I/O (Phase 4)           │  │
              │  │  NSFileManager reads/writes config.json       │  │
              │  │  Returns parsed JSON dict to RN JS            │  │
              │  └──────────────────────────────────────────────┘  │
              │                                                      │
              │  ┌──────────────────────────────────────────────┐  │
              │  │  Tier 2: Node.js sidecar (Phase 3)           │  │
              │  │  packages/engine runs in a bundled Node proc  │  │
              │  │  RN calls it via local HTTP (fetch to :PORT)  │  │
              │  │  Handles SQLite, Chrome history, git, reports │  │
              │  └──────────────────────────────────────────────┘  │
              └──────────────────────────────────────────────────────┘
```

## IPC Layer Design

React Native's Hermes JS engine cannot directly call Node.js APIs (`fs`, `better-sqlite3`,
`child_process`). The engine package requires these. Two tiers bridge the gap:

### Tier 1 — Native ObjC module (config operations only)

`apps/desktop/macos/TimesheetsDesktop-macOS/TimesheetsEngineModule.{h,mm}`

Exposes to RN JS:

```objc
RCT_EXPORT_METHOD(getConfig:(RCTPromiseResolveBlock)resolve reject:(RCTPromiseRejectBlock)reject)
RCT_EXPORT_METHOD(saveConfig:(NSDictionary *)config resolve:... reject:...)
RCT_EXPORT_METHOD(getConfigPath:(RCTPromiseResolveBlock)resolve reject:...)
```

The native module locates `config.json` using the same search order the PHP app uses:
the directory stored in `NSUserDefaults` under `TimesheetsConfigDir`, falling back to
`~/.config/timesheets/config.json`. The path is user-configurable on first launch.

This is enough for Phase 4 (config UI). No Node.js sidecar needed for read/write of JSON.

### Tier 2 — Node.js sidecar (reports, SQLite, integrations)

For Phase 3 (report UI) and beyond, the engine needs `better-sqlite3` and `child_process`.
The sidecar approach:

1. Bundle a Node.js binary with the app (via `pkg` or as a framework)
2. On launch, start `engine-server.js` as a child process on a random port
3. React Native calls it via `fetch('http://localhost:PORT/...')`
4. The sidecar exposes a minimal HTTP API matching the IPC surface below
5. On app quit, kill the sidecar

The sidecar is not needed until Phase 3. For Phase 4, the native module is sufficient.

## IPC Surface

The full API the desktop app calls. Tier 1 covers config operations; Tier 2 covers
everything that needs the engine's data pipeline.

```
Tier 1 (ObjC native module):
  getConfig()                              → Config | null
  saveConfig(config: Config)               → string (path written)
  getConfigPath()                          → string
  setConfigDir(dir: string)               → string (new path)
  pickConfigDir()                          → string | null (NSOpenPanel)
  startSidecar()                           → number (port)
  stopSidecar()                            → boolean
  getSidecarPort()                         → number (0 if not running)
  getEngineScriptPath()                    → string | null
  setEngineScriptPath(path: string)        → string
  pickEngineScript()                       → string | null (NSOpenPanel)

Tier 2 (Node.js sidecar HTTP):
  GET  /status                                              → { ok: true }
  GET  /report?from=YYYY-MM-DD&to=YYYY-MM-DD[&rebuild=1]   → Report
  GET  /discover-repos                                      → Record<path, { name, recent, last_commit_ts }>
  POST /reassign-signal   { type, key, project }           → { ok: true }
  POST /set-grouping      { project, grouping }            → { ok: true }
  POST /flag-ignored      { projects[], ignored }          → { ok: true }
  POST /generate-summary  { date }                         → { summary: string }
  POST /suggest-assignments { date }                       → { suggestions: [{kind,value,project,reason}] }

Planned (deferred):
  POST /suggest-logging   { date }                         → { suggestions[] }  (Harvest-specific)
```

## Shared UI Architecture

### packages/ui

Contains everything that is shared between the desktop app and (eventually) a React-based
web UI. Split into two layers:

**Logic layer** — framework-agnostic, importable from any JS context:

- `useConfigDraft(initial: Config)` — draft state, dirty tracking, field path mutation,
  discard/reset
- `useFieldPath(draft, path)` — reads and writes a value at a dotted path like
  `"projects.MyProject.repos"`, handling array ↔ textarea conversions
- `configSchema` — zod schema for `Config` (validates before save)
- `projectSchema`, `groupingSchema`, `integrationSchema` — per-section zod schemas

**Component layer** — React Native components (work in the desktop app via RN renderer;
can be adapted for web later via react-native-web when that work is prioritised):

- `ConfigScreen` — top-level navigator with tab bar
- `GeneralTab`, `ProjectsTab`, `GroupingsTab`, `IntegrationsTab`, `SignalsTab`
- `ProjectDrawer` — expandable per-project editor
- `FieldRow` — label + control row (text, number, checkbox, select, textarea, color, URL,
  password, JSON blob)
- `ConnectionCard` — repeatable integration connection card
- `SectionHeader` — styled section title

### Web UI retool (deferred, not Phase 4)

The web UI (`apps/web/static/app.js`) is vanilla JS with HTML string templating. It is
not React. The plan for eventual convergence:

1. The logic layer in `packages/ui` (hooks, schemas) is already usable by any bundled
   JS, including a future React web app.
2. When the desktop config UI is solid, replace `apps/web/static/app.js` with a React
   web app that imports `packages/ui` logic hooks and renders its own HTML views.
3. If full component sharing is wanted, add `react-native-web` as a dependency of the
   web app so that the same `packages/ui` components render in the browser.

**Do not** add react-native-web or build a React web app until the desktop UI is proven.
Keep `apps/web/static/app.js` as the web reference implementation.

## Config File Location

The desktop app stores `config.json` at a user-chosen path, defaulting to the path the
PHP web server would use (the directory passed to `php -S` or `activity-report.php`).

Resolution order on first launch:

1. `NSUserDefaults` key `TimesheetsConfigDir` (persists across launches once set)
2. `~/.config/timesheets/config.json` (conventional XDG-style default)
3. User prompted to locate or create a config file

The native module exposes `getConfigPath()` so the UI can show the active path and
offer a "Change…" button that triggers a file-picker.

## Migration Plan

### Phase 0: Freeze the Contract _(partially complete)_

Contracts are captured in `packages/contracts/index.js` (JSDoc typedefs for `Report`,
`Config`, and all mutation payloads, verified against live PHP output). Golden fixtures
exist in `packages/test-fixtures/`.

Remaining:

- [ ] ensure `packages/contracts/` types cover every field the config UI edits
- [ ] verify fixture shapes against a real `config.json` round-trip

### Phase 1: Bootstrap the Desktop Workspace _(complete)_

- [x] workspace structure under `apps/` and `packages/`
- [x] package scaffolding for contracts, engine, ui, test-fixtures
- [x] workspaces wired in root `package.json`
- [x] React Native macOS app shell initialised and launching in `apps/desktop/`
- [x] Metro bundler dev workflow (`npm run desktop:dev`) with pre-warm and log streaming
- [x] Watchman blockList tuned to avoid inode-overflow recrawl warnings

### Phase 2: Port the Engine Core _(complete)_

- [x] config load/save/backup logic (`lib/config.js`)
- [x] date-range resolution (`lib/date-utils.js`)
- [x] cache key and cache read/write logic (`lib/cache.js`)
- [x] classifiers and aggregation (`lib/classifiers.js`)
- [x] JSON report generation (`lib/renderer.js`)
- [x] source loaders — ActivityWatch, Chrome, Git, GitHub Desktop (`lib/loader-*.js`)
- [x] 30+ unit tests pass; typecheck clean

### Phase 3: Native Report UI _(complete)_

**IPC work:**

- [x] `apps/desktop/engine-server.js` — minimal HTTP server (Node built-in `http`, no
      Express) wrapping `packages/engine`; dynamic `await import('@timesheets/engine')`
      bridges ESM engine from CJS wrapper; writes `PORT:<n>\n` to stdout on startup
- [x] native Objective-C `NSTask` sidecar management in `TimesheetsEngineModule.mm`: - `resolveNodeBinary` — runs `/bin/zsh -l -c "which node"` (login shell picks up nvm),
      result cached in-process - `resolveEngineScriptPath` — checks `NSUserDefaults` key `TimesheetsEngineScript`,
      falls back to bundle Resources - port discovery via POSIX `select()` + `read()` on the stdout pipe in a
      `dispatch_async` background queue, scanning for `PORT:` within 15 s
- [x] `TimesheetsEngineModule` extended with `startSidecar`, `stopSidecar`,
      `getSidecarPort`, `getEngineScriptPath`, `setEngineScriptPath`, `pickEngineScript`
- [x] `EngineClient.ts` — typed fetch wrapper for all Tier 2 endpoints
- [x] `engine-server.js` added to Xcode target as a bundled resource

**UI work:**

- [x] `ReportScreen` — sidecar lifecycle ownership; `SidecarState` machine
      (`idle | starting | running | no-script | error`); date navigation; rebuild button
- [x] `DayView` — projects sorted by seconds descending, summary line, AI summary block
- [x] `ProjectCard` — tap-to-expand commits and signal breakdown; activity ratio bar;
      integration badges
- [x] `TimelineView` — 7AM–9PM window, proportional `position: 'absolute'` View segments
      (react-native-svg not installed; pure RN Views used instead); 2-hour tick labels
- [x] `WarningBanner` — amber-bordered warning box, null-renders when empty

Acceptance criteria met:

- user can navigate days and view project breakdowns without a browser
- rebuild flow works end-to-end against the local engine
- `no-script` state shows a file-picker setup UI to locate `engine-server.js`

### Phase 4: Native Config UI _(complete)_

Uses Tier 1 native module only (no sidecar needed).

**Native module:**

- [x] `TimesheetsEngineModule.h` / `.mm` — ObjC RCT module with `getConfig`,
      `saveConfig`, `getConfigPath`, `setConfigDir`, `pickConfigDir`
- [x] First-launch config path resolution (NSUserDefaults key `TimesheetsConfigDir` →
      `~/.config/timesheets/config.json` → picker)
- [x] Module registered in `AppDelegate.mm`; wired into Xcode pbxproj

**Shared logic (`packages/ui/src/config/`):**

- [x] `useConfigDraft.ts` — `useReducer`-based draft state with `reset`, `setField`,
      `discard`, `markSaved` actions; `setNestedValue` handles dotted-path mutation and
      `undefined` deletion
- [x] `useFieldPath.ts` — dotted-path getter/setter; `arrayAsTextarea` option coerces
      `string[]` ↔ newline-joined string
- [x] `configSchema.ts` — full zod schema covering Config, ProjectConfig, GroupingConfig,
      HarvestConnection, ClickUpConnection, GitHubConnection, LlmConnection,
      ClockifyConnection; validated before save
- [x] `NativeEngine.ts` — typed bridge to all native module methods (both Tier 1 and 2)

**Components (`packages/ui/src/config/`):**

- [x] `ConfigScreen.tsx` — loading / no-config / error / ready phases; five-tab tab bar;
      dirty-state header (`Config •`) with Save / Discard buttons; zod validation before save
- [x] `GeneralTab.tsx` — core paths, Chrome profiles textarea + auto-detect checkbox,
      Git authors, timing fields, personal hosts/apps
- [x] `ProjectsTab.tsx` — project list with grouping badge and signal count; inline Add flow
- [x] `ProjectDrawer.tsx` — per-project editor: name, grouping, repos, VSCode dirs,
      domains, Slack rules, SSH hosts, apps, Harvest, ClickUp; delete/ignore buttons
- [x] `GroupingsTab.tsx` — grouping cards with name, color, logo URL, aliases,
      time-tracking type, Harvest connection
- [x] `IntegrationsTab.tsx` — five collapsible sections (Harvest, ClickUp, GitHub, LLM,
      Clockify) with repeatable connection cards
- [x] `SignalsTab.tsx` — full implementation (Phase 5): loads today's unmatched signals
      from sidecar, groups by kind, project assignment with draft sync, LLM suggestions
- [x] `FieldRow.tsx` — supports `text | number | checkbox | textarea | password | segment
| url`; 200 px label column, full-width control
- [x] `ConnectionCard.tsx` — labelled card with Remove button

**Desktop app wiring (`apps/desktop/`):**

- [x] `App.tsx` — three-screen navigator (`home | reports | config`) with nav bar and back
      button; unsaved-change guard (`beforeunload` / `popstate`)
- [x] `ConfigScreen` and `ReportScreen` wired via imports from `@timesheets/ui`

Acceptance criteria met:

- user can open config, edit any field across all five tabs, save, and discard
- save writes a valid `config.json` the PHP app can still parse (zod → JSON)
- dirty-state tracked correctly; navigating away with unsaved changes warns

### Phase 4.5: Branding _(complete)_

Applied the brand identity from `branding/` across all three UI surfaces:

- [x] `packages/ui/src/brand.ts` — `Brand.ink/paper/amber/terracotta` constants, exported
      from package index
- [x] React Native components — terracotta (`#C25E2A`) replaces `#007AFF` as primary
      action color throughout; nav bars use ink (`#16130F`) background with paper text;
      `WarningBanner` uses amber border; `DayView` AI summary uses terracotta left-border
- [x] `apps/desktop/App.tsx` home screen — ink background, paper title, amber subtitle,
      terracotta primary button
- [x] `apps/web/static/app.css` — `:root` CSS vars (`--ts-ink/paper/amber/terracotta`);
      nav bar recolored to ink with paper-tinted buttons/selects; `.btn--primary` →
      terracotta; active config tab → terracotta underline
- [x] `apps/web/report_renderer.php` — favicon `<link>` tags (light/dark `prefers-color-scheme`
      pairs) pointing to `static/favicon/`
- [x] `apps/web/static/favicon/` — six PNGs copied from `branding/favicon/`
      (16 px, 32 px, 180 px in dark and light colorways)
- [x] `AppIcon.appiconset/Contents.json` — all 10 macOS icon sizes wired to filenames;
      dark-colorway PNGs (icon-16 through icon-1024) copied from `branding/png/dark/`

### Phase 5: Advanced Features _(in progress)_

After Phase 3 and 4 are complete:

1. **LLM day summaries** _(complete)_ — `POST /generate-summary` sidecar endpoint;
   `engine.generateSummary(date)` auto-discovers model from `/models`, caches back to
   config, retries on 404; DayView shows cached summary with "✦ Generate Summary" button
2. **Signals tab** _(complete)_ — real `SignalsTab` loads today's `report.unmatched` via
   sidecar; groups by kind (vscode/browser/slack/apps); project picker + Assign button
   persists via `/reassign-signal` and updates draft; "Suggest with AI" calls
   `/suggest-assignments` (engine uses same model-resolve/retry logic as summaries)
3. **GitHub Desktop repo discovery** _(complete)_ — `GET /discover-repos` sidecar endpoint;
   discovery panel in ProjectsTab shows unassigned repos with inline project assignment
4. **Improved integration badges** _(complete)_ — ProjectCard shows human-readable counts
   for GitHub commits, Harvest entries, ClickUp tasks, Clockify entries
5. **Unlogged-time suggestions** — call sidecar `/suggest-logging`, display in Signals tab
   _(deferred: Harvest-specific, lower priority)_

### Phase 6: Packaging and Cutover _(not started)_

- [ ] signed macOS `.app` build (ad-hoc or Developer ID)
- [ ] bundle Node.js binary for the engine sidecar (`pkg` or `node` framework)
- [ ] auto-start sidecar on launch, kill on quit
- [ ] migration guide for existing `config.json` and `reports/` users
- [ ] remove PHP server entrypoints once the native engine has full test parity

> **Signing note:** Move `DEVELOPMENT_TEAM` and provisioning profile settings out of
> `project.pbxproj` and into a gitignored
> `apps/desktop/macos/TimesheetsDesktop.xcodeproj/signing.xcconfig`. Reference it from
> `.pbxproj` via `#include` so the project file stays credential-free.

## Technology Stack

| Layer        | Choice                                                         | Notes                                                 |
| ------------ | -------------------------------------------------------------- | ----------------------------------------------------- |
| UI framework | React Native macOS (react-native-macos 0.81)                   | macOS first; Windows later                            |
| Language     | TypeScript (UI/engine) + JSDoc-annotated JS (engine internals) | strict mode                                           |
| Build        | Metro bundler + React Native CLI                               | `npm run desktop:dev`                                 |
| Navigation   | Custom `useState` screen switcher in `App.tsx`                 | no react-navigation dependency                        |
| Form state   | Custom `useConfigDraft` hook (`useReducer`)                    | keeps logic in packages/ui, no RHF dependency         |
| Validation   | zod                                                            | `configSchema` in packages/ui                         |
| Timelines    | Absolute-positioned `View` segments                            | react-native-svg not installed                        |
| Sidecar HTTP | Node.js built-in `http` module                                 | no Express; `engine-server.js` is CJS with ESM import |
| Brand        | `packages/ui/src/brand.ts` + web CSS vars                      | ink/paper/amber/terracotta colorway                   |
| Testing      | Jest + fixture-based parity tests                              | 30+ tests passing                                     |
| IPC tier 1   | ObjC `RCTBridgeModule` native module                           | config file I/O + sidecar lifecycle                   |
| IPC tier 2   | Node.js child process + local HTTP                             | reports, SQLite, integrations                         |

## Testing Strategy

- Unit tests for engine logic (classifiers, date math, cache, config mutations)
- Fixture tests comparing engine output to known-good PHP report JSON
- Schema validation tests (zod round-trip on golden `config.json`)
- UI component tests for dirty-state tracking and field mutation logic
- Manual smoke test: save from desktop → PHP app reads correctly; PHP app saves → desktop reads correctly

## Risks

**React Native Desktop is not the hard part.** The UI is manageable. The expensive
work is the sidecar IPC and replacing SQLite/shell access. The migration fails if
scoped as only a frontend rewrite.

**Config file location is a UX decision.** The PHP app uses whatever directory the
server is started from. The desktop app needs a canonical, stable location. Decide
this before Phase 4 ships so users aren't confused by two different `config.json` files.

**Node.js sidecar adds packaging complexity.** Bundling Node is non-trivial for a signed
macOS app. Phase 3 depends on solving this. Evaluate `pkg`, Bun, or shipping a
`node` framework before starting Phase 3.

## Definition of Done

The PHP app can be considered replaced when:

- the desktop app covers report viewing and config editing for normal daily use
- the TypeScript engine generates reports without calling PHP
- config backups and report caches are reliable
- core classification behavior matches fixture expectations
- the supported workflow no longer requires `php -S` or `php activity-report.php`
