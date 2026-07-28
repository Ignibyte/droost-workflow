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
- You know which gates this run is held to. Read the RESOLVED set recorded in
  the run state when the run began, not the config file as it stands now — a
  run is held to the levers it started under, and reading the file instead
  would silently apply an edit made mid-run.

## Work

Run the gates the run is configured for. **You do not decide what passes.**
Thresholds, which gates are on, how many retries a failure gets — all of that
was resolved before this phase began. Your job is to run them and report,
not to re-derive a verdict.

`droost_verify` runs the static and test legs — **but only the ones you ask
for**, and the default is narrower than people expect:

| Call | Legs that run |
|---|---|
| no `checks` argument | **phpcs and phpstan only** |
| `checks: [deprecations]` | deprecations, which is opt-in |
| `checks: [phpunit], confirm: true` | phpunit, which needs `confirm` because the suite creates and drops databases |

Ask for what the levers say should run, then report what actually ran. A
plain call reports "passed" having run two static checks; if you assumed
phpunit was among them you have just reported tests green that never
executed. None of the legs render a page or fetch a URL — do not describe
`droost_verify` as having checked that anything works.

`droost_last_error` and `droost_logs` tell you what the site actually said
when something failed, rather than guessing from an exit code.

When a gate fails, the run enters a bounded feedback loop: read the finding,
fix the cause, run the gate again. `max_gate_retries` in the lever file is
the bound. When it is exhausted, the run fails — a legitimate outcome, and
worth more than a success the run cannot support.

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
