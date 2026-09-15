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
| Write gates | `drush droost:gate` | `<all seven, as the subject finds them>` |
| Resolved levers | `vendor/bin/droost-workflow status` | `<attach JSON>` |
| Knowledge freshness | `drush droost:doctor` | `<every row>` |

**Traps that have bitten here before:**

- `droost:install` writes `preset: custom`, `enforcement: soft`,
  `max_gate_retries: 2`. The parser's absent-key defaults are `max`, the
  preset's, and `3`. **No installed repo sits at the documented defaults** —
  read the value, never assume it.
- A gate `on` with `toolchain.present: false` **blocks**. Record it in §2, not
  as a surprise in §4.
- On stock Drupal CMS the composer patcher may be absent from `vendor` and
  from `allow-plugins`, so `extra.patches` is silently ignored. Verify the
  patched bytes on disk.
- `droost:doctor` hardcodes its gates row `ok => TRUE` and excludes it from the
  verdict. It **cannot fail** on a site where every write refuses. Read that
  row yourself.

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
| — **write gates** — | | | | | | | |
| `allow_scaffold` | | | `drush droost:gate` | | | tool refusal text | |
| `allow_config_write` | | | ″ | | | ″ | |
| `allow_entity_write` | | | ″ | | | ″ | |
| `allow_db_write` | | | ″ | | | ″ | |
| `allow_module_ops` | | | ″ | | | ″ | |
| `allow_eval` | | | ″ | | | ″ | |
| `allow_destructive` | | | ″ | | | ″ | |
| — **gates** — | | | | | | | |
| `phpcs` | | | `.levers.gates.phpcs` | | | `gate_results` | |
| `phpstan` | | | ″ | | | ″ | |
| `phpunit` | | | ″ | | | ″ | |
| `eslint` | | | ″ | | | ″ | |
| `stylelint` | | | ″ | | | ″ | |
| `prettier` | | | ″ | | | ″ | |
| `mutation` | | | ″ | | | ″ | |
| `playwright` | | | ″ | | | ″ | |
| `coverage` | | | ″ | | | ″ | |
| `rendered_check` | | | ″ | | | ″ | |
| `config_clean` | | | ″ | | | ″ | |
| `wiki_fresh` | | | ″ | | | ″ | |
| `grounding_check` | | | ″ | | | ″ + §4b | |
| `custom:*` | | | `.levers.gates.custom` | | | ″ | |
| `module:*` | | | `.levers.contributed` | | | ″ | |

**Levers that are NOT frozen into the run** — they can change under it, so
record when you read them: `require_run` (guard re-reads raw YAML on every
write), gate tuning options of type int/string (re-read at gate time), and
`work_item` (never enters `run.json`).

---

## 4. Gate verdicts — read from `gate_results`

A green is not a measurement. Classify every one.

| Gate | Status | Exit | `duration_ms` | Invocation recorded | Measured anything? |
|---|---|---|---|---|---|

**Discrimination — 14 of 15 gates can report green having measured nothing:**

| Reading | Verified? |
|---|---|
| `passed`, findings/totals parsed, `duration_ms > 0` | **yes** |
| `passed` + "nothing to analyse" | no — empty path set |
| `passed` with `duration_ms: 0` | no — **the tool never spawned** |
| phpunit `passed`, zero tests | no — `--do-not-fail-on-empty-test-suite` |
| playwright `passed`, empty suite | no — and **unlabeled** below `max` |
| `off — by preset <level>` | no — honest, not a pass |
| `off — by the lever file` | no — the repo disarmed it |
| `skipped-no-site` | no — standalone CLI, no Drupal |
| `error-tool-missing` | no — **blocks** |
| `error-tool-failed` | no — crashed |
| `reported` | a finding that did not block |
| `waived` | no — an operator overrode it |
| grounding_check `no spec` / `no grounding table` | no — **labeled** passes, and they say so |

**Asymmetries to encode in any scorer:**
- Crash-vs-finding is mapped for **four** tools only (eslint, prettier,
  stylelint, phpcs). For phpstan, phpunit, mutation, playwright, wiki_fresh and
  every `custom:*`/`module:*` gate a **crash scores as a failing gate** — a
  verdict on the code, when nothing ran.
- `stylelint` inverts the convention: exit 1 = fatal, exit 2 = findings.
- `custom:*` and `module:*` gates **cannot be waived** and have no timeout
  handling; a timeout scores `failed`.

---

## 4-evidence. Read the store, not this form, where the store knows

