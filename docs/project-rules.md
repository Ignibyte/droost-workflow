# A project's own rules

Existing problems recorded as a baseline, the project's own gates, hooks and walls, and house rules held by a gate or the seeker.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## Inherited debt — the adoption baseline

A legacy project fails these gates on debt the first change never touched:
hundreds of phpstan errors, forty phpcs findings, config drift nobody will fix
this month. The baseline is the operator's snapshot of that debt, written
once from a terminal, committed beside the lever file in `droost/baseline/`:

```bash
droost workflow:baseline --measure    # the bill: what each gate would inherit (read-only)
droost workflow:baseline --commit     # write it (operator terminal) and commit droost/baseline/ alone
droost workflow:baseline --refresh --commit    # re-measure: paid-off debt drops, growth is refused
droost workflow:baseline --refresh --grow --reason="legacy import"   # accept more, on record
droost workflow:baseline --status     # what is recorded (read-only)
```

Each write names the branch and commit it measured, and the record keeps
both. A refresh measures whatever is checked out, so it is refused on a
branch other than the record's, or on a tree that does not descend from the
record's commit: a ticket branch that deletes files would otherwise record
its deletions as debt paid off. Refresh on the branch the baseline belongs to,
or pass `--from-here` when this tree is the one to measure. `--commit`
commits `droost/baseline/` and nothing else staged; the agent's shell may not
stage or commit the record itself, since a commit runs the repository's
hooks and a hook can rewrite it.

From then on a consulting gate — phpcs, phpstan, eslint, stylelint, prettier,
coverage, mutation — answers two questions. **Inherited** is a
finding the baseline records: reported, counted, never failing. **New** is
everything else, and fails as before. The line reads `passed — 0 new, 123
inherited`, never a bare pass. A finding is keyed by file, rule, message and
the text of its line, so a line that shifts stays inherited and a line that is
edited becomes new — touching a file does not make its whole debt yours.
phpstan runs through the baseline's own generated file; prettier's recorded
files are inherited until the run touches them; coverage and mutation pass at
or above their recorded floor when they miss the level's target, with the
target named (the ratchet — the floor rises on refresh, never falls).

The run freezes the baseline's hash at begin. A baseline added, removed or
edited under a run fails every consulting gate with "the baseline changed
during the run"; the pack guard refuses the agent's shell from writing it
and the agent's editor from touching the directory; the seeker names any
move a defeat. `baseline: { on: false }` in the lever file is strict mode,
one visible line. Design: `docs/design-adoption-baseline.md`.

## The project's own gates, hooks and walls

A project adds rules of its own as files in `droost/`, named in
`droost/droost.yml`. Each runs as its own process from the project root,
never inside droost, so a fatal error cannot take the run with it, a timeout
can be enforced, and the file cannot reach the run's state. A PHP file runs
under `php`; anything else is executed.

