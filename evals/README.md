# Evals of what the pack makes an agent do

`claude plugin eval` (Claude Code 2.1.269 and later) runs each case against a
plugin and, by default, against no plugin, and scores both. `droost-pack/` is
a plugin made of the pack's own plan skill (a symlink, so the eval reads the
skill as it ships) and a mocked droost server.

This measures what an agent CHOOSES with the skill and without it. It never
measures a gate: an eval session loads no project configuration, so droost's
guard is absent, and its MCP server is a mock. The router's answers are
tested where they are made (droost's `DecisionGraphTest`); a mocked router
here would grade itself.

| Case | Holds the agent to |
|---|---|
| `landing-page` | a home page with a camp list: asks the router, declares `--kind=page --owner=canvas_page`, never a View page |
| `collection-page` | the rink directory: `--kind=collection --owner=view_page` |
| `detail-page` | one rink's page: `--kind=detail --owner=entity_view_display` |

Run from `evals/droost-pack/`:

```bash
claude plugin eval . --runs 1 --trust-plugin \
  --allow-tools mcp__plugin_droost-pack_droost__droost_decide
```

First reading (2026-10-02, Claude Code 2.1.287, one run per arm): with the
plugin 1.00 on all three, without it 0.33 on all three, so each case fails
without the skill. The first run found that the plan skill let an agent
declare a page from the defaults without asking the router; the skill now
tells it to ask. A plugin's MCP tool is named
`mcp__plugin_<plugin>_<server>__<tool>` in an eval session.

Not in CI: a run is a paid model session, and the repository holds no key
for one. Run it before a release that changes a skill.
