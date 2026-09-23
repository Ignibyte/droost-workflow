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

This is the phase where the engine runs the functional gates — phpunit,
coverage, mutation, playwright and the rendered check. **The static pair runs
here too, over the tests you write in this phase**, and before phpunit: a test
method named in lowerCamel, or a fixture phpstan cannot follow, fails phpcs or
phpstan here rather than surfacing at complete. Write tests to the same
standard as the code they test. Everything enabled re-runs at complete.

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

**A test droost's scaffold wrote does not count until you change it.** The
hook blueprint's test checks that the `#[Hook]` attribute is there, and a
kernel-test skeleton checks nothing yet. Neither tests what your code does,
so a test file still exactly as the scaffold wrote it is set aside by name.
Make it exercise the class, or write your own.

`droost_verify` runs the static and test legs — **but only the ones you ask
for**, and the default is narrower than people expect:

| Call | Legs that run |
|---|---|
| no `module` and no `path` | **none at all** — it returns an inventory of what COULD run. Useful, and never a run: reporting it as one is the failure this table exists to prevent |
| a target, no `checks` | **phpcs and phpstan only** |
| `checks: [deprecations]` | deprecations, which is opt-in |
| `checks: [phpunit], confirm: true` | phpunit, which needs `confirm` because the suite creates and drops databases |

Ask for what the levers say should run, then report what actually ran. A
plain call reports "passed" having run two static checks; if you assumed
phpunit was among them you have just reported tests green that never
executed. None of the legs render a page or fetch a URL — do not describe
`droost_verify` as having checked that anything works.

`droost_last_error` and `droost_logs` tell you what the site actually said
when something failed, rather than guessing from an exit code.

## The browser check is a committed spec, at every level

**`playwright` is ON at every preset with `required: true`.** Write a real
spec under the repo's playwright suite, run it, and commit it. That file is
the verification AND the artefact: it re-runs on every later ticket, where a
browser tool call proves nothing the moment the session ends.

```bash
npx playwright test                     # the gate runs exactly this
npx playwright test tests/e2e/rinks.spec.ts   # while you iterate
```

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
- `none` — fine. The spec below does not need an MCP server.

**Record what proves each criterion**, one call per criterion, as you prove
it:

```bash
vendor/bin/droost-workflow verify-criterion AC-1 "RinkListTest::testEveryPublished"
vendor/bin/droost-workflow verify-criterion AC-2 "manual — checked at 390px"
```

`manual` is a legitimate answer and is reported as manual, never as passed —
a human looked is a different claim from a test proves. What you name is
recorded, not judged: whether the test is real is the suite's business, and
whether you declared the proof is this record's. A ref nobody declared at
plan is refused, because a criterion written after the fact to fit what
passed is the one thing this record cannot survive.

When a gate fails, enter a bounded feedback loop: read the finding, fix the
cause, invoke `run` again. **The engine counts the attempts** — each blocking
gate spends budget per failing invocation, recorded in run state as
`feedback_attempts` against `max_gate_retries` — and when the budget is
spent it records the phase failed and refuses to continue. That is a
legitimate outcome, and worth more than a success the run cannot support.

When a gate is failing on something that is not this diff's to fix — a
pre-existing project condition, a policy the project has deliberately not
adopted — that is the OPERATOR's call, and there is a scoped mechanism for
it: `drush droost:workflow:gate-waive <gate> "<reason>"` waives ONE gate
for the rest of this run, recorded with its reason and rendered as
"waived", never as a pass. Ask the operator to run it; it is deliberately
CLI-only, so you cannot grant it yourself. Do NOT reach for
`droost:workflow:bypass` here — that drops the whole require_run wall,
which is a different and much larger decision (two live rounds made
exactly that mistake).

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
skipped for lack of a site, or the tool was missing. Every one of those is a
distinct fact and none of them is "probably fine".

## Without a site

Some gates cannot run without a booted site — the rendered check most
obviously, and any functional test suite.

**Record those as skipped, and say why. Never report them as passed.** This
is the single most important rule in the pack. A skipped gate and a passed
gate look identical in a summary that does not distinguish them, and the
difference is the whole value of running gates at all. "Fast mode" and "no
site available" must never be indistinguishable in the record.

A gate whose tool is simply missing is a different thing again: that is a
broken environment, it fails, and the report names the invocation that could
not run.
