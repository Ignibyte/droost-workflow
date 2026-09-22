---
name: workflow-code
description: Phase 2 of the Droost Workflow. Build what the spec describes, using droost's scaffolding and the Drupal APIs, without touching core or contrib.
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

Carry the `Evidence` column too, and keep it resolvable: a class the symbol
graph, the brain or the autoloader knows; a file the index carries or that
exists on disk (docroot-relative — `modules/custom/…`, never `web/…`); or
`none: <query>` for a search that really did come back empty. `grounding_check`
re-runs each one. A cell that resolves nowhere is reported, not fatal; a tier
that cites nothing that resolves fails; and a `plan` row's `none:` that your
own build has just made true is recorded as superseded — write the `code` row
that cites what you built instead.


## Your declaration is audited here

The plan declared the files this work would touch and the tests that would
cover it. The code phase checks that against the real diff, and two of the
three outcomes stop the phase:

- **a file you touch that was never declared BLOCKS.** If the work genuinely
  needs more scope than the plan named, re-declare it —
  `vendor/bin/droost-workflow declare-changes --files=...` — and say in the
  spec why it grew. Growing the diff quietly is the drift this exists to catch.

  **Pass the WHOLE list, not just the new thing.** A re-declaration REPLACES
  the file list; declaring only the file you just added drops everything you
  declared at plan, and every one of those files then reads as scope creep. The
  verb is not `add`.
- **a test you named is RECORDED, never held against you.** droost sees that a
  suite ran and how many tests it held; it cannot see which ones, so it does not
  pretend to. What DOES block is the suite failing — that is the phpunit gate,
  at the test phase, on its own merits.
- a file declared and not touched is recorded and does not block.

The spec's contract sections were fingerprinted when plan passed —
`## Tooling plan`, `## Grounding`, `## Acceptance criteria` — and **changing
one is RECORDED, not refused.** A `spec`/`frozen_sections` row names what
moved and the phase advances. Appending is still expected: `## Realized` and
the seeker's ledgers belong in this file.

**So when a gate tells you to change the plan, change it.** `grounding_check`
fails with `planned-not-called` when the Tooling plan names a tool the ledger
never saw, and its remedy says to "change the plan to a hand-written row with
its reason stated". That is a real instruction and the edit goes through.
A live run read this paragraph's earlier wording — *"editing one is refused by
name"*, true until 0.9.4 and false after — declined the remedy it was given,
spent its one retry, escalated to a waiver it is not allowed to run, and
stopped. It was wedged by a wall that no longer exists.

State the reason in the row you write. The record then says "I planned X and
needed Y", which is the thing the freeze was protecting and could only protect
by forbidding the edit.

## Entry gate

- A spec exists from the plan phase and its acceptance criteria are readable.
- The plan phase passed. If it failed, it is still failed; clearing that is a
  deliberate act, not something this phase does on the way past.

## Work

**Scaffold before you type** — when there is a site to reach. Generated
structure is consistent structure, and the generator already knows the
conventions you would have to remember. Every tool below needs a booted
site, `droost_scaffold` included:

- `droost_scaffold` — modules, plugins, services, tests, the shapes a Drupal
  module is made of.
- `droost_structure_create` — content types, fields, bundles.
- `droost_entity_create` / `droost_entity_update` — content.
- `droost_config_set` — configuration.
- `droost_symbol` and `droost_graph` — where an existing thing lives and what
  depends on it, before you change it.

Scaffold the skeleton, then edit its method bodies with your FILE tools.
Writing a whole source file through a shell heredoc (`cat <<` into
modules/custom) is the hand-roll the Tooling plan exists to prevent — the
seeker cites it against the plan's own row, and a live round lost exactly
those points after declaring the right blueprint and then typing past it.

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
6. **Export config, or it is invisible.** `droost_structure_create`,
   `droost_config_set` and the entity tools write to ACTIVE config in the
   database — nothing the seeker's file review can see, and the static gates
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

## Exit gate

Every construct the spec named exists, and nothing exists that the spec did
not name. Custom code and configuration only. **Any config the run created is
exported to tracked files** (rule 6) — otherwise the diff the seeker inspects
is blind to it (the static gates never read YAML either way), and a
config-only run would pass having verified nothing it built.

At this phase's `run`, the engine gates the diff with phpcs and phpstan —
static analysis only, and non-negotiable: the pair is mandatory since 0.4,
tunable but never off. The functional gates belong to the test phase, where
there is behaviour to verify.

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

**ONE inspection, then fix, then move on.** Fix what it found — CRITICAL and
MEDIUM first, LOW where it is cheap — and record the fixes in the spec. Do
**not** dispatch a second inspection to confirm your own fixes. The
checkpoint asks whether an inspection RAN, and once one has, the engine
advances; another round answers a question nobody asked, and it is not
cheap — each one is a subagent reading the entire cumulative diff. A measured
run spent three rounds inside one code phase and roughly forty minutes on
them.

**Re-inspect only where you are made to**, and there are exactly two ways
that happens. The lever file's `seekers: { rounds: N }` is a floor the code
phase will not advance below — read the `outcome`, not the number, because
`inspection-due` keeps coming back until the trail is long enough. And from
`high` up the checkpoint *additionally* requires **zero open CRITICAL**, so
there, if a CRITICAL is open: fix it and re-inspect until none is.

An open MEDIUM never requires another round, at any level.

**But close the ones you fixed — that is not a re-inspection.** A finding's
status lives in the `## Seeker Inspection` table, and it is one of `open`,
`resolved`, or `carried: <reason>`. Once you have fixed what the inspection
found, append an updated section with those rows marked, and record it with
`seeker-report` again. **No subagent, no second read of the diff** — you are
reporting what you did, not asking to be reviewed again.

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

## Without a site

**Every tool above is unavailable** — including `droost_scaffold`. They are
all Drupal plugins reached over MCP, so with no booted site there is no
droost at all, not a reduced droost. It is tempting to assume the file-writing
ones still work; they do not.

So you are writing code by hand, to the conventions rather than from the
generator. Do it, and record plainly which steps could not be applied: no
content created, no configuration set, nothing introspected. Do not mark work
complete because a file exists — the test phase decides what passed, and it
cannot check what was never applied.
