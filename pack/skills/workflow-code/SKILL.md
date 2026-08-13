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

## Exit gate

Every construct the spec named exists, and nothing exists that the spec did
not name. Custom code and configuration only.

At this phase's `run`, the engine gates the diff with phpcs and phpstan —
static analysis only. The functional gates belong to the test phase, where
there is behaviour to verify.

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
