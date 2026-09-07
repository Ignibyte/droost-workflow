# Design — effort presets: one dial for how hard the workflow cranks

Status: **P1 BUILT 2026-09-07 (engine `0daefb4`, gate green: 434 tests).**
The engine scale, `required`, preset-only mandatory relaxation, aliases, the
seeker default and the run-record bool reader are shipped. P2 (pack briefs), P3
(report annotation), P4 (ergonomics + bulletin) and P5 (evals) remain. The
decisions marked **OWNER** through the body are resolved in §12.

## 1. The idea

One lever, five graded levels — `low`, `medium`, `high`, `xhigh`, `max` — that
set how much verification the workflow does, the way effort levels set how hard
an assistant thinks. Switching is a configuration change: one line in
`droost.workflow.yml`, frozen into the run that starts next.

Two things never scale with effort:

- **A spec is always written.** Every level still walks plan → code → test →
  complete and still produces the governing spec. Depth scales; existence does
  not.
- **The brain is always used.** Guidelines, the wiki as knowledge, search, the
  brain directive — the *read* side is not a gate and is not a lever. Effort
  scales what we *verify* and what artefacts we *write*, never what the agent is
  required to *know*.

At `low`, the code phase runs basic checks, the test phase carries no gates but
the browser check, and no wiki is written. At `max`, everything runs at full
strictness and tests are *required* to exist — a missing suite blocks.

## 2. This is an extension of what already exists, not a new system

The engine already has the mechanism, and the doctrine, in `PresetResolver`:

- A preset is a **base lever set**; explicit `gates:` entries are applied over
  it ("factory but without Playwright" is one line, not a fork).
- The resolved levers, the preset name, the phase map and enforcement are
  **frozen into `run.json`** at begin — a run is measured against the levers it
  started under.
- The resolved result is **always reported**, because a run whose levers cannot
  be read back is a run whose report cannot be trusted.
- **Absent means strictest.** No file, an empty file, or a file that never
  names a preset all resolve to `factory` — "a repo that has said nothing has
  not opted out of anything."

Today there are three presets: `custom` (the spelled-out baseline), `factory`
(everything on, strict) and `light` (the mandatory trio, thin). Effort presets
generalise these into a graded scale. They anchor cleanly:

| Level | Anchors to | What it is |
|---|---|---|
| `low` | *new, below light* | basic static checks; no tests; browser check only; no wiki |
| `medium` | ≈ `light` | mandatory trio, phpstan 2, rendered check, thin artefacts |
| `high` | ≈ `custom` baseline | solid static + unit tests, phpstan 6, no slow tiers |
| `xhigh` | *new* | + coverage, mutation (moderate), front-end trio, phpstan 8 |
| `max` | ≈ `factory` + `required` | everything strict; tests and regressions *required* |

So the build is: two new levels (`low`, `xhigh`), one new gate option
(`required`), and a decision about the old names. The base+override, freeze,
and report machinery is reused unchanged.

## 3. The two doctrinal collisions, and how they resolve

This is the part that needs thinking, because `low` runs into two deliberate
0.3/0.4 opinions.

### 3a. The mandatory trio vs. "low skips the testing phase"

Since 0.4, `phpcs`, `phpstan` and `phpunit` are `MANDATORY`: an `on: false` on
one of them is recorded as a deprecation notice and **superseded** — "the
toolchain Drupal core itself develops with is not optional." `light` keeps
phpunit on for exactly this reason: "light is not a shorter path — nothing
skips."

`low` as specified turns phpunit off. Collision.

**Resolution: the doctrine guards against *silent* disarming, and `preset: low`
is the opposite of silent.** Read the code comments: the failure the doctrine
exists to prevent is `touch droost.workflow.yml` weakening gates "with no error
and no warning," or a stray `on: false` nobody meant. It is about accident and
invisibility, not about forbidding a declared choice.

So the rule becomes precise rather than absolute:

