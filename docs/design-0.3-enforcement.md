# droost/workflow 0.3.0 — the enforcement arc

**Status: DESIGN, decided with the owner 2026-08-18.** Extends
[`design.md`](design.md) (the 0.1/0.2 pipeline design); nothing below changes
the phase model, the two-surface contract, or the artifacts-are-truth rule.
This document records the decisions verbatim-close so the implementation can
be checked against it.

## What 0.3 is

0.2 built a pipeline you can *choose* to run. 0.3 makes it an **enforcement
layer**: once a run is active, the agent's tools are held to the phase
discipline — and outside a run, nothing changes at all. Configurability stays
sovereign: every gate, and enforcement itself, is a lever in
`droost.workflow.yml`, because a loosened lever that shows up as a reviewable
diff is the honest way to let people forego quality (possible, recorded,
not advised).

## Decisions (owner, 2026-08-18)

1. **Presets: `factory | light | custom`.** `fast` is renamed `light` — same
   slot, better word: light describes the *artifact weight*, not corner-
   cutting. There is no third speed tier.
2. **The four working phases are mandatory, always** — `plan → code → test →
   document` (+ `complete`, the terminal re-run gate) run for every change,
   minor ones included. What varies between factory and light is the weight
   of each phase's artifacts and gates, never the path. The `phases:` config
   key is deprecated: a file that names a subset gets a warning and the full
   sequence.
3. **Enforcement is its own lever, orthogonal to preset:**
   `enforcement: hard | soft | off`. Preset defaults: factory → `hard`,
   light → `soft`. Any combination is legal — a repo may run the full
   factory gate set with `enforcement: off` ("full factory mode but forego
   the quality-enforcement") — not advised, but a diffable choice, exactly
   like every other lever.
4. **Entry is explicit, any time, via `/droost-work`** — the single entry
   command (it starts a run, or advances the active one). Pipeline commands
   can be issued whenever the user wants; nothing auto-enters a run.
5. **Hooks fire only during a run.** Every hook's first act is reading
   `.droost-workflow/run.json`; no active run → exit 0, silently. Regular
   interaction is never policed.
6. **Sub-agents ship in the pack, three to start:** `researcher`,
   `bug-fixer`, `spec-writer`.
7. **No ticket-system coupling** (unchanged non-goal). Jira or anything like
   it would be its own package (`droost-jira`), never baked in here.

## The two spec weights

| | factory | light |
|---|---|---|
| Plan artifact | Full spec with EARS acceptance criteria | **Quasi-spec**: what was asked, what will change, how we'll know — ~10 lines, no EARS table |
| Where it lives | `.droost-workflow/spec-<slug>.md` | `.droost-workflow/tmp-spec-<slug>.md` (transient) |
| Document phase | Realized plan vs. spec, recorded in the run | Present the realized quasi-spec + change summary **in chat** at the end |
| Git | `.droost-workflow/` is **gitignored by default** (the pack appends the entry); tracking specs is an opt-in a repo makes deliberately | same |

Both weights walk all four phases. Light's test phase keeps the minimum
that makes a run honest: the static pair + `rendered_check` (browser
verification at minimum — a run that stops checking whether the page renders
is not fast, it is blind).

## Enforcement semantics (Claude Code hooks)

The pack gains hook scripts, materialized alongside skills/commands, wired
via `.claude/settings.json`:

- **PreToolUse (Edit | Write | MultiEdit | NotebookEdit):** active run in
  `plan` → the edit is out of phase. `hard`: deny with "the run is in plan —
  finish the spec first (or lower `enforcement`)". `soft`: warn once per
  phase, allow.
- **Stop:** active run mid-phase → `hard`: refuse to end the turn with
  "advance or abandon the run (`/droost-work …`)". `soft`: reminder, allow.
- Every script self-gates on run state first (decision 5): **no run, no
  opinion.**

**Codex parity, stated honestly:** Codex has no PreToolUse equivalent.
On Codex, enforcement is the run-level machinery (gates, run state, the
engine refusing to advance past failures) plus AGENTS.md discipline. The
yml lever still applies to what the engine itself enforces on both surfaces.

## Custom gates

The gate vocabulary opens without droost/workflow having to know any tool:

```yaml
gates:
  # ... the named gates as today ...
  custom:
    semgrep:  { on: true, phase: code, cmd: "semgrep scan --error --quiet" }
    behat:    { on: false, phase: test, cmd: "vendor/bin/behat" }
```

Pass = exit zero, run by the existing `ShellGateExecutor`; `phase` places it
in the map (`code | test`; everything enabled re-runs at `complete` as
today); reports name custom gates like first-class ones, and a custom gate
whose binary is missing reports **tool missing**, never passed.

## Sub-agents (pack, `.claude/agents/`)

- **researcher** — used in plan: grounds the spec in reality via droost's
  read tools (capabilities, entities, routes, guidelines) or, siteless, the
  repo; returns findings, writes nothing.
- **spec-writer** — drafts the EARS table (factory) or quasi-spec (light)
  from the conversation + researcher findings; the main loop reviews it into
  the spec file.
- **bug-fixer** — used in test's feedback loop: takes ONE failing gate
  finding, fixes the cause, reports; the engine's retry budget
  (`max_gate_retries`) still counts the attempts.

Claude-side assets like the skills; Codex runs without them.

## Work breakdown

| # | Piece | Touches |
|---|---|---|
| W1 | `fast` → `light` rename + quasi-spec semantics | `PresetResolver`, `Preset`, pack yml, skills (plan/document read the weight), docs |
| W2 | `enforcement` lever + hook scripts + settings wiring | `WorkflowConfig`, pack (`hooks/`), materialization, docs |
| W3 | `gates.custom` vocabulary → engine + reports | `GateSettings`, `PhaseGateMap`, `GateRunner`, report naming |
| W4 | Phases mandatory; `phases:` deprecated (warn + full sequence) | `WorkflowConfig`, docs |
| W5 | `/droost-work` entry command (folds run; status stays) | pack commands |
| W6 | Three sub-agents | pack `agents/` |
| W7 | `.droost-workflow/` gitignore-by-default + tmp-spec path | pack materialization, `State` |
| W8 | Tests for all of the above + the lint gate green | `tests/` |

Sequencing: W1+W4 (config truth first), W3 (gates), W2 (enforcement rides a
correct config), W5–W7 (pack), W8 throughout. Version: **0.3.0** (breaking:
preset rename, phases mandatory — fine pre-1.0, and droost's submodule pins
`^0.2` today, so droost bumps to `^0.3` when it adopts).

## Non-goals, restated

- No auto-entry into runs; no policing of normal conversation.
- No ticket systems.
- No new gate *implementations* in this package beyond the custom-gate
  runner — semgrep et al. remain the repo's own tools.
