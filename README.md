# droost

[![ci](https://github.com/Ignibyte/droost-workflow/actions/workflows/ci.yml/badge.svg)](https://github.com/Ignibyte/droost-workflow/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/droost/workflow)](https://packagist.org/packages/droost/workflow)
[![PHP](https://img.shields.io/badge/php-%5E8.3-8892BF)](composer.json)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

**droost** is the command-line half of [Droost](https://droost.org): it gives
an AI coding agent a map of your Drupal codebase (the brain), and takes every
change it makes through plan → code → test → complete, with checks at each
step (the workflow). It is a framework-free PHP package: not a Drupal module,
and it never boots Drupal.

## The Droost ecosystem

| Project | What it is | Where |
|---|---|---|
| **droost/workflow** (this repository) | The `droost` command: the brain and the workflow. | [GitHub](https://github.com/Ignibyte/droost-workflow) · [Packagist](https://packagist.org/packages/droost/workflow) |
| **droost/engine** | The engine under the command: the code index, the wiki and search cores, the agent harness installers. | [GitHub](https://github.com/Ignibyte/droost-engine) · [Packagist](https://packagist.org/packages/droost/engine) |
| **Droost** (`drupal/droost`) | The Drupal module: the agent asks the live site about itself, and changes it through tools a person switches on. | [drupal.org](https://www.drupal.org/project/droost) |
| **Droost CMS** | A Drupal CMS recipe for a site an agent builds: the module's content tools, Droost Theme, UI Patterns. | [drupal.org](https://www.drupal.org/project/droost_cms) |
| **Droost Theme** | A base theme for agents: Tailwind v4 and daisyUI, design tokens in one file, Canvas-ready components. | [drupal.org](https://www.drupal.org/project/droost_theme) |
| **Droost UI Kit** | Manage Display built from Single Directory Components, on UI Patterns 2. | [drupal.org](https://www.drupal.org/project/droost_ui_kit) |
| **Droost Framework** | The map of all of these on drupal.org. | [drupal.org](https://www.drupal.org/project/droost_framework) |

Documentation, the FAQ and the agent-readable docs
([llms.txt](https://droost.org/llms.txt)) are at **[droost.org](https://droost.org)**.

## What it does

- **The brain.** `droost code:wiki`, `code:tree` and `search:arch` answer
  from the project's files: every installed module and theme, your custom
  code, your notes. No Drupal, no drush; it answers while the site is broken.
- **The workflow.** Every change runs plan → code → test → complete. Each
  phase ends with gates (phpcs, phpstan, PHPUnit, Playwright and more), set
  by one level from `low` to `max`. Plan cannot close until the agent has
  looked things up.
- **The guard.** A hook that stops the agent skipping a phase, editing the
  rules mid-run, or running the commands that are the operator's.

It works with any agent that can run commands: `droost init` writes the
instructions for Claude Code, Codex, Gemini, opencode and Qwen. The guard is
Claude Code's today; on other agents the gates still hold the run, and the
report says enforcement was advisory.

## Install

Let your agent do it:

```text
Read https://droost.org/agents.md and follow it to install droost in this project.
```

Or by hand, in its own Composer project so the site's dependencies can never
break it:

```bash
mkdir -p tools/droost
composer init -d tools/droost -n --name=local/droost-tools
composer config -d tools/droost platform.php 8.3.0   # the PHP droost runs under (the site's)
composer require -d tools/droost 'droost/workflow:^1.0'
tools/droost/vendor/bin/droost init        # the pack, the guard's hooks, AGENTS.md, droost/droost.yml
tools/droost/vendor/bin/droost code:index  # the code index the brain reads
```

Pin `platform.php` to the PHP droost will run under: installed from a host
with a newer PHP, Composer otherwise picks dependencies the site's container
cannot load. On DDEV, run Composer on the host and droost in the container
(`ddev exec tools/droost/vendor/bin/droost …`). Then start a new agent
session, so the hooks and skills load.

## Configure

`init` writes `droost/droost.yml`, the one file that says what a run is held
to. It is committed, so loosening a gate shows up in review, and the guard
refuses edits to it during a run. The main lever is the level:

| Level | What a run is held to |
|---|---|
| `low` | basic static checks, no tests; the one level that may turn a required gate off (and records it as `off`, never `passed`) |
| `medium` | phpcs, phpstan level 2 and the browser check; a changed class needs a changed test |
| `high` | phpstan 6 and unit tests; the guard's enforcement is `hard` |
| `xhigh` | adds coverage, mutation testing and the front-end linters |
| `max` | everything, strict; the test suites must exist |
| `custom` | your own `gates:` block is the truth (what `init` writes) |

The level is the operator's to set, from their own terminal:
`droost workflow:effort <level>` (`--preview` shows what it would change).
Every gate, threshold and option is in [Configuration](docs/configuration.md).

## Use

Ask your agent for a change. In Claude Code, `/droost:workflow:start <what
you want>`; any other agent finds the same procedure through `AGENTS.md`.
The agent researches, writes a spec, builds, tests and completes, and the
run stops at whatever a gate refuses.

```
droost workflow:status    where the run is
droost workflow:report    each phase, each gate's verdict and why
droost                    every command, one line each
droost <command> help     one command's arguments
```

| Command | What it answers |
|---|---|
| `code:wiki [page]` | the wiki (`droost/wiki`), and a page per installed contrib module and theme: its version (`composer.lock`), what it needs and provides (its YAML), whether the last configuration export enabled it. `code:wiki status` checks each page against its sources (the `wiki_fresh` gate); `code:wiki stamp` re-records a page's source hashes |
| `code:tree [module\|path]` | modules and themes, their files, classes, functions and methods, and each module's hooks, services and routes |
| `search:arch <words>` | plain text over the documentation: the wiki, the inventory, each extension's README and docs |
| `code:symbol <name>` | where a class, interface, trait, function or method is defined |
| `code:graph <symbol>` | what it calls, what calls it, what it injects and implements (`service:<id>`, `route:<name>`, `hook:<name>` too) |
| `search:code <query>` | symbol names and wiki text, lexically; the nearest code chunks too when `search.embeddings` names an endpoint |
| `code:docs`, `code:deprecations` | module documentation; Drupal core's deprecations, offline |
| `brief` | the project in one answer, from its files |
| `code:index [--full]` | builds or refreshes the code index |
| `workflow:<verb>` | the run: `workflow:run`, `workflow:status`, `workflow:criteria`, … (below) |
| `init`, `migrate`, `doctor` | install, move an old lever file, report what droost can see |
| `guard <verb>` | the guard a harness's hooks call; not for typing |

The commands that loosen the rules are the operator's alone: `effort`,
`gate-waive`, `bypass`, writing a `baseline`, `ticket move`, `intake answer`
and `intake abandon`. The guard refuses them from the agent's shell and hands
back the command for the operator to run in a terminal of their own, and
making a run autonomous (`lead autonomous`) needs that terminal too
([The guard](docs/guard.md)).

## Documentation

**On [droost.org](https://droost.org):** [Getting started](https://droost.org/docs/getting-started) ·
[How it works](https://droost.org/docs/how-it-works) ·
[On an existing site](https://droost.org/docs/existing-site) ·
[Your first run](https://droost.org/docs/first-run) ·
[Commands](https://droost.org/docs/commands) ·
[Troubleshooting](https://droost.org/docs/troubleshooting) ·
[FAQ](https://droost.org/docs/faq)

**The reference, in this repository:**

| Topic | What it covers |
|---|---|
| [Configuration](docs/configuration.md) | `droost/droost.yml`: the levels, every gate and its options, storage, the wiki and semantic search |
| [A project's own rules](docs/project-rules.md) | a baseline for existing problems; the project's own gates, hooks and walls; house rules |
| [Gates, criteria and the feedback loop](docs/gates.md) | which gate runs at which phase, how each requirement is matched to its proof, what happens when a gate fails |
| [The intake](docs/intake.md) | a site from a design, planned and approved before it is built; who finishes a run |
| [Tickets](docs/tickets.md) | work items from a folder of Markdown tickets, or from a cockpit |
| [Run state and run events](docs/run-state.md) | what a run records, and the events other tools can read |
| [The pack and the run panel](docs/pack.md) | what `init` installs for each agent harness; the run panel beside the chat |
| [The guard](docs/guard.md) | what it refuses, the operator's commands, which harnesses it covers |

Design notes: [design](docs/design.md) ·
[enforcement](docs/design-0.3-enforcement.md) ·
[adoption baseline](docs/design-adoption-baseline.md) ·
[effort presets](docs/design-effort-presets.md) ·
[cockpit provider API](docs/cockpit-provider-api.md).

## Requirements

PHP 8.3+ with `pdo_sqlite`. Runtime dependencies: `symfony/yaml` and
`droost/engine` (the code index, the wiki and search cores, the harness
installers), which brings `nikic/php-parser`. No Drupal
bootstrap is needed for anything: the levers, the run state, the code index
and the wiki are files.

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
