---
title: Droost Workflow — status
purpose: Report which levers this repo resolves to and where the recorded run has got to. Read-only. Loads the workflow-status skill.
---

Load the **`workflow-status`** skill and follow it. In short: run the `status`
verb on whichever surface you have and report its two halves as returned —
the levers (every gate, `phase_gates`, the toolchain, the baseline, the
contributed gates) and the run read from `droost/droost-workflow/run.json`
(phases, gate reports, retries, the seeker record, the declared host and what
enforcement amounts to there). Never from memory.

This file is Claude Code's slash-command way of invoking that skill and holds
nothing of its own: every host reads the skill, so the procedure is the same
wherever the run is driven from.
