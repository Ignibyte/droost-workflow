# droost reference

The topics the [README](../README.md) points to. People new to droost should
start at [droost.org](https://droost.org/docs/getting-started).

| Topic | What it covers |
|---|---|
| [Configuration](configuration.md) | `droost/droost.yml`: the levels, every gate and its options, storage, the wiki and semantic search |
| [A project's own rules](project-rules.md) | a baseline for existing problems; the project's own gates, hooks and walls; house rules |
| [Gates, criteria and the feedback loop](gates.md) | which gate runs at which phase, how each requirement is matched to its proof, what happens when a gate fails |
| [The intake](intake.md) | a site from a design, planned and approved before it is built; who finishes a run |
| [Tickets](tickets.md) | work items from a folder of Markdown tickets, or from a cockpit |
| [Run state and run events](run-state.md) | what a run records, and the events other tools can read |
| [The pack and the run panel](pack.md) | what `init` installs for each agent harness; the run panel beside the chat |
| [The guard](guard.md) | what it refuses, the operator's commands, which harnesses it covers |

## Design notes

Why droost is shaped the way it is, written when each part was designed.

- [design.md](design.md): the original design.
- [design-0.3-enforcement.md](design-0.3-enforcement.md): enforcement, from 0.3.
- [design-adoption-baseline.md](design-adoption-baseline.md): the adoption baseline.
- [design-effort-presets.md](design-effort-presets.md): the effort presets.
- [cockpit-provider-api.md](cockpit-provider-api.md): the cockpit provider API.
