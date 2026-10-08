---
name: intake
description: Plan a WHOLE site from a design source with the human, before anything is built — a Replit or Vite app, a static HTML folder, a zip of either. Read the source with droost-source, propose the Drupal content model (content types, fields, vocabularies, Views, landing pages, Webforms, menus, components, custom code), put it to droost, ask the human what the source cannot say, write the roadmap of rungs, and hand it to the operator to approve. Use when the human says "build me a site off this …". Claude Code also offers it as /droost:intake.
---

The human has handed you a design and asked for a site ("build me a site,
no mistakes, off this html"). Your job here is the PLAN, made with them:
what the source holds, what each part of it becomes in Drupal, what only
they can decide, and the order it gets built in. **Nothing is built in an
intake.** While one is open, no run opens; the operator approves the
roadmap, and only then does each rung run through `/droost:workflow:start`.

This is not for one feature on a site that exists: that is a run
(`/droost:workflow:start`). An intake is for a source that becomes a site.

## The record you are held to

Everything lives in `droost/intake/`, and `intake check` says what approval
still needs. Six things are checked, and none of them judges whether a
decision is good (that is the human's, at approval):

- **files**: `audit.json` (written by the audit, never by you), `model.md`,
  `questions.md`, `roadmap.md`, and each rung's ticket in `tickets/`;
- **evidence**: every id a decision cites is in the audit;
- **coverage**: every route, every repeated structure and form in a page's
  main content, every set of records the source declares, and the frame
  (header and footer) is decided, or set aside with a reason;
- **consulted**: the model, as it stands, was put to droost;
- **answered**: every question has the human's answer on record, and your
  file says what the record says;
- **ladder**: rungs in order, each consuming one below it, each with its
  ticket, and every construct shown only on pages a rung at or after its own
  builds.

The commands are drush on a site (`drush droost:workflow:intake <verb>`) and
the binary on a checkout (`vendor/bin/droost-workflow intake <verb>`). Under
DDEV, prefix `ddev`.

## 1. Open the intake

```
drush droost:workflow:intake start --request="<the human's words, verbatim>" --source=<path or URL>
```

The request is their words, exactly as they said them: it is the record of
what was asked. Unpack a zip first (into the project, never into
`droost/droost-workflow/`), and name the folder as the source.

## 2. Serve the source, then audit it

The audit reads the source the way a visitor sees it, so it needs the
source served, and it needs Playwright (the project's `node_modules`, which
the browser gate already uses). Run the server **where the audit runs**:
under DDEV the audit runs in the web container, so serve the source there
too (`ddev exec …`), or give the audit a URL the container reaches.

- **Static HTML**: `vendor/bin/droost-source serve <folder> --port 4321`,
  in the background; add `--static` to the audit so each HTML file is read
  as a route.
- **A Replit or Vite app** (`package.json`, often a pnpm workspace): install
  and start its dev server as its README or `replit.md` says. Replit apps
  read `PORT` and `BASE_PATH` from the environment; a pnpm workspace may pin
  native packages for another platform, which `pnpm install` then refuses —
  read the error before changing anything. The app's own files are the
  `--files` folder: the audit reads its router, its data files and its
  schemas from there.

```
drush droost:workflow:intake audit --url=<where it is served> --files=<the source folder>
```

This writes `droost/intake/audit.json` and `audit.md`, and records the
audit's sha256 where you cannot write. **Read `audit.md` whole** before you
decide anything. Never edit `audit.json`: a changed audit fails the check,
because every decision cites it. If the audit missed a route (one behind a
form, say), run it again with `--routes=/that,/other`.

### Reading the audit

- **Routes**: the router's own table, a crawl from `/`, and patterns
  (`/events/:id`) with the instances the crawl found. A route that reads as
  the source's not-found page is marked so.
- **Repeated structures** (`p:/events#r1`): three or more siblings of one
  shape. `cards` and `items` in a page's main content are what content types,
  Views and lists are made of; `links` are menus; `chips` and `buttons` are
  usually a filter bar, tags or decoration. Each slot says whether it
  **varies** (a field) or is the same in every item (a label, never a
  field), and what pattern its values share (price, date, phone).
- **"renders d:…#EVENTS"**: the structure's values are a set of records the
  files declare, slot by slot (`= date`). That is the strongest evidence a
  content type has: the fields are the record's, the content is the
  records'. A structure on two pages rendering the same records is one type
  shown twice (a listing and a home-page block), never two types.
- **A section that "shows" one record** is a spotlight: a View block or a
  reference field, not a type of its own.
- **Data**: records (exported or kept in a page), the interfaces that type
  them, database tables, API paths. A schema with no table, or an API with
  only a health check, is a template's scaffolding: set it aside.

## 3. The content model: `droost/intake/model.md`

Write it in the shape droost's consult reads:

```markdown
# <Site> — content model

## Tooling plan

| Construct | What it is | Evidence | Rung | Confidence | Why |
|---|---|---|---|---|---|
| Event | a content type | p:/events#r1, p:/#r2, d:src/data/events.ts#EVENTS | 2 | high | six records the event cards render |
| Event: date | a field | p:/events#r1 | 2 | medium | free text in the source ("Mar 4–6"): a date range, to ask |
| Events listing | a View page | p:/events#r1, r:/events | 2 | high | |
| Contact form | a Webform | p:/contact#f1 | 3 | high | |
| Main menu | a menu | frame | 1 | high | the header's links |

## Routes

- / — the home page, a landing page
- /events — the events listing

## Not built

| Evidence | Why not |
|---|---|
| d:lib/api-spec/openapi.yaml#api | a template's health check, no content |
```

- **One row per construct**: each content type, each field (`Type: field`),
  each vocabulary, View display, landing page, Webform, menu, block,
  component and piece of custom code. What it is goes in its own column, in
  droost's words for a kind ("a content type", "a View page").
- **Evidence** cites the audit's ids. Every structure, form and set of
  records the check names must be cited by a construct or set aside.
- **Rung** is the roadmap's rung that builds it.
- **Confidence** says how sure the source makes you; a low one is usually a
  question.
- **Every page** under `## Routes`, as `- /path — what it is`.

## 4. Put it to droost

Call `droost_consult` with `spec=droost/intake/model.md`. droost answers each
construct with the kind it reads it as, how this site builds that kind, and
the page practice here (Canvas, SDC, Views). **Its answers are advice**:
build each as you judge, and say why in the row when you differ. Revise the
model, and **consult again after every change**: the check holds the model
to its latest consult. Use `droost_decide` for a construct whose kind you
are unsure of.

## 5. Ask the human: `droost/intake/questions.md`

Ask only what the source cannot settle, and never what it can (the audit
already says how many records there are). What a source cannot say:

- **who edits what**: which content an editor changes, and which is fixed
  copy;
- **what is real**: whether the source's records are the site's content or
  placeholders;
- **where things go**: a form's submissions, a booking, a payment;
- **what is out of scope**: a backend the source sketches, accounts, a
  second language;
- **what a free-text value is**: a date written as words, a price that is
  sometimes "FREE".

```markdown
| Id | Question | Why the source cannot settle it | Recommendation | Answer | Decided by |
|---|---|---|---|---|---|
| Q1 | Who edits the events? | the source hardcodes them | Staff editors, in the admin | Staff editors | human |
| Q2 | Are the six events real? | events.ts holds them | Import them as the first content | Defer to your recommendation → import them | recommendation |
```

**Ask each through AskUserQuestion**, with the question's words exactly as
the file has them (up to four in one call). Put your recommendation first,
labelled `(Recommended)`, then the alternatives, and always an option to
defer ("Defer to your recommendation"); the human may also type their own
answer, or say they do not know. Then:

- copy the answer they chose or typed into **Answer**, verbatim;
- **Decided by** is `human` when they decided, and `recommendation` when they
  deferred or did not know: then write your recommendation after their answer
  in the same cell (`Defer to your recommendation → import them`), and the
  model follows it.

The guard records each answer as the human gave it, in a file you cannot
write; `intake check` holds `questions.md` to that record. On a host with no
AskUserQuestion, the operator records each answer in their terminal
(`drush droost:workflow:intake answer "<question>" "<answer>"`).

Revise the model with the answers, and consult again.

## 6. The roadmap: `droost/intake/roadmap.md`

```markdown
| Rung | Ticket | Builds | Consumes | Pages |
|---|---|---|---|---|
| 1 | tickets/R01.md | the theme's tokens and the frame | — | frame, / |
| 2 | tickets/R02.md | Event, its six events, /events, the home page's three | 1 | /events, / |
```

- **Design from the first rung**: rung 1 builds the theme's tokens, the
  frame (header, footer, menus) and the home page's shell, held to the
  source's look; each later rung builds its pages as the source shows them.
- **Each rung consumes one below it**, and builds something whole: a type
  with its content and its pages, never "all the types" then "all the
  pages".
- **A page that grows** (the home page's sections) is listed in every rung
  that adds to it.
- **Each ticket** (`tickets/R01.md`) says what the rung builds, what it
  consumes, its acceptance criteria from the source (what a visitor sees and
  an editor can do, never how it is built), and its pages, which are its
  parity routes.

## 7. Check, then hand it to the operator

```
drush droost:workflow:intake check
```

Repeat until it reads `ready: true`. Then show the human the roadmap in a
few lines (the rungs and what each builds, the decisions you made for them),
and ask them to approve it **in their own terminal**:

```
! drush droost:workflow:intake approve
```

Approving, abandoning and answering are the operator's; the guard refuses
them from your shell. Do not start a run before approval: it is refused, and
the refusal says why. After approval, each rung runs through
`/droost:workflow:start` with its ticket, one at a time, in order.
