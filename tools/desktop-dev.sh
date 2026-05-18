#!/bin/bash
# Start Metro bundler, wait for it to be ready, then build and launch the macOS app.
# Usage: npm run desktop:dev [-- --clean]  (from repo root)
#   --clean  wipe Xcode DerivedData for TimesheetsDesktop before building

set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Parse flags
CLEAN=0
for arg in "$@"; do
    [[ "$arg" == "--clean" ]] && CLEAN=1
done

if [[ $CLEAN -eq 1 ]]; then
    DERIVED=$(find "$HOME/Library/Developer/Xcode/DerivedData" -maxdepth 1 -name "TimesheetsDesktop-*" 2>/dev/null)
    if [[ -n "$DERIVED" ]]; then
        echo "Clearing DerivedData: $DERIVED"
        rm -rf "$DERIVED"
    fi
fi

# Kill Metro and log stream on exit so they don't linger after Ctrl-C.
cleanup() {
    [[ -n "$LOG_PID"   ]] && kill "$LOG_PID"   2>/dev/null || true
    [[ -n "$METRO_PID" ]] && kill "$METRO_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

# Stream app logs to this terminal so NSLog output is visible.
echo "--- app log stream (process: TimesheetsDesktop-macOS) ---"
log stream --predicate 'process == "TimesheetsDesktop-macOS"' --level debug 2>/dev/null &
LOG_PID=$!

echo "Starting Metro bundler..."
npm run desktop:start --prefix "$ROOT" &
METRO_PID=$!

echo "Waiting for Metro to be ready..."
until curl -s http://localhost:8081/status > /dev/null 2>&1; do
    sleep 0.5
done
echo "Metro ready — launching app."

npm -w @timesheets/desktop run macos -- --no-packager