- **A `gates:` override can never disarm a mandatory gate** (unchanged — that
  is the silent path).
- **A shipped preset's base may set one off**, because choosing that preset is
  one loud, reviewable line: `preset: low`. There is exactly one way to get a
  run with no unit tests, and it is to write those two words.

Guardrails that keep the spirit intact:

1. `low` is **never the default** — absent still resolves to the strictest.
2. A gate a preset turns off reports `GateStatus::Off`, never `Passed`.
   `GateStatus` was built on "the two 'did not run' words are deliberately not
   one word" — `Off` is that honesty already. The preset name is frozen beside
   it in `run.json`, so a report reads "preset: low · phpunit: off." Nothing
   was measured, and nothing claims it was.
3. The lever report and `workflow:status` **annotate** mandatory gates a preset
   turned off ("off by preset low") so it is not merely visible but explained.
4. The seeker, when armed, is told the effort level and can say "no tests ran
   by policy" in its ledger.

**OWNER:** confirm `low` may drop phpunit under these guardrails. The strict
alternative — `low` keeps phpunit on and only drops the slow tiers — is
`medium`, which already exists as `light`. If you want a level *below* light,
this is the only honest way to get one.

### 3b. "Nothing skips" vs. "the test phase is skipped entirely"

Every run walks all four phases; the `phases:` key is deprecated and ignored.
Dropping phases was rejected in 0.3 because `complete` re-running everything is
what makes dropped phases *safe* — a run with no test phase still meets every
enabled gate once, at the end.

**Resolution: the test phase still exists at `low`; it just has nothing due.**
"Skipped" is a rigor statement, not a structural one:

- The phase begins and ends — `onPhaseBegin`/`onPhaseEnd`, the Drupal hooks,
  the ECA events all fire, so a dashboard or Jira integration sees a
  consistent four-step lifecycle at every level.
- `PhaseGateMap` stays engine-owned and un-remappable. It decides **when** a
  gate runs; the preset decides **whether**. At `low` the map still says
  "test: phpunit, mutation, playwright, coverage, rendered_check, config_clean"
  — and the resolved levers say all of those but `rendered_check` and
  `config_clean` are off. The phase runs the two that are on. That is "the
  test phase is skipped except for a browser check," expressed in the existing
  vocabulary with no new concept.
- `complete` re-runs everything *enabled* — at `low`, that is a thinner safety
  net, which is exactly the trade `low` declares.

## 4. Invariants — what effort never touches

Stated so they can be tested, not assumed:

- **Consent.** The write wall, the `allow_*` gates, `require_run` and the
  bypass are *safety*, not rigor. `low` means less verification, never less
  consent. No level may loosen any of them; they are not in the preset at all.
- **The brain.** Guidelines, search, the wiki-as-knowledge, the brain
  directive — the read side is harness guidance, not a gate, and effort has no
  lever for it by construction.
- **The phase model.** Four phases, always, at every level (§3b).
- **The spec.** Always produced. Depth is a pack concern (§6); existence is not
  negotiable.
- **Absent means strictest.** The default preset stays the top of the scale.
- **Honest outcomes.** `Off` is never counted as success; `isPassed()` stays
  true only for `Passed`.

## 5. The matrix

Anchored to the existing presets so three of five levels change nothing.
"—" means off. Thresholds are deliberately reachable, per the factory doctrine
("a default nobody can hit is a default everybody turns off").

