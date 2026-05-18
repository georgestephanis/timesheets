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

# Kill Metro on exit so it doesn't linger after Ctrl-C.
cleanup() {
    if [[ -n "$METRO_PID" ]]; then
        kill "$METRO_PID" 2>/dev/null || true
    fi
}
trap cleanup EXIT INT TERM

echo "Starting Metro bundler..."
npm run desktop:start --prefix "$ROOT" &
METRO_PID=$!

echo "Waiting for Metro to be ready..."
until curl -s http://localhost:8081/status > /dev/null 2>&1; do
    sleep 0.5
done
echo "Metro ready — launching app."

npm -w @timesheets/desktop run macos -- --no-packager
