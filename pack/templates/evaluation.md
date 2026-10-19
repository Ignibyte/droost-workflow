# Evaluation — `<ROUND ID>`

Copy this file per round. Fill every cell or write `—` and say why in §7.3.
An empty cell is an unmeasured thing wearing the costume of a measured one.

> **The one rule the rest hangs from.** `run.json` is writable by the agent —
> the wall guards only `modules/custom` and `themes/custom` — so `phases` is
> the subject's claim about itself. **Score `gate_results`. Never `phases`.**

---

## 1. Round identity

| | |
|---|---|
| Round | `<id>` |
| Date (UTC) | `<iso>` |
| Ticket / request | `<one line>` |
| Subject | `<model, effort, host, version>` |
| Driver | `<tmux / interactive / CI>` |
| Elapsed | `<wall>` / `<subject>` |
| Operator interventions | `<count — list each in §7.1>` |

## 2. Environment — captured BEFORE the subject starts

Three of these are **unrecoverable afterwards**: gate arming, enforcement
effectiveness, and whether a patch truly landed. Capture them now or lose them.

| | Command | Value |
|---|---|---|
| Install shape | — | `registry / vcs / path-repo` |
| Package versions | `composer show -i \| grep droost` | `<each + source>` |
| Patches in force | `composer.json` → `extra.patches` | `<each + verified on disk?>` |
| Resolved levers | `droost workflow:status` | `<attach JSON>` |
| Knowledge freshness | `droost doctor` | `<every row>` |
| Project rule files | `droost workflow:status` → `.levers.hooks`, `.walls`, `.operator_only`, and `run.json` → `rule_files` | `<each file and the hash frozen at begin>` |

**Traps that have bitten here before:**

- `droost init` writes `preset: custom`, `enforcement: soft`,
  `max_gate_retries: 2`. The parser's absent-key defaults are `max`, the
  preset's, and `3`. **No installed repo sits at the documented defaults** —
  read the value, never assume it.
- A gate `on` with `toolchain.present: false` **blocks**. Record it in §2, not
  as a surprise in §4.
- On stock Drupal CMS the composer patcher may be absent from `vendor` and
  from `allow-plugins`, so `extra.patches` is silently ignored. Verify the
  patched bytes on disk.

---

## 3. The lever table

Every lever, the effort it ran at, and what it actually did. `Effort` is the
preset frozen into **this** run — a lever's meaning changes with it.

| Lever | Configured | Effort | Probe (configured?) | Expected | Observed | Fired? | Verdict |
|---|---|---|---|---|---|---|---|
| `mode` | | | `.levers.mode` | | | `.run.effective_mode` | |
| `preset` | | | `.levers.preset` | | | `.run.preset` (frozen) | |
| `enforcement` | | | `.levers.enforcement` | | | requested: the lever. OBSERVED: §7a of the generated evaluation (`guard_call` rows) | |
| `require_run` | | | `.levers.require_run` | | | guard refusal on a custom write | |
| `max_gate_retries` | | | `.levers.max_gate_retries` | | | `.run.feedback_attempts` | |
| `seekers` | | | **no pre-run probe** | | | `.run.seeker_history` | |
| `baseline` | | | `.baseline.present/honoured` | | | `.run.baseline_hash` | |
| `work_item` | | | lever file only | | | absent from `run.json` | |
| `walls` | | | `.levers.walls` | | | `guard-calls.jsonl` refusals citing the wall | |
| `hooks` | | | `.levers.hooks` | | | `hook-calls.jsonl` | |
| `operator_only` | | | `.levers.operator_only` | | | guard refusal of the script from the agent's shell | |
| — **gates** — | | | | | | | |
| `phpcs` | | | `.levers.gates.phpcs` | | | `gate_results` | |
| `phpstan` | | | ″ | | | ″ | |
| `phpunit` | | | ″ | | | ″ | |
| `eslint` | | | ″ | | | ″ | |
| `stylelint` | | | ″ | | | ″ | |
| `prettier` | | | ″ | | | ″ | |
| `mutation` | | | ″ | | | ″ | |
| `playwright` | | | ″ | | | ″ | |
| `parity` | | | ″ | | | ″ | |
| `coverage` | | | ″ | | | ″ | |
| `wiki_fresh` | | | ″ | | | ″ | |
| `house_rules` | | | ″, and `.rules` | | | ″ | |
| project gates | | | `.levers.gates.<id>` (`run:` / `cmd:`) | | | ″, and the file's hash at begin | |

**Levers that are NOT frozen into the run** — they can change under it, so
record when you read them: `require_run` (guard re-reads raw YAML on every
write), gate tuning options of type int/string (re-read at gate time), and
`work_item` (never enters `run.json`).

