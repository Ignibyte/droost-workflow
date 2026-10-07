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

Write a `## Grounding` section, one row per lookup: it is the record of what
you asked and what came back.

```markdown
## Grounding

| Phase | Tier | Asked | Found | Evidence |
|---|---|---|---|---|
| plan | custom  | is there already a rink bundle? | nothing matched — no rink type on this site | `none: rink` |
| plan | contrib | what does views give me for a filtered listing? | a page display with an exposed taxonomy filter | `Drupal\views\Plugin\views\filter\TaxonomyIndexTid` |
| plan | core    | how is a node bundle created? | NodeType config entity | `Drupal\node\Entity\NodeType` |
```

**The `Evidence` column is checked, and it is the point of the table.** The
`grounding_check` gate resolves every cell against this site — its stores
first, then the site itself — and records **which one answered**. Three forms,
each tried in this order:

| Form | Example | Resolves when |
|---|---|---|
| a class, interface or trait | `Drupal\node\Entity\NodeType` | the symbol graph or the brain holds it, **or the autoloader can load it** — so any class this site can actually run resolves, core included. A trailing `::method` is fine |
| a file | `modules/contrib/views/views.module` | the search index carries it, **or it exists on disk**. Paths are **docroot-relative**: `modules/custom/x/x.info.yml`, never `web/modules/…` — the `web/` (or whatever the docroot is called) is stripped either way, but write it the way the index does |
| a negative claim | `none: rink` | re-running the lookup still returns nothing. **A `plan` row that your own `code` phase makes true is recorded as superseded, not failed** — building the thing you said was absent is the job |

**A citation that resolves nowhere is reported, not fatal.** It appears on the
gate's findings and in the evaluation, and the run goes on. One thing about
the table DOES stop you: **a tier you claim with nothing that resolves** — you
said you searched it, so show one thing the site can confirm. Whether this
run's ledger shows droost lookups, and whether the tools your Tooling plan
named were called, are recorded beside your plan and never fail it: asking
droost is required once, at plan, by the consult (below).

Prose in `Found` is what you say about yourself; the citation is what the site
can confirm. A table with no `Evidence` column is refused at CODE, where
`grounding_check` runs — plan runs no gates, so a missing column will not stop
you here and will stop you one phase later. It used to pass entirely, which is
exactly the hole this column closes. (`strict_citations: true` on the gate
restores the old rule where any unresolvable cell fails the phase.)

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

**Ask droost: it knows this codebase first-hand.** Its index, its symbol
graph and its wiki are built from this project's own code, core and contrib
included, so asking is faster than walking the tree, and every question is
recorded. Ask at plan, and keep asking while you code. These are the
questions it answers, and the evaluation counts each as a knowledge call:

- `droost_wiki` — start here: how this project is put together and how it
  documents itself, its pages, whether they are still true, and the
  factsheet that grounds a new one. On a site with no code of its own yet,
  it says so.
- `droost_consult` — your plan, put to droost: every construct and page it
  names, answered (below).
- `droost_search` — what this codebase says, lexically and (if indexed)
  semantically.
- `droost_symbol` and `droost_graph` — a class, its callers and what it calls.
- `droost_deprecations` — what not to reach for.
- `droost_module_docs` — what an installed module already gives you.
- `droost_capabilities` — what this site can actually do right now.
- `droost_architecture` — how it is put together.
- `droost_entities` and `droost_routes` — what already exists.
- `droost_services` and `droost_db_schema` — the services and the tables
  that exist, with a renamed service's modern name.

Then produce the spec:

1. **The request, restated.** What the user asked for, in your words. If your
   restatement and their request differ, you have found the real work.
2. **The Drupal constructs to build** — content types, fields, views, pages,
   blocks, custom code. Name each one.
