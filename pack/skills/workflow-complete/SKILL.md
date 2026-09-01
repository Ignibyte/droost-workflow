---
name: workflow-complete
description: Phase 4 of the Droost Workflow. Capture what was built while the reasons are still in reach, then present the diff and the full gate report, honestly, before the run is allowed to call itself done.
---

# Complete

The terminal phase, in two halves that used to be two phases (0.4 folded
document in): first capture what was built, then present it. A run is not
finished when the work is done; it is finished when the work has been
*recorded and reported* — and doing both in one phase is what keeps the
record and the report from drifting apart.

## Entry gate

- Every earlier phase this run configured has passed.
- The seeker checkpoint holds here too: a clean inspection must be recorded,
  and if anything was edited since the last one, re-dispatch the
  `workflow-seeker` before completing rather than riding a stale clean.

## Work

### First half: capture

Write down what was built, for the person who arrives after the run — often
a later run of this same pipeline, with none of this context. **The capture
lives in the spec itself, as a `## Realized` section appended to the run's
spec file — the engine refuses to gate the complete phase until it exists.**
The spec is the run's one living document: criteria at the top, inspection
ledgers as they happened, and what was actually built at the end, so the
whole story reads in one file. (Earlier packs wrote a sibling
`realized-<slug>.md`; that form is still honoured, and the section is the
documented one.) Three things, in descending order of how fast they decay:

1. **Why.** The decisions not visible in the diff — what was considered and
   rejected, what constraint forced the shape. Nobody can reconstruct this
   later, so it is written first.
2. **What.** The realized plan: what actually got built, and where it
   differs from the spec. It will differ. A document that hides the
   difference teaches the next reader to trust the spec over the code.
3. **How to use it.** Module READMEs and a change summary, aimed at someone
   who was not here.

Tools that help — every one needs a booted site:

- `droost_search` and `droost_guidelines` — find how this project already
  documents things, and match it rather than inventing a house style. Note
  that `droost_search` returns nothing at all on a site whose index was
  never built (`drush droost:search:index`), and an empty result reads
  exactly like "this project documents nothing" — check before concluding.
- `droost_wiki` — **read-only**: list and read the site's knowledge pages;
  `kind: status` reports which are stale; `kind: factsheet` pulls the
  generation packet you write FROM.
- `droost_wiki_write` — the one MCP tool that writes the wiki. You supply
  the body; Droost composes the provenance and rolls the write back unless
  the page verifies fresh. Gated behind `droost.settings.allow_scaffold`.
  **Follow the `documenting-changes` skill.** (`drush droost:wiki:generate`
  still exists for batch regeneration; it needs an AI provider configured,
  which this path does not.)

Do the capture BEFORE this phase's `run`: the wiki gate below checks what
this half just wrote, and the re-run covers these writes like any others.

In a **light** run the capture is ALSO presented in chat — but the
`## Realized` section still lands in the spec file first (the engine's
requirement does not thin with the preset; depth does). Same three
questions, lighter medium.

### Second half: present

This phase's `run` re-executes the FULL enabled gate set — the terminal
safety net. A regression introduced since the test phase is caught now
rather than shipped, and `wiki_fresh` runs here for the first time — the
only phase at which it CAN be true, because this phase just wrote the
documentation it checks.

**Present the gate report before you say anything is done.** Not a summary
of it — the report: every gate the run was configured for, and what happened
to each one. Four outcomes, none interchangeable:

| Outcome | What it means |
|---|---|
| passed | the gate ran and the artefact satisfied it |
| failed | the gate ran and the artefact did not |
| skipped, no site | the gate could not run — **this is not a pass** |
| tool missing | the gate was enabled but its tool was not installed |

Then present, in order:

- the diff — what changed, file by file;
- the realized plan against the original acceptance criteria, naming any
  criterion that was not met;
- the seeker ledger — findings and how each was resolved or carried, and
  the observations routed to follow-up;
- which browser tier verified the work (`playwright-mcp`, `native`, or
  `none` — in which case the rendered check was the floor, and the report
  says so).

Only then, if the repo wants it, commit.

The temptation at this phase is to round up: to describe a run with three
skipped gates as "all checks passed", because nothing failed. Resist it.
Nothing failed and three things were never checked are different sentences,
and only one of them is true.

## Exit gate

The capture exists (or was presented, in a light run), the report has been
presented in full — skips, ledger and browser tier included — and the run is
recorded as complete. Tell the operator the finished record persists until
`drush droost:workflow:reset` archives it — the next run starts after that,
and until a run is active, custom-code edits are walled again
(`require_run`).

## Without a site

The capture half loses its site tools: `droost_search`, `droost_guidelines`
and `droost_wiki` all need a booted site. Write the documentation into the
repo — module READMEs, a change summary in the run's own artefacts — and say
plainly that the wiki could not be read or updated. Do not report it fresh,
and do not report it stale either: you did not look. The `wiki_fresh` gate
will record its own honest answer through drush, or tool-missing without it.

The presentation half needs no site — which is exactly why it matters most
here. A CLI run typically carries several `skipped, no site` results, and
this is the last moment anyone will see them. Present them as prominently as
the passes: the reader is deciding whether to trust the work, and they can
only do that if the report says what was actually checked.