---

## 4. Gate verdicts — read from `gate_results`

A green is not a measurement. Classify every one.

| Gate | Status | Exit | `duration_ms` | Invocation recorded | Measured anything? |
|---|---|---|---|---|---|

**Discrimination — almost every gate can report green having measured nothing:**

| Reading | Verified? |
|---|---|
| `passed`, findings/totals parsed, `duration_ms > 0` | **yes** |
| `passed` + "nothing to analyse" | no — empty path set |
| `passed` with `duration_ms: 0` | no — **the tool never spawned** |
| phpunit `passed`, zero tests | no — `--do-not-fail-on-empty-test-suite` |
| playwright `passed`, empty suite | no — and **unlabeled** below `max` |
| `off — by preset <level>` | no — honest, not a pass |
| `off — by the lever file` | no — the repo disarmed it |
| `skipped-no-site` | no — `wiki_fresh`'s command could not run |
| `error-tool-missing` | no — **blocks**; a project gate file that is missing or could not start |
| `error-tool-failed` | no — crashed, timed out, or a project gate file exited anything but 0 or 1 |
| `reported` | a finding that did not block |
| `waived` | no — an operator overrode it |

**Asymmetries to encode in any scorer:**
- Crash-vs-finding is mapped for **four** tools only (eslint, prettier,
  stylelint, phpcs). For phpstan, phpunit, mutation and playwright a **crash
  scores as a failing gate** — a verdict on the code, when nothing ran.
- `stylelint` inverts the convention: exit 1 = fatal, exit 2 = findings.
- A project gate's verdict is its exit code: 0 passed, 1 failed, anything
  else — a crash, a timeout, exit 3 — did not run. Check its file's hash in
  `rule_files` against the one at its gate: a changed file is named there.

---

## 4e. Read the store, not this form, where the store knows

Since the evidence store landed, most of what follows is recorded rather than
reconstructed, and `droost workflow:evidence --write` renders it. It takes
the flag bare (it lands at `droost/evidence/<run>.md`) or with a path, and
reports the path it actually wrote. Fill this
form by hand only for the parts a machine must not answer — §5, §6 and §8 —
and for anything the store could not see.

What the store now holds that this form used to ask you to dig for:

| Question | Where |
|---|---|
| every attempt of every gate, not just the last | `check_result`, one row per attempt |
| whose fault a block was, and its remedy | `check_result.fault`, `.remedy` |
| whether a green still describes the current code | `check_result.subject_hash` |
| what the tool actually printed | `transcript` |
| which phase a tool call happened in | `tool_call.phase` |
| what the plan said it would change | `declaration`, and the `declared_files` / `declared_tests` checks |
| whether the spec was edited after the plan froze it | `run.spec_hash` |

## 4a. The tool-call ledger — what droost was ACTUALLY asked

`droost/droost-workflow/tool-calls.jsonl`, one line per tool result, successes
and refusals alike. This is the only place in the system that is not the
subject's account of itself: the agent cannot write here, and a refusal lands
here the same way a success does.

```bash
# the tally
jq -r .tool droost/droost-workflow/tool-calls.jsonl | sort | uniq -c | sort -rn
# refusals — the most informative rows in the file
jq -r 'select(.outcome=="fail") | .tool' droost/droost-workflow/tool-calls.jsonl | sort | uniq -c
```

| Measure | Value | Reading |
|---|---|---|
| Total calls | | |
| Distinct tools | | |
| **Knowledge calls** (`code:wiki`, `code:tree`, `search:arch`, `code:symbol`, `code:graph`, `code:docs`, `code:deprecations`, `search:code`, `brief`) | | **zero here means the run never asked the codebase anything**, whatever its grounding table says; at plan, `plan_grounded` holds a run that asked nothing |
| Refusals (`outcome: fail`) | | each one is a gate that was off, or an argument that was wrong |

**How it was built.** Of the files added under `web/modules/custom` and
`web/themes/custom`, which came from a `drush generate` (recorded in
`scaffolded.jsonl`), and which were typed. Nothing holds a file to one
way; the row is the record of what the run chose.

**House rules.** For each rule in `rules.house` (`droost workflow:rules`): its judge, and what judged it — the gate's verdict for a `gate`
rule, the seeker's finding (cited by rule id) for a `seeker` rule — and any
exception, with the operator who approved it.

| Rule | Judge | Verdict | Exception (approved by) |
|---|---|---|---|

## 4b. Grounding — the three tiers, and whether each was actually reached

`plan_grounded` is the only check that measures the agent's own research, so
it is the one most worth taking apart. Two halves fail independently, and a
pass on one tells you nothing about the other.

