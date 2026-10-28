# Run state and run events

What a run records about itself, and the events it emits for other tools to read.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## Run state

Run state lives beside the lever file, in `droost/droost-workflow/run.json`
(a project that still has the older `.droost-workflow/` directory keeps
using it until that directory is gone):

```json
{ "v": 1, "run_id": "...", "phases": { "plan": "passed", "code": "active" } }
```

On the filesystem for the same reason the levers are: a run can be resumed,
inspected and described from a plain checkout, with no site at all.

Writes go through a temporary file and a rename, so a run that dies mid-write
leaves the previous state intact. A state file that cannot be parsed is never
deleted or replaced: it is still evidence of what a run was doing.

Two limits on that, stated because an overstated durability promise is worse
than a modest one: there is no `fsync`, so a process crash is covered but a
power cut is not; and there is no locking, so two processes doing
load-modify-save against one file will lose an update without either being
told. Nothing is ever torn. The model is one run per repo.

**A phase that failed is never quietly recorded as passed.** Advancing stamps
the phase you are leaving as passed, so advancing away from a failed or
skipped phase is refused outright — clearing a failure has to be a deliberate
act, not a side effect of moving on. Advancing backward, or advancing a run
that has already reached its terminal gate, is refused for the same reason: a
report has to be able to describe the run honestly. The fast flow's one way back is
its own transition, test or complete to code, which sets the phases between
back to pending; and a phase it leaves with follow-ups is `deferred`, a word
of its own, never `passed`.

## Run events

Beside `run.json`, the engine keeps `events.jsonl`: an append-only log of every
state change it persists, one JSON event per line, written after the save. It
is the record a relay or a dashboard reads (`droost workflow:events
[--after=<seq>]` prints it), and it works with nothing else installed:

```json
{"schema":"droost.run-event/1","event_id":"evt-4f1c2a9be0d37a61","seq":7,"run_id":"run-8c2d4e6f1a3b","work_item_id":"TICKET-12","at":"2026-09-28T15:04:11+00:00","type":"phase.attempted","payload":{"phase":"code","attempt":1,"report":{"phase":"code","advance":false,"tally":{"passed":3,"failed":1},"gates":[]}}}
```

| Type | When | Payload |
|---|---|---|
| `run.started` | a run begins | `preset`, `mode`, `enforcement`, `phases`, `spec`, `base_commit` |
| `phase.began` | a phase becomes current | `phase` |
| `phase.attempted` | a phase's gates ran, pass or fail (a failed gate never ends a phase, so this is the only event that shows one) | `phase`, `attempt`, `report` (the phase report: `advance`, `tally`, every gate) |
| `phase.ended` | the run leaves a phase | `phase` |
| `question.asked` | a save leaves a question newly awaiting | `question_id`, `question` |
| `question.answered` | `answer` is saved | `question_id`, `answer` |
| `run.completed` | the last phase ended | `outcome` (the run envelope) |
| `run.reset` | `reset` archived the run | `archived_run_id` |
| `phase.returned` | the fast flow sent the run back to code | `from`, `to`, `reason` (`failed` or `moved`), `spent`, `max_loops`, `gates` |
| `follow_up.filed` | a deferred phase's failure was written up | `phase`, `gate`, `why` (`spent` or `outside`), `summary`, `title`, `id`, `source`, `path`, `at`, `note` |
| `intake.opened` | an intake opened, before any run (`run_id` is the intake's id) | `intake_id`, `request` (the human's words), `source` |
| `intake.audited` | droost-source read the source | `intake_id`, `sha` (audit.json's sha256), `summary` |
| `intake.asked` | the agent put questions to the human on a host with no AskUserQuestion (`intake ask`) | `intake_id`, `asked_at`, `questions` (each `id`, `question`, `options`, `recommended`) |
| `intake.answered` | the operator recorded an answer to an intake question (`via`: `terminal`, or `cockpit` when a host relayed it) | `intake_id`, `question`, `answer`, `via` |
| `intake.approved` | the roadmap was approved on the human's recorded yes | `intake_id`, `digest` (the four files' and each rung ticket's sha256), `rungs`, `archived`, `via`, `human` (that yes) |
| `intake.abandoned` | the operator abandoned the intake | `intake_id`, `reason`, `archived` |

Every event has `schema`, `event_id` (`evt-` and 16 hex digits, the consumer's
dedup key: delivery is at least once), `seq` (strictly increasing per log from
1, a relay's cursor and never an identity), `run_id`, `work_item_id` (the bound
ticket, or null), `at`, `type` and `payload`. `schema/run-event.v1.json` is the
JSON Schema. Version 1 is additive only: new types and payload keys may come,
a consumer ignores what it does not know, and renaming or removing one is
`droost.run-event/2`.

The log spans runs, so `reset` keeps it. An append holds an exclusive lock, so
two processes never interleave a line or reuse a `seq` (on a state directory
over NFS that lock is advisory, as `flock` is). A log that cannot be written is
reported once on stderr and never fails or blocks the run. It lives in the
state directory, which `init` keeps out of version control, as it does
`evidence.sqlite`.
