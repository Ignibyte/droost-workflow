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
- **The seeker checkpoint does NOT hold here.** It is a Code-phase
  mechanism (`ModeEngine`: `$phase === Phase::Code`), and this line used to
  say it "holds here too", which is false and bought a sixth inspection on a
  measured run whose code phase had already filed five. Do not dispatch one
  out of habit.
- **The real gap, stated instead of papered over:** the test phase writes
  source — the browser spec, and usually fixes to the suite — and no seeker
  ever reads it, because the only checkpoint fired before that code existed.
  If the test phase wrote something substantial, one inspection scoped to
  *that* diff is worth its cost. That is a judgement about what changed, not
  a gate, and `seekers.rounds` does not apply to it.

## Work

### Before capture: what the export carries

If this run exported site configuration, read what moved and say so in the
report — an export is a change to every site this repo deploys to, and the
diff is the only place anybody will see it before it lands.

The write gates are NOT in that export, and used to be: `allow_*` is
per-environment operator state armed at a TTY and kept in `$settings` (see
`\Drupal\droost\GateState`), precisely so `config:export` can never sweep an
armed gate into `config/sync` and arm it on another site. If you find
`droost.settings` in an export carrying `allow_*` keys, that is a finding about
a very old site, not the normal case.

### First half: capture

Write down what was built, for the person who arrives after the run — often
a later run of this same pipeline, with none of this context. **The capture
lives in the spec itself, as a `## Realized` section appended to the run's
spec file. A missing one is recorded as a `spec`/`shape` finding rather than
refused — which means nobody will stop you shipping without it, and the next
run of this pipeline pays for that.**
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
  the page verifies fresh. Gated behind the `allow_scaffold` write gate — armed at a TTY with `drush droost:gate allow_scaffold on`, never through config.
  **Follow the `documenting-changes` skill.** (`drush droost:wiki:generate`
  still exists for batch regeneration; it needs an AI provider configured,
  which this path does not.)

Do the capture BEFORE this phase's `run`: the wiki gate below checks what
this half just wrote, and the re-run covers these writes like any others.

The capture's WEIGHT follows the run's preset — read the frozen, canonical
name from run.json:

- **`high`, `xhigh`, `max` (and `custom`)** — the full capture: the
  `## Realized` section, READMEs, and the wiki pages this change touches
  written through `droost_wiki_write`, so `wiki_fresh` has something true to
  check.
- **`medium`** — the capture is ALSO presented in chat, and the wiki is still
  written: `wiki_fresh` is on at this level.
- **`low`** — the capture is presented in chat and there is NO wiki step:
  `wiki_fresh` is off by preset, so write no wiki pages (a write nobody
  checks is a page that goes stale unnoticed) and say in the report that the
  wiki was not touched at this level — not that it is fresh.

At every level the `## Realized` section still lands in the spec file first:
the engine's requirement does not thin with the preset; depth does. Same
three questions, lighter medium.

### Second half: present

This phase's `run` re-executes the FULL enabled gate set — the terminal
safety net. A regression introduced since the test phase is caught now
rather than shipped, and `wiki_fresh` runs here for the first time — the
only phase at which it CAN be true, because this phase just wrote the
documentation it checks. (At `low` it does not run at all and the report
shows it `off` — the level's declared trade, listed like any other gate.)

**Present the gate report before you say anything is done.** Not a summary
of it — the report: every gate the run was configured for, and what happened
to each one. Four outcomes, none interchangeable:

| Outcome | What it means |
|---|---|
| passed | the gate ran and the artefact satisfied it |
| failed | the gate ran and the artefact did not |
| skipped, no site | the gate could not run — **this is not a pass** |
| tool missing | the gate was enabled but its tool was not installed |
| off | the preset (or the lever file) turned the gate off — **not a pass** either; name the level that decided it |

Then present, in order:

- the effort level the run was held to — the frozen preset name, so the
  reader knows whether "no phpunit result" means a failure or a `low` run
  that never asked for one;
- the diff — what changed, file by file;
- the realized plan against the original acceptance criteria, naming any
  criterion that was not met — and the `Verified By` column filled for every
  row, which the engine checks before this phase's gates run (an empty cell
  is a refusal, not a warning; `manual — <reason>` is printed as manual);
- the seeker ledger — findings and how each was resolved or carried, and
  the observations routed to follow-up;
- which browser tier verified the work (`playwright-mcp`, `native`, or
  `none` — in which case the rendered check was the floor, and the report
  says so).

Only then, if the repo wants it, commit.

The temptation at this phase is to round up: to describe a run with three
skipped gates as "all checks passed", because nothing failed. Resist it.
Nothing failed and three things were never checked are different sentences,
and only one of them is true. The same holds one level down: a `low` run
whose phpunit reads `off` did not pass its tests — it declared it would not
run them, and the report says exactly that.

## Exit gate

The capture exists (or was presented, at `medium`/`low`), the report has been
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
