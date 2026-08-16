# Droost Workflow — design sketch (Track F)

The open-source, **agent-first** work pipeline the Druplit worker (and any droost surface)
runs to build or change a Drupal site. A **clean-room sibling** of `littler/drup-pipeline`
— same structural DNA (phased · gated · EARS-grounded · droost-powered), rebuilt for an
**autonomous agent** and stripped of the enterprise-dev ceremony (no Jira/Confluence, no
deploy/release track).

Last updated: 2026-07-25 (the sketch). **Versioned here 2026-08-16 — see the
status block below, which is newer than everything under it.**

---

## Status: this is the sketch, and the sketch has been built

This document was written before the implementation and lived, unversioned, in
`~/Projects/contrib/droost-cms` — a directory that is not a git repository. It
was Droost's master roadmap item **P6.0**: *"Rescue the design. Version them
before building against them."* Building against it happened first; versioning
it is this commit, and it belongs here, beside the code it describes, rather
than beside a site that merely runs it.

Read everything below as **the original design intent**, not as a description
of the current code. Where the two differ, the code is right. As of 2026-08-16:

| Roadmap step | State |
|---|---|
| P6.1 — the config spine | **built** — `src/Config`: `WorkflowConfig`, `Preset`, `PresetResolver`, `Mode`, `Phase`, `GateSettings`, `PhaseGateMap`, `Provenance` |
| P6.2 — the five phases | **built** — `pack/skills`, `pack/commands`, `pack/partials`, `pack/droost.workflow.yml` |
| P6.3 — gate + verify wiring | **built** — `src/Gate`: `GateRunner`, `ShellGateExecutor`, `GateResult`/`GateStatus`, `PhaseReport` |
| P6.4 — modes | **built** — `src/Mode`: `ModeEngine`, `PendingQuestion`, `QuestionSinkInterface`, `RunStateOnlySink` |
| P6.5 — both surfaces | **built** — `src/Cli` + `src/Drush/Commands` + `modules/droost_workflow_mcp` (`droost_workflow_run`, `droost_workflow_status`) |
| P6.6 — package + adopt | **partial** — published as `droost/workflow`; the druplit worker-seat half is out of scope here |

The two constraints this document calls "the real engineering content of this
phase" both hold, and each has a class you can point at:

1. **Config and run state readable without Drupal** — `droost.workflow.yml` at
   the repo root, run state in `.droost-workflow/run.json` beside it.
2. **The gate set degrades honestly** — `Gate\NullSiteDriver` is the whole
   answer: a run with no booted site records `skipped, no site` per gate
   instead of passing.

## Where it sits

- The **Druplit manager** plans + monitors; the **worker agent** (inside the `droost_cms`
  site, via droost MCP) *runs this workflow*. This extends druplit's existing manager/worker
  split — the manager already drafts plans (`draft_plan`) and the scheduler/`build_verify`
  already monitor + verify rendered artifacts. Droost Workflow replaces the worker's current
  **flat build-brief** (`worker-pack/`) with a **structured phased pipeline**.
- Ships as a **separate Composer library** (`drupal/droost_workflow`), scaffolded into the
  site's `.claude/` (commands / skills / hooks / partials) + a default `droost.workflow.yml`.
  Druplit's `worker-pack` becomes "materialize `droost_workflow`"; a plain Claude Code / Codex
  user can `composer require` it directly (**surface-independent**).

## The phases — collapsed to 5

`plan → code → test → document → complete`  *(solutions/design folded into **plan**; no
ticket/Jira phase)*

| Phase | What the agent does | Droost |
|---|---|---|
| **plan** | Ground in the site, understand the request, produce the spec: the Drupal constructs to build (content types, fields, Views, Canvas pages, blocks), the approach, and **EARS acceptance criteria**. Absorbs *solutions + architect + design*. | `droost_capabilities` / `architecture` / `module_docs` / `entities` / `routes` — runtime truth before proposing |
| **code** | Implement via droost + Drupal APIs (scaffold, author entities/fields/Views/Canvas, custom code). Custom code + config only. | `droost_scaffold`, entity/config tools, `droost_symbol`/`graph` for placement |
| **test** | The **quality gates + verification** — where the configurable levers live (below). Artifacts-are-truth: verify the **rendered** result, never the agent's self-report. | `droost_verify` (generate→verify loop) + runtime tools |
| **document** | Capture what was built: module READMEs, the realized plan, a change summary; save reusable knowledge. | droost worker-docs (recall / author) |
| **complete** | Present the diff + verify report, optionally commit, persist knowledge. Terminal gate. | — |

