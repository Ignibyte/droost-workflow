# Configuration: the lever file

Every lever in `droost/droost.yml`: the file itself, the preset dial, each gate's options, and the errors a misspelt key gets.

Part of the droost reference: [README](../README.md) · [all topics](README.md).

## The lever file

One file, `droost/droost.yml`, is the source of truth:

```yaml
mode: agentic                   # agentic | interactive
lead: human-led                 # human-led | autonomous — who may finish a run (unset: interactive → human-led)
tests: { ask: true }            # plan asks the human: unit tests? save the browser check as a spec? (default: neither)
preset: custom                  # custom | low | medium | high | xhigh | max
enforcement: soft               # hard | soft | off — the hooks, mid-run only
require_run: hard               # hard | soft | off — custom-code edits with NO active run
gates:
  phpcs:          { on: true,  standard: "Drupal,DrupalPractice" }
  # ^ Drupal's standard needs drupal/coder. Where the project is not Drupal —
  # no coder, no docroot, no module/theme/profile at the root — `init` writes
  # PSR12 here, and a level's Drupal default is substituted the same way,
  # with a notice under `deprecations`. Set it yourself and it is yours.
  phpstan:        { on: true,  level: 6 }      # 0-9 | max
  # both static gates accept paths: "web/modules/custom,web/themes/custom" —
  # repo-relative analysis targets. Without paths and without a
  # phpcs.xml/phpstan.neon, the gate is pointed at the project's OWN code:
  # its top-level source directories and root-level files, or a Drupal
  # site's modules/custom and themes/custom — never vendor/, core or contrib.
  # And root: "site" when the Composer project is not the repository root:
  # the tool comes from site/vendor/bin, runs in site/, and discovers
  # site's phpcs.xml(.dist) or phpstan.neon(.dist); findings are still named
  # from the repository. phpunit, coverage and mutation take it too.
  phpunit:        { on: true }
  mutation:       { on: false, msi_min: 0 }
  playwright:     { on: true, required: true }   # a committed spec, at every level
  parity:         { on: true }                 # the site against its source's captured pages
  coverage:       { on: false, min: 0 }
  wiki_fresh:     { on: true }                 # the project's own docs still match the code: fails on a page whose sources the run changed; one found stale is named (scope: all holds every page)
  # every gate also takes mode: block | report — report records a failure and
  # advances (on the mandatory trio, only at preset: custom)
  # The project's own gates: a file (run:) or one shell line (cmd:).
  # gitleaks: { phase: code, run: droost/gates/gitleaks.php, mode: block }
  # suite:    { phase: test, cmd: "bin/gate.sh FULL", timeout: 900 }
# hooks: { run_start: droost/hooks/notify.php }  # told about the run's events
# walls:                                         # asked before a tool call; may refuse it
#   tracker_writes: { tools: "^mcp__atlassian__create", run: droost/walls/tracker-writes.php }
# operator_only: [droost/bin/tracker-writes]     # the guard refuses these from the agent's shell
# baseline: { on: false }                        # strict mode: ignore a committed droost/baseline/
max_gate_retries: 2
```

