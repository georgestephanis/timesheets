# Clockify Integration

This document describes the design and implementation of Clockify as an external time tracking integration alongside the existing Harvest and ClickUp integrations. The implementation is complete.

---

## Overview

The timesheets codebase already has a clean, proven pattern for external time tracking integrations: each provider gets a loader file that fetches and normalizes time entries into a standard row format, wired up through `src/loader-integrations.php`, and mapped to local projects via glob rules in `config.json`. Clockify follows the same pattern with one added wrinkle: the API requires a **workspace ID** in addition to a user ID (unlike Harvest and ClickUp, which encode the workspace/team in the token or a top-level field).

---

## Clockify API

- **Base URL**: `https://api.clockify.me/api/v1`
- **Auth**: `X-Api-Key: <api_key>` header (no Bearer prefix; generated in Clockify → Profile → API)
- **Rate limit**: 10 requests/second on standard API keys
- **Timestamps**: ISO 8601 strings (e.g. `2026-05-18T09:00:00Z`) — no millisecond epoch like ClickUp

### Key endpoints used

| Endpoint                                                      | Purpose                                                  |
| ------------------------------------------------------------- | -------------------------------------------------------- |
| `GET /v1/user`                                                | Resolve authenticated user's ID and default workspace ID |
| `GET /v1/workspaces`                                          | List all workspaces the user belongs to                  |
| `GET /v1/workspaces/{workspaceId}/user/{userId}/time-entries` | Fetch time entries (paginated)                           |
| `GET /v1/workspaces/{workspaceId}/projects`                   | List projects for catalog/sync tool                      |

### Time entry fetch parameters

```
GET /v1/workspaces/{workspaceId}/user/{userId}/time-entries
    ?start=2026-05-01T00:00:00Z
    &end=2026-05-31T23:59:59Z
    &page=1
    &pageSize=50
```

- `page` is 1-indexed; response is an array (not a wrapper object)
- Pagination ends when a page returns fewer items than `pageSize`
- Entries with no `timeInterval.end` are running timers — skip them

### Time entry response shape

```json
{
    "id": "abc123",
    "description": "Working on the feature",
    "projectId": "proj456",
    "timeInterval": {
        "start": "2026-05-18T09:00:00Z",
        "end": "2026-05-18T10:30:00Z",
        "duration": "PT1H30M"
    }
}
```

The `projectId` is an opaque ID — a second lookup to `/v1/workspaces/{workspaceId}/projects` is needed to resolve it to a human-readable name for `project_hint`. This should be done once per workspace per run and cached in memory (not persisted).

---

## Config Schema Changes

### New `$defs/clockify_connection` definition

Add to `config.schema.json` under `$defs`:

```json
"clockify_connection": {
    "type": "object",
    "required": ["name", "api_key"],
    "additionalProperties": false,
    "properties": {
        "name":         { "type": "string", "description": "Display name for this connection." },
        "api_key":      { "type": "string", "description": "Clockify API key from Profile → API." },
        "workspace_id": { "type": "string", "description": "Auto-resolved from /v1/user on first run." },
        "user_id":      { "type": "string", "description": "Auto-resolved from /v1/user on first run." }
    }
}
```

### Add `clockify` to the `integrations` object

In the `integrations` property of `config.schema.json`:

```json
"clockify": {
    "type": "array",
    "items": { "$ref": "#/$defs/clockify_connection" },
    "default": []
}
```

### New `clockify_projects` per-project signal

Add to the project definition in `config.schema.json`:

```json
"clockify_projects": {
    "description": "Clockify project name globs that map to this local project.",
    "type": "array",
    "items": { "type": "string" },
    "minItems": 1
}
```

### Example config block (for `config.example.json`)

```json
"clockify": [
    {
        "name": "Clockify",
        "api_key": "YOUR_CLOCKIFY_API_KEY",
        "workspace_id": "abc123",
        "user_id": "xyz789"
    }
]
```

And in a project:

```json
"My Project": {
    "clockify_projects": ["My Project*", "Client Work"]
}
```

---

## New Files

### `src/integrations/clockify.php`

Mirrors `harvest.php` in structure. Two public functions:

#### `resolveClockifyUserInfo(array $conn, int $timeout): ?array`

Calls `GET /v1/user`, returns `['user_id' => string, 'workspace_id' => string]` or `null` on failure.

```php
function resolveClockifyUserInfo(array $conn, int $timeout = 20): ?array
{
    $apiKey = (string)($conn['api_key'] ?? '');
    if ($apiKey === '') {
        return null;
    }
    try {
        $json = httpGetJson('https://api.clockify.me/api/v1/user', [
            'X-Api-Key: ' . $apiKey,
            'Accept: application/json',
        ], $timeout);
        $userId      = (string)($json['id']                   ?? '');
        $workspaceId = (string)($json['defaultWorkspace']     ?? '');
        if ($userId === '' || $workspaceId === '') {
            return null;
        }
        return ['user_id' => $userId, 'workspace_id' => $workspaceId];
    } catch (RuntimeException) {
        return null;
    }
}
```

#### `loadClockifyTimeEntries(array $conn, DateTimeImmutable $from, DateTimeImmutable $to, int $timeout): array`

