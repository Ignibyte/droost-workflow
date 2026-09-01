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
  `.droost-workflow/run.json` — and read it: a `current_phase` of `null`
  means the run FINISHED (the file is its record, cleared with
  `drush droost:workflow:reset`), while a named phase means it is live.

## Work

**Ground first, propose second.** The most expensive mistake in this phase is
describing a site that does not exist — a content type that is already there
under another name, a route that is taken, a field you were about to
duplicate. Ask the site before you assume:

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
   Before any row says hand-written, run `drush generate` (the bare command
   lists every generator) and check the construct against THAT list — a
   validation round hand-wrote `.permissions.yml`, `.links.menu.yml` and a
   route while `yml:permissions`, `yml:links:menu` and `controller` sat in
   the list it had itself printed. A hand-written row's reason must name
   what was checked: "no droost blueprint AND no drush generator", the
   gate is off and the operator declined, or the construct is genuinely
   novel. The seeker grades the diff against this map — building by hand
   what your own plan said a tool would build is drift, and so is a
   hand-written row whose construct a listed generator covers.
5. **Acceptance criteria in EARS form** — "When <trigger>, the <system> shall
   <observable response>", one observable behaviour per row, each with a way
   to check it. A criterion nobody can check is not a criterion.

The spec's WEIGHT follows the run's preset — and since 0.4 the weight is
DEPTH, never format. A **factory** run writes the full spec above to
`.droost-workflow/spec-<slug>.md`. A **light** run writes a shorter spec in
the same EARS shape — what was asked, what will change, and a handful of
"When <trigger>, the <system> shall <response>" criteria — to
`.droost-workflow/tmp-spec-<slug>.md`, presented back in chat at complete.
One spec format everywhere is what the seeker checkpoint grades against;
a criterion-free sketch would give the adversarial reviewer nothing to hold
the diff to. Either way the file exists BEFORE code does: light trims depth,
never the discipline. The `workflow-researcher` agent grounds the facts and
`workflow-spec-writer` drafts the artefact at either weight; review what it
drafted rather than rubber-stamping it.

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
