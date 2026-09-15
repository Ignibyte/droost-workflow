---
name: workflow-status
description: Report which levers this repo resolves to and where the recorded run has got to. Read-only. Claude Code also offers it as /droost:workflow:status.
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
present, so armed-and-broken is visible before a run hits it. Two more blocks
say what counts: `baseline` — whether an adoption baseline exists, whether the
lever honours it, when and at which commit it was written, and how many
findings each gate inherits — and `levers.contributed` — the gates enabled
modules declared (`module:<id>`), each with its provider, phases, default
mode and the sentence saying what its verdict means. A repo with no
lever file resolves to `max`, the top of the dial (tests required to exist
included), because a repo that has said nothing has not opted out of anything.

This is useful on its own: it is how someone checks what their configuration
actually resolves to before committing to a run.

**2. The run — read from `droost/droost-workflow/run.json`.** Which phases are
done, which is current, each phase's recorded gate report, the retry
counters against their bound, whether the run is awaiting an answer, the
seeker record (armed, and the latest parsed inspection), the declared
browser tier, the base commit and baseline hash the run froze, and the
`enforcement` block — what the run requested and what it amounts to on the
declared host: `advisory` where the host runs no pre-tool hooks, because the
write wall and the phase guard are hooks and cannot fire there.
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
