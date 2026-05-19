# @timesheets/desktop

React Native macOS desktop application for timesheets.

## Status

Phase 5 complete. The app is fully functional with reports, config editing, signal classification,
LLM daily summaries, and sidecar auto-start. See [NATIVE.md](../../NATIVE.md) for the phased
roadmap and current feature set.

## Running

```bash
# From the repo root — start Metro bundler and build+launch in one command:
npm run desktop:dev

# Or two terminals:
npm run desktop:start   # Terminal 1 — Metro bundler
npm run desktop:macos   # Terminal 2 — build and launch
```

## Re-installing pods (after dependency changes)

```bash
cd apps/desktop
LANG=en_US.UTF-8 pod install --project-directory=macos
```

## Architecture

The app has three screens navigated from a Home screen:

- **Home** — shows engine status (starting/running/error/not-configured) and buttons to open
  Reports or Config. If the engine is not yet configured, a "Set Up Engine…" button appears.
- **Reports** — day navigation, per-project activity, commits, integration badges, LLM day summary,
  and a "Rebuild" button. The sidecar engine starts automatically on app launch via `SidecarProvider`.
- **Config** — tabbed config editor: General, Projects (with GitHub Desktop repo discovery), Signals
  (unmatched signal assignment with LLM suggestions), and Integrations.

All data access goes through `engine-server.js` — a Node.js HTTP sidecar launched as a child
process via `NativeEngine`. The desktop app does not read databases, git repos, or config files
directly. The sidecar is started automatically when the app launches.

### Key packages

| Package              | Role                                                                             |
| -------------------- | -------------------------------------------------------------------------------- |
| `@timesheets/engine` | Node.js engine; reads ActivityWatch, Chrome, Git, integrations; writes reports   |
| `@timesheets/ui`     | React Native components: `ConfigScreen`, `ReportScreen`, `SidecarProvider`, etc. |

### IPC endpoints (engine-server.js)

| Method | Path                   | Description                                                      |
| ------ | ---------------------- | ---------------------------------------------------------------- |
| GET    | `/report`              | Fetch or rebuild a date-range report                             |
| POST   | `/save-config`         | Write `config.json` with backup                                  |
| POST   | `/reassign-signal`     | Assign an unmatched signal to a project                          |
| POST   | `/generate-summary`    | Ask the configured LLM to generate a day summary                 |
| POST   | `/suggest-assignments` | Ask the LLM to suggest project assignments for unmatched signals |
| GET    | `/discover-repos`      | Return repos registered in GitHub Desktop with assignment status |

See [NATIVE.md](../../NATIVE.md) for the full IPC table and phased feature breakdown.