3. **The approach**, including what you are deliberately NOT doing.
4. **A `## Tooling plan` section — expected, and its absence is RECORDED.**
   The engine no longer refuses a phase over a missing section: it writes a
   `spec`/`shape` row naming what the document does not say, and the phase
   advances. Write it anyway: it is what `droost_consult` reads, and the
   record sets what you used beside it. Name each construct by what it IS
   (a block plugin, a View page, a token, a content type), not only by the
   class you will write, so droost can answer it. Map each to the surface
   you will build it with. droost's advice, best first: a droost write tool
   (`droost_structure_create`, `droost_views_compose`, `droost_config_set`,
   `droost_scaffold` and its blueprints), `drush generate`, or hand-written,
   with the reason on the same line.

   **Droost extends drush; it never competes with it** (owner ruling,
   2026-09-01). Droost ships blueprints only where drush's generators stop
   short: constructs drush has no generator for (an access handler, a
   plugin deriver, a media source, a CKEditor 5 plugin, a recipe, an MCP
   tool), and ones whose drush template leaves out what the construct needs
   (an OOP `#[Hook]` class, a module SDC, a Views filter or sort with its
   registration, config schema, kernel and functional tests, migrations).
   Services, forms, blocks, event subscribers, entity types, drush commands
   and most plugin types are `drush generate`'s, and asking `droost_scaffold`
   for one returns the generator's name, not a file. So "droost has no
   blueprint for this" is the EXPECTED state for most constructs and is
   never, by itself, a reason to hand-write.
   Ask droost by KIND, not by description. `droost_decide
   graph=build-surface` with no kind and no query lists every kind this
   site can build, one line each: ask it once. Then, for each construct,
   `droost_decide graph=build-surface kind="<kind>"` returns every surface
   that builds that kind here, best first (the droost tool or blueprint,
   the exact `drush generate` command, or a hand-written verdict with its
   reason), with gate states probed live. Choose one and record it as the
   construct's Tooling plan row. A surface below the first, or
   hand-written, carries its reason on the same line. A `query` in your own
   words is only a search: it names the three nearest kinds and picks none
   of them. Where the tool is unavailable, run `drush generate` (the bare
   command lists every generator) and check the construct against THAT
   list: a validation round hand-wrote `.permissions.yml`,
   `.links.menu.yml` and a route while `yml:permissions`, `yml:links:menu`
   and `controller` sat in the list it had itself printed.

   **What you build is your choice, and the record shows it.** At code and
   at complete, `grounding_check` reads every file the run ADDED and sets
   each one that has the shape of a kind droost builds (a
   `*.permissions.yml`, a block plugin, a component, a kernel test, a
   controller) beside the surfaces droost advised for it: a file a
   blueprint wrote, a `drush generate` the guard saw run, a droost tool
   call, or a Tooling plan row that says hand-written. Scaffold or
   generate and then edit the result, or write it by hand: the gate
   records which, and fails neither.
5. **Declare what will change, before it changes.** Two lists, recorded by
   droost rather than written in prose. They are not the same kind of claim:

   - **`--files` is AUDITED** against the real diff at the code phase. Touch a
     file nobody declared and the phase stops. Declare before you build: this
     first list is the plan's prediction, and it is kept even after you
     re-declare.
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
   the test that proves each row.

   **DECLARE EACH ONE, once the run exists.** The table is for a human; the
   engine counts rows:

   ```bash
   vendor/bin/droost-workflow declare-criterion AC-1 \
     "When a visitor opens /rinks, the site shall list every published rink"
   ```

   The test phase then records what proves each one —
   `verify-criterion AC-1 "RinkListTest::testEveryPublished"`, or
   `verify-criterion AC-1 "manual — checked at 390px"` where a human looked.
   Verifying a ref nobody declared is refused, and it is the ONLY refusal on
   this surface: declare the promise, then prove it, in that order. A
   criterion written to fit whatever happened to pass is the one failure the
   record cannot survive.

   Restating a criterion is allowed and recorded as a revision — both
   sentences stay in the table — and it DROPS any proof the old sentence had,
   because a test that proved the old wording does not prove the new one.

   Why the rows and not the table: a spec once documented its six source
   records under `## Acceptance criteria`, the engine read everything under
   that heading as criteria, demanded proof for six rows that were not
   criteria, and then refused the correction. The build was finished and
   verified. A declared criterion cannot be mistaken for a data table.

