---
title: Droost Work — the pipeline entry point
purpose: Start a workflow run, or advance the one already in progress, through plan, code, test, document and complete.
---

Start a run, or advance the one in progress. This is the single entry point
to the work pipeline — `/workflow:status` inspects, `/droost-work` acts.

## What this does

Reads `droost.workflow.yml` for the levers and works the phases **in the one
order that exists**: `plan → code → test → document → complete`. Every run
walks all of them — minor changes included. What varies between a heavy and a
light run is the weight each phase carries, never the path.

## The two weights

The preset decides the artifact weight (and the file can override any lever):

- **factory** — the full spec: EARS acceptance criteria, written to
  `.droost-workflow/spec-<slug>.md`. Gates: everything on. Enforcement
  defaults hard.
- **light** — a quasi-spec: what was asked, what will change, how we'll know
  — ten lines or so, written to `.droost-workflow/tmp-spec-<slug>.md`, and
  presented back in chat at the document phase. Gates: the static pair plus
  the rendered check. Enforcement defaults soft.

Either way the spec file exists BEFORE code does; the document phase presents
the realized plan against it.

## How the engine drives this

Every surface — `vendor/bin/droost-workflow run`, `drush droost:workflow:run`,
or the MCP run tool — drives one engine, and the engine writes run state to
`.droost-workflow/run.json` on every invocation. One phase per invocation, so
the loop is:

1. `/workflow:status` — see the levers and where the run stands.
2. Do the current phase's work, per its `workflow-<phase>` skill.
3. Invoke the run surface — the engine executes the gates due at this phase,
   records the report into run state, and advances, pauses (pair mode), or
   fails.
4. Repeat until `complete`.

**Resume exists.** Re-invoking picks the run up exactly where run.json says
it is — a fresh session recovers a run's position by reading the engine's
record, never by remembering.

**The levers are frozen into the run when it begins** — `resolved_gates`,
the phase map and the enforcement level, all in run.json. A mid-run edit to
`droost.workflow.yml` does not retarget a run in flight.

**Retries are engine-counted.** Each blocking gate spends one attempt per
failing invocation (`feedback_attempts` against `max_gate_retries`); when the
budget is spent the phase is recorded failed and the run refuses to continue.
That is a legitimate outcome. **Abandoning a run is a deliberate act**:
remove `.droost-workflow/run.json`, which discards the record — there is no
quiet way out, on purpose.

**Enforcement is live while a run is.** With an active run, the repo's hooks
hold the phase discipline: editing project files during plan is blocked
(hard) or warned about (soft), and ending the turn mid-phase is challenged
once. Outside a run the hooks are silent — they read run.json first and no
run means no opinion.

## Which gates run when

WHEN a gate runs is the engine's phase map. WHETHER it runs, and with what
thresholds, is the lever file's business:

| phase | gates due |
|---|---|
| plan | none — the spec is the gate |
| code | phpcs, phpstan, plus custom gates placed at `code` |
| test | phpunit, mutation, playwright, coverage, rendered_check, plus custom gates placed at `test` |
| document | wiki_fresh |
| complete | the full enabled set, re-run — the terminal safety net |

Custom gates are the repo's own commands (`gates.custom` in the lever file —
semgrep, behat, anything); exit zero passes, a missing tool reports
tool-missing and blocks, never passes.

## Before you start

Load the `workflow-<phase>` skill for the phase you are about to work, and
read `.claude/partials/droost-usage.md` — particularly the part about which
tools need a booted site, since that determines what this run can honestly
check.

Sub-agents exist for the heavy lifting: `workflow-researcher` grounds the
plan in the real site or repo, `workflow-spec-writer` drafts the spec or
quasi-spec for review, and `workflow-bug-fixer` takes exactly one failing
gate finding at a time during test's feedback loop.

## The rules that apply to every phase

1. **The levers are not yours to reinterpret.** Which gates run and what
   counts as passing were frozen into the run when it began. Apply them; do
   not re-derive them, and do not soften one because it is inconvenient.
2. **A phase that fails is failed.** The engine refuses to advance past it,
   and once the retry budget is spent it refuses to re-run it at all.
3. **Report what could not be checked.** Every phase's skill has a "Without a
   site" section; it is not optional prose.

## When the run finishes

The `complete` phase presents the diff and the full gate report — including
every gate that was skipped and why. That report is the run's product just as
much as the code is. In a light run, the quasi-spec and the change summary
are presented in chat; in a factory run they are recorded artifacts.