**It is a file, not site configuration**, for four reasons: the agent must be
able to read its own levers while the site is mid-build or broken; every
agent harness reads the same file with no site at all; it is dev tooling and
belongs with the code it gates; and it belongs in review, where loosening a
gate shows up as a diff. During a run the guard refuses edits to it, and to
the gate, hook and wall files it names. (What each host does and does not get
from `init` is set out in [The guard](guard.md) — the enforcement hook is Claude
Code's alone, and that is a limitation, not a detail.)

Beside the levers, `droost/droost.yml` holds droost's own settings: where its
two stores live (`storage: { index: …, evidence: … }`, by default in
`droost/droost-workflow/`), the wiki (`wiki: { path: droost/wiki,
contrib_scope: reached | all }`) and semantic search (`search: { embeddings:
{ endpoint, model, format, dimension, api_key_env, scope } }`; the key is
read from the environment variable `api_key_env` names, and a key written in
this committed file is refused). Top-level keys droost does not know, such as
a project's own `acme:` block, are left alone; a near-miss of a key it
does know is refused by name.

### Presets — one dial for how hard the workflow verifies

A preset is a **base**, not an alternative to per-gate control — explicit
`gates:` entries are applied over it, so "max but without Playwright" is one
line rather than a fork. Since 2.0 the presets form one graded dial. Two
things never move with it: a spec is always written, and the brain
(search, the code graph, the wiki as knowledge) is always used — the dial scales
what is *verified* and what artefacts are *written*, never what the agent must
know. Consent (the write wall, `require_run`) is not in a preset at all.

| Level | Verification |
|---|---|
| **`low`** | basic static checks (phpcs, phpstan 1); **no tests**; the browser check; no wiki. Seeker off, enforcement `soft`. The one level whose base turns a mandatory gate off — allowed here and only here, because `preset: low` is one loud reviewable line, never the default; the gate is recorded `off`, never `passed`. |
| **`medium`** | the mandatory trio with phpstan 2, a shorter EARS spec, docs in chat. From here up phpunit carries **`in_diff`**: a run that changes a class under `src/` must change a phpunit test too, or the test phase blocks (`tests_in_diff`); a test droost's scaffold wrote and nobody changed does not count. And `wiki_fresh` carries **`cover_diff`**: a custom module or theme the run changed must have a wiki page, or the gate fails. (Formerly `light`, plus both.) |
| **`high`** | solid static analysis + unit tests (phpstan 6), no slow tiers; enforcement `hard`. (The shipped `custom` gate set, named on the dial, plus `in_diff` and `cover_diff`.) |
| **`xhigh`** | + coverage and mutation at 60, the front-end trio, phpstan 8. |
| **`max`** | everything on, strict — phpstan max, mutation 80, coverage 80 — and tests **required to exist**: `phpunit` and `playwright` carry `required: true`, so a missing or empty suite is a *failure*, not a labelled pass. (Formerly `factory`, plus `required`.) |
| **`custom`** | not a point on the dial: "no opinion — my `gates:` block is the truth", the spelled-out baseline `init` writes so choosing it is visible in a diff. Same gates as `high`, without `in_diff` or `cover_diff`. |

`factory` and `light` still load as **aliases** of `max` and `medium` — the run
records the canonical name and a notice says so. (`fast` was retired in 0.3
and is refused with a pointer.) Switching levels is one line; a run is frozen
under the level it started with, so a mid-run edit reshapes the *next* run.

**Off says why.** A gate a level turns off is reported `off — by preset
<level>`; a gate the file turns off against its level reads `off — by the
lever file (preset <level> turns it on)` — the same status word, so a reader
can tell the dial doing its job from a loosening without opening the file.
The reason rides as the result's `skip_reason`, and every run envelope
carries `preset`, the level the run was held to: "no phpunit result" is
readable as a failure or as a `low` run that never asked for one.

**Moving the dial is one command** — `droost workflow:effort <level>`
rewrites the `preset:` line, proves the file still loads (rolling back if
not), names the canonical level an alias resolved to, and lists the gate
switches the file still spells out (each overrides the dial). It is the
operator's command like `gate-waive` and `bypass`: it refuses without an
interactive terminal and the pack's guard refuses it from the agent's shell —
the agent proposes the level, a human sets it. A bare `effort` only reports,
and `effort <level> --preview` prints **the bill** — every gate, threshold,
the seeker, enforcement and the retry bound the move would change for the
next run — without writing, so an operator sees what raising the level
applies to existing code before the first run pays for it (round 30 raised a
room to `max` and the first run met 26 legacy phpstan errors), and an agent
can ground the level it proposes. A real move prints the same list after it
lands; an empty list on a move says why (every touched gate is spelled out in
the file).

**The trio follows the pair.** From `xhigh` up the level turns `eslint`,
`stylelint` and `prettier` on, and each is scoped by its own `paths`. Given
none, a trio gate takes `phpcs`'s `paths` — the project's own code is the same
place for both — so a hand-written `preset: max` with only the PHP pair scoped
never lints the repository root. A trio gate given its own `paths` keeps them.
Each also takes `config:` — the project's own lint config (the file its
package.json lint script names), which the gate pins while turning discovery
off. Set it on a Drupal docroot: left to discover, eslint's cascade reaches
core's scaffolded `.eslintrc.json`, whose plugins only core's own yarn install
provides, and the tool crashes before it reads a file. A crash is reported as
**ERROR — tool could not run**, with the tool's own line and the lever to set:
it blocks like a missing tool, and it is never counted as findings —
`baseline --measure` calls such a gate not measurable rather than "0".

