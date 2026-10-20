---
name: workflow-continue
description: Advance the Droost workflow run in progress — including after a stop or in a fresh session — through plan, code, test and complete. Claude Code also offers it as /droost:workflow:continue.
---

Advance the run **in progress** — one phase per invocation, and the skill you
re-load after a stop or in a brand-new session. The three verbs are skills,
each with a Claude Code slash command pointing at it: `workflow-start`
(`/droost:workflow:start`) opens a run, `workflow-continue`
(`/droost:workflow:continue`) advances it, `workflow-status`
(`/droost:workflow:status`) inspects without changing anything.

## First, make sure there is a run to continue

If `droost/droost-workflow/run.json` does not exist, there is nothing to advance —
begin with `/droost:workflow:start`, which writes the spec, opens the run and
declares the browser tier. Everything below assumes an open run.

## What this does

Reads `droost/droost.yml` for the levers and works the phases **in the one
order that exists**: `plan → code → test → complete`. Every run walks all of
them — minor changes included. What varies between the top and the bottom of
the effort dial is the weight each phase carries, never the path. (0.4 folded the old document
phase into complete: capturing what was built is the first half of presenting
it.)

## Browser tier: declared at start, re-declarable here

`/droost:workflow:start` records the browser tier once the run exists. If a
new session picks the run up on a machine with a different capability,
re-declare it before the test phase — run.json already exists, so it applies:

```
droost workflow:declare-browser playwright-mcp   # or: native | none
```

`playwright-mcp` = you can drive a browser; `native` = the editor gives you
one; `none` = no browser this session (criteria marked `browser` are then
checked by fetching the page, and the report says no browser ran — `none` is
not a failure). The test phase branches on this and
the final report says which tier actually ran.

## The dial, and its two weights

The preset is one graded dial for how much the run VERIFIES —
`low | medium | high | xhigh | max` (and `custom`, the spelled-out baseline).
Read the frozen, canonical name from run.json (`factory` records as `max`,
`light` as `medium`). The dial decides the gates and thresholds (engine-side)
and the artefact weight (your side); the file can override any lever. Two
things never move with it: a spec always exists, and droost's knowledge —
the wiki, the code tree, search — is always consulted. Five points, two weights:

- **Full weight — `high`, `xhigh`, `max`, `custom`** — the full EARS spec,
  written to `droost/droost-workflow/spec-<slug>.md`; the capture is recorded
  in the wiki at complete. Enforcement defaults hard from `high` up. At `max`
  the functional suites are REQUIRED to exist — a missing phpunit or
  playwright suite fails the gate rather than passing labelled-empty.
- **Light weight — `medium`, `low`** — a SHORTER spec in the same EARS shape:
  what was asked, what will change, a handful of "When <trigger>, the
  <system> shall <response>" criteria, AND the `## Grounding` and `## Routes`
  sections expected at every weight — written to
  `droost/droost-workflow/tmp-spec-<slug>.md` and presented back in chat at
  complete. Depth trims, never format: one spec shape everywhere is what the
  seeker grades against, and the grounding discipline is never what a lighter
  weight drops. At `low` there is additionally NO wiki: `wiki_fresh`
  is off and the complete phase writes no wiki pages; phpunit is off BY
  PRESET (the test phase is left with phpcs and phpstan over whatever it
  wrote, and the criteria code checked) and reports `off`, never `passed` — that is
  the level's declared trade, not a green suite.

**You cannot move the dial — propose it.** `droost workflow:effort <level>`
is the operator's command: the guard refuses it from your shell, and
editing `droost/droost.yml` yourself is the defeat the seeker's lens 7
names. When the level does not fit the work — a change that deserves tests at
`low`, a docs-only tweak held to `max` — say so and hand the operator the
exact command with your reason. Ground the proposal first with
`droost workflow:effort <level> --preview`, which is read-only and
allowed: it prints the bill — every gate, threshold, the seeker, enforcement
and the retry bound the move would change for the NEXT run. The run in
progress stays frozen at its level either way.

Either way the spec file exists BEFORE code does; complete presents the
realized plan against it.

## The mandatory trio

`phpcs` and `phpstan` run on every run at every level — they are the toolchain
Drupal core itself develops with, and the LEVER FILE tunes HOW they run
(standard, level, paths), never whether. `phpunit` joins them everywhere except
`low`, which turns it off as that level's declared trade. At `preset: custom` the
lever file owns the trio as it owns every other gate: the operator may turn one
off, put it in report mode, or waive it, and the record says so (off, reported,
waived), never passed. Moving the dial or editing the file is the operator's
act, not the agent's, and nothing a run does can turn a gate back on. A repo that
cannot run one of them yet gets an honest answer instead of a pass: tool
missing, config missing, or a labeled "nothing to analyse / no tests yet".

