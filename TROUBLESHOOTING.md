# Troubleshooting

## ActivityWatch: empty or missing activity

**Symptom:** Report shows no window/AFK/input data, or only shows commits.

**Cause:** ActivityWatch is not running, or the SQLite database path is wrong.

**Fix:**

1. Check that the ActivityWatch app is running (`aw-server` process should be visible in Activity Monitor).
2. Verify the database path in `config.json`:
    ```json
    "paths": {
        "activitywatch": "~/Library/Application Support/activitywatch/"
    }
    ```
    The tool looks for `aw-server.sqlite` under that directory. Run:
    ```bash
    ls ~/Library/Application\ Support/activitywatch/
    ```
3. If the path differs (e.g. you use `aw-server-rust`), update `config.json` to point at the correct directory.
4. Add `--show-unmatched` to a CLI run to verify events are being loaded but not matching any project rule.

---

## Chrome history: "database is locked" error

**Symptom:** Chrome history shows no results; STDERR or logs include "database is locked".

**Cause:** Chrome holds an exclusive write lock on its SQLite database while running.

**Fix:** The tool already works around this automatically via `copyForRead()` in `src/helpers.php` — it copies the SQLite file to a temp location before opening it. If you still see lock errors:

1. Make sure Chrome is not in the middle of a crash recovery (force-quit and relaunch).
2. Verify that `config.json` points to the correct Chrome profile directory:
    ```json
    "paths": {
        "chrome": "~/Library/Application Support/Google/Chrome/"
    }
    ```
3. If you use multiple Chrome profiles, set `chrome_profiles` to the list you want scanned:
    ```json
    "chrome_profiles": ["Default", "Profile 1"]
    ```
    Setting it to `null` (the default) scans all profiles.

---

## GitHub integration: skipped or no activity

**Symptom:** GitHub entries are absent from the report; STDERR shows "gh: command not found" or "not authenticated".

**Cause:** The GitHub integration uses the `gh` CLI. It must be installed and authenticated.

**Fix:**

1. Install `gh`: `brew install gh`
2. Authenticate: `gh auth login`
3. Verify: `gh auth status`
4. If you haven't added a GitHub integration to `config.json` yet, run:
    ```bash
    php tools/ensure-github-integration.php
    ```
5. The GitHub integration is **CLI-only** — it is skipped when requests come from the web UI to avoid blocking page loads. Run the CLI directly to populate GitHub data into the per-day source cache; the web UI will then serve it from cache.

---

## Clockify integration: no entries or "workspace_id required"

**Symptom:** Clockify time entries are missing from the report; STDERR may show nothing (silent empty return) or a warning about missing credentials.

**Cause:** `workspace_id` and `user_id` must be present for the API call to proceed. They are auto-resolved on first run, but only if `api_key` is set and reachable.

**Fix:**

1. Make sure your `config.json` has at least `name` and `api_key` in the Clockify connection:
    ```json
    "clockify": [{ "name": "Main", "api_key": "YOUR_CLOCKIFY_API_KEY" }]
    ```
2. Run the report once from the CLI. If `workspace_id`/`user_id` are absent or non-standard, the tool calls `GET /v1/user` to resolve them and writes them back to `config.json` automatically.
3. If auto-resolve fails (e.g. bad API key), a warning is emitted to STDERR. Check the key in Clockify under **Profile → API**.
4. Clockify IDs are strings, not integers — if you copy them manually, make sure they are quoted in `config.json`.
5. If entries still don't appear after IDs are resolved, check that `clockify_projects` globs in your project definitions match the project names in your Clockify workspace.

---

## LLM / `--suggest`: endpoint unreachable

**Symptom:** `--suggest` exits immediately or prints a "Could not fetch model list" / "LLM request failed" error.

**Cause:** The configured LLM endpoint is not running or the URL is wrong.

**Fix:**

1. Check your `config.json` LLM connection:
    ```json
    "integrations": {
        "llm": [{ "base_url": "http://localhost:11434/v1", "api_key": "ollama" }]
    }
    ```
2. Test the endpoint manually:
    ```bash
    curl http://localhost:11434/v1/models
    ```
