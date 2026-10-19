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

**Anything you write here is held to your declaration.** The scope audit runs
at complete as it did at code and test. A README, a wiki page or any other file
this phase adds must be covered by the declaration, or re-declared with the
whole list before the phase is gated.

### Before capture: what the export carries

If this run exported site configuration, read what moved and say so in the
report — an export is a change to every site this repo deploys to, and the
diff is the only place anybody will see it before it lands.

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

What helps — droost reads files, so none of it needs the site:

- `droost search:arch` — find how this project already documents things,
  and match it rather than inventing a house style.
- `droost code:wiki` — **read-only**: list and read the wiki's pages and the
  inventory of installed modules and themes; `droost code:wiki status` says
  which pages are stale against their sources.
- **A wiki page is a markdown file in `droost/wiki/`**, written by you. A
  page droost keeps fresh carries a provenance block in its frontmatter:
  the modules it covers and the source files it describes. Write each
  source's hash as the placeholder `"xxh3:0000000000000000"`, then run
  `droost code:wiki stamp <page>`, which records the real hashes once you
  have read the sources and the page is accurate:

  ```markdown
  ---
  type: "Drupal Module"
  title: "camps"
  droost:
    spec: 1
    modules: [camps]
    sources:
      - path: web/modules/custom/camps/camps.module
        hash: "xxh3:0000000000000000"
  ---

  # camps
  ```

  A stamp asserts the prose is still accurate. When it is not, rewrite the
  page, then stamp it. droost writes no page prose itself.

Do the capture BEFORE this phase's `run`: the wiki gate below checks what
this half just wrote, and the re-run covers these writes like any others.

The capture's WEIGHT follows the run's preset — read the frozen, canonical
name from run.json:

- **`high`, `xhigh`, `max` (and `custom`)** — the full capture: the
  `## Realized` section, READMEs, and the wiki pages this change touches
  written and stamped, so `wiki_fresh` has something true to check.
- **`medium`** — the capture is ALSO presented in chat, and the wiki is still
  written: `wiki_fresh` is on at this level.
- **`low`** — the capture is presented in chat and there is NO wiki step:
  `wiki_fresh` is off by preset, so write no wiki pages (a write nobody
  checks is a page that goes stale unnoticed) and say in the report that the
  wiki was not touched at this level — not that it is fresh.

**From `medium` up, every custom module or theme you changed needs a page.**
`wiki_fresh` carries `cover_diff` there: an extension this run changed that
no page's `droost.modules` names fails the gate, by name. An extension an
earlier run left without a page is listed in the summary and not held
against you, and contrib never is. Write or extend the page, stamp it,
before this phase's `run`, and declare it like any other file.

At every level the `## Realized` section still lands in the spec file first:
the engine's requirement does not thin with the preset; depth does. Same
three questions, lighter medium.

### Second half: present

**What this phase's `run` executes depends on the run's flow**
(`phase_gates` in `droost workflow:status`):

- **fast** (`flow: fast`, the default at `low` and `medium`): no gate but `wiki_fresh`, which checks the
  documentation this phase just wrote. Every other gate ran once, at the
  phase that owns it. (At `low` wiki_fresh is off too, and the report shows
  it `off`: the level's declared trade.)
- **strict** (`flow: strict`, the default from `high` up): the FULL enabled gate set re-runs, the
  terminal safety net. A regression introduced since the test phase is caught
  now rather than shipped, and `wiki_fresh` runs here for the first time,
  the only phase at which it CAN be true.

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
  row. A criterion with no recorded proof is reported unproven, never as
  passed, and the seeker's ninth lens holds it at HIGH; `manual — <reason>`
  is printed as manual;
- the seeker ledger — findings and how each was resolved or carried, and
  the observations routed to follow-up;
- which browser tier verified the work (`playwright-mcp`, `native`, or
  `none` — in which case pages were checked by fetching them, and the report
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
recorded as complete. The completed run was archived to
`droost/droost-workflow/history/` as it completed (`status` and `evidence`
still read it), so the next ticket starts with `run` and no reset; until a
run is active, custom-code edits are walled again (`require_run`).

## Without a site

The capture half keeps its tools: droost reads and checks the wiki from
files. What a run without a site cannot do is confirm the pages describe
behaviour that renders; say so in the report rather than implying the site
was looked at. The `wiki_fresh` gate records its own answer from
`droost code:wiki status`.

The presentation half needs no site — which is exactly why it matters most
here. A run without a site typically carries several `did not run` results,
and this is the last moment anyone will see them. Present them as prominently as
the passes: the reader is deciding whether to trust the work, and they can
only do that if the report says what was actually checked.
