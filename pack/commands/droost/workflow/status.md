---
title: Droost Workflow — status
purpose: Report which levers this repo resolves to and where the recorded run has got to. Read-only.
---

Report where things stand. This command changes nothing.

## Run `status`, then report what it returns

Run the `status` verb on whichever surface you have —
`vendor/bin/droost-workflow status`, `drush droost:workflow:status`, or the
MCP status tool — and report its two halves rather than reconstructing
either:

**1. The levers — what a run here is held to.** The provenance (a committed
`droost.workflow.yml`, or the built-in defaults — different situations a
reader must not have to guess between), the mode, the phases, every gate with
its switch and thresholds, `phase_gates` — which gates are due at which
phase, so "why did plan run nothing" is answerable from status alone — and
the `toolchain` rows: per gate, the binary it would run and whether it is
present, so armed-and-broken is visible before a run hits it. A repo with no
lever file resolves to `factory`, the strictest set, because a repo that has
said nothing has not opted out of anything.

This is useful on its own: it is how someone checks what their configuration
actually resolves to before committing to a run.

**2. The run — read from `.droost-workflow/run.json`.** Which phases are
done, which is current, each phase's recorded gate report, the retry
counters against their bound, whether the run is awaiting an answer, the
seeker record (armed, and the latest parsed inspection), and the declared
browser tier.
This survives sessions and surfaces: a run started against a live site is
readable from a plain checkout, and a fresh session recovers a run's
position by running `status`, never from memory.

## Report honestly

Never report from memory what the engine can tell you — run `status`. The
temptation is to summarise as "on track"; whether a run is on track depends
entirely on which gates ran and which were skipped, so give the reader the
list rather than your conclusion. Per-gate results come from the recorded
reports, not from reconstruction — and when there is no run file, the honest
report is the levers alone, plus the fact that no run is recorded.