5. **A `## Routes` section — expected, and its absence is RECORDED.** What
   the gate actually renders is `declare-route` rows (below); the section is
   the fallback and the human's copy. The paths this change adds or alters, one per line, as the
   site serves them (`/camps`, `/camps/summer-skills` — never a node id).
   `rendered_check` renders every one of them at test and at complete,
   beside the front page, and the report names which source each route came
   from. A change that touches no route writes `none — <why>`; that is
   recorded as "the spec declared none", which is a different fact from
   "nobody asked". Three live rounds rendered `/` alone while the ticket's
   own page was the one that could fail — once it was a 500 mid-build.

   **DECLARE EACH ROUTE, once the run exists.** The section is for a human;
   the gate renders rows:

   ```bash
   vendor/bin/droost-workflow declare-route /rinks "the new listing"
   vendor/bin/droost-workflow declare-route /pepsi-ice-midwest
   ```

   Repeatable, idempotent, and it must begin with `/`. Once a run has
   declared anything, the document is not consulted for routes at all — two
   sources for one fact is a disagreement waiting to happen.

   **The gate renders as an anonymous visitor.** A page only some users may
   see (an admin listing, a members-only page) is declared with the refusal
   an anonymous visitor must get, and the gate then checks it refuses: a
   page declared `--status=403` that renders for anyone fails as public.

   ```bash
   vendor/bin/droost-workflow declare-route /admin/content/registrations --status=403 "editors only"
   ```

   In the section it is `/admin/content/registrations (403)`. Declaring a
   path again replaces its expectation, so a route declared bare that turns
   out to be admin-only is corrected by declaring it again with its status.

   The section is still the fallback for a run that declared nothing, and
   there it reads however you wrote it: a plain list, a table, or a fenced
   block — **this section reads its fences, and it is the only one that
   does.** A route is a line that IS a path, so `- /camps — the listing`
   declares `/camps` and a sentence mentioning `` `/node/{nid}` `` declares
   nothing. Elsewhere a fenced block is invisible to the engine on purpose,
   so a sample command in your Tooling plan is never read as a real
   declaration.

   **WHAT BUILDS EACH PAGE: walk the tree, then declare it** (owner,
   2026-10-02). For every page this change makes or changes, ask one
   question: **what is this page's main content?**

   1. **One entity** (a rink, a camp, a post): its own page is the entity's
      display, Manage display with the theme's components: `--kind=detail`.
   2. **The collection itself** (an archive, a directory, a search): a View
      page: `--kind=collection`. Views lists content entities only, so the
      listing of a configuration entity type is that type's own list
      builder, on its `entity.<type>.collection` route:
      `--kind=collection --owner=list_builder`.
   3. **Neither** (a home, landing or single page that is not an entity):
      on a Canvas site a Canvas page, each section its own component:
      `--kind=page`. A list on it, such as the three latest posts, is a View
      block placed in it (`list_section`), judged on its page.
   4. **Its form** (a contact page): `--kind=form`. A form that saves the
      site's configuration is a settings page, a settings form
      (`drush generate form:config`) on a route:
      `--kind=settings --owner=config_form`.
   5. **No HTML page at all** (a feed, a calendar file, a download, JSON):
      a resource, a route of the module's own or a View's feed display.
      Declare it so the record says what it is: `--kind=resource
      --owner=route` (or `--owner=view_page` for a Views feed).

   **Ask before you declare, for every page.** The tree gives the kind; it
   does not give this site's owner. That depends on whether the site has
   Canvas and on the operator's rules, and droost knows both: the consult
   answers every page in `## Routes` with this site's practice, and
   `droost_decide` with `kind="landing page"`, `"collection page"`,
   `"detail page"` or `"list section"` answers one kind on its own. Write
   the path you took into `## Routes`, then declare the page with the
   owner you chose:

   ```bash
   vendor/bin/droost-workflow declare-route / --kind=page --owner=canvas_page "the home page"
   vendor/bin/droost-workflow declare-route /rinks --kind=collection --owner=view_page "the directory"
   vendor/bin/droost-workflow declare-route /rinks/pepsi-ice-midwest --kind=detail --owner=entity_view_display
   ```

   `composition_check` reads what really builds each declared page, at code.
   **A declaration that is false fails**: that is the record, and it must be
   true. A page built another way than the rules advise is recorded beside
   the advice, never failed. Say why with `--except="<reason>"`, and the
   reason is recorded with it. A judgement call (a coach grid between two
   panels: a landing page with a list, or the collection itself?) is yours
   to make and to write down, never to hide.

   Two of three live rounds lost their routes to that shape: one fenced its
   list and had a placeholder harvested out of a sentence instead, rendering
   a 404 while the page the ticket existed to build was never requested.
   Declaring is how that stops being possible.

