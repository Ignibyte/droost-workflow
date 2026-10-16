# Using droost from the workflow

[droost](https://www.drupal.org/project/droost) is what the agent knows about
this site — its code, its structure, its live state; this workflow is how it
works. Every phase leans on it, and every phase has to cope with it being
unavailable.

## How to call it

droost's tools are reachable two ways, and they are the same tools with the
same record: **through MCP**, when the project's editor config names droost's
server (`mcp__droost__<id>`), or **by command**. Use whichever your host
offers. Each MCP call runs the same command underneath, as a fresh process,
so an answer always comes from the code on disk.

By command there are two: one for the code, one for the live site.

```bash
vendor/bin/droost                                        # the code: every tool, one line each
vendor/bin/droost droost_symbol '{"name":"NodeType"}'    # run one: JSON in, JSON out
drush droost:tool                                        # the live site: every tool, one line each
drush droost:tool droost_entities help                   # one tool's arguments (`<id> help`, either command)
drush droost:brief                                       # the site's facts and house rules
```

`vendor/bin/droost` answers about the code: symbols, the code graph,
lexical search, wiki pages, module docs, deprecations, and the last brief.
It boots no Drupal. It reads the code index droost writes and the files on
disk, so it answers while the site is broken, which is when you most need
it. `drush droost:tool` answers about the running site: entities, fields,
routes, services, config, views, the database, semantic search, and every
tool that writes. It needs a site that boots.

On DDEV, run both in the container: `ddev exec vendor/bin/droost …` and
`ddev drush droost:tool …`. Each prints the tool's answer as JSON and exits
1 when the tool says no. Every call is recorded in the run's tool-call
ledger, whichever door made it, and plan reads that ledger.

## What needs what

- **`drush droost:tool` needs a booted site.** Its tools are Drupal plugins
  run against the site.
- **`vendor/bin/droost` needs the code index**, which a site writes
  (`drush droost:search:index`). On a checkout that has never had a site,
  there is no index, and it says so rather than answering empty.

So on a checkout with no site and no index, you have no droost. Plan without
grounding, build by hand, and be explicit in every artefact about which of
your foundations you could not check. This is worth stating plainly because
the opposite assumption is the easy one to make, and it produces exactly the
failure this workflow exists to prevent: an agent that believes it verified
something it never could.

## When a call fails because the site did

A `ddev snapshot restore`, a `ddev restart`, a failed module install or a
fatal in a plugin you just wrote can leave the site unable to boot. Then
every `drush droost:tool` call fails the same way, with the site's error.
`vendor/bin/droost` still answers about the code: use it to find what broke.
Fix the site (read `drush watchdog:show`, or the fatal drush prints), then
call again. Nothing is repeated for you, so read the state before repeating
a call that writes.

## What droost_verify actually does

It runs static and test legs over a target — **and which legs run depends on
what you ask for**:

| Call | Legs that run |
|---|---|
| no `module` and no `path` | **none at all** — it returns an inventory of what could run. Useful, but it is not a run and must never be reported as one |
| a target, no `checks` | **phpcs and phpstan only** |
| `checks: [deprecations]` | deprecations — it is opt-in, never a default |
| `checks: [phpunit], confirm: true` | phpunit — it needs `confirm` as well, because the suite creates and drops databases |

Getting this wrong is easy and costly: a plain call returns "passed" having
run two static checks, and an agent that assumed all four ran will report
tests as green that were never executed.

**None of the legs render anything.** `droost_verify` does not fetch a URL
and does not check that the site works. It checks that the code is
well-formed, well-typed, and — when asked — that its tests pass. Those are
valuable, they are not "the site works", and a report that blurs the two
misleads in the direction that matters.

## Three ways a tool is present but will not help you

Having a site is necessary and not sufficient. Each of these produces a
result that is easy to misread:

**1. Some tools need an index that may never have been built.**
`droost_capabilities` and `droost_architecture` fail cleanly and tell you to
run `drush droost:brain:build`. `vendor/bin/droost` fails when there is no
code index and tells you to run `drush droost:search:index`. `drush
droost:tool droost_search` (semantic) can return **empty** when its vectors
were never built, which reads exactly like "this project has no
documentation". If a search comes back empty, check whether the index was
ever built before you conclude anything from it.

**2. Every write tool is switched off until the site opts in.**
`droost_scaffold`, `droost_structure_create`, the entity writers and
`droost_config_set` each refuse unless their flag is set in droost's settings
(`allow_scaffold`, `allow_entity_write`, `allow_config_write`, or the master
`allow_destructive`). The refusal names the flag. That is a configuration
answer, not a broken site and not something to work around — report it and
stop.

**3. Some tools live in submodules that may not be installed.** The brain,
codebase search and the wiki ship in droost core — so `droost_capabilities`,
`droost_architecture`, `droost_search`, the wiki tools and every
`vendor/bin/droost` tool are there whenever droost is. What genuinely may be absent
are the backend-bridging submodules — Canvas, Devel and AI tooling. A
perfectly healthy site can be missing any of those; `drush droost:tool`
lists only what this site has.

## When a tool is unavailable

Say so, in the artefact you are producing, where the person reading it will
see it.

The failure mode this workflow exists to prevent is not "a tool was
unavailable" — that is normal. It is a run that could not check something,
did not say so, and produced a report indistinguishable from one where
everything was verified. An unchecked thing recorded as unchecked is fine. An
unchecked thing recorded as passed is a lie the next person acts on.
