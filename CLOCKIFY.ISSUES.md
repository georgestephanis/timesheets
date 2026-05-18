# Clockify Integration — Known Issues

These issues were identified in commit `0b4ff91` ("Add Clockify integration") by comparing the implementation against the spec in `CLOCKIFY.md`.

---

## Critical — Schema actively rejects valid configs

### 1. `clockify` not added to `integrations` properties in `config.schema.json`

The `integrations` object at `config.schema.json:193` has `"additionalProperties": false`. The commit added `clockify_connection` under `$defs` but never added the corresponding entry to `integrations.properties`:

```json
"clockify": {
    "type": "array",
    "items": { "$ref": "#/$defs/clockify_connection" },
    "default": []
}
```

Any user who adds `"clockify": [...]` to their `config.json` will get a schema validation error.

### 2. `clockify_projects` not added to the project definition in `config.schema.json`

The `$defs/project` object at `config.schema.json:232` also has `"additionalProperties": false`. The `clickup_tasks` property (line ~310) is the last project-level signal, but `clockify_projects` was never added after it:

```json
"clockify_projects": {
    "description": "Clockify project name globs that map to this local project.",
    "type": "array",
    "items": { "type": "string" },
    "minItems": 1
}
```

Any project using `"clockify_projects": [...]` will fail schema validation.

---

## Significant — Auto-resolve and persistence unimplemented

### 3. Auto-resolve of `user_id`/`workspace_id` missing in `src/loader-integrations.php`

The spec's Clockify loader loop (CLOCKIFY.md § "Changes to Existing Files → loader-integrations.php") includes a block that calls `resolveClockifyUserInfo()` when `user_id` or `workspace_id` are absent. This block was not implemented.

A user who provides only `name` and `api_key` (a valid config per the schema, since both IDs are optional) will silently receive no entries — `loadClockifyTimeEntries()` returns `[]` when either field is empty.

The equivalent pattern for Harvest already exists at `src/loader-integrations.php:38-43`. The Clockify loop should mirror it:

```php
if (!idLooksStandard($conn['user_id'] ?? null) || !idLooksStandard($conn['workspace_id'] ?? null)) {
    $resolved = resolveClockifyUserInfo($conn, $httpTimeout);
    if ($resolved !== null) {
        $conn['user_id']      = $resolved['user_id'];
        $conn['workspace_id'] = $resolved['workspace_id'];
        $config['integrations']['clockify'][$idx]['user_id']      = $resolved['user_id'];
        $config['integrations']['clockify'][$idx]['workspace_id'] = $resolved['workspace_id'];
        $configDirty = true;
    }
}
```

### 4. Config persistence block missing in `src/loader-integrations.php`

The spec requires a Clockify block in the `$configDirty` section (around line 111) to write resolved `user_id`/`workspace_id` back to `config.json`. Without it, even if auto-resolve were added (issue #3), the resolved values would never be persisted — so every run would re-fetch from the API.

The block to add (inside the `if ($configDirty)` section, mirroring the Harvest/ClickUp patterns):

```php
foreach (($config['integrations']['clockify'] ?? []) as $conn) {
    if (!isset($conn['user_id'], $conn['workspace_id'], $conn['name'])) {
        continue;
    }
    foreach (($existing['integrations']['clockify'] ?? []) as &$existingConn) {
        if (is_array($existingConn) && ($existingConn['name'] ?? null) === $conn['name']) {
            $existingConn['user_id']      = (string)$conn['user_id'];
            $existingConn['workspace_id'] = (string)$conn['workspace_id'];
            break;
        }
    }
    unset($existingConn);
}
```

---

## Minor — Small behavioral deviations from spec

### 5. `clockify-catalog.php` not required in `src/loader-integrations.php`

Only `clockify.php` was added to the require block. `clockify-catalog.php` exists but is never loaded anywhere — it is currently dead code. Add:

```php
require_once __DIR__ . '/integrations/clockify-catalog.php';
```

### 6. `clockify` missing from `$unmatched` init in `src/classifiers.php`

The spec says to add `'clockify' => []` to the `$unmatched` array at `src/classifiers.php:284`. It was not added. Clockify entries are still tracked via dynamic key creation at line 557, but the `--show-unmatched` output will be inconsistent — other sources appear even when empty, Clockify will not.

Change line 284 from:

```php
$unmatched = ['vscode' => [], 'browser' => [], 'slack' => [], 'apps' => [], 'harvest' => [], 'clickup' => [], 'github' => []];
```

to:

```php
$unmatched = ['vscode' => [], 'browser' => [], 'slack' => [], 'apps' => [], 'harvest' => [], 'clickup' => [], 'clockify' => [], 'github' => []];
```

### 7. Project name resolution uses per-ID endpoint instead of bulk fetch (`src/integrations/clockify.php`)

The spec says to fetch all projects via `GET /v1/workspaces/{workspaceId}/projects` once per `loadClockifyTimeEntries()` call, building an ID→name map upfront. The implementation instead calls `GET /v1/workspaces/{workspaceId}/projects/{projectId}` for each unique project ID encountered in entries.

The caching is correct (each ID is only fetched once), but the spec endpoint is `/projects` (list), not `/projects/{id}` (single). If entries span multiple projects this fires several sequential requests instead of one.

### 8. ISO 8601 duration field ignored (`src/integrations/clockify.php`)

The spec says to parse `timeInterval.duration` (e.g. `PT1H30M`) via `new DateInterval($duration)` and convert to seconds, falling back to `end - start` only if parsing fails. The implementation only uses `end - start` and ignores the `duration` field entirely. In practice the fallback is accurate, but it's a deviation from the spec.

---

## Cosmetic

### 9. `config.example.json` shows empty array instead of populated connection

The spec shows an example connection object with all four fields (`name`, `api_key`, `workspace_id`, `user_id`) populated. The commit added `"clockify": []`. Not harmful, but less helpful for onboarding.
