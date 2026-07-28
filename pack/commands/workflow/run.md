---
title: Droost Workflow — run
purpose: Start a workflow run, or advance the one already in progress, through the phases configured in droost.workflow.yml.
---

Start a run, or advance the one in progress.

## What this does

Reads `droost.workflow.yml` for the levers, reads `.droost-workflow/run.json`
for where the run has got to, and works the next phase using the matching
`workflow-*` skill.

- No run in progress → begin one at the first configured phase.
- A run in progress → continue from the phase it is on.
- A run awaiting an answer (pair mode) → present the pending question and
  stop. Answer it before running again.
- A run that has ended → say so. It is not restarted by running again.

## Before you start

Load the `workflow-<phase>` skill for the phase you are about to work, and
read `partials/droost-usage.md` — particularly which tools need a booted site,
since that determines what this run can honestly check.

## The rules that apply to every phase

1. **The levers are not yours to reinterpret.** Which gates run and what
   counts as passing were resolved when the run began and recorded in the run
   state. Read them; do not re-derive them.
2. **A phase that fails is failed.** Do not advance past it. Clearing a
   failure to retry is a deliberate, separate act.
3. **Report what could not be checked.** Every phase's skill has a "Without a
   site" section; it is not optional prose.

## When the run finishes

The `complete` phase presents the diff and the full gate report — including
every gate that was skipped and why. That report is the run's product just as
much as the code is.
