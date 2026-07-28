# Using droost from the workflow

[droost](https://www.drupal.org/project/droost) is what the agent knows about
Drupal; this workflow is how it works. Every phase leans on it, and every
phase has to cope with it being unavailable.

## The one thing to understand: no site, no droost

**Every droost tool needs a booted site. All of them, without exception.**

They are Drupal plugins, served over MCP by a running application. There is
no subset that works on a plain checkout — not even the scaffolding tools,
whose underlying generators are Drupal-free but which are only *reachable*
through a booted container.

So on the CLI surface, with no site, you do not have a reduced droost. You
have none. Plan without grounding, build by hand, and be explicit in every
artefact about which of your foundations you could not check.

This is worth stating plainly because the opposite assumption is the easy
one to make, and it produces exactly the failure this workflow exists to
prevent: an agent that believes it verified something it never could.

## What droost_verify actually does

It runs static and test legs over a target — **and which legs run depends on
what you ask for**:

| Call | Legs that run |
|---|---|
| `droost_verify` with no `checks` | **phpcs and phpstan only** |
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

## When a tool is unavailable

Say so, in the artefact you are producing, where the person reading it will
see it.

The failure mode this workflow exists to prevent is not "a tool was
unavailable" — that is normal. It is a run that could not check something,
did not say so, and produced a report indistinguishable from one where
everything was verified. An unchecked thing recorded as unchecked is fine. An
unchecked thing recorded as passed is a lie the next person acts on.