3. If using Ollama, make sure it is running: `ollama serve`
4. If the endpoint requires a real API key (e.g. OpenAI), verify `api_key` is set correctly.
5. Increase the timeout if the model is slow to respond:
    ```json
    { "base_url": "...", "timeout": 120 }
    ```

---

## Reports cache: stale data after config change

**Symptom:** After adding a new project or signal rule, old dates still show the old classification.

**Cause:** Per-day source data is cached in `reports/YYYY-MM/DD/`. The cache is not automatically invalidated when `config.json` changes.

**Fix:**

- Use the **Rebuild from source** button in the web UI to regenerate the current view with fresh data (this bypasses and overwrites the per-day caches for the visible range).
- Or delete specific day caches directly:
    ```bash
    rm -rf reports/2026-05/08/
    ```
- Or delete all caches to force a full rebuild next run:
    ```bash
    rm -rf reports/
    ```

---

## "Permission denied" on config.json

**Symptom:** The tool fails to write `config.json` after a signal reassignment or admin action.

**Cause:** You ran `chmod 600 config.json` (recommended for security) but are accessing the web UI as a different user, or the file was created with restrictive permissions.

**Fix:** Make sure the file is owned and readable by the user running `php -S`:

```bash
ls -l config.json
chmod 600 config.json   # owner read/write only
```

---

## Desktop app: engine not starting / "Engine not configured"

**Symptom:** The desktop app shows "Engine not configured" and no reports load.

**Cause:** The app cannot find `engine-server.js`. This path is saved in macOS UserDefaults on
first setup.

**Fix:**

1. Click **Set Up Engine…** on the Home screen and navigate to
   `apps/desktop/engine-server.js` inside your timesheets repo.
2. If the button is absent (app shows "Engine error" instead), see the engine error section below.

---

## Desktop app: engine error / sidecar crash

**Symptom:** Home screen shows "Engine error: …" in red, or the app spins on "Starting engine…"
indefinitely.

**Cause:** The Node.js sidecar process (`engine-server.js`) failed to start or crashed.

**Fix:**

1. Make sure Node.js is installed: `node --version` should print v18 or later.
2. Install workspace dependencies from the repo root:
    ```bash
    npm install
    ```
3. Test the sidecar directly:
    ```bash
    node apps/desktop/engine-server.js
    ```
    It should print `PORT:<n>` within a few seconds. Any error printed here is the root cause.
4. If the error mentions a missing module, re-run `npm install` from the repo root to relink
   workspace packages.
5. After fixing, relaunch the desktop app — `SidecarProvider` will attempt to start the engine
   automatically on mount.

---

## Desktop app: reports show no activity / empty days

**Symptom:** The Reports screen loads but shows no projects or all-zero time.

**Cause:** ActivityWatch is not running, the config has no projects, or the engine cannot read the
local data paths.

**Fix:**

1. Make sure ActivityWatch is running (check Activity Monitor for `aw-server`).
2. Open the Config screen → General tab and verify that the ActivityWatch and Chrome paths match
   your local installation.
3. Tap **↺ Rebuild** on the Reports screen to bypass any stale cache and re-fetch from source.
4. Check the Signals tab in Config — if many signals appear as "unmatched", your project rules
   may not cover the active windows/domains.

---

## Desktop app: LLM summary / suggestions not working

**Symptom:** "Generate Summary" button does nothing, or the Signals tab shows no LLM suggestions.

**Cause:** No LLM integration is configured, or the configured endpoint is unreachable.

**Fix:**

1. Open Config → Integrations and verify that an LLM connection is present with the correct
   `base_url` and `api_key`.
2. Test the endpoint from the terminal (see the **LLM / `--suggest`: endpoint unreachable**
   section above).
3. If using Ollama, ensure it is running: `ollama serve`

---

## Web UI: blank page or "Failed to fetch"

**Symptom:** Opening `http://localhost:8000` shows a blank report area or an error banner.

**Cause:** The PHP dev server is not running, or `api.php` returned an error.

**Fix:**

1. Start the server if it isn't running:
    ```bash
    composer serve
    ```
2. Open the browser console (F12) to see the underlying error.
3. Check the terminal where `composer serve` is running for PHP error output.
4. If the report area shows a red banner, it includes the error message from `api.php`.
