---
name: workflow-plan
description: Phase 1 of the Droost Workflow. Read the site's brief, look before you plan, and produce a spec with EARS acceptance criteria before any code is written.
---

# Plan

The first phase. Nothing gets built until there is a spec that says what
"built" means.

This phase absorbs what other pipelines split into solutions, architecture
and design. One phase, one artefact: a spec the later phases are measured
against.

## Entry gate

- `droost/droost.yml` loads. If it does not, stop and report the error —
  it names the key that is wrong.
- No run is already in progress, or you are deliberately resuming one. Check
  `droost/droost-workflow/run.json` — and read it: a `current_phase` of `null`
  means the run FINISHED (the file is its record, cleared with
  `droost workflow:reset`; a run that completes is archived as it
  completes, so this is a failed run or an older one), while a named phase
  means it is live.

## Work

**Consult the wiki and the tree, every plan.** droost answers from this
project's files and its code index, with no running site:

```bash
droost code:wiki                    # how the site is put together, and every contrib module and theme it installs
droost code:wiki <page>             # one page
droost code:tree [module|path]      # the code's layout: files, classes, hooks, services, routes
droost search:arch "<query>"        # the documentation: wiki pages, READMEs, module descriptions
```

`droost brief` prints the site's facts and its house rules; `droost
code:symbol`, `code:graph`, `code:docs`, `code:deprecations` and `search:code`
answer the narrower questions. Each is a knowledge call the ledger counts:
`code:wiki`, `code:tree`, `search:arch`, `code:symbol`, `code:graph`,
`code:docs`, `code:deprecations`, `search:code` and `brief`. (Installed in
its own vendor directory, the command is `tools/droost/vendor/bin/droost`.)
It is knowledge, not a way to
build: how you build is yours to decide, and the house rules are what the
gates and the seeker hold the result to.

**Look first, propose second — and RECORD IT.** The most expensive mistake in
this phase is describing a site that does not exist: a content type already
there under another name, a route that is taken, a field you were about to
duplicate, a contrib module that already does the job. Every droost call is
recorded by droost itself in the run's tool-call ledger.

**Plan does not close until it has looked.** The `plan_grounded` check holds
the plan phase until the ledger shows a `droost code:wiki` and a `droost
code:tree` call this run, and the spec's `## Grounding` section cites things
that resolve on this site. It forces looking. It never says how to build.

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

**The `Evidence` column is checked, and it is the point of the table.** It
is resolved against this project — the code index first, then the files —
and the record says **which one answered**. Three forms, each tried in this
order:

