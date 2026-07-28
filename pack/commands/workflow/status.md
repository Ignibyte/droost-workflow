---
title: Droost Workflow — status
purpose: Report where the current run stands, which levers it is held to, and what it has and has not checked. Read-only.
---

Report where the run stands. This command changes nothing.

## What to report

Read `.droost-workflow/run.json` and `droost.workflow.yml`, then say:

1. **Where the run is** — the current phase, and the status of every phase
   the run configured. A phase this run dropped is absent, not skipped;
   report it as not part of this run.
2. **What it is held to** — the resolved gate set recorded when the run
   began, the preset it came from, and whether that came from a committed
   `droost.workflow.yml` or the built-in defaults. Those are different
   situations and a reader should not have to guess which one this is.
3. **What has been checked so far** — per gate: passed, failed, skipped for
   lack of a site, or tool missing. Report skips as prominently as passes.
4. **Whether anything is waiting** — in pair mode a run can be paused at a
   gate with a question outstanding. Show the question.

## If there is no run

Say there is no run in progress, and report which levers a new one would
start under. That is useful on its own: it is how someone checks what
`droost.workflow.yml` actually resolves to before committing to a run.

## Report honestly

The temptation is to summarise a run with skipped gates as "on track".
Whether it is on track depends entirely on which gates were skipped, so give
the reader the list rather than your conclusion.