## The seeker checkpoint

When the code phase's gates pass, the engine holds the run at
`inspection-due` — gates verify rules; the seeker verifies judgment. The loop:

1. Dispatch the **workflow-seeker** agent (subagent tool) with: the spec
   path, the diff boundary (everything this run changed — staged, unstaged
   AND untracked), and the instruction to return its ledger section verbatim.
   Wait for it to RETURN: a seeker launched in the background is recorded
   as launched, and its return (its completion notification) is what a
   ledger is filed on. If your host does not report a background subagent's
   return, dispatch the seeker in the foreground.
2. Append the returned `## Seeker Inspection` section to the spec file,
   word for word. You never edit its rows: an independent ledger whose rows
   differ from what the seeker returned is refused. A status you change
   yourself goes in a section of your own, `Inspector: self-reviewed`.
3. Record it: `droost workflow:seeker-report < <file>` — pipe the section,
   or the whole spec file; the engine parses the LAST section. The
   parse is the record: counts come from the ledger text, never from a
   summary of it.
4. Which open findings hold the run is the lever `seekers.holds`
   (`critical` from `high` up, `none` below and at `custom` unless the file
   says `critical`, `high` or `medium`); read the `outcome`, not the level. Fix what
   they name, re-dispatch the seeker (a fresh section, appended), re-report.
   `resolved` and `carried: <reason>` rows release; LOW never blocks. The
   scale is CRITICAL, HIGH, MEDIUM, LOW.
   A ledger you write yourself is labelled `Inspector: self-reviewed`: one
   labelled independent with no seeker returned since the last is refused,
   and a self-review counts as no inspection and releases no held finding.

With `seekers.at_complete: true` the checkpoint holds again at complete, and
if you edited anything since the last inspection, re-dispatch before
completing rather than riding a stale clean either way.

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
3. Record it in the same ledger format — opening with the line
   `Inspector: self-reviewed`. The engine REFUSES a ledger that does not say
   who inspected, and refuses `Inspector: independent` standing next to
   self-review prose, so the declaration is not a formality: it is the only
   thing separating "a reviewer cleared this" from "the author cleared their
   own work" for whoever reads the record later. Explain the substitution in
   your own words as well — a live round named the agent, what it did
   instead, and why it said so, which is better than the line alone. A
   self-review is worth less than an independent one and the record must say
   so; what it is worth is more than nothing, which is what skipping
   yields.

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

`droost workflow:run` drives the engine, and the engine writes run state to
`droost/droost-workflow/run.json` on every invocation. It boots no Drupal: a
gate that needs the running site is a project's own gate file, which asks
the site itself and says when it could not. One phase per invocation, so
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

**A human-led run is finished by a human.** `status` shows the run's `lead`.
In a `human-led` run you may answer the plan, code and test holds when the
operator has told you to, and the complete hold is theirs: it holds even in
agentic mode. Ask them with AskUserQuestion, using the hold's `question`
word for word (an answer to any other question is not taken) and its
`options` as the choices; fill in only `questions`, since a question sent
with its answers already in it is refused. When they have answered, run `answer` again: droost takes
THEIR answer, as the guard recorded it from the dialog, never your words.
With no answer of theirs on record your `answer` is refused and records
nothing; they can also type `droost workflow:answer "…"` in a terminal
window of their own. An `autonomous` run is yours to finish. Every answer
records whether it came from you, from their answer to your question, or
from their terminal.

