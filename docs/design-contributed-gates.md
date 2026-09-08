# Design — D72 Extension seams: gate mode, contributed gates, skills over slash commands

Status: **BUILT 2026-09-08.** Workflow package: `f328cbc` (mode), `1a90fdf`
(contributed gates), `38826b2` (skills, host-advisory enforcement). droost:
`4792077` (the `#[DroostGate]` plugin type and catalog), `78ae8fb`
(`droost_snyk`). Owner-directed 2026-09-08, from the hook-system
conversation.

## 1. What the owner asked

Three things, in their words:

- *"A contrib module would be allowed to be enabled and used sort of like
  drush commands."* Enable a module, get its gate.
- *"During the coding phase someone implements semgrep or snyk … the module
  would have to be clear on what the enforcement meant. If snyk has findings
  then do you block the phase or simply report it. But if I want to decide
  that we should not let the coding phase continue if there are snyk
  findings then we have to enforce the gates."*
- *"Slash commands are not universal across the board (in codex). So maybe
  we steer clear from them entirely with instead commands or skills to
  invoke."*

## 2. The fact that shaped it

A gate was always blocking. Its result was passed, failed, off, skipped,
tool-missing or waived; failed spent the retry budget and, exhausted, only an
operator waiver reopened the phase. And `enforcement: soft | hard` was never
about gates — it governs whether the agent may edit out of phase while a run
is active. "Report mode" had to be built, and the smallest honest shape was
a second switch on every gate.

## 3. Gate `mode`

`mode: block | report`, after `on`. Block is the default and is not recorded,
so an unchanged file resolves to unchanged levers. Report turns a blocking
outcome into a seventh status, `REPORTED`: the tool's own summary and
findings are kept, prefixed `report — … (mode: report; would block in mode:
block)`; the phase advances; the tally shows `N reported`; `isPass()` is
false. The mandatory trio cannot be put in report mode — noticed and
superseded like `on: false`. The mode is frozen into the run at begin, like
`on`: whether a gate may block is not a mid-run tuning.

## 4. Contributed gates

`Droost\Workflow\Config\ContributedGate` is the value object a module hands
the engine: `id`, `provider`, `phases` (code, test), `command`, `defaultMode`
and a **required** `verdict` sentence — a gate is a sentence a human can
read, never a bare exit code. It resolves as `module:<id>` (a distinct
prefix from `custom:`, so a repo's own gate and a module's never collide and
provenance is legible from the name), on by default because enabling the
module was the opt-in, woven into its phases and complete like a custom gate,
run through the shell like one, with `provider` and `verdict` riding the
resolved levers into run.json and every report. A failure repeats the
sentence.

`WorkflowConfig::load($root, $contributed)` takes the declared list; the
lever file's `gates.contributed.<id>` overrides `on` and `mode` and nothing
else — the command, phases and verdict are the module's contract, and a
different scan is a `gates.custom` entry. An undeclared id is refused when a
catalog is known; a caller without a catalog leaves the block unresolved
rather than mistaking a gate it cannot see for a typo. The dial never moves
a contributed gate; `effort` lists them separately.

On the Drupal side: the `#[DroostGate]` attribute on a class under
`src/Plugin/DroostGate/` extending `GateProviderBase`; `DroostGatePluginManager`
(alterable via `hook_droost_gate_info_alter`); `GateCatalog` turning
definitions into declarations and refusing a malformed one loudly, plugin and
module named. Both live surfaces — the drush commands and both MCP tools —
hand the catalog to their facade, so a run is held to the same set whichever
door it came through: the lesson of the drush surface that fired no lifecycle
hooks, applied before it could recur.

**`droost_snyk`** is the reference: `snyk test --severity-threshold=high` at
code and test, report mode; a Semgrep gate is the same five lines. The
verdict sentence covers exit 0, 1 and 2, and a missing binary reads as
tool-missing, recorded in report mode.

## 5. Skills over slash commands

The pack's three entry verbs — start, continue, status — are skills
(`workflow-start`, `workflow-continue`, `workflow-status`) that every host
reads; the `/droost:workflow:*` slash commands are one-paragraph Claude Code
pointers holding nothing of their own. The continue skill teaches the
baseline and the contributed gates; the seeker's lens 7 names a moved
baseline and a quieted contributed gate as defeats. MCP prompts, the
protocol-level command, were checked and are not available: mcp_server ships
no Prompt plugin type. Skills are the portable artefact.

## 6. Host-advisory enforcement

The write wall and the phase guard are Claude Code hooks. The status
document's run half now carries `enforcement: {requested, effective, reason}`;
on a declared host surface without pre-tool hooks (codex, other, none) `hard`
reads as `advisory` — the gates still hold the run server-side and the briefs
carry the rules, but nothing stops an out-of-phase edit, and a report must
not claim a discipline the host never had.

## 7. Not built, on record

- **Pack pieces contributed by modules** (skills, agents, hooks): only
  droost_workflow's pack is materialized. The Jira intake command on EMT was
  placed by hand.
- **PHP-executed contributed gates** (a plugin `run()` the site driver
  dispatches): command gates cover Snyk and Semgrep; the seam is the next one
  a module needs.
- **Provider plugin types** where droost asks rather than tells: the
  `WorkItemProvider` type, parked with the Jira decision.
