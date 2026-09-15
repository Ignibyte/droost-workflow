---
name: workflow-plan
description: Phase 1 of the Droost Workflow. Ground in the site, understand the request, and produce a spec with EARS acceptance criteria before any code is written.
---

# Plan

The first phase. Nothing gets built until there is a spec that says what
"built" means.

This phase absorbs what other pipelines split into solutions, architecture
and design. One phase, one artefact: a spec the later phases are measured
against.

## Entry gate

- `droost.workflow.yml` loads. If it does not, stop and report the error —
  it names the key that is wrong.
- No run is already in progress, or you are deliberately resuming one. Check
  `droost/droost-workflow/run.json` — and read it: a `current_phase` of `null`
  means the run FINISHED (the file is its record, cleared with
  `drush droost:workflow:reset`), while a named phase means it is live.

## Work

**Ground first, propose second — and RECORD IT.** The most expensive mistake in
this phase is describing a site that does not exist: a content type already
there under another name, a route that is taken, a field you were about to
duplicate.

This is a **contract, not advice**. The plan phase cannot end without a
`## Grounding` section, and neither can code. One row per lookup:

```markdown
## Grounding

| Phase | Tier | Asked | Found | Evidence |
|---|---|---|---|---|
| plan | custom  | is there already a rink bundle? | nothing matched — no rink type on this site | `none: rink` |
| plan | contrib | what does views give me for a filtered listing? | a page display with an exposed taxonomy filter | `Drupal\views\Plugin\views\filter\TaxonomyIndexTid` |
| plan | core    | how is a node bundle created? | NodeType config entity | `Drupal\node\Entity\NodeType` |
```

**The `Evidence` column is checked, and it is the point of the table.** The
`grounding_check` gate resolves every cell against this site's own stores —
the symbol graph for custom and contrib, the brain for core — and a `none:`
claim is **re-run**, so it fails if the thing you said was absent is in fact
there. Three forms resolve:

| Form | Example | Resolves when |
|---|---|---|
| a class, interface or trait | `Drupal\node\Entity\NodeType` | the FQCN is in the symbol graph or the brain |
| an indexed file | `web/modules/contrib/views/views.module` | the file is in the search index |
| a negative claim | `none: rink` | re-running the search still returns nothing |

**Every tier you claim needs at least one citation that resolves.** Prose in
`Found` is what you say about yourself; the citation is what the site can
confirm. A table with no `Evidence` column is refused at CODE, where
`grounding_check` runs — plan runs no gates, so a missing column will not stop
you here and will stop you one phase later. It used to pass entirely, which
is exactly the hole this column closes.

**All three tiers, every phase that decides.** They answer different questions
and are not interchangeable:

- **custom** — what THIS site's own code already does. The wiki, and search
  over `modules/custom` and `themes/custom`. This is the tier that stops you
  rebuilding something that exists.
- **contrib** — what the installed modules already offer, before you write it
  yourself.
- **core** — Drupal's own APIs and the pattern it expects.

`Found` must say what came back. **"nothing matched" is a real and valuable
answer** — often the most valuable, because it is the one that proves a
duplicate was not about to be built. An empty cell is a claim to have looked,
and is refused.

Why this is enforced rather than suggested: grounding used to be advice while
routing was a contract, and usage followed the contract. Across 39 graded
rounds the build-surface router was called 179 times; the codebase knowledge
behind it was called six, and `droost_symbol`, `droost_graph`,
`droost_module_patterns` and `droost_deprecations` were never called at all.
A lookup that produces no row is a lookup nobody can tell you made.

Ask the site before you assume:

- `droost_capabilities` — what this site can actually do right now.
- `droost_architecture` — how it is put together.
- `droost_entities` and `droost_routes` — what already exists.
- `droost_module_docs` — what an installed module already gives you.
- `droost_guidelines` — the conventions this project expects you to follow.

Then produce the spec:

