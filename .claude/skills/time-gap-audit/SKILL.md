---
name: time-gap-audit
description: Find work that was done but never logged to Harvest, by aggregating ActivityWatch focus, git and GitHub history, Claude Code session logs, Slack and ClickUp over a date range. Use when asked to find missing or unlogged time, to check activity against Harvest, to reconstruct what was worked on over a period, or before invoicing or closing out a month.
---

# Finding unlogged time

Aggregate every local signal over a date range, compare against what Harvest
actually holds, and produce a per-day, per-client list of work with no entry.

For the mechanics of generating the activity JSON and the `jq` recipes that read
it, follow `BOL-MANUAL-GENERATION.md` at the repo root. This skill covers what
that runbook does not: **how much to trust each signal, and how to turn a gap
into a defensible entry.**

Writing the entries is `harvest-log-entry`. Checking existing entries are billed
to the right client is `harvest-clickup-reconcile`.

## Gather every signal, not just ActivityWatch

Focus time alone cannot tell you what was done. Collect in parallel:

| Signal          | Where                                                   | What it gives you                                       |
| --------------- | ------------------------------------------------------- | ------------------------------------------------------- |
| Activity JSON   | `php activity-report.php --from X --to Y --format json` | per-day, per-project focus seconds                      |
| Harvest         | `list_time_entries`, or `tools/bol/harvest-pull.php`    | what is already logged                                  |
| Local git       | `git log --all --since --until --author` across repos   | commit times + subjects                                 |
| GitHub          | `gh api search/commits`, `gh api search/issues`         | commits and PRs the local scan misses                   |
| Claude sessions | `~/.claude/projects/<slug>/*.jsonl`                     | **the user's own prompts — the best evidence there is** |
| ClickUp         | `clickup_filter_tasks` by folder                        | tasks touched in the window                             |

Local git alone is not enough. A GitHub commit search routinely surfaces repos
that were not cloned locally, or whose work happened on another machine.

## The evidence hierarchy

Rank every candidate line by what backs it. This determines whether it can be
billed at all, not merely how confident the summary is.

1. **Claude session prompts** — the user describing the task in their own words,
   with timestamps. Strongest available evidence; it states intent, not just activity.
2. **Commits and merged PRs** — what shipped, when, and usually why.
3. **Slack channel focus** — shows a client was in play; not what was done.
4. **Window focus alone** — shows _something_ happened. **Never bill from this
   by itself.**

> Focus time is a floor for work that has other corroboration, and worthless
> without it. In a real audit, 0.92h of "Onapsis" focus across two days turned
> out to be Bambu Studio, x.com and Facebook — misfiled because an `onapsis-wp`
> editor window was the last active project context and the 15-minute
> `project_gap_window_seconds` smoothing pulled the surrounding idle time in
> with it. Always print the per-signal detail before proposing a line:
>
> ```bash
> jq -r --arg d "$DAY" '.days[$d]["<Project>"].detail | to_entries[]
>   | select(.value|type=="object") | .key as $k | .value | to_entries[]
>   | select(.value>60) | "  \((.value/36|round)/100)h  [\($k)] \(.key)"' range.json | sort -rn
> ```

The leak runs both ways: client work also lands in the `null`/unclassified
bucket. Treat a large unclassified figure on a gap day as evidence that the
observed total understates the day, not as noise to ignore.

## Reading Claude session logs

Session logs are the highest-value and least obvious source.

```bash
ls -d ~/.claude/projects/*            # one slug per working directory
```

Extract the user's prompts, skipping system reminders and tool noise:

```python
for ln in open(path, errors="replace"):
    o = json.loads(ln)
    if o.get("type") != "user": continue
    c = o.get("message", {}).get("content")
    if isinstance(c, list):
        c = " ".join(x.get("text","") for x in c
                     if isinstance(x, dict) and x.get("type") == "text")
    if not isinstance(c, str) or c.startswith(("<system-reminder", "Caveat:", "<command-")):
        continue
    print(o["timestamp"][:16], " ".join(c.split())[:220])
```

Also mine them for tool calls — `clickup_update_task`, `clickup_create_comment`
— to recover exactly which tickets were touched and how.

> **Session timestamps are UTC.** An evening session in US Eastern crosses
> midnight UTC and reads as the following day. A Yoast remediation that ran
> Monday 17:50–20:10 EDT appears in the log as `2026-09-14T21:50` through
> `2026-09-15T00:10` — which reads as two days' work across Monday _and_
> Tuesday. Convert before attributing a day, or you will invent a gap that
> doesn't exist and miss the one that does.

A session's wall-clock span is not work time. A 292-minute span often means an
idle tab. Cross-check against that day's focus figure and bill the smaller
number unless commits say otherwise.

## Finding the ClickUp task behind a gap

ClickUp's keyword search is weak — it misses tasks whose wording differs from
yours, which is most of them. Work outward:

1. **The Harvest note convention** — existing entries lead with `#<task-id>`.
2. **PR bodies** — `gh pr view N --repo X --json body`. Many carry
   `## [ClickUp #86...](...)`. Check before concluding a ticket doesn't exist.
3. **Claude session logs** — grep for `86b[a-z0-9]{6,}` and `app.clickup.com/t/`.
4. **`clickup_filter_tasks` by folder**, not by assignee.

> **Never scan by current assignee.** Work is routinely handed off the moment
> it's done — nine tickets developed on a Tuesday were reassigned to a QA
> teammate on the Wednesday, so an assignee-filtered scan showed none of them.
> Filter by `folder_ids` with `include_closed: true` and read the whole list.

A task id found in a long-running session is not necessarily _that day's_ work —
sessions span weeks and carry old context. Confirm against the day's prompts.

## Turning gaps into proposed entries

Produce a table per day: client, Harvest project, hours, ClickUp task,
evidence, and **evidence strength**. Mark plainly which lines rest on focus
alone so they can be dropped rather than guessed at.

State hours as a recommendation, not a decision. Give the observed floor and the
session span, and let the user set the number — these are billable hours and the
call is theirs.

Batching several small items under one grouped ticket is fine and usually
preferable to nickel-and-diming. Say what the batch covers in the note.

## Caveats

- **Harvest returns the whole team.** `list_time_entries` is not scoped to you —
  filter by your `user_id` or every teammate's entries inflate the totals.
  Check `scope_limited` in the response too.
- **A project's `starts_on` does not block backdated entries.** Harvest accepts
  time before a project's start date without complaint; some reports filter it out.
- **`aw-watcher-input` is frequently not running**, which zeroes
  `active_seconds`/`activity_ratio`. Use focus seconds, not active seconds.
- **`.days` can include dates outside the requested range.** Filter explicitly.
- Check for runaway timers in both Harvest and ClickUp before trusting any total.
