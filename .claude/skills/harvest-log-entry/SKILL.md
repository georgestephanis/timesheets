---
name: harvest-log-entry
description: Write time entries to Harvest with the correct note format — the salient ClickUp task id plus a written summary of what was actually done — and create or find the ClickUp task first when none exists. Use when asked to log time, add Harvest entries, record hours, or bill work that has just been identified.
---

# Logging time to Harvest

Every entry names the ClickUp task it belongs to **and** explains what was done.
Finding the unlogged work in the first place is `time-gap-audit`.

## The note format

```
#<task-id> - <ClickUp task name> - <summary of what was actually done>
```

```
#86bbqepdm - Update Wordpress - The site wasn't recognizing its Yoast SEO
Premium license, so traced via Terminus on production which account the license
sits under, then updated Yoast SEO Premium to 28.4 and added a repeatable Yoast
update skill. Also cleaned up docroot hygiene alongside it: removed stray
wp-native-php-sessions copies and protected non-public docroot paths
(see also #86bbj4cpy).
```

The leading `#<task-id>` is load-bearing — `harvest-clickup-reconcile` parses it
to verify the entry is billed to the client that owns the task. An entry without
it cannot be audited.

## Always write the summary

**The task name is not a summary.** Repeating the ticket title tells a reader
nothing they couldn't get from the ticket, and months later neither you nor the
client can reconstruct what the hours bought.

A good summary states:

- **what was wrong or asked for** — the problem, in plain terms
- **what was actually done** — the change, not the activity
- **anything found along the way** — scope discovered, related tickets, surprises
- **concrete specifics** — file names, counts, versions, environments, PR numbers

> Specifics are what make an entry defensible under review. "Fixed the stamp
> display" invites a question; "the `(18g)` shown on every product was a
> hardcoded dummy value left from launch; it now reads the real grams field,
> and Drupal's HTML was stripped from ingredient lists that were printing as
> visible markup on 11,087 of 19,412 products" answers it.

Write prose, not a bullet list — Harvest renders notes as plain text.

Additional conventions that have earned their place:

- **Name every ticket a batched entry covers.** If one deploy shipped five
  tickets, list all five ids in the note.
- **Say when something reached production**, and on which date — `DEPLOYED TO
PRODUCTION 2026-09-17`. Deploy dates are the first thing anyone reconstructing
  a release asks for.
- **Be explicit when time is batched onto a ticket it isn't strictly about.**
  Billing deploy-pipeline work to a bug ticket that deploy happened to ship is
  acceptable if the note says so; silently is not.

## Find the task before creating one

Search before creating, or the workspace fills with duplicates. Work through
`time-gap-audit`'s "Finding the ClickUp task behind a gap" — Harvest note
convention, PR bodies, session logs, then `clickup_filter_tasks` by folder with
`include_closed: true`, **never by current assignee**.

Check for an established naming series while you're there. A closed
`WordPress 7.0.3 Security Update` means the next one should be
`WordPress 7.1.1 Security Update` — a differently-worded title breaks the series
and is why the search missed it in the first place.

## Creating the task when none exists

```
clickup_create_task → clickup_update_task → verify
```

> **`create_task` silently ignores `status`.** It returns `success: true` and
> the task lands in the list's default (`to do`). Always follow with
> `clickup_update_task` to set the status you asked for.

> **`get_task` often omits `assignees` and `custom_fields`**, even with
> `include: ["custom_fields"]` — so a get is not proof the write landed. Verify
> with `clickup_search`, whose result rows do carry `status` and `assignees`.

**`Client` is a required dropdown** on Delivery-space lists. Read the real ids
with `clickup_get_custom_fields --list_id <id>` rather than reusing remembered
UUIDs. Set `Role`, `CMS Platform`, `Task Source` and `Slack Convo Link` where known.

Give the task a description that carries the evidence: the request in the
requester's own words if you have it, the PR, the commits, the date, and a line
noting it was created retroactively so the time has somewhere to report against.

### Assignee versus cc

Adding someone as an **assignee** puts the task in their work queue. To merely
notify them — the "cc" case — post a comment mentioning them instead:

```
cc [@Name](#user_mention#<numeric-user-id>) — context for why they're being looped in.
```

The user id must be numeric, from `clickup_resolve_assignees`.

## Writing the entry

```
log_time → project_id, task_id, spent_at, hours, notes
```

Confirm the Harvest task exists on that project before writing — task ids are
per-project assignments, not global:

```
list_project_assignments --project_id <id> --assignment_type tasks
```

`Programming` (`5509266`) is the usual default for development and advisory
work; `Meetings` and `Project Management` exist on most projects too.

Verify after writing — `list_time_entries` for the day, or `get_time_report`
for the range.

## Caveats

- **Confirm before writing.** These are billable hours. Propose the entries,
  including which ticket each maps to, and let the user approve the split.
  Don't infer a logging instruction from a request to investigate.
- **Report what was actually written**, including anything that didn't take —
  a status that silently reverted, a field that didn't apply.
- Entries land `unsubmitted` and un-invoiced; submitting for approval is separate.
- Harvest accepts hours on a project before its `starts_on` date without warning.
