---
name: workflow-code
description: Phase 2 of the Droost Workflow. Build what the spec describes, asking droost what the site already has, within the site's house rules, without touching core or contrib.
---

# Code

Build what the plan describes. Nothing more — scope discovered mid-build
belongs in the spec first, not in the diff quietly.


## Grounding — required again here

The code phase cannot end without its own `## Grounding` rows in the spec, in
all three tiers, the same shape the plan phase used. Plan grounded to choose a
shape; code grounds to commit it to disk, and the questions are different ones:
what the existing custom code names things, what a contrib module's API
actually accepts, what core's constructor signature really is.

Add rows tagged `code`. `Found` must carry what came back — "nothing matched"
included. An empty cell is refused.

Carry the `Evidence` column too, and keep it resolvable: a class the code
index holds or whose file is where PSR-4 puts it; a file the index carries or
that exists on disk (docroot-relative — `modules/custom/…`, never `web/…`);
or `none: <query>` for a search that really did come back empty. The seeker
reads the table against the diff, and a `plan` row's `none:` that your own
build has just made true is superseded, not wrong — write the `code` row that
cites what you built instead.


## Your declaration is audited here

The plan declared the files this work would touch and the tests that would
cover it. The code phase checks that against the real diff, and two of the
three outcomes stop the phase:

- **a file you touch that was never declared BLOCKS.** If the work genuinely
  needs more scope than the plan named, re-declare it —
  `droost workflow:declare-changes --files=...` — and say in the
  spec why it grew. Growing the diff quietly is the drift this exists to catch.

  **Pass the WHOLE list, not just the new thing.** A re-declaration REPLACES
  the file list; declaring only the file you just added drops everything you
  declared at plan, and every one of those files then reads as scope creep. The
  verb is not `add`.

  **The first declaration is kept.** A changed path that only a later
  re-declaration covers is named in the record, and `declared_files` reads
  `recorded` rather than `satisfied`: the evaluation shows what the plan did
  not predict. That is the record, not a penalty. A re-declaration piped from
  `git status` covers the diff and predicts none of it.
- **a test you named is RECORDED, never held against you.** droost sees that a
  suite ran and how many tests it held; it cannot see which ones, so it does not
  pretend to. What DOES block is the suite failing — that is the phpunit gate,
  on its own merits, at the phase the run's flow runs it at (code in the fast
  flow, test in the strict one).
- a file declared and not touched is recorded and does not block.

The spec's contract sections were fingerprinted when plan passed —
`## Grounding`, `## Acceptance criteria`, `## Routes` — and **changing one is
RECORDED, not refused.** A `spec`/`frozen_sections` row names what moved and
the phase advances. Appending is still expected: `## Realized` and the
seeker's ledgers belong in this file.

## Entry gate

- A spec exists from the plan phase and its acceptance criteria are readable.
- The plan phase passed. If it failed, it is still failed; clearing that is a
  deliberate act, not something this phase does on the way past.

## Work

### Work through the acceptance criteria, checking as you go

**The spec's acceptance criteria are this phase's to-do list.** Put them in
your task list, then work through them, one at a time or a few together:

1. **Build it.**
2. **Check it**, the way its `Check` column says, or another way that shows
   it better. How you check is yours to choose; that you check is not:
   - `rendered`: fetch the page and read it, `curl -sL <site>/<route>`
     (`ddev describe` gives the site's URL; `-L`, since a node path
     redirects to its alias). No browser needed, and it costs little.
   - `browser`: open it in the run's browser (your editor's) and take a
     snapshot; for clicks, forms and JavaScript.
   - `test`: write the criterion's test now, and run that file alone
     (`node_modules/.bin/playwright test tests/e2e/x.spec.ts`, `phpunit
     --filter …`).
   - `command`: run it and compare what it prints.
3. **Record what showed it**, the moment it does:
   ```bash
   droost workflow:verify-criterion AC-1 "curl /rinks lists all six published rinks"
   droost workflow:verify-criterion AC-3 "tests/e2e/rinks.spec.ts passes"
   ```
   `droost workflow:criteria` shows what is checked and when.
4. **Move on.** A check that fails is fixed now, not left for test.

Check more than the criteria whenever it helps; nothing limits you to them.

**Before you leave code, re-check what you broke.** A later edit can undo
an earlier criterion. Check again, and record it again, every criterion an
edit since its check could have touched.

**The seeker reads this.** It compares each criterion's proof with the run's
edits. A criterion with no check, one checked before an edit that could break
it, or a proof that does not show the criterion met (a test that does not test
it, a page that does not show it) is a **HIGH** finding. Test then runs the
suite once, as the sign-off: it is not where you find out.

