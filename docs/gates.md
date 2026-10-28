# Gates, criteria and the feedback loop

Which gate runs at which phase, how each requirement is matched to its proof, and what happens when a gate fails.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## Which gates run when

WHETHER a gate runs is the lever file's business. WHEN it runs is the
engine's phase map, frozen into each run when it begins:

```text
plan: none
code: phpcs, phpstan, eslint, stylelint, prettier, house_rules
test: phpcs, phpstan, eslint, prettier, phpunit, mutation, playwright, parity, coverage
complete: phpcs, phpstan, eslint, stylelint, prettier, phpunit, mutation, playwright, parity, coverage, house_rules, wiki_fresh
```

Plan runs no gate — there is no code yet to measure. One check holds it,
`plan_grounded`: plan waits until the run's tool-call ledger shows a `droost
code:wiki` and a `droost code:tree` call since the run began (owner,
2026-10-19), and the spec's `## Grounding` cites what the project and its
code really hold. `brief` alone is not looking, and nor is a table of `none:`
claims: at least one citation must name something that exists (a class, a
file, a wiki page; never a directory or the run's own spec), and a `none:`
claim counts only while the code index can be asked. It reads the ledger and
resolves citations from droost's code index and the files, none of which
needs Drupal. droost forces the agent to LOOK; it never says how to build
(owner, 2026-10-15: "as models get better they dont need to ask they need
you to build rules around your code"). The site's own rules are what the
work is held to: a house rule (`rules.house`) is checked on the diff by
`house_rules` when a gate judges it and by the seeker when judgment does,
and a page rule in `rules:` is read by the seeker against the owner each
declared route names. Code gates the diff with static analysis. Test runs
the functional gates, and re-runs
the static ones over the tests it has just written — a suite whose own source
would not pass code's standards is not a suite whose green anyone should
believe. Complete opens by capturing
what was built — the documentation work that was its own phase until 0.4 —
and then re-runs the full enabled set as the terminal safety net, the
project's own gates included, so every other enabled gate is met at least twice: once at
its own phase, once at the end.

That is the **strict** flow, which `high` and the levels above it run. Since
0.11 `low` and `medium` run the **fast** flow (`flow: fast | strict`, a lever
the level defaults): each gate runs once, at the phase that owns it. Code
runs the analysers and the unit tests (phpunit, mutation and coverage where
on), test runs playwright, parity and the house rules, and complete runs no
gate but `wiki_fresh` on the documentation it writes. A run
freezes its flow's table as `phase_gates` when it begins. The same two
levels default `scope: ticket` on phpcs, phpstan, phpunit and playwright: the
analysers read the files the run changed, and the suites run the test files
it added or changed, the Playwright CLI handed those specs. The files the run
declared by name (`declare-changes --files=…`) are read too, so work
committed before the run opened (a closed PR re-run under a new layer) is
in scope once declared; a declared directory widens nothing. A project's own
gate file finds them as `declared_files` beside `changed_files`. `scope: full` runs
a gate's whole set. A scoped row says so ("ticket scope: 2 file(s) this run
changed, not the full suite"), and a required suite the ticket gave no test
to run fails. At `low` the browser gate also takes `reuse: recorded`:
`droost workflow:specs`, run at test, runs the ticket's specs exactly as the
gate would and records the result against a fingerprint of the tree, and the
test phase then takes that record rather than running the suite a second
time. The row says so ("recorded at …, on this tree: not run again"). An edit
after the recording, a tool that could not run, or a level at `reuse: never`
(every level above low) runs the suite. What is taken is always droost's own
run of the tool, never an account of one. `droost workflow:gate [--json]
[--phase=code|test|complete] [--base=<ref>] [--skip=<gate,…>]` (0.14.17) runs one phase's
gates, test by default, with no run at all: the lever file frozen as a run
would freeze it, nothing kept (no run opened, no evidence written, no retry
spent). A ticket-scoped gate reads what
changed since `--base` (a landing's pre-merge commit), or its full set without
one. `--skip=<gate,…>` leaves gates out, each recorded `off`; the browser suite
runs for real against the site, and the check says so before it starts
(`--skip=playwright` leaves it out). It exits 0 when no gate blocks, 1 when one
does, and 2 when refused: while
the project's run is at test, or for a base that names no commit. `--json`
prints `{phase, preset, base, passed, gates: [{gate, status, outcome, labelled,
summary, exit_code, duration_ms}]}`, `outcome` one of pass, fail, skipped or
reported, and `labelled` true for a pass that examined nothing. P6 run 23 measured
what the strict flow costs at the bottom of the dial: its 279-test browser
suite ran three times in 86 minutes, for a ticket whose own tests were two
files. `wiki_fresh` is due only here, and only here
CAN it be true: it asks `droost code:wiki status` whether the project's own
documentation still matches the code, and complete is the phase that just
wrote it. A
stale page is read as fact, which is worse than no page.

## Promise against proof: the criteria table

The full spec's acceptance-criteria table carries a `Verified By` column,
empty at plan. The test phase fills it with the test that proves each row —
the PHPUnit method or class, or the Playwright spec — or `manual — <reason>`
for a criterion no test can prove. At `complete` an empty cell (or a missing
column) is **recorded, not blocking**: the report names each unverified row
under `criteria_verified`, prints manual as manual, never as passed, and
`workflow:status` shows the three lists. It was a refusal once, and the shape
of a markdown file ended three live runs whose every gate had passed (F-35),
so the spec's shape is a record now and the gates are the wall. A criterion
verified through `verify_criterion` is recorded against the test that ran. A
quasi-spec at `medium`/`low` has no table and is not held to one.

## The feedback loop

A blocking gate does not end a run; it starts a bounded loop. Each failing
invocation spends one attempt per blocking gate — recorded in run state as
`feedback_attempts`, measured against `max_gate_retries` — and the agent
fixes the cause between invocations. `max_gate_retries: 2` means one attempt
plus two retries; `0` means one attempt and no retry. A missing tool spends
budget exactly like a failure, because a missing binary re-invoked forever
is the worst infinite loop of all.

When the budget is spent, the phase is recorded **failed** — terminal — and
`run` refuses to execute anything further. Every surface renders the same
envelope: `{outcome, current_phase, report, awaiting, retries, loop}`, where
`retries.exhausted` separates "fix it and run again" from "this run is
over". Exit codes stay simple — paused is not failed, and both kinds of
failure exit non-zero. Recovery from a terminal failure is deliberate:
`droost workflow:reset`
archives the record to `droost/droost-workflow/history/` and clears the way —
the same verb that closes out a COMPLETED run, whose record also persists
until reset.

### The fast flow's loop: back to code, or a follow-up

In the fast flow a failure at test does not retry in place: the run goes
**back to code** (`outcome: returned`, exit non-zero), with the failing gates
named in `blocked`. Code's gates run again on the next `run`, and test after
them. `max_loops` bounds it (low 2, medium 3, high and xhigh 5, max 5). A
return because the code **moved** spends nothing: when the files code's gates
measured change after they ran (a fix made at test, an edit at complete), the
next test or complete sends the run back to code before any gate runs, so no
green stands over code it never saw. Markdown and the workflow's own
`droost/` directory are not code a later phase re-measures.

A failure that is **out of the ticket's scope** (a full-scope browser suite
whose every failure is in a spec the run did not change), or one still there
when the budget is spent, becomes a **follow-up ticket**: the phase is
recorded `deferred`, never passed, a `follow_up` row at the phase names each
ticket, and the run moves on (`outcome: deferred`, exit 0). `follow_ups`
decides where they go: `auto` (the default) writes to the cockpit when it is
the project's ticket source and to markdown otherwise (the project's own
ticket directory, or `docs/tickets/`); `markdown` and `cockpit` say so
outright; `none` writes nowhere, so a spent budget fails the phase. At `max`
a spent budget fails the phase too. A follow-up the cockpit refuses is
written as markdown, and the record says why. A tool that could not run
(missing, crashed, timed out) is not code to fix, and keeps the in-place
retry above. Every return, its reason and each follow-up are in the envelope's
`loop` block and in `run.json`.
