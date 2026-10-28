# Tickets

Where a run's work items come from: a folder of Markdown tickets (solo mode) or a cockpit.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## Work-item project config

The optional `work_item:` block is the project config of a tracker bridge
(a project's own Jira layer and its kin). The engine never consumes it — it stays
framework-free and knows nothing of Jira — except for the one provider it
builds in, `markdown` (below, "Tickets (solo mode)"). It parses and validates the
block so a typo surfaces in review rather than at the first write, and
`workflow:status` echoes it. The shape is provider-agnostic on purpose: which
tracker and cloud, which projects and issue types are workable, how branches
are named, what the tracker calls its transitions, and a local map of every
custom field under a name the site chooses, so no module ever hardcodes
another team's field ids and a team's own layer only ever says
`developer_notes`:

```yaml
work_item:
  provider: jira
  cloud_id: <your Atlassian cloud id>
  projects: [PROJ]
  eligible_types: [Story, Task, Bug, Sub-Story]
  branch: { prefixes: { feature: feature, bugfix: bugfix }, base: development }
  transitions: { in_progress: 21, in_review: 121, done: 31 }   # for a /transition-style command; the engine never fires them
  fields:
    developer_notes: { id: customfield_10001, format: adf }
    testing_notes:   { id: customfield_10002, format: adf }
    developer_id:    { id: customfield_10003, format: user }
  track_map: { Bug: bugfix, Story: standard, Task: standard }
  writeback: { acceptance_criteria: description, dev_notes_field: developer_notes }
  status_map: {}          # empty is the common case: SCM events move the ticket, not droost
  publish: { target: confluence, space: DOCS, parent: "<page id>" }
```

A writeback target that names a `fields` entry resolves to its id. What a
ticket MUST carry, and what goes into each field, is the bridge's or the
team's business, never this block's — every write it describes stays gated.

## Tickets (solo mode)

With droost alone, tickets are markdown files in the repo,
read through a `WorkItemSourceInterface`. `work_item.provider: markdown` turns it on;
any other provider keeps the block's metadata-only meaning:

```yaml
work_item:
  provider: markdown
  markdown: { dir: droost/tickets, prefix: TICKET }   # both optional; these are the defaults
```

A ticket is `<dir>/open/<PREFIX>-<n>-<slug>.md`, or under `closed/` once it is
`done`, with a YAML frontmatter holding at least `title`, `status` and
`ticket_number`, and its sections after it. The file's name is its identity:
its id is `<PREFIX>-<n>` and its number is `n`, and a `ticket_number` that says
otherwise is kept as an extra key. Two older names read too: a split ticket,
`<PREFIX>-<n><letter>-<slug>.md` (id `<PREFIX>-<n><letter>`, so a number can
repeat), and a numberless `<PREFIX>-<slug>.md` (id the name, number null). A
file named for the prefix in neither shape is refused by name:

```markdown
---
title: TICKET-12-camp-news-by-recipe
status: ready
ticket_number: 12
type: feature
created: 2026-09-28
---

# TICKET-12-camp-news-by-recipe

## Summary
```

The states are `backlog`, `ready`, `in_progress`, `review` and `done`, machine
names so a Drupal Workflow can use the same ids. The legacy `open` and
`closed` read as `ready` and `done`, and a write always uses the new names.
Every other frontmatter key is kept, and a move rewrites the `status:` line and
nothing else, through a temporary file and a rename that keep the file's mode;
a symlinked ticket or directory is refused.

- `run --ticket=<id>` binds a run as it begins: `status` shows `run.work_item`,
  the `work_item` declaration is recorded as `declare-changes --work-item`
  records one, and the ticket moves to `in_progress`. When the run completes
  it moves to `review`, never to `done`, which is only ever a person's move. A
  move the source refuses is recorded as a note and never fails the run.
- `ticket list [--status=<state>]`, `ticket show <id>` and `ticket new
  --title="…" [--type=<type>]` are anyone's; `new` files into `backlog`,
  numbered one past the highest number any file's name carries, across
  `open/` and `closed/`.
- `ticket move <id> <state>` is the operator's (see [The guard](guard.md)).

## Tickets (cockpit mode)

`work_item.provider: droost_cockpit` reads tickets from a cockpit, a web
service that owns a team's queue, and relays each run's events to it. The lever
file names two environment variables and never holds either value, since the
file is committed:

```yaml
work_item:
  provider: droost_cockpit
  cockpit: { url_env: DRUPLIT_MAILBOX_URL, token_env: DRUPLIT_SEAT_TOKEN }   # path: /work-items/v1 is the default
```

`url_env` holds the cockpit's origin, and `path` is appended to it. `token_env`
holds a bearer token, which is sent as `Authorization: Bearer` and never
written, printed or put in an error. `docs/cockpit-provider-api.md` is the API
a cockpit implements, and `tests/fixtures/cockpit-stub.php` is that API as a
`php -S` router, held to the document by a test.

- `run --ticket=<id>` fetches the ticket as the run begins and caches it in
  the state directory as `work-item-<id>.json`, so a restart with the cockpit
  down still binds it. A ticket the cockpit has never answered for is refused.
- `ticket list`, `ticket show` and `ticket new` ask the cockpit. `ticket
  move` exits 2 with "move tickets in the cockpit": its queue owns ticket
  state, and it reads a run's progress from the run's events.
- Every event the log gains is posted as it is written, and once more at the
  end of every command, from the cursor in `relay-cursor`, in batches of at
  most 100 events and 256 KiB. Delivery is at least once, and the cockpit
  dedups by `event_id`. The first failure in a process is reported once on
  stderr and silences the relay for the rest of that process, so a cockpit
  that is down costs a run at most one 5-second timeout and never fails it.
  The events wait in the log.
- `relay` sends what waits and exits 0 when nothing does, or 1 while events
  still wait. `status` shows `relay: {cursor, pending, last_error}`.
- Requests go over http or https only, with redirects refused rather than
  followed, since a redirect would carry the token to another host.
