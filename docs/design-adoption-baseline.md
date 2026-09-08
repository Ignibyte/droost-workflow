# Design — D71 Adoption baseline: inherited debt vs new findings

Status: **BUILT P1–P3 2026-09-08** (engine `d6b5884` core, `7c4bd9c` writer and ratchet, `1a099c5` guard; droost `835bc70` live surface) — one deviation from the text below: the directory is **`droost/baseline/`**, beside `droost/wiki`, not `droost/workflow/baseline/`, because the run-state directory is gitignored and a baseline must be committed and reviewed. P4 is the T27 round (`scripts/evals/`). Decided 2026-09-07 (§11). Owner-directed: *"we will have to
figure out a baseline effort because so many code bases out there will be this
exact way."* Source finding: the EMT dogfood ledger (droost `docs/EMT-DOGFOOD-
LEDGER.md`, product finding 2) and T26's 26 pre-existing phpstan-max errors.

## 1. The problem

Droost's mandatory gates — phpcs, phpstan, config_clean, and at the upper
levels the front-end trio — run over the project's measured paths, whole. On a
legacy project that means adopting droost fails on debt the run never touched.
EMT, the first real adopter: 45 JS phpcs errors, 123 level-6 phpstan errors and
2 config-drift items, none from the change under review. T26, a greenfield at
`max`, met the same shape in miniature: 26 pre-existing phpstan-max errors in
test files that had been built at level 6.

The EMT workaround was three hand moves droost neither guided nor recorded: a
PHP-only phpcs ruleset, a committed phpstan baseline, a config reset. That is
the wall every legacy adopter will hit, and most codebases droost meets will be
EMT, not a greenfield. drup-pipeline never hit it because it gated only the
files a change touched — one answer, with a hole (§8).

## 2. The doctrine this must not break

Two rules stand, and they are why baselines have been refused until now:

- **The agent never baselines, suppresses or scopes its way past a finding.**
  Round 30's operator answer to the 26 errors was *fix at the source, never a
  baseline*. The seeker brief names "a new suppression or baseline" a defeat.
- **`off` is never `passed`.** A report says who decided what, and why (D70).

Both rules are about the RUN. Neither speaks to debt that existed before droost
arrived. What makes a baseline legitimate is WHO writes it and WHEN: the
operator, at adoption, as a recorded act — never the agent, never mid-run. That
is the same shape as the dial (`effort <level>`), the waiver and the bypass:
operator acts, TTY-required, refused from the agent shell by the guard hook.

## 3. Two questions a gate answers

Today a gate answers one question: is the measured tree clean? The design
splits it:

1. **Inherited** — a finding the baseline already records. Reported, counted,
   never fails the gate.
2. **New** — anything else. Fails the gate exactly as today.

A gate with inherited findings and none new reports
`passed — 0 new, 123 inherited`, never a bare `passed`. The debt stays on every
report, in the run record and in the seeker's view. Nothing is hidden; only
the FAIL is scoped to what the run did.

## 4. What is recorded, and where

A visible, committed directory `droost/workflow/baseline/` — the D57 doctrine
(human-facing artefacts live in the visible `droost/` folder; run state stays
hidden) applied to the one artefact an adopter must be able to review:

| File | Content | Mechanism |
|---|---|---|
| `phpstan-baseline.neon` | phpstan's native baseline | `--generate-baseline`; keyed by message + file + count, so line shifts do not resurface debt. droost runs phpstan through a generated wrapper neon that includes the project's own config plus this file, with `reportUnmatchedIgnoredErrors: false` so paid-off debt cannot fail a run (it reports as "N inherited findings gone"). |
| `phpcs.json`, `eslint.json`, `stylelint.json` | droost-owned baselines | each finding keyed by (file, rule id, message, hash of the source line's text), from each tool's JSON reporter. A line SHIFTING keeps its key; EDITING the offending line changes it, so the finding becomes new. |
| `prettier.txt` | files unformatted at adoption | touching a listed file requires formatting it — `prettier --write` is mechanical and safe. |
| `config_clean.json` | config names with drift at adoption, and the direction (create/update/delete) | new drift fails; recorded drift is inherited. |
| `metrics.json` | coverage % and MSI at adoption | the ratchet floor (§7). |
| `baseline.json` | the manifest: generated_at, generated_commit, preset in force, per-gate counts, the hash of every file above | what the run freezes and the seeker checks. |

## 5. The operator acts

- `drush droost:workflow:baseline` — measure and write. TTY-required; the pack
  guard hook refuses it from the agent shell (`operator-commands`). Prints the
  bill per gate.
- `drush droost:workflow:baseline --refresh` — re-measure. Entries that no
  longer match DROP. An entry that would be ADDED is refused unless
  `--grow --reason="…"`, recorded in the manifest like a waiver (who, when,
  why). **The ratchet: debt only goes down by default.**
- `drush droost:workflow:baseline --status` — the bill, read-only; allowed from
  the agent shell (like `effort --preview`).
- `droost:workflow:install --preset=<level>` on a tree with debt prints the
  bill and names the baseline command. It does NOT run it. This is the guided
  adoption flow the EMT ledger asked for.

## 6. Run-time rules

- The run freezes the manifest hash into `run.json` at begin (as it freezes
  the preset). A baseline that changes mid-run makes every gate that consulted
  it report `failed — baseline changed during the run`, and the seeker names
  it a defeat. Adoption-time baselines predate every run and are exempt by
  construction.
- The agent's own findings never enter the baseline: the guard hook refuses
  `baseline` writes from the agent shell, and the write wall treats
  `droost/workflow/baseline/` as operator-only while a run is open.
- Touching a legacy file: inherited findings on lines the run did not change
  stay inherited; a finding whose source line changed has a new key and is
  new. A one-line fix in a file carrying 200 findings surfaces only what the
  fix broke, and the report still says `200 inherited in this file`. (§11.1 —
  the boy-scout alternative is stricter and simpler; the default is key-based
  because the boy-scout wall is exactly what made whole-tree gating
  unadoptable.)
- `effort --preview` and the lever report state inherited counts per gate at
  the level in question, so the bill of a level move is given in both terms.

## 7. Thresholds ratchet (coverage, mutation)

A legacy repo at 34 % coverage cannot meet `max`'s 80 on day one; today the
gate fails and the operator's only moves are the dial or a `gates:` override.
With a baseline, `metrics.json` records the adoption value and the gate passes
when the current value is at or above it, reporting the level's threshold as
the TARGET: `passed — coverage 35 % (inherited floor 34 %, target 80 %)`.
Dropping below the floor fails. `--refresh` raises the floor to the current
value and never lowers it. The level's threshold is reached by ratcheting,
not declared.

## 8. Change scope — the drup-pipeline answer, and why it is second

Recording `base_commit` (HEAD at run begin) in `run.json` is cheap and useful
on its own: the seeker's "cumulative diff" gets a stated base and reports can
list the run's changed files. Gating ONLY changed files is then one flag away
(`scope: changed`). But it has the hole the baseline closes: a touched legacy
file surfaces all of its debt (the boy-scout wall), and phpstan on a file
subset loses cross-file type errors. So P1 records the base commit and reports
changed files; `scope: changed` is offered for repos that want drup-pipeline's
behaviour; the default legacy story is the baseline.

## 9. What does NOT get a baseline

phpunit, playwright, rendered_check, wiki_fresh. A suite failing at adoption is
not debt to inherit, it is a broken suite: the gate says so and the operator
fixes it or scopes `paths`. (Phase-2 option, deliberately out of scope:
record failing test ids as inherited failures.)

## 10. Phases

- **P1 (engine, `droost/workflow`)** — `base_commit` + `changed_files` on
  RunState and the report; manifest reading and hash-freeze; GateResult gains
  `inherited`/`new` counts and the `passed — N new, M inherited` summary;
  phpstan wrapper neon + baseline include; phpcs/eslint/stylelint JSON diff
  keyed as §4; config_clean snapshot diff; metrics floor. Tests for each keying
  rule (a shifted line stays inherited; an edited line becomes new).
- **P2 (module, `droost_workflow`)** — `drush droost:workflow:baseline
  [--status | --refresh [--grow --reason]]` (TTY; guard hook); the installer's
  bill and hint; `effort --preview` inherited counts; the wall rule for the
  baseline directory.
- **P3 (pack + docs)** — briefs read the baseline ("inherited debt is not
  yours to fix unless the ticket says so; new is"); the seeker rule (manifest
  hash unchanged is fine; changed is a defeat); README "Inherited debt"
  section; bulletin item; the adoption guide.
- **P4 (evals)** — T27: a legacy fixture with seeded debt in every gate,
  adopted at `high`. Install prints the bill; the operator baselines; a run
  that adds one new error fails naming it new; a run that fixes an inherited
  one shows the ratchet; the agent's attempt at `--refresh` is refused by
  name. Run on the clean room, then on EMT for real.

## 11. Decisions — taken by the owner 2026-09-07

1. **Changed lines only.** An inherited finding stays inherited until the
   line carrying it changes; touching a file does not make the rest of its
   debt yours. (The boy-scout alternative was declined.)
2. **Thresholds ratchet in the first cut** (§7): coverage and MSI floors are
   part of P1, not a later phase.
3. **The strict-mode lever stays.** Presence of the manifest turns the
   baseline on; `baseline: { on: false }` in `droost.workflow.yml` refuses a
   committed baseline, visibly, in one line.
4. **The name is `baseline`** — the directory, the manifest, the drush verb
   and the lever all use it.

Status after these: DESIGNED AND DECIDED; build order P1 → P4 as §10, after
the `droost_ui_patterns` extraction (done 2026-09-07).