| Lever | `low` | `medium` (=light) | `high` (=custom) | `xhigh` | `max` (=factory+) |
|---|---|---|---|---|---|
| phpcs | on, Drupal | on, Drupal,DrupalPractice | on | on | on |
| phpstan | on, level **1** | on, level 2 | on, level 6 | on, level **8** | on, **max** |
| eslint / stylelint / prettier | — | — | — | **on** | on |
| phpunit | **— (off by preset)** | on | on | on | on, **required** |
| mutation | — | — | — | on, msi **60** | on, msi 80 |
| playwright | — | — | — | **on** | on, **required** |
| coverage | — | — | — | on, min **60** | on, min 80 |
| rendered_check | **on** | on | on | on | on |
| config_clean | on | on | on | on | on |
| wiki_fresh | **—** | on | on | on | on |
| seekers (default) | **off** | on | on | on | on |
| enforcement (default) | soft | soft | **hard** | hard | hard |
| max_gate_retries | 1 | 2 | 2 | 2 | 3 |
| spec depth (pack) | quasi (10-line EARS) | quasi | **full EARS** | full | full |
| documentation (pack) | **none** (chat summary) | chat | **wiki** | wiki | wiki |

Notes:

- `rendered_check` stays on at every level. It is the artefacts-are-truth leg
  ("a run that stops checking whether the page renders is not light, it is
  blind"), and it is the "browser check" `low` keeps.
- `config_clean` stays on everywhere: cheap, and a dirty config export is a
  correctness bug at any rigor.
- `medium` is `light` unchanged, `high` is the `custom` baseline unchanged, so
  a repo on either today keeps its behaviour under the new name.
- The front-end trio at `xhigh`/`max`: a repo with no node toolchain reports
  tool-missing (blocks) — that is the existing gate contract, and it is correct
  at those levels. **OWNER:** at `high`, turn the trio on only when a lint config
  exists outside core? (The P8.1 open question, now scale-shaped.)

## 6. The new capability: `required` — what "regressions forced" means

Today a functional gate with nothing to run reports a *labelled pass* ("nothing
to analyse") — right for a PHP-only site at `medium`, wrong at `max`, where a
change shipping with no tests is the defect. So `max` needs one new gate option:

```yaml
phpunit:    { on: true, required: true }
playwright: { on: true, required: true }
```

`required: true` means: **a missing or empty suite is `Failed`, not a labelled
pass.** Tool-missing already blocks; `required` extends "blocks" to
"suite-missing." That is "playwright with regressions forced" made executable —
you cannot complete a `max` run without regression coverage that actually
exists and passes. It applies to the functional gates (`phpunit`, `playwright`;
arguably `coverage`/`mutation`, whose thresholds already force existence).
Off at every level below `max`.

## 7. Two layers read the same frozen name

Effort is not only gates. Where each concern lives:

| Concern | Layer | Mechanism |
|---|---|---|
| which gates run, thresholds, `required` | **engine** | preset base lever set |
| enforcement / seekers / retries defaults | **engine** | preset fields (exist today) |
| frozen per run; reported; `Off` never passes | **engine** | `RunState::begin`, report (exist today) |
| spec depth (quasi vs full EARS) | **pack** | the spec-writer brief reads the preset |
| documentation weight (none / chat / wiki) | **pack** | the complete-phase brief reads the preset |
| seeker awareness ("no tests by policy") | **pack** | the seeker brief reads the preset |

The pack already branches on `factory`/`light`; the scale extends that branch
to five names. The one rule: **both layers key on the frozen preset name in
`run.json`**, never on the live lever file, so a mid-run edit cannot make the
agent's briefs and the engine's gates disagree.

## 8. Mechanism and ergonomics

- `preset: low | medium | high | xhigh | max | custom`. `custom` stays: it is
  "no base — my `gates:` block is the truth," a legitimate escape hatch and what
  `init` writes spelled out.
- Switching = editing that line. Frozen per run: the *next* run starts under
  the new level; a half-finished run is not reshaped (the existing freeze
  doctrine).
- `drush droost:workflow:install --preset=<level>` (exists for the current
  names; grows the vocabulary). A convenience `drush droost:workflow:effort
  <level>` that rewrites the one line is a nice-to-have.
- The lever report / `workflow:status` print the level and annotate
  preset-disabled mandatory gates ("off by preset low").
- Custom gates gain an optional `min_effort:` (e.g. `snyk: { …, min_effort:
  high }`) so a slow custom scan rides the dial too. Optional; default = always.

## 9. Default, old names, migration

- **Default (absent) → `max`.** This preserves "absent means strictest." It is
  a behaviour change *only* because `max` adds `required` (§6): a repo that has
  never named a preset will now be told its missing test suite blocks. That is
  the doctrine working as intended, but it is visible — ship it with a bulletin
  entry and a lever-report notice. **OWNER:** accept that, or keep `required`
  opt-in even at `max` (then default is behaviour-identical to today's factory).
- **`factory` and `light`.** Two options: (a) keep them as first-class names
  alongside the scale (seven names, two overlapping); (b) make them **aliases**
  that resolve — `factory → max`, `light → medium` — reported under the new
  name with a one-line notice. The `fast → light` precedent *refused* the old
  name because it was *retired*; these are synonyms, not retirements, so a
  resolving alias fits. Recommend (b). **OWNER.**
- Existing lever files keep working either way; `custom` is untouched.

## 10. Phased plan

- **P1 — engine scale.** Add `low` and `xhigh` to `PresetResolver` (anchor
  `medium`/`high`/`max` to light/custom/factory); add the `required` gate
  option + the suite-missing → `Failed` behaviour in the functional gates;
  mandatory relaxation *only via a preset base* (a `gates:` override still
  cannot disarm); aliases per the OWNER call. Tests: resolution per level,
  override-cannot-disarm, preset-may-disarm-loudly, `required` semantics,
  freeze. `composer lint` green.
- **P2 — pack briefs.** Spec-writer (depth), complete-phase (documentation
  weight), seeker (effort awareness) branch on the five names; lever template
  documents the scale.
- **P3 — report/status.** Annotate "off by preset X"; print the level.
- **P4 — ergonomics + docs.** `--preset` vocabulary, optional `workflow:effort`,
  README table, bulletin for the default/`required` change.
- **P5 — evals.** Two tickets minimum: **T-low** proves the test phase runs
  only the browser check and the report says `off`, not `passed`; **T-max**
  proves a missing regression suite *blocks* completion.

## 11. Risks

- **Silent weakening** — the one risk the package exists to prevent. Mitigated
  by: absent→strictest, `low` only via an explicit line, `Off` never passes,
  annotation in the report. Test it directly (P1).
- **Engine/pack drift** — both layers must read the frozen name (§7); a test
  that the pack's brief and the engine's levers agree on a fixture run.
- **Eval coverage** — a level nobody runs in evals is a level nobody trusts;
  P5 is not optional.
- **Threshold creep** — keep `xhigh`/`max` thresholds reachable; the factory
  doctrine already warns that an unhittable default gets turned off.

## 12. Decisions — DECIDED 2026-09-07 (owner)

1. **`low` may drop phpunit** under the §3a guardrails: never the default;
   reports `Off`, never `Passed`; annotated "off by preset low" in the report;
   the seeker is told. A `gates:` override still can never disarm a mandatory
   gate — the preset base is the only path, and it is one loud line.
2. **`factory` → `max` and `light` → `medium` become resolving aliases**,
   reported under the new name with a one-line notice. One five-point scale
   plus `custom`. (Synonyms resolve; only retirements — `fast` — are refused.)
3. **Default (absent) → `max` WITH `required`.** A repo that has said nothing
   gets the strictest set, tests-must-exist included. Ship with a bulletin
   entry and a lever-report notice — the doctrine working visibly.
4. **Seekers default OFF at `low`, ON at `medium` and above.** One reviewable
   line (`seekers: { on: true }`) re-arms it for a repo that wants it at low.
5. **Front-end trio at `high`: on only when a lint config exists outside
   core** (`.eslintrc*`/`.stylelintrc*`/`.prettierrc*` in the repo, not just
   `web/core`); at `xhigh`/`max` unconditionally on (tool-missing blocks, which
   is correct there). Resolves the P8.1 open question scale-shaped.
6. **`medium` is exactly `light`** — least surprise for repos on it today.