1. **The request, restated.** What the user asked for, in your words. If your
   restatement and their request differ, you have found the real work.
2. **The Drupal constructs to build** — content types, fields, views, pages,
   blocks, custom code. Name each one.
3. **The approach**, including what you are deliberately NOT doing.
4. **A `## Tooling plan` section — REQUIRED; the engine refuses to leave the
   plan phase without it.** Every construct from item 2, mapped to the
   surface that builds it, in this order of preference: a droost write tool
   (`droost_structure_create`, `droost_views_compose`, `droost_config_set`,
   `droost_scaffold` and its blueprints), `drush generate`, or —
   last — hand-written, WITH the reason stated on the same line.

   **Droost extends drush; it never competes with it** (owner ruling,
   2026-09-01). Droost ships blueprints only for what drush's generators do
   not cover, so "droost has no blueprint for this" is the EXPECTED state
   for many constructs and is never, by itself, a reason to hand-write.
   Ask the map instead of eyeballing two lists: `droost_decide
   graph=build-surface query="<construct>"` returns the resolved surface —
   the droost tool or blueprint, the exact `drush generate` command, or an
   explicit hand-written verdict — with gate states probed live. One call
   per construct IS the Tooling plan row. Where the tool is unavailable,
   run `drush generate` (the bare command lists every generator) and check
   the construct against THAT list — a
   validation round hand-wrote `.permissions.yml`, `.links.menu.yml` and a
   route while `yml:permissions`, `yml:links:menu` and `controller` sat in
   the list it had itself printed. A hand-written row's reason must name
   what was checked: "no droost blueprint AND no drush generator", the
   gate is off and the operator declined, or the construct is genuinely
   novel. The seeker grades the diff against this map — building by hand
   what your own plan said a tool would build is drift, and so is a
   hand-written row whose construct a listed generator covers.
5. **Declare what will change, before it changes.** Two lists, recorded by
   droost rather than written in prose. They are not the same kind of claim:

   - **`--files` is AUDITED** against the real diff at the code phase. Touch a
     file nobody declared and the phase stops.
   - **`--tests` is RECORDED, not verified.** droost sees that a suite ran and
     how many tests it held; it cannot see WHICH tests ran, because the gate
     reports totals rather than names. Naming them is still worth doing — it is
     the plan on record, and a reviewer reads it against the diff — but nothing
     here checks it, and nothing will block you for it.

   ```bash
   vendor/bin/droost-workflow declare-changes \
     --files=web/themes/custom/mytheme,config/sync/system.site.yml \
     --tests=MyThemeSafeUrlTest \
     --type=theme
   ```

   A directory covers what you create under it, so name the module or theme
   rather than every file you expect to write.

   **`--type` says what KIND of work this is**, which is a different question
   from how much effort the run is set to. One of:

   | Type | For |
   |---|---|
   | `code` | custom PHP — modules, classes, plugins, hooks |
   | `content_model` | content types, fields, displays, views: configuration |
   | `theme` | themes, templates, SDCs, CSS |
   | `content` | nodes, menus, taxonomy terms |
   | `docs` | markdown and comments; nothing executes |
   | `mixed` | honestly several of the above |

   Hyphens are fine (`content-model` works). The type decides which gates must
   have actually MEASURED something before the test phase ends — a
   content-model run rests on `config_clean` and `rendered_check`, and one of
   those passing over an empty path set has not checked what the ticket is
   about. It never turns a gate OFF; the mandatory trio is the operator's dial,
   not yours.

   **When to run it:** after the PLAN phase has advanced, and before you write
   anything. Not earlier: this verb needs a run to declare against, and the run
   does not exist until the first `run` invocation writes `run.json`. Called
   before that it refuses with *"there is no run in progress"* — the same trap
   `declare-browser` already carries a warning about. The window is between the
   plan `run` and the code `run`.

   Re-running it REPLACES the previous declaration for whichever lists you
   pass, so correcting a declaration is one command and not a second promise
   stacked on the first. That also means a re-declaration must carry the WHOLE
   list: passing only the new file drops every file you declared before, and
   each of them then reads as scope creep.

   The audit is asymmetric on purpose:

   - **a file you touch that you never declared BLOCKS the code phase.** Scope
     found mid-build belongs in the spec first — re-declare and say why in the
     plan, rather than letting the diff grow quietly.
   - **a file you declared and did not touch is recorded, and does not block.**
     Plans shrink for good reasons.
   - **a test you named that never ran BLOCKS**, at the TEST phase where tests
     actually run. "I will cover this" is a promise about verification.
   - **a type contradicted by the diff BLOCKS** — `docs` over a directory of
     PHP is not a documentation ticket. Only a narrow claim can be
     contradicted: `code`, `theme` and `mixed` are broad and never are.

   The run's own record (`droost/droost-workflow/`) and lock files are never
   counted against you.

   Declare honestly. A vague declaration is not the safe option — it buys
   nothing, and the thing it costs is the only evidence that the diff matched
   the plan.

