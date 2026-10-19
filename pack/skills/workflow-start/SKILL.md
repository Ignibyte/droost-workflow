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
  the operator runs `droost workflow:reset --force`.
- The run has **failed**, or completed before droost/workflow 0.14.18 (a
  `run.json` whose `current_phase` is `null`) → clear it with
  `droost workflow:reset` (it archives the record to
  `droost/droost-workflow/history/`), then start fresh.
  A run that completes now is archived as it completes, so there is nothing
  to clear.

Only when there is no run.json does a start proceed.

## The order that actually works

**Open the run first. Then plan. Then declare the spec.** Everything that
records against a run — every knowledge-tool call the plan phase makes, the
browser tier, the task surface, seeker reports — needs `run.json` to exist,
and the plan phase is where the codebase gets asked the most. A run opened
after the plan had already grounded could not attribute any of that work
(F-25). So the sequence is:

1. **Open the run** — `droost workflow:run`. With no spec written yet this
   BEGINS the run (writes `droost/droost-workflow/run.json`
   with the plan phase active) and answers `outcome: blocked` with one row,
   `spec`, saying the run is open and waiting for its document. That is not
   a failure; it is the run existing before the work it will govern. Nothing
   is gated yet.
2. **Declare your browser tier** — `run.json` exists now:
   ```
   droost workflow:declare-browser playwright-mcp   # or: native | none
   ```
   - `playwright-mcp` — you hold Playwright MCP tools and can drive a browser.
   - `native` — the editor gives you its own browser (Claude in Chrome, a
     cloud browser).
   - `none` — no browser this session; criteria marked `browser` are then
     checked by fetching the page. `none` is not a failure.

   The test phase branches on this, and the final report says which tier
   actually ran.
3. **Declare your task surface** — whether this session can show a human
   where the run is:
   ```
   droost workflow:declare-tasks claude-code   # or: codex | other | none
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
   does, at every level of the dial. Plan does not close until the spec's
   `## Grounding` cites what resolves and the run has consulted droost's wiki
   and code tree (`droost code:wiki`, `droost code:tree`, then whatever
   narrower command the plan needs): the `plan_grounded` check. While the run is in plan the guard
   permits writes only under `droost/droost-workflow/` — the spec is plan's
   artefact; project files wait for code.
5. **Declare the spec and gate the plan phase** —
   `droost workflow:run --spec=droost/droost-workflow/spec-<slug>.md`
   This records WHICH document governs the run and gates plan against it —
   its `## Grounding` must cite what resolves before the run may leave plan,
   and a missing `## Routes` is recorded. On a project holding several spec files the declaration is
   mandatory: the engine refuses to guess which document a run answers to.

Then switch to **`/droost:workflow:continue`** to work code → test →
complete. Everything about the phases, the mandatory trio, the seeker
checkpoint, enforcement and the gate map lives there and in the
`workflow-<phase>` skills; this command's only job is to open the run
correctly.
