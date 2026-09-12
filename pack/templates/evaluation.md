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
| `enforcement` | | | `.levers.enforcement` | | | **live only — §7.3** | |
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

**Discrimination — 13 of 14 gates can report green having measured nothing:**

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

**Asymmetries to encode in any scorer:**
- Crash-vs-finding is mapped for **four** tools only (eslint, prettier,
  stylelint, phpcs). For phpstan, phpunit, mutation, playwright, wiki_fresh and
  every `custom:*`/`module:*` gate a **crash scores as a failing gate** — a
  verdict on the code, when nothing ran.
- `stylelint` inverts the convention: exit 1 = fatal, exit 2 = findings.
- `custom:*` and `module:*` gates **cannot be waived** and have no timeout
  handling; a timeout scores `failed`.

---

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

| # | Link | Artefact | How observed | Held? |
|---|---|---|---|---|
| 1 | Lever written | `droost.workflow.yml` | the file, in the diff | |
| 2 | Lever resolved | resolver output | `droost-workflow status` → `.levers` | |
| 3 | Frozen into the run | `run.json` | `.run.preset` etc. at begin | |
| 4 | Phase map derived | `run.json` | `.run.phase_gates[phase]` | |
| 5 | Gate invoked | the argv | `gate_results[…].invocation` | |
| 6 | Tool ran | exit + duration | `exit_code`, `duration_ms > 0` | |
| 7 | Verdict recorded | `gate_results` | status + summary + findings | |
| 8 | Phase advanced | `phases` | **subject-written — corroborate with 7** | |
| 9 | Artefact on disk | files / config | `git diff`, `config:status`, HTTP | |
| 10 | Reported to a human | the report | `drush droost:workflow:report` | |

**Operator interventions** — every one, with why. Each is a first-run defect
in disguise:

| At | Intervention | Why it was needed | Fixed at source? |
|---|---|---|---|

### 7.2 Chain integrity

| | Check | Result |
|---|---|---|
| I1 | For every phase with a **non-empty** configured gate set: `phases[p] == "passed"` ⟺ `gate_results[p]` non-empty. (`plan` is exempt — its gate set is empty by design and the spec is its gate.) | |
| I2 | Every non-`off` gate has `duration_ms > 0` **and** a non-empty invocation | |
| I3 | `.run.browser` / `.run.tasks` are self-declared through unguarded, TTY-free commands — corroborate against the transcript | |
| I4 | No refusal read as success. **`isError` is never set anywhere**, so every gate refusal is a protocol-level success — scan for refusal *text* | |
| I5 | Baseline coherent: `baseline.on: false` alongside an existing `droost/baseline/` fails every consulting gate. Void, not a product failure | |
| I6 | Cross-check the doctor's gates row, `drush droost:gate`, and what the decision graph reported — these have disagreed live on one boolean | |

### 7.3 Blind spots — what this chain CANNOT see

State every one. A written-down blind spot is a known limit; an unwritten one
is a false claim of coverage.

| Blind spot | Why it is dark | Consequence for this round |
|---|---|---|
| Enforcement effectiveness | `run.json` stores the **requested** value only; `{requested, effective, reason}` is computed at read time and never persisted | No archived round can say whether its discipline held |
| Knowledge-layer usage | No tool call is logged anywhere in the run record | "The brain is always used" is unverifiable from the product alone |
| Write-gate decisions | No logger, watchdog or hook in the gate path | Refusals exist only in a transcript |
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
