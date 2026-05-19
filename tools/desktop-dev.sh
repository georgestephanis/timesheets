#!/bin/bash
# Start Metro bundler, wait for it to be ready, then build and launch the macOS app.
# Usage: npm run desktop:dev [-- --clean] [-- --reset-cache]  (from repo root)
#   --clean        wipe Xcode DerivedData for TimesheetsDesktop before building
#   --reset-cache  pass --reset-cache to Metro (clears transform cache)

set -e

ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# Parse flags
CLEAN=0
RESET_CACHE=0
for arg in "$@"; do
    [[ "$arg" == "--clean" ]]       && CLEAN=1
    [[ "$arg" == "--reset-cache" ]] && RESET_CACHE=1
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
echo "--- app log stream (process: TimesheetsDesktop) ---"
log stream --predicate 'process == "TimesheetsDesktop" AND NOT subsystem BEGINSWITH "com.apple.network" AND NOT subsystem BEGINSWITH "com.apple.launchservices" AND NOT subsystem BEGINSWITH "com.apple.CFNetwork" AND NOT subsystem BEGINSWITH "com.apple.defaults"' --level default 2>/dev/null &
LOG_PID=$!

METRO_FLAGS=""
[[ $RESET_CACHE -eq 1 ]] && METRO_FLAGS="-- --reset-cache"

echo "Starting Metro bundler..."
# shellcheck disable=SC2086
npm run desktop:start --prefix "$ROOT" $METRO_FLAGS &
METRO_PID=$!

echo "Waiting for Metro to be ready..."
until curl -s http://localhost:8081/status > /dev/null 2>&1; do
    sleep 0.5
done
echo "Metro ready — pre-warming bundle (first compilation may take ~30s)..."
curl -s --max-time 120 \
  "http://localhost:8081/index.bundle?platform=macos&dev=true&lazy=true&minify=false" \
  -o /dev/null \
  && echo "Bundle warm — launching app." \
  || echo "Bundle pre-warm timed out — launching anyway."

npm -w @timesheets/desktop run macos -- --no-packager

echo "App launched. Metro is still running — press Ctrl-C to stop."
wait $METRO_PID