Each phase has an **entry/exit gate**. Pass → advance; fail → the bounded verify→feedback→
rebuild loop, or fail the run.

## Configurable levers — the "completely configurable CLI"

A single config file, **`droost.workflow.yml`** (repo-root, version-controlled), is the
source of truth.

**Recommendation: a FILE, not Drupal config.** Reasons:
- **Resilience** — the agent must read it *around* Drupal being bootable, and while Drupal may
  be mid-build or broken. A file survives what Drupal config can't.
- **Surface-independence** — a plain Claude Code / Codex user reads the same file; not everyone
  runs Druplit or even a booted site.
- **It's dev-tooling** — belongs with the code, version-controlled, diffable.
- **Proven** — mirrors DRUP's `pipeline.config.yaml` exactly.

**"Druplit peers in and sets it"** = the Druplit daemon reads/writes this file (a cockpit
settings panel owns the filesystem). *Optionally* the `druplit` Drupal module also surfaces a
settings form that writes the file — but the **file stays canonical** (Drupal config, if used,
mirrors it, never the reverse).

The levers:

```yaml
mode: automated                 # automated | pair
phases: [plan, code, test, document, complete]   # drop any (e.g. skip 'document')
gates:
  phpcs:      { on: true,  standard: "Drupal,DrupalPractice" }
  phpstan:    { on: true,  level: 6 }        # 0–9 | max | off
  phpunit:    { on: true }                    # unit / kernel / functional
  mutation:   { on: false, msi_min: 0 }       # MSI / Infection — off for speed
  playwright: { on: false }                   # browser walkthrough — off for speed
  coverage:   { min: 0 }
  rendered_check: { on: true }                # artifacts-are-truth — recommended always on
presets:
  factory:  # everything on, strict (the software factory)
  fast:     # phpcs on, phpstan level 2, no phpunit / mutation / playwright (running fast)
  custom:   # explicit per-gate
```

"Pull the levers" = pick a **preset** or set individual gates. A *fast / vibe* run skips
Playwright + MSI + unit tests and drops PHPStan; a *factory* run turns everything on. The
**test phase reads these** to decide what "pass" means; the manager reads them to know which
gates it's monitoring for.

## Two modes: automated vs pair (+ swap)

- **automated** — the worker runs `plan → complete` unattended (`dontAsk`), the manager
  monitoring + verifying (druplit's `build_verify` loop). *The software factory.*
- **pair** — at each **phase gate** the worker pauses and asks the user: it writes a `question`
  to its outbox → the manager relays it → cockpit → the user reviews / approves / adjusts →
  continue. *Control.*
- **swap** — flip `pair → automated` at any gate to finish unattended (a runtime signal the
  worker checks at each gate; once automated it stops pausing). Re-enterable per run.

Druplit already has the plumbing: manager mediation + the `question`/answer mailbox + the
cockpit answer form. So **Droost Workflow owns the phase-gates + mode logic; Druplit provides
the mediation/question transport** (already built). Automated vs pair is the same pipeline with
the gate either auto-advancing on pass or pausing for a human answer.

## Clean-room / open-source

A **fresh** implementation of the *pattern* (phases / gates / EARS / droost-partial / funnel),
original content — **not** a copy of Littler's proprietary `drup-pipeline` text. Different use
case (autonomous site-build vs human enterprise dev), so it's a **sibling, not a fork**.

## Build order (Track F sub-steps)

- **F1** — `droost.workflow.yml` schema + loader (levers, presets, modes): the config spine.
- **F2** — the 5 phase definitions as `.claude/` skills/commands (plan/code/test/document/
  complete) + the shared droost-usage partial.
- **F3** — phase-gate + verify wiring: the test phase reads the levers; pass/fail drives
  advance / feedback (artifacts-are-truth via `droost_verify` + rendered check).
- **F4** — mode logic: automated (no pause) vs pair (a `question` at each gate) + the swap.
- **F5** — package as `drupal/droost_workflow` (scaffold/recipe); druplit materializes it into
  the worker seat (replacing the flat build-brief); expose the config in the cockpit.
