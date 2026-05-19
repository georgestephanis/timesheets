# Clockify Integration Implementation Review

This document details the issues found during the review of the Clockify integration implementation against the specification in `CLOCKIFY.md`.

## Critical Issues (Must Fix)

### Issue 1: Schema Definition Missing - `clockify` not added to `integrations` properties

**Location**: `config.schema.json`
**Status**: ✗ Not Implemented
**Reference**: CLOCKIFY.ISSUES.md #1

The `integrations` object at `config.schema.json:193` has `"additionalProperties": false`. The implementation added `clockify_connection` under `$defs` but never added the corresponding entry to `integrations.properties`:

```json
"clockify": {
    "type": "array",
    "items": { "$ref": "#/$defs/clockify_connection" },
    "default": []
}
```

**Impact**: Any user who adds `"clockify": [...]` to their `config.json` will get a schema validation error.

### Issue 2: Schema Definition Missing - `clockify_projects` not added to project definition

**Location**: `config.schema.json`
**Status**: ✗ Not Implemented
**Reference**: CLOCKIFY.ISSUES.md #2

The `$defs/project` object at `config.schema.json:232` also has `"additionalProperties": false`. The implementation never added `clockify_projects` after the `clickup_tasks` property:

```json
"clockify_projects": {
    "description": "Clockify project name globs that map to this local project.",
    "type": "array",
    "items": { "type": "string" },
    "minItems": 1
}
```

**Impact**: Any project using `"clockify_projects": [...]` will fail schema validation.

## Significant Issues

### Issue 3: Auto-resolve Logic Missing - `user_id`/`workspace_id` Resolution

**Location**: `src/loader-integrations.php`
**Status**: ✗ Not Implemented
**Reference**: CLOCKIFY.ISSUES.md #3

The spec requires a block that calls `resolveClockifyUserInfo()` when `user_id` or `workspace_id` are absent. This block was not implemented.

**Impact**: A user who provides only `name` and `api_key` will silently receive no entries.

### Issue 4: Config Persistence Missing

**Location**: `src/loader-integrations.php`
**Status**: ✗ Not Implemented
**Reference**: CLOCKIFY.ISSUES.md #4

The spec requires a Clockify block in the `$configDirty` section to write resolved `user_id`/`workspace_id` back to `config.json`.

**Impact**: Resolved values would never be persisted, causing re-fetch on every run.

### Issue 5: Catalog File Not Required

**Location**: `src/loader-integrations.php`
**Status**: ✗ Not Implemented
**Reference**: CLOCKIFY.ISSUES.md #5

Only `clockify.php` was added to the require block. `clockify-catalog.php` exists but is never loaded.

**Impact**: Dead code - `clockify-catalog.php` is currently unused.

### Issue 6: Unmatched Tracking Missing

**Location**: `src/classifiers.php`
**Status**: ✗ Not Implemented
**Reference**: CLOCKIFY.ISSUES.md #6

The spec requires adding `'clockify' => []` to the `$unmatched` array initialization.

**Impact**: `--show-unmatched` output will be inconsistent - Clockify entries won't appear in the unmatched list.

## Minor Issues

### Issue 7: Project Name Resolution Suboptimal

**Location**: `src/integrations/clockify.php`
**Status**: ⚠ Partially Implemented
**Reference**: CLOCKIFY.ISSUES.md #7

The spec says to fetch all projects via `/projects` once per call, building an ID→name map upfront. The implementation instead calls `/projects/{id}` for each unique project ID.

**Impact**: Multiple sequential requests instead of one bulk fetch.

### Issue 8: Duration Field Ignored

**Location**: `src/integrations/clockify.php`
**Status**: ⚠ Partially Implemented
**Reference**: CLOCKIFY.ISSUES.md #8

The spec says to parse `timeInterval.duration` via `DateInterval` and convert to seconds, falling back to `end - start`. The implementation only uses `end - start`.

**Impact**: Deviation from spec, though fallback is accurate.

### Issue 9: Example Config Incomplete

**Location**: `config.example.json`
**Status**: ⚠ Not Implemented
**Reference**: CLOCKIFY.ISSUES.md #9

The spec shows an example connection with all fields populated. The implementation shows `"clockify": []`.

**Impact**: Less helpful for onboarding.

## Implementation Status

The current implementation partially addresses the specification but has several critical and significant issues that prevent it from being fully functional and compliant with the proposed specification.

---

## Implementation Progress

This section tracks the progress of addressing each issue above.

### ✅ Issue 1: Schema Definition - `clockify` added to integrations properties

**Completed**: Added `clockify` to the `integrations` object in `config.schema.json`

### ✅ Issue 2: Schema Definition - `clockify_projects` added to project definition

**Completed**: Added `clockify_projects` to the project definition in `config.schema.json`

### ✅ Issue 3: Auto-resolve Logic - User/workspace ID resolution implemented

**Completed**: Added logic to `src/loader-integrations.php` to auto-resolve user_id and workspace_id

### ✅ Issue 4: Config Persistence - Resolved values written back to config

**Completed**: Added persistence block to `src/loader-integrations.php` to save resolved values

### ✅ Issue 5: Catalog File Required - `clockify-catalog.php` now loaded

**Completed**: Added `require_once` for `clockify-catalog.php` in `src/loader-integrations.php`

### ✅ Issue 6: Unmatched Tracking - `clockify` added to `$unmatched` array

**Completed**: Added `'clockify' => []` to the `$unmatched` initialization in `src/classifiers.php`

### ✅ Issue 7: Project Name Resolution - Bulk fetch approach

**Completed**: Replaced per-ID `/projects/{id}` calls with a single paginated `/projects` bulk fetch at the start of `loadClockifyTimeEntries`. The pre-built ID→name map is used for all entries in the same call.

### ✅ Issue 8: Duration Field - ISO 8601 parsing

**Completed**: Added `DateInterval` parsing of `timeInterval.duration` (e.g. `PT1H30M`) with conversion to seconds. Falls back to `end - start` if the field is absent or unparseable.

### ✅ Issue 9: Example Config - Full example provided

**Completed**: `config.example.json` already contains a populated connection block with all four fields (`name`, `api_key`, `workspace_id`, `user_id`).
