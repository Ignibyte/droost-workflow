# droost

[![ci](https://github.com/Ignibyte/droost-workflow/actions/workflows/ci.yml/badge.svg)](https://github.com/Ignibyte/droost-workflow/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/droost/workflow)](https://packagist.org/packages/droost/workflow)
[![PHP](https://img.shields.io/badge/php-%5E8.3-8892BF)](composer.json)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue)](LICENSE)

One command, `droost`, for an agent building or changing a Drupal site: what
the codebase holds, and the phased, gated run the work goes through.

```
plan → code → test → complete
```

- **Knowledge.** `droost code:wiki` (the wiki, and every installed contrib
  module and theme), `droost code:tree` (the code's structure) and `droost
  search:arch` (the documentation, as plain text), with `code:symbol`,
  `code:graph`, `search:code`, `code:docs`, `code:deprecations` and `brief`
  for the narrower questions. Read from the project's files and its code
  index: no Drupal is booted, drush is never called, and it answers while
  the site is broken.
- **The run.** Each phase has an entry and an exit gate. Pass, and the run
  advances; fail, and it enters a bounded feedback loop or stops. Plan does
  not close until the run has consulted `code:wiki` and `code:tree`. What
  "pass" means is configured per project, in `droost/droost.yml`.
- **The guard.** A harness hook that holds the agent to the run: it refuses
  out-of-phase edits, the operator's commands from the agent's shell, and
  edits to the rules the run is held to. Claude Code today.

droost is a framework-free PHP package (`droost/workflow`), installed in its
own vendor directory. It is not a Drupal module and needs none. Clean-room
GPL.

## Install

```bash
mkdir -p tools/droost
composer init -d tools/droost -n --name=local/droost-tools   # a Composer project of its own, for config to write to
composer config -d tools/droost platform.php 8.3.0   # the PHP droost runs under (the site's, e.g. the web container's)
composer require -d tools/droost droost/workflow
tools/droost/vendor/bin/droost init       # the pack, the guard's hooks, AGENTS.md, droost/droost.yml
tools/droost/vendor/bin/droost code:index # the code index the knowledge commands read
```

**Pin the platform to the PHP droost will run under.** Installed on a host
with a newer PHP than the site's container, Composer otherwise resolves
dependencies the container cannot load (a host on 8.4 chose symfony/yaml 8,
and droost died in the 8.3 container at Composer's platform check).

`tools/droost/composer.json` is a Composer project of its own, so the site's
dependency tree can break without taking droost with it, and droost cannot
load Drupal by accident. On DDEV run it in the container (`ddev exec
tools/droost/vendor/bin/droost …`). A project that kept the earlier root
`droost.workflow.yml` runs `droost migrate`, which moves it to
`droost/droost.yml` and carries the wiki and embedding settings over from the
exported configuration.

## The droost command

```
droost                      every command, one line each
droost <command> help       one command's arguments
```

| Command | What it answers |
|---|---|
| `code:wiki [page]` | the wiki (`droost/wiki`), and a page per installed contrib module and theme: its version (`composer.lock`), what it needs and provides (its YAML), whether the last configuration export enabled it. `code:wiki status` checks each page against its sources (the `wiki_fresh` gate); `code:wiki stamp` re-records a page's source hashes |
| `code:tree [module\|path]` | modules and themes, their files, classes, functions and methods, and each module's hooks, services and routes |
| `search:arch <words>` | plain text over the documentation: the wiki, the inventory, each extension's README and docs |
| `code:symbol <name>` | where a class, interface, trait, function or method is defined |
| `code:graph <symbol>` | what it calls, what calls it, what it injects and implements (`service:<id>`, `route:<name>`, `hook:<name>` too) |
| `search:code <query>` | symbol names and wiki text, lexically; the nearest code chunks too when `search.embeddings` names an endpoint |
| `code:docs`, `code:deprecations` | module documentation; Drupal core's deprecations, offline |
| `brief` | the project in one answer, from its files |
| `code:index [--full]` | builds or refreshes the code index |
| `workflow:<verb>` | the run: `workflow:run`, `workflow:status`, `workflow:criteria`, … (below) |
| `init`, `migrate`, `doctor` | install, move an old lever file, report what droost can see |
| `guard <verb>` | the guard a harness's hooks call; not for typing |

Every knowledge call writes a row to the run's tool-call ledger, written by
droost itself: `plan_grounded` reads it, so a call the agent only describes
is not a call. `bin/droost-workflow` still answers for one release and names
the `droost workflow:<verb>` it stands for.

## The lever file

One file, `droost/droost.yml`, is the source of truth:

```yaml
mode: agentic                   # agentic | interactive
lead: human-led                 # human-led | autonomous — who may finish a run (unset: interactive → human-led)
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
from `init` is set out under The guard — the enforcement hook is Claude
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

### Inherited debt — the adoption baseline

A legacy project fails these gates on debt the first change never touched:
hundreds of phpstan errors, forty phpcs findings, config drift nobody will fix
this month. The baseline is the operator's snapshot of that debt, written
once from a terminal, committed beside the lever file in `droost/baseline/`:

```bash
droost workflow:baseline --measure    # the bill: what each gate would inherit (read-only)
droost workflow:baseline              # write it (operator terminal); commit droost/baseline/
droost workflow:baseline --refresh    # re-measure: paid-off debt drops, growth is refused
droost workflow:baseline --refresh --grow --reason="legacy import"   # accept more, on record
droost workflow:baseline --status     # what is recorded (read-only)
```

From then on a consulting gate — phpcs, phpstan, eslint, stylelint, prettier,
coverage, mutation — answers two questions. **Inherited** is a
finding the baseline records: reported, counted, never failing. **New** is
everything else, and fails as before. The line reads `passed — 0 new, 123
inherited`, never a bare pass. A finding is keyed by file, rule, message and
the text of its line, so a line that shifts stays inherited and a line that is
edited becomes new — touching a file does not make its whole debt yours.
phpstan runs through the baseline's own generated file; prettier's recorded
files are inherited until the run touches them; coverage and mutation pass at
or above their recorded floor when they miss the level's target, with the
target named (the ratchet — the floor rises on refresh, never falls).

The run freezes the baseline's hash at begin. A baseline added, removed or
edited under a run fails every consulting gate with "the baseline changed
during the run"; the pack guard refuses the agent's shell from writing it
and the agent's editor from touching the directory; the seeker names any
move a defeat. `baseline: { on: false }` in the lever file is strict mode,
one visible line. Design: `docs/design-adoption-baseline.md`.

### The project's own gates, hooks and walls

A project adds rules of its own as files in `droost/`, named in
`droost/droost.yml`. Each runs as its own process from the project root,
never inside droost, so a fatal error cannot take the run with it, a timeout
can be enforced, and the file cannot reach the run's state. A PHP file runs
under `php`; anything else is executed.

| Kind | Declared as | Gets on stdin | Its answer |
|---|---|---|---|
| **gate** — a check at a phase | `gates.<id>: { run: droost/gates/<file>, phase: code \| test \| "code,test", mode: block \| report, timeout: <s> }`, or `cmd: "<one shell line>"` in place of `run:` | the run's JSON: `project_root`, `run_id`, `phase`, the changed files; `DROOST_PROJECT_ROOT` and `DROOST_GATE` in the environment | exit 0 passed, 1 failed, stdout its report; exit 4 ran and examined nothing (no file it reads, nothing to check), recorded as a **labelled** pass; **anything else — a missing file, a crash, a timeout, exit 3 — is recorded as did not run, never as passed** |
| **wall** — asked before a tool call | `walls.<id>: { tools: <regex over the tool's name>, run: droost/walls/<file> }` | `tool`, `tool_name`, `input`, `project_root` | exit 0 allows, exit 2 refuses with the reason on stderr; a wall that crashes, is missing or times out (10 s) refuses — its silence is never consent |
| **hook** — told about the run | `hooks: { run_start \| phase_begin \| phase_end \| run_complete: droost/hooks/<file> }` | the event: `phase`, `run_id`, `work_item` | recorded in `hook-calls.jsonl`, and its first line shown to the agent in `workflow:run` (`hooks` in the envelope) and `workflow:answer`; a hook never holds the run |
| **operator-only script** | `operator_only: [droost/bin/<script>]` | — | the guard refuses it from the agent's shell, as it refuses `droost workflow:bypass` |
| **panel tab** — a tab in the run panel | `panel: { tabs: { <id>: { title: <label>, run: droost/panel/<file>, timeout: <s, default 5> } } }`; `.ts`/`.js` run under node (which strips TypeScript's types) | `project_root`, `tab`, and `run` (the open run's id, state, phase, preset, lead, work item, spec), or `null` | one JSON object on stdout: `{"badge": "3/4", "sections": [...]}`, sections of kind `text`, `fields`, `checklist` or `list` ([The run panel](#the-run-panel-and-the-droost-mod)); one that fails, times out or prints anything else shows why in its place |

**The rules are frozen.** A run records every gate, hook and wall file's hash
when it begins (`rule_files` in `run.json`), and names a gate file that
changed since at its gate. While the run is open the guard refuses writes to
`droost/droost.yml`, `droost/gates/`, `droost/hooks/`, `droost/walls/`, `droost/panel/` and
`droost/agents.md`. **Trust is review**: only files committed in the
repository run, and droost fetches nothing.

`droost/agents.md` is the project's own text for the AGENTS block: `init`
carries it beneath droost's paragraphs, so every agent reads it.

A gate's verdict can be steered by a file the run changes: its config, its
ignore or policy file. The built-in gates know theirs; a project gate takes
`steered_by: <path>,<name>`, and snyk, gitleaks, semgrep, trivy and rector
are known by id. When the run changed one, the gate's summary says
`[steered by config this run changed: …]`, and the seeker reads an ignore
the spec does not record as the developer's decision as a discipline
finding. In the strict flow, complete re-runs every gate the run holds, the
project's included: it is the last wall before the run is recorded complete.
`droost workflow:evidence --full` prints each gate's output whole (up to the
16 KB kept) where the report otherwise cuts it at 2,000 characters.

A check that needs the running site is a project gate that asks it:
`config_clean: { phase: test, cmd: "drush config:status --format=json" }`.
droost itself never does.

### House rules — the site's own, held by a gate or the seeker

droost tells the agent no way to build. A site writes the rules its work is
held to, each with an id and a judge:

```yaml
rules:
  house:
    - { id: HR1, rule: 'New markup lives in single-directory components.', judge: gate, check: sdc_markup, mode: block }
    - { id: HR2, rule: 'Pages are composed in Canvas, never as a View page.', judge: seeker }
    - { id: HR3, rule: 'Custom code never queries the database directly.', judge: gate, cmd: "! grep -n 'Drupal::database'", files: '*.php', mode: block }
seekers: { holds: high, at_complete: true }
```

A rule a machine can check is judged by the `house_rules` gate, through one
of two things:

- a `check` droost knows: `sdc_markup`, read over what the run added, fails a
  Twig template outside a single-directory component's directory and a
  Canvas code component; `site_tools`, read over what the guard saw the run
  do, fails each raw `drush php:eval`, `php:script` or SQL call, since the
  droost module's tools (`drush droost:tool`) are recorded and held to the
  run and raw PHP and SQL are neither;
- a `cmd` of the site's own: phpstan, a phpcs sniff, semgrep, ast-grep, a
  grep. It runs at the project root with the files the run changed as its
  arguments (`cmd "$@"`), narrowed by `files` (a glob or a list), so it reads
  the run's work and never the code the run inherited. Exit zero holds the
  rule; any other exit is a break, its first lines in the finding; a command
  that is not there (127) or runs past ten minutes fails the gate as the
  environment's, never as a pass. One line, the site's own, reviewed in the
  diff like every lever.

A break fails the gate at `mode: block`, or is recorded beside a passing
phase at `report` (the default). A rule only judgment can read is the
seeker's: it runs
`droost workflow:rules`, checks the diff against each, cites the id in every
finding, and grades a break HIGH unless the spec records an exception the
operator approved; `seekers.holds: high` makes that hold the run. The page
rules (`rules.<kind>`: which owner builds each kind of page) are read the
same way, by the seeker, against the owner each declared route names.
`droost workflow:rules` prints what the open run is held to.

### Work-item project config

The optional `work_item:` block is the project config of a tracker bridge
(a project's own Jira layer and its kin). The engine never consumes it — it stays
framework-free and knows nothing of Jira — except for the one provider it
builds in, `markdown` (below, "Tickets (solo mode)"). It parses and validates the
block so a typo surfaces in review rather than at the first write, and
`workflow:status` echoes it. The shape is provider-agnostic on purpose: which
tracker and cloud, which projects and issue types are workable, how branches
are named, what the tracker calls its transitions, and a local map of every
custom field under a name the site chooses, so no module ever hardcodes
another team's field ids and a team's own layer only ever says
`developer_notes`:

```yaml
work_item:
  provider: jira
  cloud_id: <your Atlassian cloud id>
  projects: [PROJ]
  eligible_types: [Story, Task, Bug, Sub-Story]
  branch: { prefixes: { feature: feature, bugfix: bugfix }, base: development }
  transitions: { in_progress: 21, in_review: 121, done: 31 }   # for a /transition-style command; the engine never fires them
  fields:
    developer_notes: { id: customfield_10001, format: adf }
    testing_notes:   { id: customfield_10002, format: adf }
    developer_id:    { id: customfield_10003, format: user }
  track_map: { Bug: bugfix, Story: standard, Task: standard }
  writeback: { acceptance_criteria: description, dev_notes_field: developer_notes }
  status_map: {}          # empty is the common case: SCM events move the ticket, not droost
  publish: { target: confluence, space: DOCS, parent: "<page id>" }
```

A writeback target that names a `fields` entry resolves to its id. What a
ticket MUST carry, and what goes into each field, is the bridge's or the
team's business, never this block's — every write it describes stays gated.

### Tickets (solo mode)

With droost alone, tickets are markdown files in the repo,
read through a `WorkItemSourceInterface`. `work_item.provider: markdown` turns it on;
any other provider keeps the block's metadata-only meaning:

```yaml
work_item:
  provider: markdown
  markdown: { dir: droost/tickets, prefix: TICKET }   # both optional; these are the defaults
```

A ticket is `<dir>/open/<PREFIX>-<n>-<slug>.md`, or under `closed/` once it is
`done`, with a YAML frontmatter holding at least `title`, `status` and
`ticket_number`, and its sections after it. The file's name is its identity:
its id is `<PREFIX>-<n>` and its number is `n`, and a `ticket_number` that says
otherwise is kept as an extra key. Two older names read too: a split ticket,
`<PREFIX>-<n><letter>-<slug>.md` (id `<PREFIX>-<n><letter>`, so a number can
repeat), and a numberless `<PREFIX>-<slug>.md` (id the name, number null). A
file named for the prefix in neither shape is refused by name:

```markdown
---
title: TICKET-12-camp-news-by-recipe
status: ready
ticket_number: 12
type: feature
created: 2026-09-28
---

# TICKET-12-camp-news-by-recipe

## Summary
```

The states are `backlog`, `ready`, `in_progress`, `review` and `done`, machine
names so a Drupal Workflow can use the same ids. The legacy `open` and
`closed` read as `ready` and `done`, and a write always uses the new names.
Every other frontmatter key is kept, and a move rewrites the `status:` line and
nothing else, through a temporary file and a rename that keep the file's mode;
a symlinked ticket or directory is refused.

- `run --ticket=<id>` binds a run as it begins: `status` shows `run.work_item`,
  the `work_item` declaration is recorded as `declare-changes --work-item`
  records one, and the ticket moves to `in_progress`. When the run completes
  it moves to `review`, never to `done`, which is only ever a person's move. A
  move the source refuses is recorded as a note and never fails the run.
- `ticket list [--status=<state>]`, `ticket show <id>` and `ticket new
  --title="…" [--type=<type>]` are anyone's; `new` files into `backlog`,
  numbered one past the highest number any file's name carries, across
  `open/` and `closed/`.
- `ticket move <id> <state>` is the operator's (see the table below).

### Tickets (cockpit mode)

`work_item.provider: droost_cockpit` reads tickets from a cockpit, a web
service that owns a team's queue, and relays each run's events to it. The lever
file names two environment variables and never holds either value, since the
file is committed:

```yaml
work_item:
  provider: droost_cockpit
  cockpit: { url_env: DRUPLIT_MAILBOX_URL, token_env: DRUPLIT_SEAT_TOKEN }   # path: /work-items/v1 is the default
```

`url_env` holds the cockpit's origin, and `path` is appended to it. `token_env`
holds a bearer token, which is sent as `Authorization: Bearer` and never
written, printed or put in an error. `docs/cockpit-provider-api.md` is the API
a cockpit implements, and `tests/fixtures/cockpit-stub.php` is that API as a
`php -S` router, held to the document by a test.

- `run --ticket=<id>` fetches the ticket as the run begins and caches it in
  the state directory as `work-item-<id>.json`, so a restart with the cockpit
  down still binds it. A ticket the cockpit has never answered for is refused.
- `ticket list`, `ticket show` and `ticket new` ask the cockpit. `ticket
  move` exits 2 with "move tickets in the cockpit": its queue owns ticket
  state, and it reads a run's progress from the run's events.
- Every event the log gains is posted as it is written, and once more at the
  end of every command, from the cursor in `relay-cursor`, in batches of at
  most 100 events and 256 KiB. Delivery is at least once, and the cockpit
  dedups by `event_id`. The first failure in a process is reported once on
  stderr and silences the relay for the rest of that process, so a cockpit
  that is down costs a run at most one 5-second timeout and never fails it.
  The events wait in the log.
- `relay` sends what waits and exits 0 when nothing does, or 1 while events
  still wait. `status` shows `relay: {cursor, pending, last_error}`.
- Requests go over http or https only, with redirects refused rather than
  followed, since a redirect would carry the token to another host.

### Unknown keys are errors

A loader that shrugs at `phpstain:` hands back a run with static analysis
quietly disabled and a report that says everything passed. So every unknown
setting, gate, option, phase, mode and preset is refused by name:

```
droost/droost.yml: unknown gate "phpstain" (known: phpcs, phpstan, eslint,
stylelint, prettier, phpunit, mutation, playwright, parity, coverage,
house_rules, wiki_fresh)
```

## Which gates run when

WHETHER a gate runs is the lever file's business. WHEN it runs is the
engine's phase map, frozen into each run when it begins:

```text
plan: none
code: phpcs, phpstan, eslint, stylelint, prettier, house_rules
test: phpcs, phpstan, eslint, prettier, phpunit, mutation, playwright, parity, coverage
complete: phpcs, phpstan, eslint, stylelint, prettier, phpunit, mutation, playwright, parity, coverage, house_rules, wiki_fresh
```

Plan runs no gate — there is no code yet to measure. One check holds it,
`plan_grounded`: plan waits until the run's tool-call ledger shows a `droost
code:wiki` and a `droost code:tree` call since the run began (owner,
2026-10-19), and the spec's `## Grounding` cites what the project and its
code really hold. `brief` alone is not looking, and nor is a table of `none:`
claims: at least one citation must name something that exists (a class, a
file, a wiki page; never a directory or the run's own spec), and a `none:`
claim counts only while the code index can be asked. It reads the ledger and
resolves citations from droost's code index and the files, none of which
needs Drupal. droost forces the agent to LOOK; it never says how to build
(owner, 2026-10-15: "as models get better they dont need to ask they need
you to build rules around your code"). The site's own rules are what the
work is held to: a house rule (`rules.house`) is checked on the diff by
`house_rules` when a gate judges it and by the seeker when judgment does,
and a page rule in `rules:` is read by the seeker against the owner each
declared route names. Code gates the diff with static analysis. Test runs
the functional gates, and re-runs
the static ones over the tests it has just written — a suite whose own source
would not pass code's standards is not a suite whose green anyone should
believe. Complete opens by capturing
what was built — the documentation work that was its own phase until 0.4 —
and then re-runs the full enabled set as the terminal safety net, the
project's own gates included, so every other enabled gate is met at least twice: once at
its own phase, once at the end.

That is the **strict** flow, which `high` and the levels above it run. Since
0.11 `low` and `medium` run the **fast** flow (`flow: fast | strict`, a lever
the level defaults): each gate runs once, at the phase that owns it. Code
runs the analysers and the unit tests (phpunit, mutation and coverage where
on), test runs playwright, parity and the house rules, and complete runs no
gate but `wiki_fresh` on the documentation it writes. A run
freezes its flow's table as `phase_gates` when it begins. The same two
levels default `scope: ticket` on phpcs, phpstan, phpunit and playwright: the
analysers read the files the run changed, and the suites run the test files
it added or changed, the Playwright CLI handed those specs. `scope: full` runs
a gate's whole set. A scoped row says so ("ticket scope: 2 file(s) this run
changed, not the full suite"), and a required suite the ticket gave no test
to run fails. At `low` the browser gate also takes `reuse: recorded`:
`droost workflow:specs`, run at test, runs the ticket's specs exactly as the
gate would and records the result against a fingerprint of the tree, and the
test phase then takes that record rather than running the suite a second
time. The row says so ("recorded at …, on this tree: not run again"). An edit
after the recording, a tool that could not run, or a level at `reuse: never`
(every level above low) runs the suite. What is taken is always droost's own
run of the tool, never an account of one. `droost workflow:gate [--json]
[--phase=code|test|complete] [--base=<ref>] [--skip=<gate,…>]` (0.14.17) runs one phase's
gates, test by default, with no run at all: the lever file frozen as a run
would freeze it, nothing kept (no run opened, no evidence written, no retry
spent). A ticket-scoped gate reads what
changed since `--base` (a landing's pre-merge commit), or its full set without
one. `--skip=<gate,…>` leaves gates out, each recorded `off`; the browser suite
runs for real against the site, and the check says so before it starts
(`--skip=playwright` leaves it out). It exits 0 when no gate blocks, 1 when one
does, and 2 when refused: while
the project's run is at test, or for a base that names no commit. `--json`
prints `{phase, preset, base, passed, gates: [{gate, status, outcome, labelled,
summary, exit_code, duration_ms}]}`, `outcome` one of pass, fail, skipped or
reported, and `labelled` true for a pass that examined nothing. P6 run 23 measured
what the strict flow costs at the bottom of the dial: its 279-test browser
suite ran three times in 86 minutes, for a ticket whose own tests were two
files. `wiki_fresh` is due only here, and only here
CAN it be true: it asks `droost code:wiki status` whether the project's own
documentation still matches the code, and complete is the phase that just
wrote it. A
stale page is read as fact, which is worse than no page.

## Promise against proof: the criteria table

The full spec's acceptance-criteria table carries a `Verified By` column,
empty at plan. The test phase fills it with the test that proves each row —
the PHPUnit method or class, or the Playwright spec — or `manual — <reason>`
for a criterion no test can prove. At `complete` an empty cell (or a missing
column) is **recorded, not blocking**: the report names each unverified row
under `criteria_verified`, prints manual as manual, never as passed, and
`workflow:status` shows the three lists. It was a refusal once, and the shape
of a markdown file ended three live runs whose every gate had passed (F-35),
so the spec's shape is a record now and the gates are the wall. A criterion
verified through `verify_criterion` is recorded against the test that ran. A
quasi-spec at `medium`/`low` has no table and is not held to one.

## The feedback loop

A blocking gate does not end a run; it starts a bounded loop. Each failing
invocation spends one attempt per blocking gate — recorded in run state as
`feedback_attempts`, measured against `max_gate_retries` — and the agent
fixes the cause between invocations. `max_gate_retries: 2` means one attempt
plus two retries; `0` means one attempt and no retry. A missing tool spends
budget exactly like a failure, because a missing binary re-invoked forever
is the worst infinite loop of all.

When the budget is spent, the phase is recorded **failed** — terminal — and
`run` refuses to execute anything further. Every surface renders the same
envelope: `{outcome, current_phase, report, awaiting, retries, loop}`, where
`retries.exhausted` separates "fix it and run again" from "this run is
over". Exit codes stay simple — paused is not failed, and both kinds of
failure exit non-zero. Recovery from a terminal failure is deliberate:
`droost workflow:reset`
archives the record to `droost/droost-workflow/history/` and clears the way —
the same verb that closes out a COMPLETED run, whose record also persists
until reset.

### The fast flow's loop: back to code, or a follow-up

In the fast flow a failure at test does not retry in place: the run goes
**back to code** (`outcome: returned`, exit non-zero), with the failing gates
named in `blocked`. Code's gates run again on the next `run`, and test after
them. `max_loops` bounds it (low 2, medium 3, high and xhigh 5, max 5). A
return because the code **moved** spends nothing: when the files code's gates
measured change after they ran (a fix made at test, an edit at complete), the
next test or complete sends the run back to code before any gate runs, so no
green stands over code it never saw. Markdown and the workflow's own
`droost/` directory are not code a later phase re-measures.

A failure that is **out of the ticket's scope** (a full-scope browser suite
whose every failure is in a spec the run did not change), or one still there
when the budget is spent, becomes a **follow-up ticket**: the phase is
recorded `deferred`, never passed, a `follow_up` row at the phase names each
ticket, and the run moves on (`outcome: deferred`, exit 0). `follow_ups`
decides where they go: `auto` (the default) writes to the cockpit when it is
the project's ticket source and to markdown otherwise (the project's own
ticket directory, or `docs/tickets/`); `markdown` and `cockpit` say so
outright; `none` writes nowhere, so a spent budget fails the phase. At `max`
a spent budget fails the phase too. A follow-up the cockpit refuses is
written as markdown, and the record says why. A tool that could not run
(missing, crashed, timed out) is not code to fix, and keeps the in-place
retry above. Every return, its reason and each follow-up are in the envelope's
`loop` block and in `run.json`.

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

## Run state

Run state lives beside the lever file, in `droost/droost-workflow/run.json`
(a project that still has the older `.droost-workflow/` directory keeps
using it until that directory is gone):

```json
{ "v": 1, "run_id": "...", "phases": { "plan": "passed", "code": "active" } }
```

On the filesystem for the same reason the levers are: a run can be resumed,
inspected and described from a plain checkout, with no site at all.

Writes go through a temporary file and a rename, so a run that dies mid-write
leaves the previous state intact. A state file that cannot be parsed is never
deleted or replaced: it is still evidence of what a run was doing.

Two limits on that, stated because an overstated durability promise is worse
than a modest one: there is no `fsync`, so a process crash is covered but a
power cut is not; and there is no locking, so two processes doing
load-modify-save against one file will lose an update without either being
told. Nothing is ever torn. The model is one run per repo.

**A phase that failed is never quietly recorded as passed.** Advancing stamps
the phase you are leaving as passed, so advancing away from a failed or
skipped phase is refused outright — clearing a failure has to be a deliberate
act, not a side effect of moving on. Advancing backward, or advancing a run
that has already reached its terminal gate, is refused for the same reason: a
report has to be able to describe the run honestly. The fast flow's one way back is
its own transition, test or complete to code, which sets the phases between
back to pending; and a phase it leaves with follow-ups is `deferred`, a word
of its own, never `passed`.

## Run events

Beside `run.json`, the engine keeps `events.jsonl`: an append-only log of every
state change it persists, one JSON event per line, written after the save. It
is the record a relay or a dashboard reads (`droost workflow:events
[--after=<seq>]` prints it), and it works with nothing else installed:

```json
{"schema":"droost.run-event/1","event_id":"evt-4f1c2a9be0d37a61","seq":7,"run_id":"run-8c2d4e6f1a3b","work_item_id":"TICKET-12","at":"2026-09-28T15:04:11+00:00","type":"phase.attempted","payload":{"phase":"code","attempt":1,"report":{"phase":"code","advance":false,"tally":{"passed":3,"failed":1},"gates":[]}}}
```

| Type | When | Payload |
|---|---|---|
| `run.started` | a run begins | `preset`, `mode`, `enforcement`, `phases`, `spec`, `base_commit` |
| `phase.began` | a phase becomes current | `phase` |
| `phase.attempted` | a phase's gates ran, pass or fail (a failed gate never ends a phase, so this is the only event that shows one) | `phase`, `attempt`, `report` (the phase report: `advance`, `tally`, every gate) |
| `phase.ended` | the run leaves a phase | `phase` |
| `question.asked` | a save leaves a question newly awaiting | `question_id`, `question` |
| `question.answered` | `answer` is saved | `question_id`, `answer` |
| `run.completed` | the last phase ended | `outcome` (the run envelope) |
| `run.reset` | `reset` archived the run | `archived_run_id` |
| `phase.returned` | the fast flow sent the run back to code | `from`, `to`, `reason` (`failed` or `moved`), `spent`, `max_loops`, `gates` |
| `follow_up.filed` | a deferred phase's failure was written up | `phase`, `gate`, `why` (`spent` or `outside`), `summary`, `title`, `id`, `source`, `path`, `at`, `note` |
| `intake.opened` | an intake opened, before any run (`run_id` is the intake's id) | `intake_id`, `request` (the human's words), `source` |
| `intake.audited` | droost-source read the source | `intake_id`, `sha` (audit.json's sha256), `summary` |
| `intake.asked` | the agent put questions to the human on a host with no AskUserQuestion (`intake ask`) | `intake_id`, `asked_at`, `questions` (each `id`, `question`, `options`, `recommended`) |
| `intake.answered` | the operator recorded an answer to an intake question (`via`: `terminal`, or `cockpit` when a host relayed it) | `intake_id`, `question`, `answer`, `via` |
| `intake.approved` | the roadmap was approved on the human's recorded yes | `intake_id`, `digest` (the four files' and each rung ticket's sha256), `rungs`, `archived`, `via`, `human` (that yes) |
| `intake.abandoned` | the operator abandoned the intake | `intake_id`, `reason`, `archived` |

Every event has `schema`, `event_id` (`evt-` and 16 hex digits, the consumer's
dedup key: delivery is at least once), `seq` (strictly increasing per log from
1, a relay's cursor and never an identity), `run_id`, `work_item_id` (the bound
ticket, or null), `at`, `type` and `payload`. `schema/run-event.v1.json` is the
JSON Schema. Version 1 is additive only: new types and payload keys may come,
a consumer ignores what it does not know, and renaming or removing one is
`droost.run-event/2`.

The log spans runs, so `reset` keeps it. An append holds an exclusive lock, so
two processes never interleave a line or reuse a `seq` (on a state directory
over NFS that lock is advisory, as `flock` is). A log that cannot be written is
reported once on stderr and never fails or blocks the run. It lives in the
state directory, which `init` keeps out of version control, as it does
`evidence.sqlite`.

## The pack

`droost init` installs the phases as a `.claude/` pack: eleven skills (the
three entry verbs `workflow-start`, `workflow-continue` and `workflow-status`,
one per phase, `intake`, and one per knowledge command: `droost-code-wiki`,
`droost-code-tree`, `droost-search-arch`), four slash commands that are
one-paragraph pointers to the verb skills (`/droost:workflow:start`,
`/droost:workflow:continue`, `/droost:workflow:status`,
`/droost:workflow:intake` — Claude Code's way in; every other host reads the
skill itself, which is why the procedure lives there and not in the
command), five agents (the plan researcher and spec-writer, the adversarial
`workflow-seeker`, the one-finding-at-a-time `workflow-bug-fixer`, and
`droost-debugger`, which flips xdebug on for a stubborn failure and back off
after), and a shared partial on using droost. It writes:

```
.claude/skills/workflow-{start,continue,status}/SKILL.md
.claude/skills/workflow-{plan,code,test,complete}/SKILL.md
.claude/skills/intake/SKILL.md
.claude/skills/droost-{code-wiki,code-tree,search-arch}/SKILL.md
.claude/commands/droost/workflow/{start,continue,status,intake}.md
.claude/agents/workflow-{researcher,spec-writer,seeker,bug-fixer}.md
.claude/agents/droost-debugger.md
.claude/partials/droost-usage.md
.claude/templates/evaluation.md
.claude/settings.json        # the guard's hook entries, merged in
AGENTS.md                    # droost's blocks, created when absent
CLAUDE.md                    # Claude Code's pointer to AGENTS.md
droost/droost.yml            # only if you don't already have one
```

**The droost module's skills join it.** On a Drupal site with the droost
module on disk, `init` also installs each skill the module and its
submodules ship (`skills/<name>/`, the skill and the files it links):
`droost-tools`, which says which of the site's tools (`drush droost:tool`)
does what and how a run holds them, on a Canvas site `canvas-build`, and
with `droost_mcp` the `droost-tools-mcp` skill for the same tools over MCP. Each
goes into `.claude/skills/` for Claude Code and `.agents/skills/` for
Codex. Only a module named `droost` or `droost_*` is read, a name the pack
ships stays the pack's, and a skill whose module is gone is removed at the
next `init` unless you edited it.

The workflow forces the consult; the knowledge skills say how. Each phase
skill states its entry gate, the work, its exit gate, and **what it can and
cannot check without a running site**: droost reads files, so it answers with
the site down, but the site's content, active configuration and rendered
pages are not droost's to read, and a run that hides that produces a report
nobody should trust.

**Ownership is explicit.** Every directory the pack owns gets a
`.droost-workflow-pack` marker. Re-running `init` refreshes those
directories and nothing else; a directory without the marker belongs to you
and is refused rather than overwritten. Your `droost/droost.yml` is never
refreshed at all — it is version-controlled intent you wrote. `.claude/settings.json`
is merged (an existing file keeps everything else it holds; one that cannot
be parsed is refused, not replaced), and the state directory's ignore lines
are appended to `.gitignore` if they are not already there.

## The run panel, and the droost mod

`droost workflow:panel --json` is the run as a panel: the run (ticket, level,
lead, state), its four phases, a question it waits on, and tabs. droost's own
are **Spec** (the spec itself, and each criterion with its proof),
**Activity** (what the agent says it is doing, with `droost workflow:say
"<what>" [--detail=<…>]`, labelled as its own account, beside what the
guard saw it do: each call and the file or program it touched, refusals
marked), **Gates** (each phase's
verdicts, a pass that examined nothing, a gate a level turned off and one
that could not run told apart from a pass) and **Seeker** (each finding and
how it closed). A project's own tabs follow, from `panel.tabs` (the table
above). A tab is sections of four kinds:

| kind | holds |
|---|---|
| `text` | `text` |
| `markdown` | `text`, drawn as markdown (at most 9,500 characters) |
| `fields` | `items: [{label, value}]` |
| `checklist` | `items: [{text, done, note?}]` |
| `list` | `items: [{text, detail?, tag?, tone?: ok \| warn \| bad \| info \| muted}]` |

Each may carry a `title`. What does not fit is dropped and the tab says so,
so a project tab can be written against this and nothing else.

**The droost mod** draws it in Claude Code: a pane (`/droost-panel`) with
the Drupal drop, the phases, the run, and a bordered tab per tab (keys 1 to
9). It opens by itself as `panel.open` in droost.yml says: `auto` (the
default) when a run is under way, `always` at every session, `never` only
when asked; Claude Code shows a pane opened unasked only where it docks it.
While the mod is loaded the agent's own task list (TaskCreate, TodoWrite) is
turned away: the panel is the run's list. `m` minimizes it to the phase line alone and maximizes it again
(as much room as Claude Code gives); Esc closes it and `/droost-panel`
brings it back. Claude Code seats the pane: on the right, full height and
as wide as it allows, in its fullscreen layout (`/tui fullscreen`) on a
terminal 110 columns or wider; above the prompt, about a third of the
height, otherwise. The drop is a real image where the
terminal draws images (iTerm2, kitty, WezTerm, Ghostty; not inside tmux),
and a blank otherwise. It asks `droost workflow:panel --json` every three seconds and
draws what comes back; it refuses nothing and writes nothing. It ships in
this package (`claude-plugins/`), and installing it is the operator's act
(the guard refuses an agent's):

```
claude plugin marketplace add ./tools/droost/vendor/droost/workflow/claude-plugins
claude plugin install droost@droost --scope project
```

or, for one session, `claude --plugin-dir tools/droost/vendor/droost/workflow/claude-plugins/droost`.
Where droost runs in a container, set the plugin's `droost_command` (for
example `ddev droost`).

## The guard, and each harness's shim

The guard is droost's code and ships in the package; it is never copied into
the project. Each agent harness's hook configuration gets one line per
event, calling the guard from where the package is installed. For Claude
Code, `init` writes nine entries into `.claude/settings.json` (PreToolUse
on Edit/Write, on Bash, on `mcp__.*` and on AskUserQuestion; PostToolUse for
the nudge, for Agent/Task and for AskUserQuestion; Stop; SubagentStop), each running
`php <package>/bin/droost-guard <verb> --harness=claude` (`droost guard
<verb>` is the same guard). Before a tool call it applies droost's rules and
the project's walls; after an event it runs the project's hooks.

It refuses, from the agent: out-of-phase edits while a run is open
(`enforcement`), custom-code edits with no run at all (`require_run`), edits
to the run's record, the evidence store, the baseline, the lever file and the
project's rule files during a run, and edits to its own wiring
(`.claude/settings.json`) and to the package (`tools/droost/`) at any time,
as it refuses a new file the editor would make in the state directory (the
spec is the one file there that is the agent's).
And it refuses the operator's commands from the agent's shell, in every
spelling (`droost workflow:<verb>` from any install path, the retired
`droost-workflow <verb>`, and the colon spelling `droost:workflow:<verb>`),
handing back a command the operator can run:

| Refused from the agent's shell | What is still the agent's to run |
|---|---|
| `droost workflow:gate-waive` | — nothing; a waiver is always the operator's |
| `droost workflow:baseline` (writing) | `--status` and `--measure`, which only read |
| `droost workflow:bypass` | `bypass --off`, which tightens |
| `droost workflow:effort <level>` | bare `effort` (reports) and `effort <level> --preview` (prices it) |
| `droost workflow:ticket move <id> <state>` | `ticket list`, `ticket show` and `ticket new` (a follow-up, filed in backlog) |
| `droost workflow:intake abandon`, `intake answer` | `intake start`, `intake audit`, `intake check`, `intake status`, `intake ask`, and `intake approve` once the human has said yes; asking the human through AskUserQuestion, whose answers the guard records |
| a script `operator_only` names | — |

The pattern is the same in each row: the reading and the tightening are the
agent's, the loosening is not. An agent that needs one proposes it — the
refusal prints the exact command to hand over, and the operator runs it in a
terminal of their own, outside the agent's session. Not with Claude Code's
`!`: it runs a command with no terminal, so the command refuses it as it
refuses the agent (F-256).

**Which harnesses are forced.** A harness is forced only when its hooks can
refuse a tool call before it runs, and only Claude Code has a shim today.
`init` writes droost's AGENTS.md block, and the pointer files of the
harnesses it finds (`--harness=all` for every one it knows: Claude Code,
Codex, opencode, Gemini, Qwen), so every host gets the doctrine, the
levers, the command and the skills as readable prose — and not the
enforcement. `declare-tasks codex|other|none` records which host is driving,
and on a host with no shim the status document says `enforcement.effective:
advisory` in as many words: the gates still hold the run, because the engine
runs them, but nothing stops an out-of-phase edit. A report must not claim a
discipline the host never had. The guard's rules are written against
droost's own events (a file write, a shell command, a tool call, a stop, a
subagent), with a translator per harness, so a second shim is a translator
and its probe, not a second guard.

## Requirements

PHP 8.3+ with `pdo_sqlite`. Runtime dependencies: `symfony/yaml` and
`droost/engine` (the code index, the wiki and search cores, the harness
installers), which brings `nikic/php-parser` and `symfony/process`. No Drupal
bootstrap is needed for anything: the levers, the run state, the code index
and the wiki are files.

## Development

```bash
composer install
scripts/lint            # phpcs, phpstan (level max), phpunit
scripts/lint src/Config # scope phpcs to a subset
```

All three legs are **hard**. A missing binary fails the gate rather than
skipping it and says which invocation it could not run — an environment that
cannot run a gate is a broken environment, not a gate that does not apply.

## License

GPL-2.0-or-later.
