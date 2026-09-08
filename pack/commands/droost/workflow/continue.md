---
title: Droost Workflow — continue
purpose: Advance the workflow run in progress — including after a stop or in a fresh session — through plan, code, test and complete. Loads the workflow-continue skill.
---

Load the **`workflow-continue`** skill and follow it. In short: one phase per
invocation, resumed from `droost/droost-workflow/run.json` — the frozen
`resolved_gates`, the phase map, the retry counters, the seeker record — never
from memory; the mandatory trio, the seeker checkpoint, the dial you propose
but never move, the adoption baseline you never touch, the contributed gates
whose mode is the site's to set. (Until 0.4 a single `/droost-work` did both
start and advance; the honest split is start vs continue.)

This file is Claude Code's slash-command way of invoking that skill and holds
nothing of its own: every host reads the skill, so the procedure is the same
wherever the run is driven from.
