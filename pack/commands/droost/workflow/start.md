---
title: Droost Workflow — start
purpose: Begin a NEW workflow run — write the spec, start the run, declare the browser tier — then hand off to continue for code, test and complete.
---

Begin a **new** run. `/droost:workflow:start` opens one;
`/droost:workflow:continue` advances the one already open;
`/droost:workflow:status` inspects without changing anything.

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
2. **Invoke the run surface** — `vendor/bin/droost-workflow run`,
   `drush droost:workflow:run`, or the MCP run tool. This BEGINS the run
   (writes `.droost-workflow/run.json`) and gates the plan phase. This is the
   step that makes the run real; only after it does anything below work.
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

Then switch to **`/droost:workflow:continue`** to work code → test →
complete. Everything about the phases, the mandatory trio, the seeker
checkpoint, enforcement and the gate map lives there and in the
`workflow-<phase>` skills; this command's only job is to open the run
correctly.
