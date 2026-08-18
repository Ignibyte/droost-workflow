---
title: Droost Workflow — run (moved)
purpose: The pipeline entry point moved to /droost-work in 0.3; this command remains as a pointer.
---

The entry point is **`/droost-work`** — start a run or advance the one in
progress there. This name remains so muscle memory from 0.2 lands somewhere
useful rather than on "unknown command".

Everything that used to be documented here — the engine loop, frozen levers,
retry budgets, the phase-gate map — lives in `/droost-work` now.
`/workflow:status` is unchanged: it inspects, `/droost-work` acts.
