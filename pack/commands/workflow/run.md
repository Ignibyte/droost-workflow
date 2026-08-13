---
title: Droost Workflow — run
purpose: Start a workflow run, or advance the one already in progress, through the phases configured in droost.workflow.yml.
---

Start a run, or advance the one in progress.

## What this does

Reads `droost.workflow.yml` for the levers and works the phases in order,
using the matching `workflow-*` skill for each.

## How the engine drives this

Every surface — `vendor/bin/droost-workflow run`, `drush droost:workflow:run`,
or the MCP run tool — drives one engine, and the engine writes run state to
`.droost-workflow/run.json` on every invocation. One phase per invocation, so
the loop is:

1. `status` — see the levers and where the run stands.
2. Do the current phase's work, per its `workflow-<phase>` skill.
3. Invoke `run` — the engine executes the gates due at this phase, records
   the report into run state, and advances, pauses (pair mode), or fails.
4. Repeat until `complete`.

**Resume exists.** Re-invoking `run` picks the run up exactly where run.json
says it is — a fresh session recovers a run's position by reading the engine's
record, never by remembering. A paused run re-presents its question rather
than re-running its gates.

**The levers are frozen into the run when it begins** — `resolved_gates` in
run.json. A mid-run edit to `droost.workflow.yml` does not retarget a run in
flight; the run is held to the levers it started under.

**Retries are engine-counted.** Each blocking gate spends one attempt per
failing invocation (`feedback_attempts`, measured against
`max_gate_retries`); when the budget is spent the phase is recorded failed
and `run` refuses to execute anything further. The envelope's
`retries.exhausted` tells you which failure you are looking at — retryable
(fix the cause, invoke `run` again) or terminal. Recovery from a terminal
failure is a deliberate act: remove `.droost-workflow/run.json` and begin
again. Exit codes stay simple: paused is not failed, and retryable and
terminal failures share the non-zero exit — the distinction lives in the
envelope.

**Pair mode works end to end.** The run pauses at each phase gate — the
pause is written to run state before anything is notified — and
`answer "<text>"` or `swap automated`, on any surface, resumes it.

## Which gates run when

WHEN a gate runs is the engine's phase map. WHETHER it runs, and with what
thresholds, is the lever file's business:

| phase | gates due |
|---|---|
| plan | none |
| code | phpcs, phpstan |
| test | phpunit, mutation, playwright, coverage, rendered_check |
| document | none |
| complete | the full set, re-run — the terminal safety net |

Complete re-running everything is what makes dropped phases safe: a run
configured without a test phase still meets every enabled gate once, at the
end.

## Before you start

Load the `workflow-<phase>` skill for the phase you are about to work, and
read `.claude/partials/droost-usage.md` — particularly the part about which
tools need a booted site, since that determines what this run can honestly
check.

## The rules that apply to every phase

1. **The levers are not yours to reinterpret.** Which gates run and what
   counts as passing were frozen into the run when it began. Apply them; do
   not re-derive them, and do not soften one because it is inconvenient.
2. **A phase that fails is failed.** The engine refuses to advance past it,
   and once the retry budget is spent it refuses to re-run it at all.
   Clearing a terminal failure is a deliberate act — removing the run file —
   not something that happens on the way past.
3. **Report what could not be checked.** Every phase's skill has a "Without a
   site" section; it is not optional prose.

## When the run finishes

The `complete` phase presents the diff and the full gate report — including
every gate that was skipped and why. That report is the run's product just as
much as the code is.