Since the evidence store landed, most of what follows is recorded rather than
reconstructed, and `drush droost:workflow:evidence --write` renders it — or
`vendor/bin/droost-workflow evidence --write` where there is no Drupal to boot.
Both take the flag bare (it lands at `droost/evidence/<run>.md`) or with a path,
and both report the path they actually wrote. Fill this
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
| **Knowledge calls** (`search`, `symbol`, `graph`, `module_patterns`, `module_docs`, `deprecations`, `entities`, `routes`, `capabilities`, `architecture`) | | **zero here means the run never asked the codebase anything**, whatever its grounding table says |
| Router calls (`droost_decide`) | | |
| **Knowledge : router ratio** | | the number that exposed the original defect — 6 : 179 across 39 rounds |
| Write/scaffold calls (`scaffold`, `structure_create`, `config_set`, `entity_create`, `views_compose`) | | |
| Refusals (`outcome: fail`) | | each one is a gate that was off, or an argument that was wrong |

**Tooling-plan fidelity.** Every droost tool the Tooling plan named, against
the ledger:

| Tool the plan named | In the ledger? | If not — was the plan changed to hand-written with a reason? |
|---|---|---|

> A plan that says `droost_structure_create` while the code hand-writes the
> YAML is drift. `grounding_check` fails on it now, but record it here too:
> the gate says *that* it drifted, the table says *what* it drifted to.

**Scaffold-vs-handwritten.** Of the files added under `web/modules/custom` and
`web/themes/custom`, how many came from a droost blueprint or a
`drush generate`, and how many were typed? A run whose Tooling plan is all
droost tools and whose diff is all hand-written prose has followed neither.

## 4b. Grounding — the three tiers, and whether each was actually reached

`grounding_check` is the only gate that measures the agent's own research, so
it is the one most worth taking apart. Two halves fail independently, and a
pass on one tells you nothing about the other.

```bash
# what the spec claimed
sed -n '/^## Grounding/,/^## /p' droost/droost-workflow/*spec-*.md

# what the gate said about it
drush droost:workflow:report | grep -A3 grounding_check
```

| Tier | Rows | Cited | Citations that resolved | Store that answered | Verdict |
|---|---|---|---|---|---|
| custom | | | | symbol graph | |
| contrib | | | | symbol graph | |
| core | | | | **brain** — core is not an indexed scope | |

- **A tier with rows but no resolvable citation is not grounded.** Prose in
  `Found` is the agent's account of itself; the citation is what the site
  confirms. The gate refuses a claimed tier that cites nothing — before that
  rule a table with no `Evidence` column passed with "0 citation(s) resolved".
- **`none:` rows are re-run, not read.** The most valuable row in the table is
  the one proving a duplicate was not about to be built, and it is also the
  easiest to type without looking. Check the gate re-ran it: a `none:` claim
  about something the site has must fail.
- **Half two is the ledger, not the table.** A citation proves the symbol
  exists; it can be copied out of a file. `grounding_check` also fails when the
  ledger records no knowledge-tool call at all — see §4a. Record both verdicts
  separately, or a run that cited well and looked up nothing scores as grounded.
- **Core resolves against the brain, and only the brain.** `droost:search:index`
  runs custom|contrib|themes|wiki; core is a scope nothing indexes by default,
  so the symbol-graph table holds no `node`, `views` or `field` symbols at all.
  If a round reports core citations resolving against the symbol graph, the
  probe is wrong, not the finding.

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
| 1 | Lever written | `droost.workflow.yml` | the file, in the diff | |
| 2 | Lever resolved | resolver output | `droost-workflow status` → `.levers` | |
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
| 10 | Reported to a human | the report | `drush droost:workflow:report` | |

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
| I6 | Cross-check the doctor's gates row, `drush droost:gate`, and what the decision graph reported — these have disagreed live on one boolean | |

### 7.3 Blind spots — what this chain CANNOT see

State every one. A written-down blind spot is a known limit; an unwritten one
is a false claim of coverage.

| Blind spot | Why it is dark | Consequence for this round |
|---|---|---|
| ~~Enforcement effectiveness~~ | **NO LONGER BLIND, as of 2026-09-15, and it was the largest one here.** The guard appends one line per invocation to `guard-calls.jsonl`, ingested at phase close, so whether the hook was THERE is a row. `run.json` still stores only the **requested** level and `{requested, effective, reason}` is still computed at read time — `effective` infers from the declared host, a claim about a claim — which is why the two must be read together. | Read §7a of the generated evaluation. **No rows on a round claiming `hard` is the finding**: either the hook never ran, or it ran on a build older than the ledger. Its refusal count is a FLOOR — the guard has 26 exit paths and only two are centralised, so most rows say `invoked`, meaning "it ran; this row does not say what it decided" |
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
