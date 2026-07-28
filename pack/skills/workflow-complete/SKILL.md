---
name: workflow-complete
description: Phase 5 of the Droost Workflow. Present the diff and the full gate report, honestly, before the run is allowed to call itself done.
---

# Complete

The terminal gate. A run is not finished when the work is done; it is
finished when the work has been *reported*.

## Entry gate

- Every earlier phase this run configured has passed.

## Work

**Present the gate report before you say anything is done.** Not a summary
of it — the report: every gate the run was configured for, and what happened
to each one. There are four outcomes and they are not interchangeable:

| Outcome | What it means |
|---|---|
| passed | the gate ran and the artefact satisfied it |
| failed | the gate ran and the artefact did not |
| skipped, no site | the gate could not run — **this is not a pass** |
| tool missing | the gate was enabled but its tool was not installed |

Then present the diff — what changed, file by file — and the realized plan
against the original acceptance criteria, naming any criterion that was not
met.

Only then, if the repo wants it, commit.

The temptation at this phase is to round up: to describe a run with three
skipped gates as "all checks passed", because nothing failed. Resist it.
Nothing failed and three things were never checked are different sentences,
and only one of them is true.

## Exit gate

The report has been presented in full, including skips and their reasons. The
run is recorded as complete.

## Without a site

This phase needs no site tools — it presents what the run already recorded.

That is exactly why it matters most here. A CLI run will typically carry
several `skipped, no site` results, and this is the last moment anyone will
see them. Present them as prominently as the passes. Someone reading this
report is deciding whether to trust the work, and they can only do that if
the report tells them what was actually checked.