Fetches paginated time entries and normalizes them to the standard row format.

Key implementation notes:

- **Project name resolution**: Fetch all projects via `GET /v1/workspaces/{workspaceId}/projects` once per call (memoize in a local `$projectNames` map from ID → name), then use the name as `project_hint`.
- **Skip running timers**: Entries where `timeInterval.end` is null or empty are still running — skip them.
- **Pagination**: Loop while `count($page_results) === $pageSize` (max 50); cap at 100 pages.
- **Duration**: Parse ISO 8601 duration (`PT1H30M`) via `new DateInterval($duration)` and convert to seconds, or fall back to `end - start`.
- **Timestamps**: Parse `timeInterval.start` and `timeInterval.end` directly as `new DateTimeImmutable($str)`.

Output row format (identical contract to Harvest/ClickUp):

```php
[
    'source'           => 'clockify',
    'connection'       => $name,              // conn['name']
    'start'            => DateTimeImmutable,
    'end'              => DateTimeImmutable,
    'seconds'          => int,
    'project_hint'     => string,             // Clockify project name, or description if no project
    'label'            => string,             // "Project / description" or just description
    'entry_count'      => 1,
    'activity_count'   => 1,
    'discussion_count' => int,               // 1 if description is non-empty, else 0
]
```

### `src/integrations/clockify-catalog.php`

One function used by the sync/tools scripts:

#### `clockifyFetchProjectNames(array $conn, int $timeout): array`

Returns `list<string>` of all project names in the workspace. Used by any future `tools/sync-clockify-projects.php` to auto-populate `clockify_projects` globs.

```
GET /v1/workspaces/{workspaceId}/projects?page=1&pageSize=50
```

Paginate the same way as `loadClockifyTimeEntries`.

---

## Changes to Existing Files

### `src/loader-integrations.php`

**Add requires** at the top (lines 9–14):

```php
require_once __DIR__ . '/integrations/clockify.php';
require_once __DIR__ . '/integrations/clockify-catalog.php';
```

**Add Clockify loop** after the ClickUp block (after line 75), before the GitHub block:

```php
foreach (($integrations['clockify'] ?? []) as $idx => $conn) {
    if (!is_array($conn)) {
        continue;
    }
    $label = (string)($conn['name'] ?? "clockify[$idx]");
    // Auto-resolve user_id and workspace_id from /v1/user on first run.
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
    try {
        foreach (loadClockifyTimeEntries($conn, $from, $to, $httpTimeout) as $row) {
            $rows[] = $row;
        }
    } catch (RuntimeException $e) {
        warning('integrations', "[$label] " . $e->getMessage());
    }
}
```

**Add Clockify config persistence** in the `$configDirty` block (around lines 100–123), mirroring the Harvest and ClickUp patterns:

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

### `src/classifiers.php`

**Add Clockify matching** in `projectForExternal()` (after the ClickUp block, around line 244):

```php
if ($source === 'clockify' && !empty($p['clockify_projects']) && fnmatchAny($hint, $p['clockify_projects'])) {
    return $name;
}
```

**Add `clockify` to the `$unmatched` init array** in `classifyAndAggregate()` (line 281):

```php
$unmatched = ['vscode' => [], 'browser' => [], 'slack' => [], 'apps' => [], 'harvest' => [], 'clickup' => [], 'clockify' => [], 'github' => []];
```

---

## Implementation Order

1. **`config.schema.json`** — add `clockify_connection` def, update `integrations` object, add `clockify_projects` to project schema
2. **`config.example.json`** — add sample Clockify block
3. **`src/integrations/clockify.php`** — `resolveClockifyUserInfo` + `loadClockifyTimeEntries`
4. **`src/integrations/clockify-catalog.php`** — `clockifyFetchProjectNames`
5. **`src/loader-integrations.php`** — require new files, add Clockify loop, add config persistence
6. **`src/classifiers.php`** — add source matching in `projectForExternal`, add `clockify` to `$unmatched` init
7. **Manual test** — add a real Clockify API key to `config.json`, run `php activity-report.php --from=2026-05-01 --to=2026-05-18`, confirm entries appear and `clockify_projects` globs route them correctly

---

## Notable Differences from Harvest/ClickUp

| Aspect                    | Harvest                                                | ClickUp                        | Clockify                                      |
| ------------------------- | ------------------------------------------------------ | ------------------------------ | --------------------------------------------- |
| Auth header               | `Authorization: Bearer {token}` + `Harvest-Account-ID` | `Authorization: {token}`       | `X-Api-Key: {api_key}`                        |
| User ID type              | `int`                                                  | `string`                       | `string`                                      |
| Workspace field in config | `account_id`                                           | `team_id`                      | `workspace_id` (auto-resolved)                |
| Timestamps                | Date string + hours float                              | Unix ms epoch                  | ISO 8601 string                               |
| Pagination style          | `total_pages` + `next_page` in response                | `page` param, no-results stops | `page` param, fewer-than-pageSize stops       |
| Project name              | Directly in entry payload                              | Directly in entry payload      | Requires separate project ID → name lookup    |
| Running timers            | N/A (all entries are complete)                         | N/A                            | Skip entries where `timeInterval.end` is null |
