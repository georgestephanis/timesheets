# @timesheets/desktop

React Native macOS desktop application for timesheets.

## Status

Phase 1 — bootstrapping. The app shell is scaffolded but not yet runnable. The React
Native macOS project and native build configuration have not been initialized yet.

## Setup (once Phase 1 is complete)

```bash
npm install
npm run macos
```

## Architecture

This app is the UI layer only. All data access, classification, and caching go through
`@timesheets/engine` via an IPC boundary — the desktop app does not read databases,
git repos, or config files directly.

See [NATIVE.md](../../NATIVE.md) for the full migration plan.
