---
title: Droost Workflow — continue
purpose: Start a workflow run, or advance the one already in progress, through plan, code, test and complete.
---

Start a run, or advance the one in progress. This is the single entry point
to the work pipeline — `/droost:workflow:status` inspects,
`/droost:workflow:continue` acts. (Until 0.4 this command was `/droost-work`;
same pipeline, honest name.)

## What this does

Reads `droost.workflow.yml` for the levers and works the phases **in the one
order that exists**: `plan → code → test → complete`. Every run walks all of
them — minor changes included. What varies between a heavy and a light run is
the weight each phase carries, never the path. (0.4 folded the old document
phase into complete: capturing what was built is the first half of presenting
it.)

## First invocation of a run: declare your browser

Before the first `run`, record which browser tier THIS session actually has —
nothing on disk can know it for you:

```
vendor/bin/droost-workflow declare-browser playwright-mcp   # or: native | none
```

- `playwright-mcp` — you hold Playwright MCP tools and can drive a browser.
- `native` — the editor gives you its own browser (Claude in Chrome, a cloud
  browser).
- `none` — no browser this session.

The test phase branches on this, and the final report says which verification
tier actually ran. `none` is not a failure — the rendered check is the floor
and always runs.

## The two weights

The preset decides the artifact weight (and the file can override any lever):

- **factory** — the full EARS spec, written to
  `.droost-workflow/spec-<slug>.md`. Gates: everything on. Enforcement
  defaults hard.
- **light** — a SHORTER spec in the same EARS shape: what was asked, what
  will change, and a handful of "When <trigger>, the <system> shall
  <response>" criteria — written to `.droost-workflow/tmp-spec-<slug>.md`
  and presented back in chat at complete. Light trims depth, never format:
  one spec shape everywhere is what the seeker grades against.

Either way the spec file exists BEFORE code does; complete presents the
realized plan against it.

## The mandatory trio

`phpcs`, `phpstan` and `phpunit` run on every run — they are the toolchain
Drupal core itself develops with, and 0.4 made them non-negotiable. The lever
file tunes HOW they run (standard, level, paths), never whether. A repo that
cannot run one of them yet gets an honest answer instead of a pass: tool
missing, config missing, or a labeled "nothing to analyse / no tests yet".

## The seeker checkpoint

When the code phase's gates pass, the engine holds the run at
`inspection-due` — gates verify rules; the seeker verifies judgment. The loop:

1. Dispatch the **workflow-seeker** agent (subagent tool) with: the spec
   path, the diff boundary (everything this run changed — staged, unstaged
   AND untracked), and the instruction to return its ledger section verbatim.
2. Append the returned `## Seeker Inspection` section to the spec file,
   word for word. You never edit its rows.
3. Record it: `vendor/bin/droost-workflow seeker-report < <(the section)` —
   or pipe the whole spec file; the engine parses the LAST section. The
   parse is the record: counts come from the ledger text, never from a
   summary of it.
4. Open CRITICAL or open MEDIUM findings hold the run. Fix what they name,
   re-dispatch the seeker (a fresh section, appended), re-report. `resolved`
   rows and `carried: <reason>` MEDIUMs release; LOW never blocks.

The checkpoint holds again at complete — and if you edited anything since the
last inspection, re-dispatch before completing rather than riding a stale
clean.

## How the engine drives this

Every surface — `vendor/bin/droost-workflow run`, `drush droost:workflow:run`,
or the MCP run tool — drives one engine, and the engine writes run state to
`.droost-workflow/run.json` on every invocation. One phase per invocation, so
the loop is:

1. `/droost:workflow:status` — see the levers and where the run stands.
2. Do the current phase's work, per its `workflow-<phase>` skill.
3. Invoke the run surface — the engine executes the gates due at this phase,
   records the report into run state, and advances, holds for inspection,
   pauses (pair mode), or fails.
4. Repeat until `complete`.

**Resume exists.** Re-invoking picks the run up exactly where run.json says
it is — a fresh session recovers a run's position by reading the engine's
record, never by remembering.

**The levers are frozen into the run when it begins** — `resolved_gates`,
the phase map, the enforcement level and the seeker switch, all in run.json.
A mid-run edit to `droost.workflow.yml` does not retarget a run in flight.

**Retries are engine-counted.** Each blocking gate spends one attempt per
failing invocation (`feedback_attempts` against `max_gate_retries`); when the
budget is spent the phase is recorded failed and the run refuses to continue.
That is a legitimate outcome. The inspection hold is NOT a failing gate — it
spends no budget. **Abandoning a run is a deliberate act**: remove
`.droost-workflow/run.json`, which discards the record — there is no quiet
way out, on purpose.

**Enforcement is live while a run is.** With an active run, the repo's hooks
hold the phase discipline: editing project files during plan is blocked
(hard) or warned about (soft), and ending the turn mid-phase is challenged
once. Outside a run the hooks are silent — they read run.json first and no
run means no opinion.

## Which gates run when

WHEN a gate runs is the engine's phase map. WHETHER the optional tiers run,
and with what thresholds, is the lever file's business:

| phase | gates due |
|---|---|
| plan | none — the spec is the gate |
| code | phpcs, phpstan, plus custom gates placed at `code`; then the seeker checkpoint |
| test | phpunit, mutation, playwright, coverage, rendered_check, plus custom gates placed at `test` |
| complete | documentation first, then the full enabled set re-run — the terminal safety net — behind a clean inspection |

Custom gates are the repo's own commands (`gates.custom` in the lever file —
semgrep, behat, anything); exit zero passes, a missing tool reports
tool-missing and blocks, never passes.

## Before you start

Load the `workflow-<phase>` skill for the phase you are about to work, and
read `.claude/partials/droost-usage.md` — particularly the part about which
tools need a booted site, since that determines what this run can honestly
check.

Sub-agents exist for the heavy lifting: `workflow-researcher` grounds the
plan in the real site or repo, `workflow-spec-writer` drafts the spec at
either weight, `workflow-seeker` is the adversarial reviewer the checkpoint
waits on, `workflow-bug-fixer` takes exactly one failing gate finding at a
time during test's feedback loop, and `droost-debugger` flips xdebug on when
a failure needs a debugger — and off again after, because a debugger left on
wrecks the very gates this pipeline runs.

## The rules that apply to every phase

1. **The levers are not yours to reinterpret.** Which gates run and what
   counts as passing were frozen into the run when it began. Apply them; do
   not re-derive them, and do not soften one because it is inconvenient.
2. **A phase that fails is failed.** The engine refuses to advance past it,
   and once the retry budget is spent it refuses to re-run it at all.
3. **Report what could not be checked.** Every phase's skill has a "Without a
   site" section; it is not optional prose.

## When the run finishes

The `complete` phase captures what was built, then presents the diff and the
full gate report — including every gate that was skipped and why, the seeker
ledger, and which browser tier verified the work. That report is the run's
product just as much as the code is. In a light run, the spec and the change
summary are presented in chat; in a factory run they are recorded artifacts.
