---
name: workflow-document
description: Phase 4 of the Droost Workflow. Capture what was built and why, while the reasons are still in reach.
---

# Document

Write down what was built, for the person who arrives after the run — often
a later run of this same pipeline, with none of this context.

This phase is droppable. A run configured without it is a legitimate run, and
that is a decision the repo made in its lever file, not one you make here.

## Entry gate

- The test phase passed, or the run is configured without one.

## Work

Capture three things, in descending order of how fast they decay:

1. **Why.** The decisions that are not visible in the diff — what was
   considered and rejected, what constraint forced the shape. This is the
   part nobody can reconstruct later, so it is the part worth writing first.
2. **What.** The realized plan: what actually got built, and where it differs
   from the spec. It will differ. A document that hides the difference is
   worse than none, because it teaches the next reader to trust the spec over
   the code.
3. **How to use it.** Module READMEs and a change summary, aimed at someone
   who was not here.

Tools that help:

- `droost_search` and `droost_guidelines` — find how this project already
  documents things, and match it rather than inventing a house style. Note
  that `droost_search` returns nothing at all on a site whose index was never
  built (`drush droost:search:index`), and an empty result reads exactly like
  "this project documents nothing" — check before concluding.
- `droost_wiki` — **read-only**. The default kind lists and reads the site's
  knowledge pages; `kind: status` reports which are stale and which modules
  have no page; `kind: factsheet` pulls a module's generation packet, which is
  what you write FROM.
- `droost_wiki_write` — the one MCP tool that writes the wiki, and the reason
  this phase can now leave the project's own documentation sound instead of
  merely reading it. You supply the body; Droost composes the provenance from
  the factsheet and the current commit, strips any frontmatter you emitted,
  and rolls the write back unless the page verifies fresh. Gated behind
  `droost.settings.allow_scaffold`. **Follow the `documenting-changes` skill**
  — it carries the procedure and the rule about what a page should say.
  (`drush droost:wiki:generate` still exists for batch regeneration; it needs
  an AI provider configured on the site, which this path does not.)

Write what is true, not what sounds finished. If a gate was skipped, say so
here too; documentation that quietly upgrades a skipped check into a passed
one is how a run's record stops being worth reading.

## Exit gate

Someone who was not present can read what you wrote and understand what
changed and why.

The engine runs no shell gates at this phase; the full set re-runs at
complete, immediately after.

## Without a site

`droost_search`, `droost_guidelines` and `droost_wiki` all need a booted
site. With none, you cannot read what the project already says, let alone
match it.

Write the documentation into the repo — module READMEs, a change summary in
the run's own artefacts. That is where it belongs anyway.

The wiki is a site-dependent surface, so with no site you cannot read it and
cannot update it. Say that plainly, and say which modules you would have
documented. Do not report the wiki as fresh, and do not report it as stale
either — you did not look. A run with a site would have used
`droost_wiki_write`; this one could not.
