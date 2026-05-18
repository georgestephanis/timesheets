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
Tier 1 (native module, Phase 4):
  getConfig()                              → Config
  saveConfig(config: Config)               → void
  getConfigPath()                          → string

Tier 2 (sidecar HTTP, Phase 3+):
  GET  /report?from=YYYY-MM-DD&to=YYYY-MM-DD[&rebuild=1]   → Report
  POST /reassign-signal   { type, key, project }           → void
  POST /set-grouping      { project, grouping }            → void
  POST /flag-ignored      { projects[], ignored }          → void
  POST /generate-summary  { date }                         → { summary: string }
  POST /suggest-logging   { date }                         → { suggestions[] }
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

### Phase 3: Native Report UI _(not started)_

Depends on Tier 2 sidecar being in place.

**IPC work (do first):**

- [ ] `apps/desktop/engine-server.js` — minimal Express server wrapping `packages/engine`
- [ ] native Objective-C code to launch/kill the sidecar as an `NSTask`
- [ ] `TimesheetsEngineModule` extended with `startSidecar`, `stopSidecar`, `getSidecarPort`
- [ ] React Native `EngineClient.ts` — typed fetch wrapper for all Tier 2 endpoints

**UI work:**

- [ ] `ReportScreen` — date navigation, day/range toggle
- [ ] `DayView` — project cards, grouped display, duration bars
- [ ] `ProjectCard` — grouping color, duration, activity ratio, commit list, detail rows
- [ ] `TimelineBar` — SVG-based (react-native-svg) per-day timeline
- [ ] `WarningBanner` — integration error messages
- [ ] `RebuildButton` — triggers rebuild, shows cache age

Acceptance criteria:

- user can navigate days and view project breakdowns without a browser
- rebuild flow works end-to-end against the local engine

### Phase 4: Native Config UI _(next)_

Uses Tier 1 native module only (no sidecar needed).

**Native module (do first):**

- [ ] `TimesheetsEngineModule.h` / `.mm` — ObjC RCT module with `getConfig`,
      `saveConfig`, `getConfigPath`
- [ ] First-launch config path resolution (NSUserDefaults + default + file picker)
- [ ] Register module in `AppDelegate.mm`

**Shared logic (`packages/ui/src/config/`):**

- [ ] `useConfigDraft.ts` — draft state, dirty flag, discard, field mutation
- [ ] `useFieldPath.ts` — dotted-path accessor/mutator; array ↔ newline-textarea coercion
- [ ] `configSchema.ts` — zod schema validating the full `Config` shape before save
- [ ] `ianaTimezones.ts` — IANA timezone list for the timezone autocomplete

**Components (`packages/ui/src/config/components/`):**

- [ ] `ConfigScreen.tsx` — tab navigator (General / Projects / Groupings / Integrations /
      Signals), dirty-state header bar, Save / Discard buttons
- [ ] `GeneralTab.tsx` — Core paths, Chrome profiles auto-detect toggle, Git authors,
      timing fields, personal hosts/apps
- [ ] `ProjectsTab.tsx` — project list with grouping badge and signal badges; Add project
- [ ] `ProjectDrawer.tsx` — per-project editor: name, grouping, repos, VSCode dirs,
      domains, Slack rules, SSH hosts, apps, Harvest, ClickUp; delete/ignore buttons
- [ ] `GroupingsTab.tsx` — grouping cards: name, color picker, logo URL, aliases,
      time-tracking type, Harvest connection
- [ ] `IntegrationsTab.tsx` — repeatable connection cards for Harvest, ClickUp, GitHub,
      LLM, Clockify
- [ ] `SignalsTab.tsx` — unmatched signal reassignment (needs report data; show
      placeholder when no report loaded yet)
- [ ] `FieldRow.tsx` — universal label + control: text, number, checkbox, select,
      textarea (newline-separated arrays), color, URL, password (with show/hide), JSON blob
- [ ] `ConnectionCard.tsx` — labelled card with Remove button; used by IntegrationsTab

**Desktop app wiring (`apps/desktop/`):**

- [ ] Add `ConfigScreen` to app navigation (button from main placeholder screen)
- [ ] Hook `getConfig` / `saveConfig` native module calls into `useConfigDraft`
- [ ] Dirty-state warning on navigate-away (macOS `windowShouldClose:` equivalent)

Acceptance criteria:

- user can open config, edit any field across all five tabs, save, and discard
- save writes a valid `config.json` that the PHP app can still parse
- dirty-state is tracked correctly; navigating away with unsaved changes warns

### Phase 5: Advanced Features _(not started)_

After Phase 3 and 4 are complete:

1. **LLM day summaries** — call sidecar `/generate-summary`, display in DayView
2. **Unlogged-time suggestions** — call sidecar `/suggest-logging`, display in Signals tab
3. **GitHub integration activity** — surface GitHub PR/commit counts alongside projects
4. **GitHub Desktop repo discovery** — surface discovered repos in Projects tab

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

| Layer        | Choice                                                      | Notes                                         |
| ------------ | ----------------------------------------------------------- | --------------------------------------------- |
| UI framework | React Native macOS (react-native-macos 0.81)                | macOS first; Windows later                    |
| Language     | TypeScript (engine) + JSDoc-annotated JS (engine internals) | strict mode                                   |
| Build        | Metro bundler + React Native CLI                            | `npm run desktop:dev`                         |
| Navigation   | React Navigation or built-in tab view                       | TBD in Phase 4                                |
| Form state   | Custom `useConfigDraft` hook                                | keeps logic in packages/ui, no RHF dependency |
| Validation   | zod                                                         | `configSchema` in packages/ui                 |
| Timelines    | react-native-svg                                            | Phase 3                                       |
| Testing      | Jest + fixture-based parity tests                           | 30+ tests passing                             |
| IPC tier 1   | ObjC `RCTBridgeModule` native module                        | config file I/O only                          |
| IPC tier 2   | Node.js child process + local HTTP                          | reports, SQLite, integrations                 |

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
