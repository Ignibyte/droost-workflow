---
title: Droost Workflow — continue
purpose: Advance the workflow run in progress — including after a stop or in a fresh session — through plan, code, test and complete.
---

Advance the run **in progress** — one phase per invocation, and the command
you re-run after a stop or in a brand-new session. The three verbs:
`/droost:workflow:start` opens a run, `/droost:workflow:continue` advances it,
`/droost:workflow:status` inspects without changing anything. (Until 0.4 a
single `/droost-work` did both start and advance; the honest split is start
vs continue.)

## First, make sure there is a run to continue

If `.droost-workflow/run.json` does not exist, there is nothing to advance —
begin with `/droost:workflow:start`, which writes the spec, opens the run and
declares the browser tier. Everything below assumes an open run.

## What this does

Reads `droost.workflow.yml` for the levers and works the phases **in the one
order that exists**: `plan → code → test → complete`. Every run walks all of
them — minor changes included. What varies between a heavy and a light run is
the weight each phase carries, never the path. (0.4 folded the old document
phase into complete: capturing what was built is the first half of presenting
it.)

## Browser tier: declared at start, re-declarable here

`/droost:workflow:start` records the browser tier once the run exists. If a
new session picks the run up on a machine with a different capability,
re-declare it before the test phase — run.json already exists, so it applies:

```
vendor/bin/droost-workflow declare-browser playwright-mcp   # or: native | none
```

`playwright-mcp` = you can drive a browser; `native` = the editor gives you
one; `none` = no browser this session (the rendered check is the floor and
always runs — `none` is not a failure). The test phase branches on this and
the final report says which tier actually ran.

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

**If you cannot dispatch the seeker, the checkpoint still happens.** Some
sessions cannot spawn subagents — a host that offers no subagent surface, or
an operator instruction against using one. That is a constraint on HOW the
inspection runs, never on WHETHER it runs, and skipping it because the
preferred mechanism is unavailable is the failure this checkpoint exists to
prevent. In that case:

1. Tell the operator you cannot dispatch it, and why. Offer the choice — they
   may simply permit it.
2. If it stays unavailable, do the adversarial pass YOURSELF against the same
   diff, and hold yourself to the seeker agent's brief rather than a lighter
   one: look for dead new code, drift from the spec's acceptance criteria,
   coupling the change breaks, weak tests, and security smells in the changed
   code.
3. Record it in the same ledger format — and write the words
   **self-reviewed** in the section itself, so the run's permanent record
   shows the inspection was not independent. The engine reads that word back
   into run state and the report prints it, so this is not a formality: it is
   the only thing separating "a reviewer cleared this" from "the author
   cleared their own work" for whoever reads the record later. Explain the
   substitution in your own words as well — a live round named the agent, what
   it did instead, and why it said so, which is better than the label alone —
   but include the word, because prose the parser cannot read leaves the
   record claiming an independence the run did not have. A self-review is
   worth less than an independent one and the record must say so; what it is
   worth is more than nothing, which is what skipping yields.

Before concluding a subagent is unavailable, check rather than assume: the
`workflow-seeker` agent ships with the pack and is installed in
`.claude/agents/`. Three consecutive live rounds declined to dispatch it on
the belief that their session forbade agents, in a project whose settings
restricted nothing.

A live run met exactly this and handled it well: it stopped, said the
pipeline wanted the subagent while its session guidance discouraged one, and
offered the operator both paths with the weaker one honestly labelled. This
paragraph exists so that behaviour is the documented contract rather than one
agent's good judgment.

## How the engine drives this

Every surface — `vendor/bin/droost-workflow run`, `drush droost:workflow:run`,
or the MCP run tool — drives one engine, and the engine writes run state to
`.droost-workflow/run.json` on every invocation.

**If this project has a working site, advance through the SITE-BACKED
surface** — `drush droost:workflow:run` or the MCP run tool — not
`vendor/bin/droost-workflow`. They are not equivalent: the standalone binary
has no booted site, so every site-dependent gate (today, `rendered_check`)
comes back skipped with its reason, however healthy the site is. A live run
advanced its test phase through the binary while the site was up and being
driven by a browser, and the gate recorded "skipped-no-site"; it only ran
because the next phase happened to use a different surface. Choosing the
binary where a site exists means choosing not to run a gate the run has
configured. Use the binary when there is genuinely no site — that is what it
is for, and it says exactly which checks it could not perform. One phase per invocation, so
the loop is:

1. `/droost:workflow:status` — see the levers and where the run stands.
2. Do the current phase's work, per its `workflow-<phase>` skill.
3. Invoke the run surface — the engine executes the gates due at this phase,
   records the report into run state, and advances, holds for inspection,
   holds to converse (interactive mode), or fails.
4. If you declared a task surface at start, mark this phase's task completed
   and the next one in progress.
5. Repeat until `complete`.

**Interactive mode holds to CONVERSE, not to collect a yes.** When the
engine pauses, run.json's `awaiting` block carries the question, a headline
naming what the phase produced, `detail` lines the operator needs in order to
answer, and `options` — the answers worth offering. Present it properly:

- If your host has a structured-question surface (Claude Code's
  `AskUserQuestion`, or the equivalent), ask with it, using the recorded
  `options` as the choices.
- Otherwise print the headline, the detail, and the options, and take a
  sentence back.
- **Add what only you know.** The engine can speak to the phase and its
  gates; it cannot speak to what grounding turned up, which trade-off you
  took, or what you would recommend. Say those, and say which option you
  recommend and why. A hold where you relay the question and nothing else
  wastes the operator's turn.
- Then record the answer — `answer "<what they said>"` — which releases the
  pause and advances the run. Do not advance a paused run any other way.

`agentic` mode never holds. An operator can switch to it mid-run at any hold
(`swap agentic`), which also releases the current pause — that is what it is
for. The reverse is not supported: a run that started without stopping is
not interrupted into conversation.

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
spends no budget. **Abandoning a run is a deliberate act**: ask the operator
to run `drush droost:workflow:reset --force` (or
`vendor/bin/droost-workflow reset --force`), which archives the record to
`.droost-workflow/history/` — there is no quiet way out, on purpose, and the
record is never discarded.

**Enforcement is live while a run is.** With an active run, the repo's hooks
hold the phase discipline: editing project files during plan is blocked
(hard) or warned about (soft), and ending the turn mid-phase is challenged
once. Outside a run the phase hooks have no opinion — but `require_run` still
stands: a custom-code edit (`modules/custom`, `themes/custom`) with no ACTIVE
run is blocked (hard, the default) or nudged (soft) until a run starts or the
operator grants a bypass. A finished run counts as no active run.

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

The finished record persists as `.droost-workflow/run.json` until it is
cleared — `drush droost:workflow:reset` archives it to
`.droost-workflow/history/` — and the next run cannot start over it, so
finishing a ticket ends with the reset pointer, not just the report.
