---
name: workflow-start
description: Begin a NEW Droost workflow run — REQUIRED before building anything (a content type, fields, views, config, custom code). Write the spec, start the run, declare the browser and task surfaces, then hand off to workflow-continue. Claude Code also offers it as /droost:workflow:start.
---

Begin a **new** run — and a run is where EVERY build starts. Building means
any new functionality: a content model, fields, displays, a view, config
composition, or custom code. The moment intent turns from discussing a change
to making it ("build this", "add the X", "let's do it"), this command is the
first move — never a write. `/droost:workflow:continue` advances the one
already open; `/droost:workflow:status` inspects without changing anything.

## First, refuse to clobber an existing run

If `droost/droost-workflow/run.json` already exists, do **not** start a second run:

- The run is still **in progress** → resume it with
  `/droost:workflow:continue`, not a new start. To abandon it deliberately,
  `drush droost:workflow:reset --force` (or, on a checkout with no site,
  `vendor/bin/droost-workflow reset --force`).
- The run has **finished** (completed or failed) → clear it with
  `drush droost:workflow:reset` or `vendor/bin/droost-workflow reset` (either
  archives the record to `droost/droost-workflow/history/`), then start fresh.

Only when there is no run.json does a start proceed.

## The order that actually works

**Open the run first. Then plan. Then declare the spec.** Everything that
records against a run — every knowledge-tool call the plan phase makes, the
browser tier, the task surface, seeker reports — needs `run.json` to exist,
and the plan phase is where the codebase gets asked the most. A run opened
after the plan had already grounded could not attribute any of that work
(F-25). So the sequence is:

1. **Open the run** — on a project with a working site use the SITE-BACKED
   surface, `drush droost:workflow:run` (or the droost MCP run tool). With no
   spec written yet this BEGINS the run (writes `droost/droost-workflow/run.json`
   with the plan phase active) and answers `outcome: blocked` with one row,
   `spec`, saying the run is open and waiting for its document. That is not
   a failure; it is the run existing before the work it will govern. Nothing
   is gated yet. The standalone `vendor/bin/droost-workflow run` is for a
   checkout with no site: through it every site-dependent gate
   (`config_clean`, `rendered_check`) comes back skipped with its reason, and
   an agentic run does not circle back to run them.
2. **Declare your browser tier** — `run.json` exists now:
   ```
   drush droost:workflow:declare-browser playwright-mcp        # site-backed; or: native | none
   vendor/bin/droost-workflow declare-browser playwright-mcp   # the same verb on a checkout with no site
   ```
   - `playwright-mcp` — you hold Playwright MCP tools and can drive a browser.
   - `native` — the editor gives you its own browser (Claude in Chrome, a
     cloud browser).
   - `none` — no browser this session; the rendered check is the floor and
     always runs. `none` is not a failure.

   The test phase branches on this, and the final report says which tier
   actually ran.
3. **Declare your task surface** — whether this session can show a human
   where the run is:
   ```
   drush droost:workflow:declare-tasks claude-code        # site-backed; or: codex | other | none
   vendor/bin/droost-workflow declare-tasks claude-code   # the same verb on a checkout with no site
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
4. **Load the `workflow-plan` skill** and do the plan work: the spec comes
   before any code. `workflow-researcher` grounds it in the real site or
   repo — and every one of those calls is now this run's, in the ledger and
   in the evidence store, under the plan phase; `workflow-spec-writer` drafts
   the spec at the preset's weight (a full EARS spec at `high`/`xhigh`/`max`,
   a shorter same-shape spec at `medium`/`low`), reading the frozen preset
   from the `run.json` that now exists. The spec file exists BEFORE code
   does, at every level of the dial. While the run is in plan the guard
   permits writes only under `droost/droost-workflow/` — the spec is plan's
   artefact; project files wait for code.
5. **Declare the spec and gate the plan phase** —
   `drush droost:workflow:run --spec=droost/droost-workflow/spec-<slug>.md`
   (or the MCP run tool with the same option). This records WHICH document
   governs the run and gates plan against it — the spec's `## Tooling plan`,
   `## Grounding` and `## Routes` sections must be present before the run may
   leave plan. On a project holding several spec files the declaration is
   mandatory: the engine refuses to guess which document a run answers to.

Then switch to **`/droost:workflow:continue`** to work code → test →
complete. Everything about the phases, the mandatory trio, the seeker
checkpoint, enforcement and the gate map lives there and in the
`workflow-<phase>` skills; this command's only job is to open the run
correctly.
