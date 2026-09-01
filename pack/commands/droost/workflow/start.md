---
title: Droost Workflow — start
purpose: Begin a NEW workflow run — REQUIRED before building ANYTHING (a content type, fields, views, config, custom code). Write the spec, start the run, declare the browser tier — then hand off to continue.
---

Begin a **new** run — and a run is where EVERY build starts. Building means
any new functionality: a content model, fields, displays, a view, config
composition, or custom code. The moment intent turns from discussing a change
to making it ("build this", "add the X", "let's do it"), this command is the
first move — never a write. `/droost:workflow:continue` advances the one
already open; `/droost:workflow:status` inspects without changing anything.

## First, refuse to clobber an existing run

If `.droost-workflow/run.json` already exists, do **not** start a second run:

- The run is still **in progress** → resume it with
  `/droost:workflow:continue`, not a new start. To abandon it deliberately,
  `drush droost:workflow:reset --force`.
- The run has **finished** (completed or failed) → clear it with
  `drush droost:workflow:reset` (it archives the record to
  `.droost-workflow/history/`), then start fresh.

Only when there is no run.json does a start proceed.

## The order that actually works

The run is created by the FIRST invocation of the run surface, and everything
that records against a run — the browser tier, seeker reports — needs that
file to exist. So the sequence is:

1. **Load the `workflow-plan` skill** and do the plan work: the spec comes
   before any code. `workflow-researcher` grounds it in the real site or
   repo; `workflow-spec-writer` drafts it at the preset's weight (a full EARS
   spec at `factory`, a shorter same-shape spec at `light`). The spec file
   exists BEFORE code does.
2. **Invoke the run surface, declaring the spec** —
   `vendor/bin/droost-workflow run --spec=.droost-workflow/spec-<slug>.md`
   (the drush and MCP surfaces take the same option). This BEGINS the run
   (writes `.droost-workflow/run.json`), records WHICH document governs it,
   and gates the plan phase — which requires the spec's `## Tooling plan`
   section to be present before the run may leave plan. On a project holding
   several spec files the declaration is mandatory: the engine refuses to
   guess which document a run answers to. This is the step that makes the
   run real; only after it does anything below work.
3. **Declare your browser tier** — now that run.json exists:
   ```
   vendor/bin/droost-workflow declare-browser playwright-mcp   # or: native | none
   ```
   - `playwright-mcp` — you hold Playwright MCP tools and can drive a browser.
   - `native` — the editor gives you its own browser (Claude in Chrome, a
     cloud browser).
   - `none` — no browser this session; the rendered check is the floor and
     always runs. `none` is not a failure.

   The test phase branches on this, and the final report says which tier
   actually ran. (This used to be documented as a step to run BEFORE the
   first `run` — it cannot be: declare-browser records against a run that the
   first `run` is what creates.)
4. **Declare your task surface** — whether this session can show a human
   where the run is:
   ```
   vendor/bin/droost-workflow declare-tasks claude-code   # or: codex | other | none
   ```
   - `claude-code` — you hold task tools (TaskCreate / TaskUpdate).
   - `codex` — the host's own task list.
   - `other` — a task list this vocabulary does not name yet. Say so rather
     than saying none.
   - `none` — no task surface this session. Not a failure, and not a reason
     to invent one.

   If you declared a surface, **create one task per phase now** — plan, code,
   test, complete — and keep them current as the run moves: the phase you are
   working is in progress, a phase that passed is completed. That is the
   whole point of declaring: a human watching should be able to see where the
   run is without reading a transcript or asking you. Do not create tasks for
   your own sub-steps at the same level as the phases; the phases are the
   spine, and anything finer belongs underneath them or nowhere.

Then switch to **`/droost:workflow:continue`** to work code → test →
complete. Everything about the phases, the mandatory trio, the seeker
checkpoint, enforcement and the gate map lives there and in the
`workflow-<phase>` skills; this command's only job is to open the run
correctly.
