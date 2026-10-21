---
name: workflow-test
description: Phase 3 of the Droost Workflow. Run the configured gates and report what actually happened — including which gates could not run and why.
---

# Test

The phase the levers exist for. Everything before this produced claims;
this phase produces evidence.

**Artifacts are truth.** What the agent believes it built is not evidence.
What the gates report about the artefact is.

## Entry gate

- The code phase passed.
- You know which gates this run is held to — the resolved set was frozen
  into `droost/droost-workflow/run.json` (`resolved_gates`) when the run began,
  and survives any restart. The engine reads it from there; so should you.

## Work

Run the gates the run is configured for. **You do not decide what passes.**
Thresholds, which gates are on, how many retries a failure gets — all of that
was resolved before this phase began. Your job is to run them and report,
not to re-derive a verdict.

**What runs here depends on the run's flow** (`phase_gates` in
`droost workflow:status`):

- **fast** (`flow: fast`, the default at `low` and `medium`): this phase checks the build in a browser.
  Look at the pages by hand (your browser), write or update the ticket's
  specs, and run them with the Playwright CLI. The engine runs playwright,
  parity, the house rules and the project's own gates placed at `test`. The analysers and the unit
  tests ran at code and do not run again here, and complete runs no gate.

  **A failure here sends the run back to code** (`outcome: returned`): fix
  it there, and `run` code again. Code's gates measure the fix, and this phase
  follows. `max_loops` bounds it (low 2, medium 3), and the envelope's `loop`
  block says how many are left. A failure that is outside the ticket (a
  full-scope suite whose every failure is in a spec this run did not change),
  or one still failing when the budget is spent, becomes a **follow-up
  ticket** and the phase is recorded `deferred`, never passed
  (`outcome: deferred`). At `max`, or with `follow_ups: none`, a spent budget
  fails the phase instead. A tool that could not run (missing, crashed, timed
  out) is not code to fix, and keeps the in-place retry below.

  **Fixing PHP or CSS here also sends the run back.** If a file code's gates
  measured has changed when this phase's `run` begins (a fix you made while
  testing by hand), the run goes back to code before any browser runs, and
  this return spends no loop. Specs you write here, markdown and `droost/`
  are this phase's own and do not.

  **At `scope: ticket` (the default at `low` and `medium`) the playwright
  gate runs the spec files this run added or changed, and nothing else.** The
  full suite runs only where `gates.playwright.scope: full` says so. So the
  ticket's behaviour must be proved by a spec this run wrote or updated: a
  required gate with no changed spec FAILS, whatever the rest of the suite
  would say. The row names its scope ("ticket scope: 2 file(s) this run
  changed, not the full suite"), so nobody reads it as a regression pass.

  **At `low`, run your specs with `droost workflow:specs`** (inside the
  container on DDEV, where the gate runs). It runs the ticket's specs exactly
  as the playwright gate does and records the result against the tree. When
  `run` follows and nothing has changed since, the gate takes that record
  instead of running the suite a second time, and its row says so ("recorded
  at …, on this tree: not run again"). Any edit after it, to a spec or the
  code, and the gate runs them itself. A plain `npx playwright test` is
  yours, and is not recorded.
- **strict** (`flow: strict`, the default from `high` up): the engine runs the functional gates here:
  phpunit, coverage, mutation and playwright. **The static
  pair runs here too, over the tests you write in this phase**, and before
  phpunit: a test method named in lowerCamel, or a fixture phpstan cannot
  follow, fails phpcs or phpstan here rather than surfacing at complete.
  Write tests to the same standard as the code they test. Everything enabled
  re-runs at complete.

**Your declaration is audited here too, and again at complete.** A file you
change in this phase that the declaration does not cover blocks, as it would
at code: a fix made because a test failed is still building. Re-declare with
the whole list if the work genuinely grew, and say why in the spec.

**From `medium` up, a class you wrote needs a phpunit test.** When phpunit
carries `in_diff` (every level from `medium`), a run that changed a file under
a module's `src/` must also change a phpunit test (`tests/…/*Test.php`), or
the `tests_in_diff` check blocks this phase. A Playwright spec proves a
criterion; it does not test the class. A deploy hook or an `.install` file is
not held to this, and a test the declaration does not cover blocks like any
other file, so declare it.

**A test a generator wrote does not count until you change it.** A file
`drush generate` (or `dcg`) wrote is recorded as generated, and a kernel-test
skeleton checks nothing yet, so a test file still exactly as the generator
wrote it is set aside by name. Make it exercise the class, or write your own.

When something fails, read what the site actually said (`drush
watchdog:show`, the fatal drush prints) rather than guessing from an exit
code.

## The browser check is a committed spec, at every level

**`playwright` is ON at every preset with `required: true`.** Write a real
spec under the repo's playwright suite, run it, and commit it. That file is
the verification AND the artefact: it re-runs on every later ticket, where a
browser tool call proves nothing the moment the session ends.

```bash
node_modules/.bin/playwright test                   # what the gate runs
node_modules/.bin/playwright test tests/e2e/x.spec.ts   # while you iterate
```

**Run it where the gate runs it.** The gate runs in the process that runs
the phase. On DDEV the phase runs inside the container (`ddev exec
tools/droost/vendor/bin/droost workflow:run`), so the gate runs the suite there, and so should you: `ddev
exec node_modules/.bin/playwright test tests/e2e/x.spec.ts`. From the host, every `ddev drush` a spec makes costs
seconds where it costs a fraction of one inside, and a suite the gate passes
times out; two runs spent most of a test phase chasing exactly those
timeouts. The command each gate ran is in the invocations table of the
evaluation `droost workflow:evidence` prints.

**An empty suite is a FAILURE, not a labelled pass** — that is what
`required: true` means. If playwright is not installed the gate REPORTS
rather than blocks, and says how to install it; it is the one gate whose
missing binary does not stop the run, because it is due everywhere and
wedging every project without a node toolchain would be worse than the gap.

**Write the spec directly. Do not drive the browser and then reconstruct it.**
Recording your actions captures clicks, never assertions — and the assertions
are the test. Half of a typical ticket is negative cases ("the save is
refused and names the field") and absence cases ("no empty block renders"),
and neither can be recorded, only written. You already know what you are
asserting; that knowledge is exactly what a record-and-recreate round trip
throws away.

**Verify through the browser tier the run declared** (`browser` in
`droost/droost-workflow/run.json`, recorded at run start):

- `playwright-mcp` — available for **exploration**, and no longer what you
  are held to. Snapshot a page to learn its real selectors and error text
  before you write a spec against them. The guard records every call and the
  evaluation reports the count, but nothing gates on it.
- `native` — the same, through the editor's own browser.
- `none` — fine. The spec below does not need a browser of yours.

**Code checked each criterion; test is the sign-off.** Code worked through
the acceptance criteria and recorded what showed each (`droost workflow:criteria` lists
them, with when). Here the suite runs once, as the official
check, and you do not repeat code's checks. A criterion whose test fails here
goes back to code by name: fix it there, check it again, record it again.

A criterion code never checked is the seeker's HIGH finding. If you prove one
here, record it the same way:

```bash
droost workflow:verify-criterion AC-2 "manual — checked at 390px"
```

`manual` is a legitimate answer and is reported as manual, never as passed —
a human looked is a different claim from a test proves. What you name is
recorded, not judged here: the seeker judges whether it shows the criterion
met. A ref nobody declared at plan is refused, because a criterion written
after the fact to fit what passed is the one thing this record cannot
survive.

When a gate fails, enter a bounded feedback loop: read the finding, fix the
cause, invoke `run` again. **The engine counts the attempts** — each blocking
gate spends budget per failing invocation, recorded in run state as
`feedback_attempts` against `max_gate_retries` — and when the budget is
spent it records the phase failed and refuses to continue. That is a
legitimate outcome, and worth more than a success the run cannot support.

When a gate is failing on something that is not this diff's to fix — a
pre-existing project condition, a policy the project has deliberately not
adopted — that is the OPERATOR's call, and there is a scoped mechanism for
it: `droost workflow:gate-waive <gate> "<reason>"` waives ONE gate
for the rest of this run, recorded with its reason and rendered as
"waived", never as a pass. Ask the operator to run it; it is deliberately
CLI-only, so you cannot grant it yourself. Do NOT reach for
`droost workflow:bypass` here — that drops the whole require_run wall,
which is a different and much larger decision (two live rounds made
exactly that mistake).

## Parity: the site against the source it rebuilds

When a ticket rebuilds a source (a design, or a site being replaced), the
`parity` gate holds each page to a reference read from that source. With no
reference in `droost/parity/` it passes labelled, having compared nothing.

```bash
tools/droost/vendor/bin/droost-parity judge                             # every route, as the reference holds it
tools/droost/vendor/bin/droost-parity judge --scope frame --pages /       # the frame everywhere, / whole
```

Run it with the scope and pages the gate's levers name (`status` shows them
under the gate). The reference in `droost/parity/` is the operator's,
captured from the source before the run, and it is frozen with the run: the
gate fails a reference that has changed since the run began, so match the
site to it and never the other way round. A reference captured at more than
one width is judged at each (`/@390` is the home page at 390 pixels), which
is how a menu that folds is held to the source's. A text the client decided
the site will not show is named, with its decision, in
`droost/parity/decided.json`; the gate leaves it out and says so. That file is
part of the reference, frozen with it: a text you find set aside wrongly, or
one that should be, is the operator's to change before a run, never yours
during one. A capture refuses a source whose declared typeface is
never drawn; a reference is only ever read in the faces the source shows. A view fails on the first
of D1 to D7 it misses, and the finding says which elements. The reference
says where it was read (`read_on` in its manifest): a page lays out
differently in another platform's browser, so it is judged only on the
platform it was read on, and anywhere else the gate reads INVALID with
nothing judged. Run droost where the reference was read (on DDEV, the host
when it was read on the host; in the container, `ddev exec`, when it was read
there); which one is the operator's, never yours to pick by the verdict.

## Fill `Verified By`

The spec's acceptance-criteria table has a `Verified By` column, empty since
the plan. For every row, write the test that proves it — the PHPUnit method
or class, or the Playwright spec — or `manual — <reason>` when no test can,
saying why. This is the traceability link between what was promised and what
was checked: `complete` refuses to gate while any cell is empty, and the
report prints manual as manual, never as passed. A criterion you cannot map
to a test or to an honest manual reason is a criterion this run has not met;
say so in the spec rather than leaving the cell blank.

## Exit gate

Every enabled gate has a result, and the result is one of: passed, failed,
skipped, or did not run (the tool was missing, the file crashed or timed
out, the site did not answer a gate that asks it). Every one of those is a
distinct fact and none of them is "probably fine".

## Without a site

Some gates cannot run without a booted site — any functional test suite,
and a project's own gate that asks the site (a config export check, a page
request).

**Record those as skipped, and say why. Never report them as passed.** This
is the single most important rule in the pack. A skipped gate and a passed
gate look identical in a summary that does not distinguish them, and the
difference is the whole value of running gates at all. "Fast mode" and "no
site available" must never be indistinguishable in the record.

A gate whose tool is simply missing is a different thing again: that is a
broken environment, it fails, and the report names the invocation that could
not run.