6. **Acceptance criteria in EARS form** — "When <trigger>, the <system> shall
   <observable response>", one observable behaviour per row, each with a way
   to check it. A criterion nobody can check is not a criterion. Give the
   table a `Verified By` column NOW, left empty: the test phase fills it with
   the test that proves each row, and `complete` refuses while any cell is
   empty. The plan freezes every other cell of this table; a column added
   later is allowed only as an appended column with nothing else touched, and
   a run that reached complete without one has lost a round to exactly that.

The spec's WEIGHT follows the run's preset — read the frozen, canonical name
from run.json — and since 0.4 the weight is DEPTH, never format. The five-point
dial collapses to two weights: a **`high`/`xhigh`/`max`** (or `custom`) run
writes the full spec above to `droost/droost-workflow/spec-<slug>.md`. A
**`medium`/`low`** run writes a shorter spec in the same EARS shape — what was
asked, what will change, a handful of "When <trigger>, the <system> shall
<response>" criteria, AND the `## Grounding` and `## Tooling plan` sections
(the engine refuses to leave plan without them at every weight — grounding is
the discipline the light spec trims depth from, not out) — to
`droost/droost-workflow/tmp-spec-<slug>.md`, presented back in chat at
complete.
One spec format everywhere is what the seeker checkpoint grades against;
a criterion-free sketch would give the adversarial reviewer nothing to hold
the diff to. Either way the file exists BEFORE code does: the lighter weight
trims depth, never the discipline. The `workflow-researcher` agent grounds the
facts and `workflow-spec-writer` drafts the artefact at either weight; review
what it drafted rather than rubber-stamping it.

## When intake already wrote the spec

A work-item command (`/droost:work <KEY>`) may have written
`droost/droost-workflow/spec-<KEY>.md` before this phase began: the request,
the agreed acceptance criteria, the testing expectations, the track. That file
is the run's document — extend it with the constructs, the approach and the
`## Tooling plan`; do not write a second spec beside it (two spec files make
the engine refuse to guess which governs), and do not redraft criteria the
developer agreed and may already have on the ticket.

## Exit gate

The spec exists, and every acceptance criterion is observable. If you cannot
say how a criterion would be checked, rewrite it until you can.

The engine runs no shell gates at this phase — the spec is the gate. Static
analysis first fires at code, on code that exists.

In pair mode the run pauses here and asks before continuing — even though no
gates ran. That is the cheapest moment in the whole pipeline to be told you
understood the request wrong.

## Without a site

Every tool above reaches a running Drupal site. With no site — a plain
checkout, or one that is mid-build — none of them answer.

Say so in the spec. Write what you could not verify as an explicit
assumption, so the code phase knows which of its foundations are guesses.
Do not substitute a plausible answer for a fact you could not check: a spec
that quietly invents the site's current state is worse than one that admits
it is working blind.
