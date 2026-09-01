---
name: workflow-code
description: Phase 2 of the Droost Workflow. Build what the spec describes, using droost's scaffolding and the Drupal APIs, without touching core or contrib.
---

# Code

Build what the plan describes. Nothing more — scope discovered mid-build
belongs in the spec first, not in the diff quietly.

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

**Then the seeker checkpoint.** When the static pair passes, the engine
holds the run at `inspection-due` rather than advancing: dispatch the
`workflow-seeker` agent over everything this run changed, append its
`## Seeker Inspection` section to the spec verbatim, and record it with the
`seeker-report` surface. Open CRITICAL or MEDIUM findings are fixed and
re-inspected — a fresh section, appended — before the run moves to test.
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
