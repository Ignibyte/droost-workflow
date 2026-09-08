---
title: Droost Workflow — start
purpose: Begin a NEW workflow run — REQUIRED before building ANYTHING. Loads the workflow-start skill.
---

Load the **`workflow-start`** skill and follow it. In short: refuse to
clobber an existing `droost/droost-workflow/run.json` (resume or reset
instead), write the spec, invoke the run surface declaring it, declare the
browser and task surfaces, then hand off to `/droost:workflow:continue` —
the `workflow-continue` skill.

This file is Claude Code's slash-command way of invoking that skill and holds
nothing of its own: every host reads the skill, so the procedure is the same
wherever the run is driven from.