The spec's WEIGHT follows the run's preset — read the frozen, canonical name
from run.json — and since 0.4 the weight is DEPTH, never format. The five-point
dial collapses to two weights: a **`high`/`xhigh`/`max`** (or `custom`) run
writes the full spec above to `droost/droost-workflow/spec-<slug>.md`. A
**`medium`/`low`** run writes a shorter spec in the same EARS shape — what was
asked, what will change, a handful of "When <trigger>, the <system> shall
<response>" criteria, AND the `## Grounding`, `## Tooling plan` and
`## Routes` sections (expected at every weight, and a missing one is recorded
rather than refused — grounding is the discipline the light spec trims depth
from, not out) — to
`droost/droost-workflow/tmp-spec-<slug>.md`, presented back in chat at
complete.
**Plan only the capture your level makes.** Complete's capture follows the
same frozen preset, and every droost tool a Tooling plan row names is set
beside the ledger in the record. At **`low`** complete writes
**no wiki pages** (`wiki_fresh` is off), so a
`droost_wiki_write` row is a promise the level forbids you to keep: plan the
change's documentation as hand-written at `low`, in the spec's `## Realized`
section and the READMEs. From
**`medium`** up complete writes a page through `droost_wiki_write` for every
custom module or theme the change touches, and that row belongs in the plan.

One spec format everywhere is what the seeker checkpoint grades against;
a criterion-free sketch would give the adversarial reviewer nothing to hold
the diff to. Either way the file exists BEFORE code does: the lighter weight
trims depth, never the discipline. The `workflow-researcher` agent grounds the
facts and `workflow-spec-writer` drafts the artefact at either weight; review
what it drafted rather than rubber-stamping it.

## Consult droost with your plan

**The one thing plan requires of how you build.** When the spec names its
constructs (the Tooling plan) and its pages (`## Routes`), call
`droost_consult`. It reads the spec from disk (name it with `spec` only when
the state directory holds more than one) and answers every construct and
page: the kinds each reads as and what builds each on this site, best first;
this site's page practice where it is a page; and what already exists of it,
in custom code, in the symbol graph and in the wiki. It is what droost
believes is good Drupal practice here.

It is advice. Use it to write each construct's surface and to declare each
page, and build as you judge: the record sets what droost advised beside
what you built, and nothing fails you for choosing differently.

Plan does not close until **every construct and page in the spec as it
stands** was among the items a consult of this run answered. Add a
construct or a page, or reword one, after consulting, and consult again: it
is one call, and it answers the whole plan.

If droost's MCP tools stop answering (the server died under a snapshot
restore, a restart or a fatal), the consult runs through drush and counts
the same: `ddev drush droost:tool droost_consult` (`drush droost:tool …`
without DDEV). `.claude/partials/droost-usage.md` says more. Do not wait for
someone to reconnect the editor.

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

The engine runs no shell gates at this phase. It runs one check,
`plan_consulted`: plan waits until the spec as it stands was put to droost
with `droost_consult`. That is the only thing about how you build that the
workflow forces; static analysis first fires at code, on code that exists.

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
