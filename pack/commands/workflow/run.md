---
title: Droost Workflow — run
purpose: Start a workflow run, or advance the one already in progress, through the phases configured in droost.workflow.yml.
---

Start a run, or advance the one in progress.

## What this does

Reads `droost.workflow.yml` for the levers and works the phases in order,
using the matching `workflow-*` skill for each.

## What does not exist yet — read this before looking for it

**Nothing writes `.droost-workflow/run.json` in this release.** The engine
that records run state is the gate runner, which ships later; today the file
has no producer, so it will not be there and you should not go looking for
it or invent one.

Concretely, until it lands:

- The run is held to `droost.workflow.yml` **as you read it at the start**.
  Read it once, at the beginning, and work from that. If someone edits it
  mid-run you will not see the change, which is the correct behaviour.
- There is no resume. A run is one session's work from `plan` to `complete`.
- Nothing counts your gate retries. `max_gate_retries` is still the bound —
  count your own attempts against it and stop when you reach it.
- Pair mode's pause-and-ask transport is not built either. If the lever file
  says `mode: pair`, ask the human directly at each phase gate.

None of that makes the pipeline unusable; it makes it a single-session
pipeline. What it must not do is make you improvise a state file or report
progress from one that does not exist.

## Before you start

Load the `workflow-<phase>` skill for the phase you are about to work, and
read `.claude/partials/droost-usage.md` — particularly the part about which
tools need a booted site, since that determines what this run can honestly
check.

## The rules that apply to every phase

1. **The levers are not yours to reinterpret.** Which gates run and what
   counts as passing come from `droost.workflow.yml` as read at the start of
   the run. Apply them; do not re-derive them, and do not soften one because
   it is inconvenient.
2. **A phase that fails is failed.** Do not advance past it. Clearing a
   failure to retry is a deliberate, separate act.
3. **Report what could not be checked.** Every phase's skill has a "Without a
   site" section; it is not optional prose.

## When the run finishes

The `complete` phase presents the diff and the full gate report — including
every gate that was skipped and why. That report is the run's product just as
much as the code is.
