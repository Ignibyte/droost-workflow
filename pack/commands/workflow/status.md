---
title: Droost Workflow — status
purpose: Report which levers this repo resolves to and, within a session, how far the run has got. Read-only.
---

Report where things stand. This command changes nothing.

## What you can actually report

**In this release there is no run-state file.** Nothing writes
`.droost-workflow/run.json` yet — the engine that records it ships later — so
there is no persisted history to read, and a status check from a fresh
session has nothing to recover.

That leaves two honest things to report, and one to refuse.

**1. What a run here would be held to.** Read `droost.workflow.yml` and
report the resolved result: the mode, the phases, and every gate with its
switch and thresholds. Say whether the file exists or whether these are the
built-in defaults — those are different situations, and a reader should not
have to guess which. A repo with no lever file gets `factory`, the strictest
set, because a repo that has said nothing has not opted out of anything.

This is useful on its own: it is how someone checks what their configuration
actually resolves to before committing to a run.

**2. Where the run in THIS session has got to**, if one is in progress —
which phases are done, which is current, and what each gate reported when you
ran it. You know this because you did it, not because it was recorded.

**3. What not to do.** Do not report per-gate results for a run you did not
perform in this session. There is no store to read them from, and
reconstructing them from memory produces a verification report for checks
that were never run. If you were not there, say the run is not recoverable
and why.

## Report honestly

The temptation is to summarise as "on track". Whether a run is on track
depends entirely on which gates ran and which were skipped, so give the
reader the list rather than your conclusion — and be equally plain when the
answer is that nothing was recorded.