```bash
# what the spec claimed
sed -n '/^## Grounding/,/^## /p' droost/droost-workflow/*spec-*.md

# what the check said about it
droost workflow:evidence | grep -A3 plan_grounded
```

| Tier | Rows | Cited | Citations that resolved | Store that answered | Verdict |
|---|---|---|---|---|---|
| custom | | | | code index · PSR-4 · filesystem | |
| contrib | | | | code index · PSR-4 · filesystem | |
| core | | | | code index (when it holds core) · PSR-4 · filesystem | |

- **A tier with rows but no resolvable citation is not grounded.** Prose in
  `Found` is the agent's account of itself; the citation is what the project
  confirms. The check refuses a claimed tier that cites nothing, and a table
  whose only citations are `none:` rows.
- **`none:` rows are re-run, not read.** The most valuable row in the table is
  the one proving a duplicate was not about to be built, and it is also the
  easiest to type without looking. A `none:` claim about something the code
  index holds must fail, and needs a built index to be checked at all.
- **Half two is the ledger, not the table.** A citation proves the symbol
  exists; it can be copied out of a file. `plan_grounded` also holds a run
  whose ledger shows no `code:wiki` and no `code:tree` call — see §4a. Record
  both verdicts separately, or a run that cited well and looked up nothing
  scores as grounded.
- **Which store answered is the fact this section exists to record.** A
  citation that resolved on disk rather than in the index is true grounding
  and an index gap at once. Count them.

## 4c. Transcripts — what the tool actually said

**Generated.** `check_result` holds each gate's own output, so a reader never
has to take a summary's word for what a tool printed. Nothing to fill in here:
if you are reading this heading in a hand-written evaluation, the generator did
not run and you should say so rather than paraphrase a transcript from memory.

## 4d. The seeker's findings — what an adversarial read caught

**Generated**, from the ledger droost parsed rather than the agent's summary of
it, so the counts and the rows come from one place and cannot disagree.

Two facts a reader must not confuse, and the generated section distinguishes
them explicitly:

- **`seekers: off`** — nothing read this diff with intent. A statement about
  COVERAGE, not quality.
- **`seekers: on`, no findings** — something read it and found nothing.

`low` turns seekers off by preset. A round at `low` closes with no adversarial
read at all, and the record must say so rather than let a green be mistaken for
a reviewed green.

## 5. Build verdict

Against the spec's own acceptance criteria, **verified live** — not from the
run record, which is the subject's account of itself.

| # | Criterion (EARS) | How verified | Result |
|---|---|---|---|

## 6. Score — `S / V / B`

Three numbers, never collapsed: a product can be disciplined and useless at
once.

| | Score | Basis |
|---|---|---|
| **S** setup | `/10` | Could a stranger install it and build one thing with no operator rescue? **Zero if this round arranged setup in advance.** |
| **V** verification | `/10` | Of the gates claiming green, how many measured something (§4)? A gate that could not have failed scores zero whatever its status. |
| **B** build | `/10` | Did the requested thing get built, correctly, against §5? |

Comparable only to a round at the same preset, install shape and §2.

---

## 7a. Enforcement — was the hook there, and did it fire

**Generated**, from rows the guard wrote about itself and the engine ingested at
phase close. This is the only place that answers "was the discipline real on
this host", as opposed to "was it configured" — a host without pre-tool hooks
holds `hard` with nothing, and §3's lever table says what was asked for, not
what the host could deliver.

## 7. Observability — the entire chain

### 7.1 The chain, link by link

A lever only means something if every link holds. Record what you observed at
each, and where a link is dark say so — a dark link makes every verdict
downstream of it provisional.

THE STORE IS THE RECORD. Links 5 to 7 used to read `gate_results` out of
`run.json`; that is a subject-writable file and it now carries a slimmed
summary for older readers, not the evidence. Every verdict, every attempt of
it, and what the tool printed live in `droost/droost-workflow/evidence.sqlite`,
written by droost from processes droost started. Read it with the evidence
command — never by opening the file, which the guard refuses for the same
reason the rows are trustworthy.

