# The intake, and who finishes a run

A site from a design, planned and approved before it is built; and whether a person or the agent closes a run.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## The intake: a site from a design, planned before it is built

A run builds one ticket. When the human hands over a whole design ("build me
a site off this html": a Replit or Vite app, a folder of HTML) the work
before the first ticket is deciding what the source becomes, and that is the
intake: the `intake` skill (`/droost:workflow:intake`), with the human, and nothing
built until they approve.

```
droost workflow:intake start --request="<the human's words>" --source=<path|URL>
droost workflow:intake audit --url=<where the source is served> --files=<its folder>
droost workflow:intake check
droost workflow:intake ask --file=<the batch, in droost/intake/>   # a host with no AskUserQuestion
droost workflow:intake approve          # once the human has said yes
```

- **`droost-source`** (`tools/droost/vendor/bin/droost-source`, Node, the project's own
  Playwright) reads the served source and its files once, the same way every
  time: the routes (the router's table, a crawl, a pattern's instances), each
  page's outline, sections, repeated structures (each slot: a field that
  varies or a label that does not, its values' pattern, the record field it
  shows), forms, media, the frame's menus and the tokens, and the data the
  files declare (records, the interfaces that type them, database tables,
  API paths; a template's empty schema says so; a field whose every value
  names a record of another set, a reference rather than text). `intake audit` runs it and
  records `audit.json`'s sha256 where the agent cannot write. `serve <dir>`
  serves a static source.
- **The agent** writes, in `droost/intake/`: `model.md`, a `## Constructs`
  table (each construct, what it is, the audit ids that are its evidence, the
  rung that builds it) with its `## Routes` and a
  `## Not built` for what it sets aside; `questions.md`, what only the human
  can say, each with a recommendation, asked through AskUserQuestion, or,
  on a host with none (a Droost Cockpit seat), put on record with `intake
  ask` for the host to show the human and answer through `intake answer
  --via=cockpit`;
  `roadmap.md`, the rungs in order, each consuming one below, each with its
  ticket in `tickets/`.
- **`intake check`** judges no decision (that is the human's), and closes
  the ways the human's part or droost's could be skipped or forged: the audit
  is the one the tool wrote; every cited id is in it; every route, structure,
  form, set of records and the frame is decided or set aside; every question's
  answer is on record (the guard writes it from AskUserQuestion; the operator
  from a terminal with `intake answer`) and the file agrees; the ladder is in
  order and shows nothing before the rung that builds it.
- **While an intake is open, no run opens.** The agent asks the human whether
  to approve the roadmap (AskUserQuestion, recorded by the guard, or `intake
  ask` on a host) and runs `approve` on their yes; `approve` is refused
  unless the human's latest answer to "Approve this roadmap?" is a yes, whoever
  runs it (an operator records their own with `intake answer`). The approval
  records how it was run and whose yes it was, and the sha256 of the four
  files and of each rung's ticket. `abandon` stays the operator's. Both archive the intake's ledgers, since a row written outside a
  run is the next run's and the intake's own reading of the site would
  otherwise satisfy the first rung's `plan_grounded`. Each rung then runs from its ticket, as any
  ticket does.

## Who finishes a run: human-led or autonomous

A run has a **lead**. In a **human-led** run the agent may answer the plan,
code and test holds, and the complete hold is a human's: it holds even in
agentic mode, and `droost workflow:answer` with no terminal is refused
there, recording nothing. An **autonomous** run is the agent's to finish,
complete included. `lead:` in `droost/droost.yml` sets the default (unset,
interactive mode is human-led and agentic autonomous); `droost workflow:run
--lead=<lead>` chooses it when a run begins; `droost workflow:lead <lead>`
changes it. Making a run autonomous where the levers would not needs the
operator's terminal; making one human-led is anyone's.

The human answers the complete hold **in the session**: the agent asks with
AskUserQuestion with the hold's own question word for word, the guard
records the dialog's result (never its input; a question sent already
answered is refused before it opens, and an answer to any other question is
not taken) in
`hold-answers.jsonl`, which the agent cannot write, and the agent's next
`workflow:answer` takes the human's words from there. Where the question
wall is not wired, nothing is taken from that record. The operator's
terminal (`droost workflow:answer "…"`) is the fallback, for any host. Every
answer records where it came from (`via`: `agent`, `question`, `terminal`),
and the workchain report counts them.