**Ask droost rather than walk the tree.** droost knows this codebase
first-hand: its wiki, its index and its symbol graph are built from this
project's own code, core and contrib included. The questions that send an
agent grepping through `web/core` or `vendor` are the ones it answers, and it
is there all phase, not only at plan. It reads files only, so it answers even
while the site will not boot (on DDEV, `ddev droost …`):

- `droost code:wiki` — how this project is put together, page by page, and
  what each installed contrib module and theme provides.
- `droost code:tree <module>` — a module's files, classes, hooks, services
  and routes.
- `droost code:symbol` and `droost code:graph` — where an existing class or
  hook lives, what calls it and what it calls, before you change it.
- `droost code:docs` — what an installed module documents.
- `droost search:code` and `droost search:arch` — the code and the
  documentation, by words.

**How you build is yours to decide.** Write it by hand, run `drush generate`
(bare, it lists what this site's drush offers), apply a recipe, or build in
the site and export the configuration. droost writes nothing into the site.
A file `drush generate` writes is recorded as generated, so a generated test
left untouched is the generator's, not the run's.

Nothing holds a file to having come from one of them. What the gates and the
seeker hold the result to is the spec, the declaration and the site's house
rules (`droost workflow:rules`).

Then the rules that do not bend:

1. **Never edit core or contrib.** If a contrib module is wrong, configure
   around it, subclass it, or alter it through the APIs built for that. A
   patched vendor directory is a site that cannot be updated.
2. **Validate before every write, not after.** A rejected write you saw is
   cheaper than a successful write you did not.
3. **Never blind-retry.** If a write fails, read the error and change
   something. Repeating the same call is not a strategy.
4. **Render through components, not HTML blobs.** A pasted markup string is a
   thing no theme can restyle and no editor can maintain.
5. **Leave no orphans.** A field with no storage, a route with no controller,
   a service with no definition — each is a half-built thing the next phase
   will report as broken.
6. **Export config, or it is invisible.** drush, a recipe and the admin UI
   write to ACTIVE config in the database — nothing the seeker's file review
   can see, and the static gates
   analyse PHP only. After any config-affecting change, export it
   (`drush config:export -y`, or the site's own config workflow) so it lands
   as reviewable YAML — **and confirm the sync directory is inside the repo
   and tracked**: on a default fresh site the export lands under
   `web/sites/default/files/config_*/sync`, which is gitignored, so the YAML
   never enters the diff and the export silently changes nothing. If it is
   outside the repo, export into a tracked path
   (`drush config:export --destination=<in-repo dir>`) or fix
   `$settings['config_sync_directory']` first. A content type, field, view or
   menu that never reaches a tracked file cleared the code phase with nothing
   inspecting it — the one blind spot in an otherwise file-based gate set,
   and yours to close by exporting.

### Pages, and the house rules

**Build each page as you declared it.** Every page the spec declared names
what builds it: a Canvas page, a View page, an entity's display, a Webform,
or a route. A page declared as one and built as another is a false record,
and the seeker reads the declaration against the diff. If the build had to
change what owns a page, declare it again with its new owner.

**The site's house rules bind the result.** `droost workflow:rules` prints
them: the page rules (which owners each kind of page may have on this site)
and `rules.house`, each with an id and its judge.

- A rule judged by **`gate`** is checked mechanically: `sdc_markup` for new
  markup outside an SDC component's directory, or the site's own `cmd`. In
  `block` mode a break fails the gate; in `report` mode it is recorded.
- A rule judged by **`seeker`** is read against your diff by the seeker
  (its lens 8). A break is HIGH, and on a site that holds on HIGH it holds
  the run.
- An exception is the OPERATOR's to grant. Ask, and record their answer in
  the spec with who approved it and why; one you write for yourself
  (`--except=` on a route) records the break, it does not excuse it.

**Canvas pages and displays** are built in the site (its UI, drush, or a
recipe) and exported, like any configuration: a Canvas page as its entity
and tree, an entity's own page as its full view mode with its fields bound
to the theme's components.

## Exit gate

Everything the spec said it would build exists, and nothing exists that the
spec did not name. Custom code and configuration only. **Any config the run created is
exported to tracked files** (rule 6) — otherwise the diff the seeker inspects
is blind to it (the static gates never read YAML either way), and a
config-only run would pass having verified nothing it built.

At this phase's `run`, the engine gates the diff with phpcs and phpstan, the
pair mandatory since 0.4, tunable but never off. **What else runs here depends
on the run's flow**, and `droost workflow:status` lists it under `phase_gates`:

- **fast** (`flow: fast`, the default at `low` and `medium`): code runs every analyser the level turns on
  AND the unit tests: phpunit, mutation and coverage, where on. So write the
  ticket's unit tests in this phase, run them, fix what they and the
  analysers find, and repeat. At `scope: ticket` the analysers (phpcs,
  phpstan and, where on, eslint, stylelint and prettier) read only the files
  this run changed, at every level, and at `low` and `medium` phpunit runs
  the test files this run added or changed; `scope: full` on a gate runs its
  whole configured set. The test phase that follows runs only the
  browser suite, parity, the house rules and the project's own gates placed
  at test; nothing re-runs your analysers or unit tests after this phase,
  and complete runs only `wiki_fresh`.

  **The run can come back here.** A failure at test, or a code file changed
  after this phase's gates ran, returns the run to code (`outcome:
  returned`). The envelope's `blocked` row `returned_to_code` names what
  failed and how many loops are left. Fix it, then `run`: this phase's gates
  measure the fix, and test follows.
- **strict** (`flow: strict`, the default from `high` up): code runs static analysis only, and the
  functional gates belong to the test phase, where they run again at
  complete.

**Then the seeker checkpoint, wherever one is armed.** The envelope's
`outcome` is the truth: `advanced` means no inspection is due; `inspection-due`
means one is, and the run will not move until its ledger is recorded — re-running
`run` returns `inspection-due` again, indefinitely. The level decides the
default (`low` arms no seeker; every other level does), and the lever file's
`seekers: { on: … }` overrides the level either way — the file `init` writes
arms it at every level, `low` included. Do not reason about the level; read
the outcome. When it is `inspection-due`, the engine is
holding the run rather than advancing: dispatch the
`workflow-seeker` agent over everything this run changed, append its
`## Seeker Inspection` section to the spec verbatim, and record it with the
`seeker-report` surface.

**ONE inspection, then fix, then move on.** Fix what it found — CRITICAL,
HIGH and MEDIUM first, LOW where it is cheap — and record the fixes in the
spec. Do **not** dispatch a second inspection to confirm your own fixes. The
checkpoint asks whether an inspection RAN, and once one has, the engine
advances; another round answers a question nobody asked, and it is not
cheap — each one is a subagent reading the entire cumulative diff. A measured
run spent three rounds inside one code phase and roughly forty minutes on
them.

**Re-inspect only where you are made to**, and there are exactly two ways
that happens. The lever file's `seekers: { rounds: N }` is a floor the code
phase will not advance below — read the `outcome`, not the number, because
`inspection-due` keeps coming back until the trail is long enough. And
`seekers.holds` makes the checkpoint *additionally* require zero open
findings at that severity (`critical` by default from `high` up; `custom`
may say `critical` or `medium`), so there, if one is open: fix it and
re-inspect until none is.

Otherwise an open finding the lever does not hold never requires another
round: record it, show it at the hold, carry it if you do not fix it.

**But close the ones you fixed — that is not a re-inspection.** A finding's
status lives in the `## Seeker Inspection` table, and it is one of `open`,
`resolved`, or `carried: <reason>`. Once you have fixed what the inspection
found, append an updated section with those rows marked, and record it with
`seeker-report` again, labelled `Inspector: self-reviewed`.
**No subagent, no second read of the diff** — you are reporting what you
did, not asking to be reviewed again. The engine records it as a self-review: it counts as no
inspection, and where `seekers.holds` holds the run on a finding, only a
seeker's ledger releases it. A ledger labelled independent with no
`workflow-seeker` returned since the last one is refused.

Skip it and the evidence store keeps every finding at `open` forever, because
the only thing that ever moves a status is a later report. A run that fixed
four mediums then reads, permanently, as a run that shipped four — and the
record is the thing everything here exists to make true. Measured: `P5 · run
3` fixed a translation regression and its store still says otherwise
(**F-44**).

Mark honestly. `carried: <reason>` is a real answer for something you chose
not to fix; `resolved` on something you did not fix is the one thing this
whole pipeline is built to prevent.

Gates verify rules; the seeker verifies judgment. The checkpoint spends no
retry budget: it is a hold, not a failure.

## When the site stops answering

A `ddev snapshot restore`, a `ddev restart`, a failed module install or a
fatal in a plugin you wrote can leave the site unable to boot. droost still
answers, since it reads files: use `droost code:tree` and `code:graph` to find
what broke. Fix the site (the fatal drush prints, or `drush watchdog:show`),
then go on. Read the state before repeating anything that writes.

## Without a site

droost still answers about the code, the wiki and the installed modules. What
you cannot do is anything the running site does: create content, set
configuration, render a page, run a test that boots Drupal.

So you are writing code by hand, to the conventions you can read in the
repository. Do it, and record plainly which steps could not be applied: no
content created, no configuration set, nothing introspected. Do not mark work
complete because a file exists — the test phase decides what passed, and it
cannot check what was never applied.