| # | Link | Where it is recorded | How observed | Held? |
|---|---|---|---|---|
| 1 | Lever written | `droost/droost.yml` | the file, in the diff | |
| 2 | Lever resolved | resolver output | `droost workflow:status` → `.levers` | |
| 3 | Frozen into the run | `run` row | `run.preset`, `.mode`, `.enforcement` | |
| 3b | **Spec frozen** | `run.spec_hash` | non-empty, and `spec_frozen_at` set | |
| 4 | Phase map derived | `run.json` | `.run.phase_gates[phase]` | |
| 4b | **Tool actually called** | `tool_call` | the ledger, WITH its phase — not the spec's claim | |
| 4c | **Change declared** | `declaration` | `kind: file` / `kind: test` rows, before the diff | |
| 5 | Gate invoked | `check_result.invocation` | the argv droost built, per attempt | |
| 6 | Tool ran | `check_result` | `exit_code` set, `duration_ms > 0` | |
| 7 | Verdict recorded | `check_result.state` | plus `.fault`, `.remedy`, and `finding` rows | |
| 7b | **Verdict still current** | `check_result.subject_hash` | matches the subject as it stands NOW | |
| 7c | **What the tool printed** | `transcript` | the raw stream, per attempt | |
| 8 | Phase advanced | `phases` in `run.json` | **subject-written — corroborate with 7** | |
| 9 | Artefact on disk | files / config | `git diff`, `config:status`, HTTP | |
| 10 | Reported to a human | the report | `droost workflow:evidence` | |

**Operator interventions** — every one, with why. Each is a first-run defect
in disguise:

| At | Intervention | Why it was needed | Fixed at source? |
|---|---|---|---|

### 7.2 Chain integrity

| | Check | Result |
|---|---|---|
| I1 | For every phase with a **non-empty** configured gate set: `phases[p] == "passed"` ⟺ that phase has `check_result` rows. (`plan` is exempt — its gate set is empty by design and the spec is its gate.) | |
| I2 | Every non-`off` gate has `duration_ms > 0` **and** a non-empty `invocation` | |
| I2b | No `check_result` row is green against a `subject_hash` the tree no longer matches — a green that outlived its subject is not a verdict about this code | |
| I2c | Every `blocked` row carries a `fault` other than `none`, and an `environment` fault carries a `remedy` naming a command that exists on the surface it is offered on | |
| I3 | `.run.browser` / `.run.tasks` are self-declared through unguarded, TTY-free commands — corroborate against the transcript | |
| I4 | No refusal read as success. **`isError` is never set anywhere**, so every gate refusal is a protocol-level success — scan for refusal *text* | |
| I5 | Baseline coherent: `baseline.on: false` alongside an existing `droost/baseline/` fails every consulting gate. Void, not a product failure | |
| I6 | Every gate, hook and wall file `droost/droost.yml` names is in `run.json`'s `rule_files` with the hash it had at begin, and none of them changed before its gate ran | |

### 7.3 Blind spots — what this chain CANNOT see

State every one. A written-down blind spot is a known limit; an unwritten one
is a false claim of coverage.

| Blind spot | Why it is dark | Consequence for this round |
|---|---|---|
| ~~Enforcement effectiveness~~ | **NO LONGER BLIND, as of 2026-09-15, and it was the largest one here.** The guard appends one line per invocation to `guard-calls.jsonl`, ingested at phase close, so whether the hook was THERE is a row. `run.json` still stores only the **requested** level and `{requested, effective, reason}` is still computed at read time — `effective` infers from the declared host, a claim about a claim — which is why the two must be read together. | Read §7a of the generated evaluation. **No rows on a round claiming `hard` is the finding**: either the hook never ran, or it ran on a build older than the ledger. From droost/workflow 0.10.13 every row carries a verdict: `refuse` (with the rule that refused), `nudge` (a soft wall that let the call through), `crash` or `allow`, so its counts are totals. Rows from an older guard say `invoked`, meaning "it ran; this row does not say what it decided", and any of them makes the refusal count a FLOOR. **Zero refusals at `hard` is not proof that the walls hold**: it means nothing this run did reached one |
| ~~Knowledge-layer usage~~ | **NO LONGER BLIND.** Every tool result appends to `tool-calls.jsonl`, so "was the codebase asked" is a fact with a count, not a doctrine. | Report it in §4a rather than listing it here |
| ~~Write-gate decisions~~ | **NO LONGER BLIND** for tool refusals: a gate refusal returns through `fail()` and lands in the ledger with `outcome: fail`. Drush-surface arming is still unlogged. | Count refusals in §4a |
| Search quality | With no embedding backend, conceptual queries return **empty-but-successful** | Indistinguishable from "nothing exists" |
| Wiki accuracy | Freshness is a source hash; `--all-stale` re-stamps without reading prose | "Fresh" is not "true" — nothing checks accuracy anywhere |
| `<add this round's own>` | | |

---

## 8. Findings

| # | Finding | Evidence (`file:line` / command + output) | Severity | Fixed at source? |
|---|---|---|---|---|

> A finding names a file and line, or a command and its output, or a field in a
> recorded artefact. "The agent said it passed" is not evidence — it is the
> thing under test.
