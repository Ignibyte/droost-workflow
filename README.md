# Droost Workflow

[![ci](https://github.com/Ignibyte/droost-workflow/actions/workflows/ci.yml/badge.svg)](https://github.com/Ignibyte/droost-workflow/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/droost/workflow)](https://packagist.org/packages/droost/workflow)
[![PHP](https://img.shields.io/badge/php-%5E8.3-8892BF)](composer.json)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

The phased, gated pipeline an agent runs to build or change a Drupal site:

```
plan → code → test → document → complete
```

Each phase has an entry and an exit gate. Pass, and the run advances; fail, and
it enters a bounded feedback loop or stops. What "pass" means is configured per
repo, in one version-controlled file.

Droost Workflow is the **methodology** layer. Where
[droost](https://www.drupal.org/project/droost) is what an agent *knows* about
Drupal, this is how it *works*: the same pipeline, the same levers, whether it
runs against a live site or from a plain checkout with no site at all.

Clean-room GPL. A sibling of, not a fork of, any proprietary pipeline.

## Status

Built ticket by ticket, and past its skeleton:

| Step | What | State |
|---|---|---|
| P6.1 | Config spine — the lever file, presets, run state | **shipped** |
| P6.2 | The five phases as a `.claude/` pack | **shipped** |
| P6.3 | Gate runner + honest degradation | **shipped** |
| P6.4 | Automated / pair modes and the mid-run swap | **shipped** |
| P6.5 | The drush live-site surface and the standalone CLI | **shipped** |
| P6.6 | The MCP surface (optional submodule) | **shipped** |
| — | Hardening: the phase→gate map, engine-counted retries, a coverage gate that can pass | **shipped** |
| — | `droost/workflow` published on Packagist | **this release** |

## The lever file

A single repo-root file, `droost.workflow.yml`, is the source of truth:

```yaml
mode: automated                 # automated | pair
preset: custom                  # custom | factory | fast
phases: [plan, code, test, document, complete]
gates:
  phpcs:          { on: true,  standard: "Drupal,DrupalPractice" }
  phpstan:        { on: true,  level: 6 }      # 0-9 | max | off
  phpunit:        { on: true }
  mutation:       { on: false, msi_min: 0 }
  playwright:     { on: false }
  coverage:       { on: false, min: 0 }
  rendered_check: { on: true }                 # artifacts are truth
max_gate_retries: 2
```

**It is a file, not Drupal configuration**, for four reasons: the agent must be
able to read its own levers while the site is mid-build or broken; a plain
Claude Code or Codex user reads the same file with no site at all; it is dev
tooling and belongs with the code it gates; and it belongs in review, where
loosening a gate shows up as a diff.

### Presets

A preset is a **base**, not an alternative to per-gate control — explicit
`gates:` entries are applied over it, so "factory but without Playwright" is
one line rather than a fork.

- **`factory`** — everything on, strict. The software factory.
- **`fast`** — coding standards and a shallow analysis; nothing that costs
  minutes.
- **`custom`** — the values shown above; the ergonomic middle, and what `init`
  writes for you.

Three details worth knowing:

- **Anything that does not name a preset resolves to `factory`** — no file, an
  empty file, or a file that sets other things but never mentions one. A repo
  that has said nothing has not opted out of anything. This is deliberately
  one rule rather than three: an earlier revision defaulted a file that exists
  to `custom`, which meant `touch droost.workflow.yml` turned mutation,
  playwright and coverage off and dropped PHPStan from max to 6, silently. If
  you want the gentler set, name it — `preset: custom` — so the choice is
  visible in a diff.
- **Thresholds never imply `on`.** Writing `coverage.min` without
  `coverage.on` leaves the gate where the preset put it. An inferred switch
  would make `min: 0` and `on: false` two spellings of one intent with two
  different failure modes. The single exception is `phpstan.level: off`, which
  the level vocabulary has always included: it turns the gate off, and pairing
  it with an explicit `on: true` is refused rather than silently resolved, so
  the recorded levers can never claim a gate ran when it did not.
- **Something at the config path that is not a readable regular file is an
  error**, not an absent config. A directory, a broken symlink or an
  unreadable file would otherwise swap your gates for the built-in ones and
  report nothing unusual.

### Gate options

Most gates carry their thresholds inline — `phpcs.standard`,
`phpstan.level`, `coverage.min`, `mutation.msi_min`. One is easy to miss:
`rendered_check.routes` is a comma-separated list of internal paths the
live surface renders (`routes: "/,/pricing"`); omitted, it renders `/`.
The option vocabulary is closed per gate — anything else is refused by name.

### Unknown keys are errors

A loader that shrugs at `phpstain:` hands back a run with static analysis
quietly disabled and a report that says everything passed. So every unknown
setting, gate, option, phase, mode and preset is refused by name:

```
droost.workflow.yml: unknown gate "phpstain" (known: phpcs, phpstan, phpunit,
mutation, playwright, coverage, rendered_check)
```

## Which gates run when

WHETHER a gate runs is the lever file's business. WHEN it runs is the
engine's phase map, frozen into each run when it begins:

```text
plan: none
code: phpcs, phpstan
test: phpunit, mutation, playwright, coverage, rendered_check
document: none
complete: phpcs, phpstan, phpunit, mutation, playwright, coverage, rendered_check
```

Plan and document run nothing — there is nothing yet to measure, and prose
needs no linter. Code gates the diff with static analysis. Test runs the
functional gates. Complete re-runs the full enabled set as the terminal
safety net — which is what makes dropped phases safe: a run configured
without a test phase still meets every enabled gate once, at the end.

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
envelope: `{outcome, current_phase, report, awaiting, retries}`, where
`retries.exhausted` separates "fix it and run again" from "this run is
over". Exit codes stay simple — paused is not failed, and both kinds of
failure exit non-zero. Recovery from a terminal failure is deliberate:
remove `.droost-workflow/run.json` and begin again.

## Run state

Run state lives beside the lever file, in `.droost-workflow/run.json`:

```json
{ "v": 1, "run_id": "...", "phases": { "plan": "passed", "code": "active" } }
```

On the filesystem for the same reason the levers are. If run state lived only
in Drupal's State API, a run started against a live site could not be resumed,
inspected, or even described from a plain checkout — and the two surfaces would
be two pipelines sharing a name.

Writes go through a temporary file and a rename, so a run that dies mid-write
leaves the previous state intact. A state file that cannot be parsed is never
deleted or replaced: it is still evidence of what a run was doing.

Two limits on that, stated because an overstated durability promise is worse
than a modest one: there is no `fsync`, so a process crash is covered but a
power cut is not; and there is no locking, so two processes doing
load-modify-save against one file will lose an update without either being
told. Nothing is ever torn. The model is one run per repo.

**A phase that failed is never quietly recorded as passed.** Advancing stamps
the phase you are leaving as passed, so advancing away from a failed or
skipped phase is refused outright — clearing a failure has to be a deliberate
act, not a side effect of moving on. Advancing backward, or advancing a run
that has already reached its terminal gate, is refused for the same reason: a
report has to be able to describe the run honestly.

## The pack

The five phases ship as a `.claude/` pack — one skill per phase, two
commands, and a shared partial on using droost. Installing it into a repo
writes:

```
.claude/skills/workflow-{plan,code,test,document,complete}/SKILL.md
.claude/commands/workflow/{run,status}.md
.claude/partials/droost-usage.md
droost.workflow.yml          # only if you don't already have one
```

Each skill states four things: its entry gate, the work, its exit gate, and
**what it can and cannot check without a booted site**. That last section is
the point. Nearly every droost tool needs a running site, so a CLI run has
real blind spots — and a run that hides them produces a report nobody should
trust.

**Ownership is explicit.** Every directory the pack owns gets a
`.droost-workflow-pack` marker. Re-running the installer refreshes those
directories and nothing else; a directory without the marker belongs to you
and is refused rather than overwritten. Your `droost.workflow.yml` is never
refreshed at all — it is version-controlled intent you wrote, and resetting
your gates on an unrelated re-install would be an unpleasant surprise.

## Install

**This package is a framework-free PHP library.** It requires no Drupal, and
it is the whole standalone surface:

```bash
composer require --dev droost/workflow
vendor/bin/droost-workflow init      # writes the pack + a default lever file
```

**The Drupal surface ships with droost, not here.** `drupal/droost`'s
`droost_workflow` submodule supplies the two things that genuinely need a
booted site — the `drush droost:workflow:*` commands and the
`droost_workflow_status` / `droost_workflow_run` MCP tools. Enable it and you
get both; the pipeline underneath is this library either way.

That split is P6.7 in droost's roadmap, and the reason is delivery rather than
capability: a Drupal site builder does not install contrib from a git remote,
and a plain Claude Code or Codex user does not want an AI work pipeline
delivered as a Drupal module. Nothing about the pipeline changed.

The lever file's `preset` is a scalar (`preset: custom`) — there is no
`presets:` block to configure; a preset is a base the `gates:` entries
overlay.

## Two surfaces, one pipeline

The same run, the same levers, the same report — whether or not there is a
site.

```bash
# Standalone. Any Drupal repo, no booted site, nothing running.
vendor/bin/droost-workflow init
vendor/bin/droost-workflow status
vendor/bin/droost-workflow run

# Against a live site.
drush droost:workflow:status
drush droost:workflow:run
drush droost:workflow:answer "yes, continue"
drush droost:workflow:swap automated
```

The only thing that differs is what the site-dependent gates can say. Verified
on a real Drupal 11.4.4 site:

| Surface | `rendered_check` |
|---|---|
| live (drush) | **passed** — "1 route(s) rendered", a real sub-request to `/` |
| standalone | **skipped, no site** — with the reason recorded |

That difference is the entire point. The CLI surface is not a degraded live
run pretending otherwise; it is a run that tells you exactly which checks it
could not perform. Everything else — which gates ran, their verdicts, the
phase, the advance decision — is identical, because both surfaces call one
facade and differ only in which site driver they inject.

## The MCP surface (ships with droost)

`drupal/droost`'s `droost_workflow` submodule exposes the same engine over MCP,
as a third and fourth front onto the one `WorkflowFacade`:

- **`droost_workflow_status`** — read-only. The resolved levers (which preset,
  which gates, and where that came from), the phase order with each phase's
  status, the latest tally, and whether the run is awaiting an answer.
- **`droost_workflow_run`** — drives the run: gates the current phase and
  advances when they pass. `answer` and `swap` are ARGUMENTS of this one tool
  rather than separate tools, because they are sub-operations of driving a run
  and every extra destructive tool is another separately allow-listed surface.

Both take an optional `project` (absolute path to the repository); omit it for
the site's own root. A root that is not a directory comes back as a failure
envelope naming the path — never an exception, which over JSON-RPC would tell the
caller nothing it could act on.

It is a SUBMODULE so a plain consumer of this package never pulls the alpha
`mcp_server`: enable it only if you want the MCP surface. It depends on
`droost:droost`, `mcp_server:mcp_server` and this module.

`droost_workflow_run` is **STDIO/Drush-only** — gated on the transport alone, no
`allow_*` flag, the same posture `droost_verify` has for the same risk class (it
spawns the project's own analysis binaries). Its whole body runs inside droost's
Fiber shield, because a run can reach the rendered check and Drupal's renderer
suspends the fiber in a way the MCP SDK misreads as a dropped response.

**Analysing it.** `./scripts/lint` does NOT type-check this submodule, and says
so on every run. Its types come from three packages this checkout cannot have
(`drupal/droost` is unpublished; `mcp_server` and `mcp/sdk` exist only inside a
site), so no portable `scanDirectories` list can resolve them. Analyse it where
they live:

```bash
ddev exec "cd /var/www/contrib/droost/droost_workflow && \
  php vendor/bin/phpstan analyse -c phpstan-mcp-site.neon \
  --autoload-file /var/www/html/vendor/autoload.php"
```

That reaches everything except `$container->get()`'s return type, which needs
`phpstan-drupal`; droost's own Tool plugins carry the identical gap for the
identical reason.

## Requirements

PHP 8.3+. The engine's only runtime dependency is `symfony/yaml` — no Drupal
bootstrap is required to read config or run state, which is what lets the same
code serve both surfaces.

## Development

```bash
composer install
scripts/lint            # phpcs, phpstan (level max), phpunit
scripts/lint src/Config # scope phpcs to a subset
```

All three legs are **hard**. A missing binary fails the gate rather than
skipping it and says which invocation it could not run — an environment that
cannot run a gate is a broken environment, not a gate that does not apply.

## License

GPL-2.0-or-later.