**A waiver can reopen a terminally failed phase.** When a gate spends the
retry budget, `run` refuses and the record says why; recovery used to be
`reset` alone. Now the operator's `droost workflow:gate-waive <gate>
"<reason>"` — signed from a terminal, refused from the agent's shell — also
reopens the phase when it covers every gate that killed it: the phase runs
again with those gates recorded as **waived**, never passed, and the reason
rides in the report. A waiver on some other gate changes nothing, and the
mandatory trio can be waived only on a run at `preset: custom`.

**The phases are not levers.** Since 0.3 every run walks the canonical
order, minor changes included — `plan → code → test → complete` since 0.4
folded document into complete — and the `phases:` key is deprecated and
ignored (with a notice). What varies between heavy and light is the weight
each phase carries, never the path.

**The mandatory trio is not a lever either, except at `custom`.** Since 0.4,
`phpcs`, `phpstan` and `phpunit` cannot be turned off from the `gates:` block
(the `low` preset's *base* drops `phpunit` — one loud reviewable line, recorded
`off`, never `passed`). `preset: custom` is the whole exception (0.14.15): a
file that names it owns every lever, so its `on: false`, `mode: report` or
phpstan `level: off` on the trio stands, recorded `off` or `reported`, never
`passed`, and the operator may waive them. Everywhere else they hold, as the
toolchain Drupal core itself develops with (exactly what `drupal/core-dev`
ships). Their tuning
levers (standard, level, paths) still apply; an `on: false`, or phpstan's
`level: off`, is recorded as a deprecation notice and superseded. A repo
that cannot run one of them yet gets an honest answer instead of a pass:
tool missing, config missing (phpunit with no phpunit.xml refuses before
spawning anything), or a labeled "nothing to analyse / no tests yet" that
hardens itself the moment real code or a first test exists.

**The seeker checkpoint** holds a green code phase until an adversarial
inspection is recorded. Gates verify rules; the seeker
(the pack's `workflow-seeker` agent) verifies judgment: dead new code, drift
from the spec's EARS criteria, coupling the change breaks, weak tests,
security smells in the changed code, attempts to defeat the workflow's own
discipline. Its scope contract is the diff plus one hop to the changed
symbols' consumers — never an audit of the neighbourhood — and its verdict
is an exact ledger the engine PARSES (`seeker-report`, stdin), and a section
with neither rows nor the `(no findings)` sentinel is an incomplete
inspection and refuses. A ledger is filed only after a `workflow-seeker`
subagent has returned since the last one: the guard records each subagent
that returns (PostToolUse on Agent and Task), and a ledger with none behind
it is refused (where the hooks do not record dispatches, it is taken and
marked `dispatch: unverified`). Which open rows hold the run is
`seekers.holds`: `critical` (the default at `high` and above), `high` (an
open CRITICAL or HIGH), `medium` (CRITICAL, HIGH or MEDIUM), or `none` (the default below `high` and at
`custom`, where the findings are advisory and go to the report); resolved
and carried-with-reason rows never hold. `seekers.at_complete: true` makes
complete wait for an inspection of its own, held the same way. A ledger
filed while a hold is open releases it, so the next `run` asks again on the
new verdict. `seekers: { on: false }` turns the checkpoint off,
`seekers.rounds` raises how many inspections code needs (1 to 5); the hold
spends no retry budget.

**Enforcement is its own lever**, orthogonal to the preset: `hard` blocks
out-of-phase actions while a run is active (editing project files during
plan, ending the turn mid-phase), `soft` warns once per phase, `off` stands
the hooks down. Outside an active run the phase hooks have no opinion — but
**`require_run` still stands**: a custom-code edit (`modules/custom`,
`themes/custom`) with no ACTIVE run is blocked (`hard`, the default even when
the key is absent), nudged once (`soft`), or allowed (`off`). A finished or
failed run counts as no active run — the record is history, not a licence —
and the way past the wall is to start a run, or an operator-granted bypass
(`droost workflow:bypass "<why>"`, cleared with `--off`), never the
agent's own hand. You may pair factory gates with `enforcement: off`; not
advised, but the lever file is a reviewable diff, and a visible loosening is
the honest way to allow it.

**Playwright is the npm tier, and it is on at EVERY preset.** The gate runs
`node_modules/.bin/playwright test` — committed regression specs, exit code
as verdict — with `required: true`, so an empty suite is a failure rather
than a labelled pass.

It is the only gate whose **missing binary REPORTS instead of blocking**.
Every other absent tool is `error-tool-missing`, which stops the run, and
that is right: you asked for the gate and the environment cannot run it. The
browser suite is the exception because it is due everywhere, and blocking on
an uninstalled binary would wedge every project that has not run
`npm i -D @playwright/test` — an instrument that cannot see refusing to let
the run past. The row says what is missing and how to install it, and the
moment it IS installed `required: true` makes this a real wall.

**Parity is the site held to the source it rebuilds.** A reference is a
page of that source (a design, or the site being replaced) read once with
`tools/droost/vendor/bin/droost-parity capture --source <url> --routes
/,/about` and kept in the project under `droost/parity/`: every visible
element that carries text (a button input's value included), with its box,
the extent of its text, and the computed style that decides how it looks. The
gate runs `droost-parity judge` (the project's `vendor/bin/droost-parity` when
it has one, else the package's own), reads the same routes on the site with the
project's own Playwright, and fails a route whose text is missing or out of
order, whose type, colour or place differs past a stated tolerance (every
heading's type must match; a text's place is its own extent when both readings
carry one, its box otherwise), or whose header and footer differ (D1 to D7,
each named in the finding). A page it
cannot read is INVALID and fails closed, never a pass.

The site judged is `--base` when given; else, inside DDEV's container,
`DDEV_PRIMARY_URL`; else the project's DDEV site from `.ddev/config.yaml` (its
`name`, or the directory's when it has none, and `project_tld`); else
`DDEV_PRIMARY_URL`, `PLAYWRIGHT_BASE_URL` or `DROOST_PARITY_BASE_URL`. Every
verdict says `judged at <url> (from <where>)`. A capture records where it was
read (`read_on`: the platform and the browser) in the reference's manifest,
and a judge on another platform reads INVALID with nothing judged, since the
same page lays out differently in another platform's browser: judge where
the reference was read, or capture it again where the gate runs. A reference
with no `read_on` is judged anywhere, as before. With no reference
captured it is a labelled pass that measured nothing, so it is on at every
preset; `required: true` makes a missing reference a failure, and `scope:
frame` judges only the header and footer, for a ticket that builds the frame
before the pages. No Playwright, or no Node, REPORTS, as the browser suite
does.

A reference may be captured at several widths (`--width 1280,390`), and every
route is judged at each: a narrow width is how a collapsed menu is held to the
source's. `pages: /,/camps` judges those routes whole (page scope) at the
first width while every other view keeps `scope`, so a ticket that rebuilds
one page holds it and the frame everywhere at once. The reference is frozen
with the run: its digest is taken when the run begins, and the gate fails a
reference that has changed since, whatever changed it. Capturing is the
operator's, before a run.

A static source's page is captured by the path the site answers: `--routes
/internet.html` is the site's `/internet` (`/internet=/internet.html` names
both, and `/legacy.html=/legacy.html` keeps a `.html` route). A typeface the
source declares and never draws (no face loaded, none installed) makes every
reading a fallback's, so capture refuses it, unless `--accept-unloaded
"Family"` names it, which the manifest records; and the judge's D3 fails a
site text whose face is declared and never drawn.

A source shows things the client decided the site will not (a link hidden
until an editor sets it, a language switch on a site in one language), and a
page held whole would fail on each. `decided.json` beside the readings names
them, `{"set_aside": [{"route": "/about", "text": "Learn More", "decided":
"Q6", "why": "…"}]}` (`"route": "*"` for every page): the judge leaves each
text, and a header link with that label, out of the route's reference, and its
verdict lists every one with its decision. A text set aside takes its space
with it: the texts below it in its column, under the same heading, may sit higher by its
height and the gap above it, no more. An entry with no decision, one
citing no question of the intake (`droost/intake/questions.md`), or one
naming a text the captured page does not show is INVALID, so the file can
record decisions and nothing else. It is part of the reference, so its digest
is frozen with the run.

**Why a spec and not an MCP call.** The browser check used to be "the agent
called a Playwright MCP tool", counted from the guard's ledger. That forced
looking and left nothing behind: one `browser_navigate` satisfied it, and the
regression test had to be recreated from memory afterwards. A committed spec
is the verification AND the artefact — it re-runs on every later ticket, and
it cannot be satisfied cheaply, because it has to pass against the running
site. A session's Playwright MCP tools remain available for exploration, and
the run still records the declared `browser` capability.

**Custom gates** (`gates.custom`) wire the repo's own commands — semgrep,
behat, anything — as first-class gates: everything explicit (`on`, `phase`
of code|test, single-line `cmd`), exit zero passes, and a command the shell
cannot find reports **tool missing**, which blocks, never passes.

Three details worth knowing:

- **Anything that does not name a preset resolves to `max`** — no file, an
  empty file, or a file that sets other things but never mentions one. A repo
  that has said nothing has not opted out of anything — tests-must-exist
  included, since 2.0. This is deliberately
  one rule rather than three: an earlier revision defaulted a file that exists
  to `custom`, which meant creating an empty lever file turned mutation,
  playwright and coverage off and dropped PHPStan from max to 6, silently. If
  you want the gentler set, name it — `preset: custom` — so the choice is
  visible in a diff.
- **Thresholds never imply `on`.** Writing `coverage.min` without
  `coverage.on` leaves the gate where the preset put it. An inferred switch
  would make `min: 0` and `on: false` two spellings of one intent with two
  different failure modes. (`phpstan.level: off` was the one other switch;
  the 0.4 mandate superseded it — the attempt is noticed and the gate keeps
  the preset's level, so the recorded levers can never claim a gate was off
  when it ran.)
- **Something at the config path that is not a readable regular file is an
  error**, not an absent config. A directory, a broken symlink or an
  unreadable file would otherwise swap your gates for the built-in ones and
  report nothing unusual.

### Gate options

Most gates carry their thresholds inline — `phpcs.standard`,
`phpstan.level`, `coverage.min`, `mutation.msi_min`. Every gate that spawns
a tool also takes `timeout` (seconds) before the executor kills it and
reports "could not run"; the default is 600, and `xhigh`/`max` give
mutation 1800 and coverage 900 because infection over one kernel-test-heavy
module already outruns ten minutes. The option vocabulary is closed per
gate — anything else is refused by name.

**Every gate has a second switch after `on`: `mode`.** `block` (the default,
and the only behaviour that existed before) fails the phase on a blocking
result. `report` runs the gate exactly as before but records the failure as
`REPORTED` — the findings ride the report, the seeker reads them, the phase
advances — and nothing reads as a pass. It is frozen into the run like `on`:
whether a gate may block is not something a mid-run edit changes. The
mandatory trio can never be put in report mode, except at `preset: custom`;
that would be a disarm by another name, and the attempt is noticed and
superseded like `on: false`.


### Asking about tests at plan

`tests: { ask: true }` (at `preset: custom` only: a "no" turns phpunit off,
which the other levels hold mandatory) makes plan's hold ask two more questions, word for
word, in the same dialog: *Should this ticket get unit tests (PHPUnit)?* and
*Should the Playwright check of this work be saved as a test?* In a human-led
run the answers are the human's, taken from the dialog the guard recorded or
typed in the operator's terminal (`droost workflow:answer "…" --unit-tests=yes
--save-spec=no`); an autonomous run is asked nothing. Until someone says yes,
the answer is no.

The answer rewrites the run's frozen levers before any gate runs. No unit
tests: `phpunit` is off. Yes: on, with `in_diff`. No saved spec: `playwright`,
which runs saved specs, is off. Yes: on and `required`. `status` shows the
choice and who made it.

Three checks hold the run to it. `unit_tests_chosen` and `spec_chosen` block
a phase whose diff adds or changes a PHPUnit test or a Playwright spec that
was declined (deleting one is fine). `browser_checked` holds code until the
guard's diary shows a browser tool call in that phase, whatever was chosen
about saving it.

### Unknown keys are errors

A loader that shrugs at `phpstain:` hands back a run with static analysis
quietly disabled and a report that says everything passed. So every unknown
setting, gate, option, phase, mode and preset is refused by name:

```
droost/droost.yml: unknown gate "phpstain" (known: phpcs, phpstan, eslint,
stylelint, prettier, phpunit, mutation, playwright, parity, coverage,
house_rules, wiki_fresh)
```