| Form | Example | Resolves when |
|---|---|---|
| a class, interface or trait | `Drupal\node\Entity\NodeType` | the code index holds it, **or its file is where PSR-4 puts it** (an extension's `src/`, core's `lib/`, the project's Composer map) — core included. A trailing `::method` is fine |
| a file | `modules/contrib/views/views.module` | the code index carries it, **or it exists on disk**. Paths are **docroot-relative**: `modules/custom/x/x.info.yml`, never `web/modules/…` — the `web/` (or whatever the docroot is called) is stripped either way, but write it the way the index does |
| a negative claim | `none: rink` | re-running the lookup still returns nothing. **A `plan` row that your own `code` phase makes true is recorded as superseded, not failed** — building the thing you said was absent is the job |

`plan_grounded` resolves the table before plan closes. **A citation that
resolves nowhere is reported, not fatal**, with two exceptions: **a tier you
claim with nothing that resolves** holds the phase — you said you searched
it, so show one thing the project can confirm — and **at least one citation
must name something that exists**: a `none:` row alone resolves on any
project. Prose in `Found` is what you say about yourself; the citation is
what the project can confirm.

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

Then produce the spec:

1. **The request, restated.** What the user asked for, in your words. If your
   restatement and their request differ, you have found the real work.
2. **What you will build** — content types, fields, views, pages, blocks,
   components, custom code — and **the approach**, including what you are
   deliberately NOT doing. Which tool, generator or hand-written file builds
   each is your call; droost does not prescribe one. Use whatever serves the
   change: `drush generate`, a recipe, configuration you export, or your own
   code.
3. **The house rules this change meets.** Read them in the brief, or with
   `droost workflow:rules`. Say how the change meets each one that
   applies. A rule judged by a gate is checked mechanically; a rule judged by
   the seeker is read against your diff, and a break is HIGH. An exception is
   the OPERATOR's to grant: ask, and record their answer in the spec.
4. **Declare what will change, before it changes.** Two lists, recorded by
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
   droost workflow:declare-changes \
     --files=web/themes/custom/mytheme,config/sync/system.site.yml \
     --tests=MyThemeSafeUrlTest \
     --type=theme
   ```

   A directory covers what you create under it, so name the module or theme
   rather than every file you expect to write. A ticket is declared with the
   files it covers: `--work-item=<KEY>` goes beside `--files`.

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
   have actually MEASURED something before the test phase ends — a `code` or
   `mixed` run rests on phpcs, phpstan and phpunit, and one of those passing
   over an empty path set has not checked what the ticket is about. It never
   turns a gate OFF; the mandatory trio is the operator's dial, not yours.

   **When to run it:** after the PLAN phase has advanced, and before you write
   anything. Not earlier: this verb needs a run to declare against, and the run
   does not exist until the first `run` invocation writes `run.json`. Called
   before that it refuses with *"there is no run in progress"*. The window is
   between the plan `run` and the code `run`.

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

   The run's own record (`droost/droost-workflow/`), lock files, and files the
   tree already held changed when the run began are never counted against you.

   Declare honestly. A vague declaration is not the safe option — it buys
   nothing, and the thing it costs is the only evidence that the diff matched
   the plan.

5. **Acceptance criteria in EARS form** — "When <trigger>, the <system> shall
   <observable response>", one observable behaviour per row. A criterion
   nobody can check is not a criterion. **The table is code's to-do list**:
   code works through it row by row, checking each as it goes, so give every
   row a `Check` saying how:

   - `rendered`: the default for anything on a page. Fetch the page and
     read it (`curl -sL <site>/<route>`: `-L`, since a node path redirects to
     its alias). No browser.
   - `browser`: only where a page must be driven (a click, a form,
     JavaScript): the run's browser and a snapshot.
   - `test`: its own test, written in code and run on its own.
   - `command`: a shell command (drush included), and the output to expect.
   - `manual`: a human looks; say why nothing else can.

   Give the table a `Verified By` column NOW, left empty: test fills it with
   the test that proves each row at sign-off.

   **DECLARE EACH ONE, once the run exists.** The table is for a human; the
   engine counts rows:

   ```bash
   droost workflow:declare-criterion AC-1 \
     "When a visitor opens /rinks, the site shall list every published rink"
   ```

   Code then records what proves each one as it checks it —
   `droost workflow:verify-criterion AC-1 "RinkListTest::testEveryPublished"`, or
   `verify-criterion AC-1 "manual — checked at 390px"` where a human looked.
   Verifying a ref nobody declared is refused, and it is the ONLY refusal on
   this surface: declare the promise, then prove it, in that order. A
   criterion written to fit whatever happened to pass is the one failure the
   record cannot survive.

   Restating a criterion is allowed and recorded as a revision — both
   sentences stay in the table — and it DROPS any proof the old sentence had,
   because a test that proved the old wording does not prove the new one.

6. **A `## Routes` section — expected, and its absence is RECORDED.** What
   the run records is `declare-route` rows (below); the section is the
   fallback and the human's copy. The paths this change adds or alters, one
   per line, as the site serves them (`/camps`, `/camps/summer-skills` —
   never a node id). The seeker, the browser check and the report read them,
   and a project's own gate that requests pages can too. A change that
   touches no route writes `none — <why>`; that is recorded as "the spec
   declared none", which is a different fact from "nobody asked".

   **DECLARE EACH ROUTE, once the run exists.** The section is for a human;
   the run records rows:

   ```bash
   droost workflow:declare-route /rinks "the new listing"
   droost workflow:declare-route /pepsi-ice-midwest
   ```

   Repeatable, idempotent, and it must begin with `/`. Once a run has
   declared anything, the document is not read for routes at all — two
   sources for one fact is a disagreement waiting to happen.

   **A route is declared as an anonymous visitor meets it.** A page only some
   users may see (an admin listing, a members-only page) is declared with the
   refusal an anonymous visitor must get (`--status=403`), so whoever checks
   it checks that it refuses.

   ```bash
   droost workflow:declare-route /admin/content/registrations --status=403 "editors only"
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
   so a sample command in the spec is never read as a real declaration.

   **Declare what builds each page.** For every page this change makes or
   changes, declare its kind (what its main content is) and its owner (what
   builds it):

   - `--kind=detail` — one entity: its own page.
   - `--kind=collection` — the collection itself: an archive, a directory, a
     search.
   - `--kind=page` — neither: a home, landing or single page; a list on it is
     a `list_section`.
   - `--kind=form` — its form; one that saves the site's configuration is
     `settings`.
   - `--kind=resource` — no HTML page at all: a feed, a calendar file, a
     download, JSON.

   The owner is what you chose to build it with: `canvas_page`,
   `view_page`, `view_block`, `entity_view_display`, `content_template`,
   `webform`, `route`, `config_form` or `list_builder`.

   ```bash
   droost workflow:declare-route / --kind=page --owner=canvas_page "the home page"
   droost workflow:declare-route /rinks --kind=collection --owner=view_page "the directory"
   droost workflow:declare-route /rinks/pepsi-ice-midwest --kind=detail --owner=entity_view_display
   ```

   The declaration is the record, and it must be true: the seeker reads it
   against the diff. Whether the owner you chose is one the site's rules
   allow is the house rules' question: a rule judged by a gate is checked
   mechanically, one judged by the seeker is read against your diff. An
   exception is the OPERATOR's to grant.

The spec's WEIGHT follows the run's preset — read the frozen, canonical name
from run.json — and the weight is DEPTH, never format. The five-point dial
collapses to two weights: a **`high`/`xhigh`/`max`** (or `custom`) run writes
the full spec above to `droost/droost-workflow/spec-<slug>.md`. A
**`medium`/`low`** run writes a shorter spec in the same EARS shape — what was
asked, what will change, a handful of "When <trigger>, the <system> shall
<response>" criteria, AND the `## Grounding` and `## Routes` sections
(expected at every weight: grounding is the discipline the light spec trims
depth from, not out) — to `droost/droost-workflow/tmp-spec-<slug>.md`,
presented back in chat at complete.

**Plan only the capture your level makes.** Complete's capture follows the
same frozen preset. At **`low`** complete writes
**no wiki pages** (`wiki_fresh` is off), so planning a wiki page is a
promise the level forbids you to keep: plan the change's documentation in
the spec's `## Realized` section and the READMEs. From **`medium`** up
complete writes or refreshes a page in `droost/wiki/` for every custom
module or theme the change touches, and the plan says so.

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
is the run's document — extend it with the grounding, the approach and the
routes; do not write a second spec beside it (two spec files make the engine
refuse to guess which governs), and do not redraft criteria the developer
agreed and may already have on the ticket.

## Exit gate

The spec exists, its `## Grounding` cites things that resolve, and every
acceptance criterion is observable. If you cannot say how a criterion would be
checked, rewrite it until you can.

The engine runs no shell gates at this phase. It runs one check,
`plan_grounded`: plan waits until the ledger shows `droost code:wiki` and
`droost code:tree` were consulted and the spec's Grounding citations resolve. Static analysis
first fires at code, on code that exists.

In pair mode the run pauses here and asks before continuing — even though no
gates ran. That is the cheapest moment in the whole pipeline to be told you
understood the request wrong.

## Without a site

droost reads the project's files and its code index, so it answers with the
site down. What it cannot tell you is the running site's state: the active
configuration, the content, what a page renders today. Ask the site for those
yourself (drush, the browser); when you cannot, say so in the spec. Write
what you could not verify as an explicit assumption, so the code phase knows
which of its foundations are guesses.
Do not substitute a plausible answer for a fact you could not check: a spec
that quietly invents the site's current state is worse than one that admits
it is working blind.
