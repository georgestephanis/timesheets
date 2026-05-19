#!/usr/bin/env bash
# bundle-engine.sh — prepare the engine/ resource bundle for a distribution build
#
# Downloads a macOS universal Node.js binary and assembles the minimal set of
# files the engine sidecar needs into:
#
#   apps/desktop/macos/TimesheetsDesktop-macOS/engine-bundle/
#     node                    ← Node.js universal binary
#     engine-server.js        ← sidecar entry point
#     node_modules/
#       @timesheets/engine/   ← engine package (copied from workspace)
#       better-sqlite3/       ← native SQLite bindings
#       node-gyp-build/       ← better-sqlite3 runtime loader
#
# After running this script, add the engine-bundle/ folder to Xcode as a
# folder reference under Copy Bundle Resources so it lands at:
#   Contents/Resources/engine/
#
# Usage:
#   bash tools/bundle-engine.sh [--node-version 22]
#
# The script is idempotent: re-running it overwrites the bundle in place.
# Run it whenever engine-server.js, packages/engine, or the Node version changes.
#
# Requirements: curl, tar, node (for initial npm ci), and a macOS host.

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="$REPO_ROOT/apps/desktop/macos/TimesheetsDesktop-macOS/engine-bundle"
NODE_VERSION=22

# Parse --node-version flag
while [[ $# -gt 0 ]]; do
  case "$1" in
    --node-version) NODE_VERSION="$2"; shift 2 ;;
    *) echo "Unknown option: $1" >&2; exit 1 ;;
  esac
done

echo "→ Bundling engine for distribution (Node $NODE_VERSION)"
echo "  Destination: $DEST"

# ── 1. Resolve latest patch release ─────────────────────────────────────────

RELEASES_URL="https://nodejs.org/dist/index.json"
echo "→ Resolving latest Node $NODE_VERSION.x release..."
FULL_VERSION=$(curl -fsSL "$RELEASES_URL" | \
  node -e "const d=require('fs').readFileSync('/dev/stdin','utf8');
           const v=JSON.parse(d).find(r=>r.version.startsWith('v$NODE_VERSION.'));
           if(!v){process.stderr.write('No Node $NODE_VERSION release found\n');process.exit(1);}
           process.stdout.write(v.version)" 2>/dev/null)
echo "  Using $FULL_VERSION"

# ── 2. Download macOS universal binary ──────────────────────────────────────

TARBALL="node-${FULL_VERSION}-darwin-x64.tar.gz"
TARBALL_ARM="node-${FULL_VERSION}-darwin-arm64.tar.gz"
BASE_URL="https://nodejs.org/dist/${FULL_VERSION}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

echo "→ Downloading Node binaries..."
curl -fsSL "$BASE_URL/$TARBALL"     -o "$TMP/node-x64.tar.gz"
curl -fsSL "$BASE_URL/$TARBALL_ARM" -o "$TMP/node-arm64.tar.gz"

tar -xzf "$TMP/node-x64.tar.gz"   -C "$TMP" --strip-components=2 \
  "node-${FULL_VERSION}-darwin-x64/bin/node"
mv "$TMP/node" "$TMP/node-x64"

tar -xzf "$TMP/node-arm64.tar.gz" -C "$TMP" --strip-components=2 \
  "node-${FULL_VERSION}-darwin-arm64/bin/node"
mv "$TMP/node" "$TMP/node-arm64"

echo "→ Creating universal binary via lipo..."
lipo -create "$TMP/node-x64" "$TMP/node-arm64" -output "$TMP/node-universal"

# ── 3. Assemble the bundle ───────────────────────────────────────────────────

rm -rf "$DEST"
mkdir -p "$DEST/node_modules"

echo "→ Copying Node binary..."
cp "$TMP/node-universal" "$DEST/node"
chmod +x "$DEST/node"

echo "→ Copying engine-server.js..."
cp "$REPO_ROOT/apps/desktop/engine-server.js" "$DEST/engine-server.js"

echo "→ Copying @timesheets/engine..."
cp -r "$REPO_ROOT/packages/engine" "$DEST/node_modules/@timesheets"
# Resolve workspace symlink if present
if [ -L "$REPO_ROOT/node_modules/@timesheets/engine" ]; then
  mkdir -p "$DEST/node_modules/@timesheets"
  cp -rL "$REPO_ROOT/node_modules/@timesheets/engine" "$DEST/node_modules/@timesheets/engine"
fi

echo "→ Copying better-sqlite3 (with native module)..."
cp -rL "$REPO_ROOT/node_modules/better-sqlite3" "$DEST/node_modules/better-sqlite3"
cp -rL "$REPO_ROOT/node_modules/node-gyp-build"  "$DEST/node_modules/node-gyp-build"

# Verify the native module exists and is the right arch
NATIVE_MODULE="$DEST/node_modules/better-sqlite3/build/Release/better_sqlite3.node"
if [ ! -f "$NATIVE_MODULE" ]; then
  echo "✗ better_sqlite3.node not found at $NATIVE_MODULE" >&2
  echo "  Run: npm install (from the repo root) to build native modules first." >&2
  exit 1
fi

ARCHES=$(lipo -archs "$NATIVE_MODULE" 2>/dev/null || file "$NATIVE_MODULE")
echo "  better_sqlite3.node arches: $ARCHES"

# ── 4. Strip debug symbols to reduce size ───────────────────────────────────

echo "→ Stripping debug symbols..."
strip -x "$DEST/node" 2>/dev/null || true
strip -x "$NATIVE_MODULE" 2>/dev/null || true

# ── 5. Print summary ─────────────────────────────────────────────────────────

BUNDLE_SIZE=$(du -sh "$DEST" | cut -f1)
echo ""
echo "✓ Engine bundle ready: $DEST ($BUNDLE_SIZE)"
echo ""
echo "Next steps:"
echo "  1. Open the Xcode project"
echo "  2. In the project navigator, right-click the TimesheetsDesktop-macOS group"
echo "  3. Choose Add Files → navigate to engine-bundle/ → add as folder reference"
echo "  4. Rename the reference to 'engine' in Xcode so it lands at Contents/Resources/engine/"
echo "  5. Build and archive"
