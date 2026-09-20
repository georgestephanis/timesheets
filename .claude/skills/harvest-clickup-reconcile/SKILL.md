---
name: harvest-clickup-reconcile
description: Audit Harvest time entries against the ClickUp tasks their notes reference, to catch time billed to the wrong client. Use when asked to reconcile, audit, or sanity-check Harvest time, to find conflated or misbilled clients, before invoicing or closing out a month, or when a client's hours look wrong.
---

# Harvest ↔ ClickUp client reconciliation

Catches time billed to the wrong client by comparing each Harvest entry's client
against the client that owns the ClickUp task the entry references.

## How the match works

Harvest notes in this account lead with the ClickUp task id:

```
#86bbgu5cn - Please rebuild the logos module
```

The tool extracts that id, fetches the task, and reads its container hierarchy.
**ClickUp `space` is empty in this workspace** — the real hierarchy is
`folder → list`. Containers map to a client through `config.json`:

```jsonc
"projects": {
  "Whole Grains": {
    "clickup_tasks":  ["*Whole Grains Council*"],  // globs matched against list/folder/space
    "harvest_client": "Whole Grains Council"       // expected Harvest client
  }
}
```

### Match most-specific container first

Matching runs **list → folder → space** and stops at the first hit. This ordering
is load-bearing, not cosmetic:

> Whole Grains Council is a _program of_ Oldways. Its tasks live in ClickUp
> folder `Oldways`, list `Whole Grains Council`. Matching folder-first
> misattributes all 78 of those tasks to Oldways and produces a ~78-entry false
> positive run. Always match the innermost container first.

## Running it

The tool ships inside this skill at `tools/reconcile-harvest-clickup.php`.
It finds the timesheets repo (the one holding `config.json` and
`src/integrations/shared.php`) by walking up from its own location, so run it
from anywhere inside that repo — or point `TIMESHEETS_ROOT` at the repo if the
skill is installed outside it.

```bash
SKILL=.claude/skills/harvest-clickup-reconcile   # or the installed skill path

php "$SKILL/tools/reconcile-harvest-clickup.php"                      # year to date, Markdown
php "$SKILL/tools/reconcile-harvest-clickup.php" --days=30            # last 30 days
php "$SKILL/tools/reconcile-harvest-clickup.php" --from=2026-01-01 --to=2026-03-31
php "$SKILL/tools/reconcile-harvest-clickup.php" --format=tsv > audit.tsv   # for a spreadsheet
php "$SKILL/tools/reconcile-harvest-clickup.php" --format=html \
    > reports/harvest-clickup-mismatches.html                      # clickable worklist
php "$SKILL/tools/reconcile-harvest-clickup.php" --format=json --all        # full detail
php "$SKILL/tools/reconcile-harvest-clickup.php" --refresh            # bypass the task cache
```

### The HTML worklist

`--format=html` writes a standalone remediation worklist: findings grouped by
date, each date heading deep-linking to the Harvest day view
(`<base_uri>/time/day/YYYY/MM/DD`, resolved live from `GET /v2/company`) and each
row linking to its ClickUp task. Rows carry a checkbox whose state persists in
`localStorage`, so a long list can be worked through across sittings.

Write it to `reports/` — that directory is gitignored, and the report contains
client billing detail that should not be committed.

Exit codes: `0` clean · `2` cross-client mismatches or suspect unmapped entries
found · `1` error. Suitable for gating a cron job or CI check.

ClickUp task placement is cached in `tmp/clickup-task-cache.json` (gitignored).
Tasks rarely move, so re-runs are near-instant; use `--refresh` after
reorganising ClickUp.

## Reading the output

| Bucket                                         | Meaning                                                                                     | Action                                   |
| ---------------------------------------------- | ------------------------------------------------------------------------------------------- | ---------------------------------------- |
| **Cross-client mismatch**                      | Task's client ≠ billed client. Genuine conflation.                                          | Fix the Harvest entry.                   |
| **Unmapped — container suggests other client** | No config rule, but the folder name (e.g. `Synack`) clearly differs from the billed client. | Triage individually; then add a rule.    |
| **Unmapped — no config rule**                  | Container has no `clickup_tasks` glob or no `harvest_client`. Not verifiable.               | Add the mapping to `config.json`.        |
| **No ClickUp id in notes**                     | Note has no `#id`. Not verifiable by this tool at all.                                      | Only fixable by changing logging habits. |
| **Within client family**                       | Differs, but both names are declared equivalent.                                            | Informational.                           |

Always report the unverifiable buckets alongside the mismatches. A clean
mismatch list means nothing if a third of the hours carry no ClickUp reference —
say what the audit could and could not see.

## Optional: client families

Related entities that bill as one relationship can be declared so that
differences between them report as informational rather than as conflation:

```jsonc
"reconcile": {
  "client_families": [
    ["Oldways", "Whole Grains Council"]
  ]
}
```

Leave this unset if the two are separate Harvest clients with separate budgets —
in that case a swap between them _is_ a real billing error and should stay loud.
This is a billing judgement call; confirm with the user before adding it.

## Extending

- `rhcExtractClickUpId()` — accepts `#id`, `CU-id`, or a task URL. It deliberately
  refuses bare alphanumeric words; a loose pattern here matches ordinary note
  prose and fabricates task ids that then fail lookup.
- `rhcUnmappedLooksWrong()` — heuristic for unmapped containers. Skips generic
  names (`Support`, `Internal Tasks`, `hidden`) and only fires when neither name
  contains the other, so `PayItGov` vs `PayIt` is not flagged.
- `rhcSameFamily()` — reads `reconcile.client_families`.

## Caveats

- Only verifies **which client** was billed, not whether the hours or the task
  itself were right.
- A wrong-but-plausible `#id` (typo landing on a real task) is invisible.
- Entries whose notes lack an id can never be checked here; cross-reference
  ActivityWatch or git history instead.
