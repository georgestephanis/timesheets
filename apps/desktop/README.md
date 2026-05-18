# @timesheets/desktop

React Native macOS desktop application for timesheets.

## Status

Phase 1 — macOS shell initialized and building. Shows the default React Native
welcome screen. Phase 2 will wire in `@timesheets/engine` and `@timesheets/ui`
for real report data.

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

This app is the UI layer only. All data access, classification, and caching go through
`@timesheets/engine` via an IPC boundary — the desktop app does not read databases,
git repos, or config files directly.

See [NATIVE.md](../../NATIVE.md) for the full migration plan.