| Kind | Declared as | Gets on stdin | Its answer |
|---|---|---|---|
| **gate** — a check at a phase | `gates.<id>: { run: droost/gates/<file>, phase: code \| test \| "code,test", mode: block \| report, timeout: <s> }`, or `cmd: "<one shell line>"` in place of `run:` | the run's JSON: `project_root`, `run_id`, `phase`, the changed files; `DROOST_PROJECT_ROOT` and `DROOST_GATE` in the environment | exit 0 passed, 1 failed, stdout its report; exit 4 ran and examined nothing (no file it reads, nothing to check), recorded as a **labelled** pass; **anything else — a missing file, a crash, a timeout, exit 3 — is recorded as did not run, never as passed** |
| **wall** — asked before a tool call | `walls.<id>: { tools: <regex over the tool's name>, run: droost/walls/<file> }` | `tool`, `tool_name`, `input`, `project_root` | exit 0 allows, exit 2 refuses with the reason on stderr; a wall that crashes, is missing or times out (10 s) refuses — its silence is never consent |
| **hook** — told about the run | `hooks: { run_start \| phase_begin \| phase_end \| run_complete: droost/hooks/<file> }` | the event: `phase`, `run_id`, `work_item` | recorded in `hook-calls.jsonl`, and its first line shown to the agent in `workflow:run` (`hooks` in the envelope) and `workflow:answer`; a hook never holds the run |
| **operator-only script** | `operator_only: [droost/bin/<script>]` | — | the guard refuses it from the agent's shell, as it refuses `droost workflow:bypass` |
| **panel tab** — a tab in the run panel | `panel: { tabs: { <id>: { title: <label>, run: droost/panel/<file>, timeout: <s, default 5> } } }`; `.ts`/`.js` run under node (which strips TypeScript's types) | `project_root`, `tab`, and `run` (the open run's id, state, phase, preset, lead, work item, spec), or `null` | one JSON object on stdout: `{"badge": "3/4", "sections": [...]}`, sections of kind `text`, `fields`, `checklist` or `list` ([The run panel](pack.md#the-run-panel-and-the-droost-mod)); one that fails, times out or prints anything else shows why in its place |

**The rules are frozen.** A run records every gate, hook and wall file's hash
when it begins (`rule_files` in `run.json`), and names a gate file that
changed since at its gate. While the run is open the guard refuses writes to
`droost/droost.yml`, `droost/gates/`, `droost/hooks/`, `droost/walls/`, `droost/panel/` and
`droost/agents.md`. **Trust is review**: only files committed in the
repository run, and droost fetches nothing.

`droost/agents.md` is the project's own text for the AGENTS block: `init`
carries it beneath droost's paragraphs, so every agent reads it.

A gate's verdict can be steered by a file the run changes: its config, its
ignore or policy file. The built-in gates know theirs; a project gate takes
`steered_by: <path>,<name>`, and snyk, gitleaks, semgrep, trivy and rector
are known by id. When the run changed one, the gate's summary says
`[steered by config this run changed: …]`, and the seeker reads an ignore
the spec does not record as the developer's decision as a discipline
finding. In the strict flow, complete re-runs every gate the run holds, the
project's included: it is the last wall before the run is recorded complete.
`droost workflow:evidence --full` prints each gate's output whole (up to the
16 KB kept) where the report otherwise cuts it at 2,000 characters.

A check that needs the running site is a project gate that asks it:
`config_clean: { phase: test, cmd: "drush config:status --format=json" }`.
droost itself never does.

## House rules — the site's own, held by a gate or the seeker

droost tells the agent no way to build. A site writes the rules its work is
held to, each with an id and a judge:

```yaml
rules:
  house:
    - { id: HR1, rule: 'New markup lives in single-directory components.', judge: gate, check: sdc_markup, mode: block }
    - { id: HR2, rule: 'Pages are composed in Canvas, never as a View page.', judge: seeker }
    - { id: HR3, rule: 'Custom code never queries the database directly.', judge: gate, cmd: "! grep -n 'Drupal::database'", files: '*.php', mode: block }
seekers: { holds: high, at_complete: true }
```

A rule a machine can check is judged by the `house_rules` gate, through one
of two things:

- a `check` droost knows: `sdc_markup`, read over what the run added, fails a
  Twig template outside a single-directory component's directory and a
  Canvas code component; `site_tools`, read over what the guard saw the run
  do, fails each raw `drush php:eval`, `php:script` or SQL call, since the
  droost module's tools (`drush droost:tool`) are recorded and held to the
  run and raw PHP and SQL are neither;
- a `cmd` of the site's own: phpstan, a phpcs sniff, semgrep, ast-grep, a
  grep. It runs at the project root with the files the run changed as its
  arguments (`cmd "$@"`), narrowed by `files` (a glob or a list), so it reads
  the run's work and never the code the run inherited. Exit zero holds the
  rule; any other exit is a break, its first lines in the finding; a command
  that is not there (127) or runs past ten minutes fails the gate as the
  environment's, never as a pass. One line, the site's own, reviewed in the
  diff like every lever.

A break fails the gate at `mode: block`, or is recorded beside a passing
phase at `report` (the default). A rule only judgment can read is the
seeker's: it runs
`droost workflow:rules`, checks the diff against each, cites the id in every
finding, and grades a break HIGH unless the spec records an exception the
operator approved; `seekers.holds: high` makes that hold the run. The page
rules (`rules.<kind>`: which owner builds each kind of page) are read the
same way, by the seeker, against the owner each declared route names.
`droost workflow:rules` prints what the open run is held to.
