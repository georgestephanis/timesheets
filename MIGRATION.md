# Migration Guide — PHP to Desktop App

This guide is for users who have been using the PHP CLI or PHP web UI and want to
start using the React Native macOS desktop app as their primary interface.

The desktop app reads the same `config.json` and `reports/` directory as the PHP tools.
You can run both in parallel — no data migration is required.

---

## First-time setup

### 1. Install Node.js

The desktop app's engine sidecar requires Node.js v18 or later.

```bash
# Check if you have it
node --version

# Install via nvm (recommended — matches the project's .nvmrc)
nvm install

# Or install directly from https://nodejs.org
```

### 2. Install workspace dependencies

From the repo root:

```bash
npm install
```

This links the `@timesheets/engine` workspace package and builds native modules
(including `better-sqlite3`).

### 3. Build and launch the app

```bash
npm run desktop:dev
```

This starts Metro and launches the macOS app. On first launch:

- If you already have a `config.json`, click **Open Config** and the app will use
  the default path (`~/.config/timesheets/config.json`) or you can pick a different
  directory.
- If your `config.json` lives in a custom location (e.g. the repo root), click
  **Open Config → (config path in header) → Change…** and navigate to the directory
  containing `config.json`.

The engine sidecar starts automatically when the app launches. The Home screen shows
engine status; once it shows nothing (running normally), open Reports or Config.

---

## Config file location

The PHP tools use `config.json` in the current working directory (wherever you run
`php activity-report.php` or `composer serve`). The desktop app stores the config
directory in `NSUserDefaults` and defaults to `~/.config/timesheets/config.json`.

**To point the desktop app at your existing config:**

1. Open the app → **Open Config**
2. In the header, tap the config path
3. Choose **Change…** and select the directory that contains your `config.json`

The app writes the chosen directory to `NSUserDefaults` and uses it on every
subsequent launch. The PHP tools are unaffected — they still look in the cwd.

---

## What the desktop app does and doesn't do (yet)

### Fully supported

- Day-by-day report browsing with rebuild
- Per-project activity detail, commits, integration badges
- LLM-generated daily summaries
- Full config editing: General, Projects, Groupings, Integrations, Signals tabs
- GitHub Desktop repo discovery
- LLM-assisted signal assignment suggestions

### Not yet in the desktop app

These features still require the PHP CLI or web UI:

| Feature                         | PHP alternative                                        |
| ------------------------------- | ------------------------------------------------------ |
| Multi-day / weekly reports      | `php activity-report.php --days 7` or `composer serve` |
| Harvest time-tracking sidebar   | `composer serve`                                       |
| Harvest/ClickUp project sync    | `php tools/sync-integration-projects.php`              |
| Export to Markdown / JSON / TSV | `php activity-report.php --format md`                  |
| Backfill prior 7 days           | `php activity-report.php` (no-arg / cron)              |
| Flag signal as personal         | Web UI config panel                                    |

See [APP-vs-PHP.md](APP-vs-PHP.md) for the full feature comparison.

---

## Keeping the cron job

The cron job (`php activity-report.php` at 4 am) is still useful even when using
the desktop app as your primary interface. It pre-warms the per-day source caches so
the desktop's Reports screen loads instantly rather than re-fetching from ActivityWatch
and Chrome on demand.

If you remove the cron job, clicking **↺ Rebuild** in the desktop app will still work
— it just takes a few seconds longer.

---

## Recommended workflow transition

| Task                          | Before                                            | After                                        |
| ----------------------------- | ------------------------------------------------- | -------------------------------------------- |
| Check today's activity        | `composer serve`                                  | Open desktop app → Reports                   |
| Fix a misassigned signal      | Web UI config panel                               | Desktop → Config → Signals                   |
| Add a new project             | Edit `config.json`                                | Desktop → Config → Projects → + Add Project  |
| Add a Harvest connection      | Edit `config.json`                                | Desktop → Config → Integrations              |
| Add repos from GitHub Desktop | `php tools/list-github-desktop-repos.php --apply` | Desktop → Config → Projects → Discover Repos |
| Generate a day summary        | Web UI "Generate day summary" button              | Desktop → Reports → ✦ Generate Summary       |
| Weekly review                 | `composer serve` (date range)                     | Still use `composer serve` for now           |
| Daily backfill                | Cron or `php activity-report.php`                 | Keep the cron job running                    |

---

## Apple Intelligence in the PHP web UI

The PHP web UI can use on-device Apple Intelligence as an LLM provider without the
desktop app open. It requires a one-time build of the standalone server binary:

```bash
cd tools/apple-intelligence-server
swift build -c release
```

After that, `composer serve` (or any PHP LLM call) will automatically start the server
the first time it needs Apple Intelligence — no manual steps. To select it:

```bash
# Explicit selection
TIMESHEETS_LLM="Apple Intelligence (on-device)" php activity-report.php

# Or put it first in config.json integrations.llm to make it the default
```

The server runs on `127.0.0.1:57911` — the same port the desktop app uses — so
`config.json` needs only a single entry for both surfaces.

**One-shot CLI testing with apfel:** If you have apfel installed (`brew install apfel`),
you can also test Apple Intelligence interactively without the server:

```bash
echo "Summarise what 3h of ActivityWatch time on a project looks like" | apfel
```

---

## Packaging for distribution (Phase 6)

If you want to distribute the app as a signed `.app` bundle (so it runs without the
development environment installed):

### 1. Run the engine bundler

```bash
bash tools/bundle-engine.sh
```

This downloads a universal macOS Node.js binary and assembles the minimal set of
engine files into `apps/desktop/macos/TimesheetsDesktop-macOS/engine-bundle/`.

### 2. Add the bundle to Xcode

1. Open `apps/desktop/macos/TimesheetsDesktop.xcodeproj` in Xcode
2. In the project navigator, right-click **TimesheetsDesktop-macOS**
3. Choose **Add Files…** → navigate to `engine-bundle/` → add as **folder reference**
4. Rename the reference to `engine` so it lands at `Contents/Resources/engine/`

### 3. Set up signing

```bash
cp apps/desktop/macos/signing.xcconfig.example apps/desktop/macos/signing.xcconfig
```

Edit `apps/desktop/macos/signing.xcconfig`:

```xcconfig
DEVELOPMENT_TEAM = YOUR_TEAM_ID          # 10-char Apple Developer Team ID
CODE_SIGN_IDENTITY = Apple Development   # or "Apple Distribution" for release
PRODUCT_BUNDLE_IDENTIFIER = com.example.timesheets   # pick a real bundle ID
```

### 4. Archive and notarize

```bash
xcodebuild -workspace apps/desktop/macos/TimesheetsDesktop.xcworkspace \
           -scheme TimesheetsDesktop-macOS \
           -configuration Release \
           -archivePath build/TimesheetsDesktop.xcarchive \
           archive
```

Then export and notarize via Xcode Organizer or `xcrun notarytool`.

---

## Keeping PHP around

The PHP implementation remains the behavior oracle and the only surface with feature
parity for multi-day reports, Harvest sidebar, and maintenance tooling. There is no
need to remove it — the two interfaces coexist on the same `config.json` and
`reports/` data without conflict.

When the desktop app reaches full parity (see [NATIVE.md](NATIVE.md) Phase 6), the
PHP web server entrypoints can be retired. Until then, keep both running.
