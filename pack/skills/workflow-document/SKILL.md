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
  documents things, and match it rather than inventing a house style.
- `droost_wiki_pages` and `droost_wiki_factsheet` — the site's own knowledge
  pages, where durable knowledge belongs.

Write what is true, not what sounds finished. If a gate was skipped, say so
here too; documentation that quietly upgrades a skipped check into a passed
one is how a run's record stops being worth reading.

## Exit gate

Someone who was not present can read what you wrote and understand what
changed and why.

## Without a site

`droost_search`, `droost_wiki_pages` and `droost_wiki_factsheet` all need a
booted site. With none, the site-hosted knowledge pages are out of reach.

Write the documentation into the repo instead — module READMEs, a change
summary in the run's own artefacts. Note that the wiki was not updated, so
that whoever next runs against the real site knows there is a gap to close.