`agentic` mode never holds (but for a human-led run's complete). An operator can switch to it mid-run at any hold
(`swap agentic`), which also releases the current pause — that is what it is
for. The reverse is not supported: a run that started without stopping is
not interrupted into conversation.

**Resume exists.** Re-invoking picks the run up exactly where run.json says
it is — a fresh session recovers a run's position by reading the engine's
record, never by remembering.

**The levers are frozen into the run when it begins** — `resolved_gates`,
the phase map, the enforcement level and the seeker switch, all in run.json.
A mid-run edit to `droost/droost.yml` does not retarget a run in flight, and
the guard refuses one: the levers and the project's gate, hook and wall files
are the rules the run is held to.

**Retries are engine-counted.** Each blocking gate spends one attempt per
failing invocation (`feedback_attempts` against `max_gate_retries`); when the
budget is spent the phase is recorded failed and the run refuses to continue.
That is a legitimate outcome. The inspection hold is NOT a failing gate — it
spends no budget. **In the fast flow a failure at test goes back to code
instead** (`outcome: returned`, within `max_loops`), and once the loops are
spent, or for a failure outside the ticket, it becomes a follow-up ticket and
the phase is recorded `deferred` (`outcome: deferred`). Read `returned` as
"work to do at code", never as a failed run. **Abandoning a run is a deliberate act**: ask the operator
to run `droost workflow:reset --force`, which archives the record to
`droost/droost-workflow/history/` — there is no quiet way out, on purpose, and the
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

| phase | fast flow (`flow: fast`, the default at `low` and `medium`) | strict flow (`flow: strict`, the default from `high` up) |
|---|---|---|
| plan | no gate; one check, `plan_grounded`: the spec's `## Grounding` cites what resolves, and the run asked droost's knowledge during plan | the same |
| code | phpcs, phpstan, eslint, stylelint, prettier, phpunit, mutation, coverage, house_rules, plus the project's gates placed at `code`; then the seeker checkpoint, where the level runs one | phpcs, phpstan, eslint, stylelint, prettier, house_rules, plus the project's gates placed at `code`; then the seeker checkpoint |
| test | playwright, parity, house_rules, plus the project's gates placed at `test` | the static pair again, phpunit, mutation, playwright, parity, coverage, plus the project's gates placed at `test` |
| complete | documentation, then `wiki_fresh` only | documentation first, then the full enabled set re-run, the terminal safety net |

The run's own table is `phase_gates` in `droost workflow:status`: it is
frozen when the run begins, and it is what the engine dispatches from.

The project's own gates are files or commands declared in `droost/droost.yml`
(`gates.<id>` with `run: droost/gates/<file>` or `cmd:` — semgrep, behat,
anything). Each runs as its own process: exit 0 passes, 1 fails, and anything
else — a missing file or tool, a crash, a timeout — is recorded as did not
run, never as passed.

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

## Inherited debt: the adoption baseline

A legacy project carries findings that predate every run. When the operator
has written a baseline (`droost/baseline/`, from their own terminal with
`droost workflow:baseline`), the consulting gates — phpcs, phpstan,
eslint, stylelint, prettier, coverage, mutation — judge only
what the baseline does NOT record. Read their lines as written: `passed — 0
new, 123 inherited` is the baseline working, and the 123 are not this run's
to fix unless the spec says so; `FAILED — 2 new error(s) the baseline does not
record` is yours — the change added them.

Three rules:

- **Never touch `droost/baseline/`.** The run froze its hash at begin; a
  change there fails every consulting gate with "the baseline changed during
  the run", and the seeker names it a defeat. The guard refuses the edit
  anyway.
- **You cannot write or refresh the baseline — propose it.** When gates fail
  on debt the change never touched and the project has no baseline, ground
  the proposal with `droost workflow:baseline --measure` (read-only: the
  bill per gate) and hand the operator `droost workflow:baseline` with your
  reason. Paid-off debt is theirs to drop with `--refresh`; growth
  needs their `--grow --reason`.
- **Coverage and mutation ratchet.** Under the level's target but at or above
  the inherited floor passes, and the line names the target. Below the floor
  fails: the change lost coverage the project already had.

## The project's own gates, hooks and walls

`droost/droost.yml` may name files of the project's own, in `droost/`:

- **gates** (`gates.<id>` with `run: droost/gates/<file>` or `cmd:`): a check
  at the phases it names. Its line carries its mode: in `block` mode a
  failure holds the phase; in `report` mode it is recorded, the phase
  advances, and it is still a finding to weigh in your summary. A gate that
  could not run (exit 3, a crash, a timeout, a missing tool) is never a pass.
- **walls** (`walls.<id>` with the tools they judge): asked before a tool
  call, and they may refuse it with a reason. A refusal is the project's
  rule, not a broken tool: read the reason, and when it names an operator
  step (arming tracker writes, say), hand the operator that step.
- **hooks** (`hooks.<event>`): told when the run starts, a phase begins or
  ends, and the run completes. They never hold anything.

The files were frozen when the run began, and the guard refuses an edit to
them, to `droost/droost.yml` or to `droost/agents.md` while the run is open.
Whether a gate blocks is the site's decision, not yours: propose a mode
change to the operator when the work calls for it.

## When the run finishes

The `complete` phase captures what was built, then presents the diff and the
full gate report — including every gate that was skipped and why, the seeker
ledger, and which browser tier verified the work. That report is the run's
product just as much as the code is. At `medium` and `low` the spec and the
change summary are presented in chat; from `high` up they are recorded
artifacts, and the wiki is written (at `low` it is not — `wiki_fresh` is off).

A COMPLETED run is archived the moment it completes: its record, ledgers and
spec go to `droost/droost-workflow/history/`, `status` and `evidence` still
read it there, and the next ticket's `run` starts with no reset. A FAILED
run stays as `run.json` until `droost workflow:reset` archives it.
