<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Config\PhaseGateMap;

/**
 * What the agent said it would change, against what it actually changed.
 *
 * Nothing in the workflow asked for this before. The `## Tooling plan` maps
 * constructs to surfaces and never names a path; the `Verified By` column names
 * a test and is filled at the TEST phase, after the fact. The only statement of
 * scope was a sentence in the code brief — "Build what the plan describes.
 * Nothing more" — which nothing checked.
 *
 * So the plan declares, and the code phase is audited against the declaration.
 * The asymmetry is the whole design:
 *
 *   * a file touched that was never declared is SCOPE CREEP, and blocks. It is
 *     the defect the seeker exists to catch by judgement, caught here by
 *     arithmetic — and the seeker is off below `medium`, so on a light run
 *     nothing looked at all.
 *   * a file declared and not touched is RECORDED and does not block. Plans
 *     shrink for good reasons, and a run that wedged because the agent found a
 *     simpler way would teach it to pad declarations rather than to plan.
 *   * a test planned and not run BLOCKS, because "I will cover this" is a
 *     promise about verification, and dropping it silently is how a green
 *     arrives over untested code.
 *
 * The comparison is against the diff, not against anything the agent reports:
 * the caller supplies the changed paths from VCS.
 */
final class DeclarationAudit {

  /**
   * Paths never counted as scope creep, whoever touched them.
   *
   * The run's own record, the pack droost itself installs, and the lock files a
   * legitimate build rewrites. An agent cannot plan around composer
   * reformatting
   * a lock file, and a blocked phase over one is noise that teaches people to
   * declare everything.
   *
   * `.claude/` and the lever file are here because leaving them out made this
   * audit punish the one thing it exists to reward. A reviewer declared its
   * files honestly and was blocked at code over THIRTY-ONE undeclared paths,
   * every one of them written by `droost-workflow init` itself: the five agent
   * briefs, four commands, the guard hook, settings.json, six skills and their
   * pack markers, the evaluation template, .gitignore and droost.workflow.yml.
   * Declaring nothing skipped the audit entirely and completed in half the
   * commands. So the measured shortest path through a run was to declare
   * nothing, and the cause was droost blaming the agent for droost's own
   * install.
   *
   * This is the third time that exact inversion has shipped, which is why it is
   * spelled out rather than left to a reader to infer from a prefix list.
   */
  /**
   * The gates a test can be observed running through.
   *
   * `EvidenceStore::ranTests()` reads these four and nothing else, so a level
   * that turns all four off leaves "which tests ran" with no possible answer.
   */
  private const array TEST_GATES = ['phpunit', 'playwright', 'coverage', 'mutation'];

  /**
   * Where the gates' executables live, which no exemption covers.
   *
   * `ShellGateExecutor::binaryPathFor()` resolves every gate to one of these,
   * so a tool droost is about to trust is not the agent's to rewrite — and a
   * dependency-tree exemption that swallowed them turned "the gates passed"
   * into "the gates were replaced".
   *
   * Mapped to the manifests whose presence in the same diff means the PACKAGE
   * MANAGER rewrote them rather than the agent, because composer touches these
   * on every install and refusing that would wall off ordinary work.
   *
   * @var array<string, list<string>>
   */
  private const array TOOL_DIRS = [
    'vendor/bin/' => ['composer.json', 'composer.lock'],
    'node_modules/.bin/' => ['package.json', 'package-lock.json', 'yarn.lock'],
  ];

  private const array NEVER_CREEP = [
    'droost/droost-workflow/',
    '.droost-workflow/',
    // Everything `droost-workflow init` writes, plus the lever file the briefs
    // tell the agent to edit.
    //
    // This exemption is only safe because the GUARD refuses the parts of
    // `.claude/` that matter while a run is under way — the hook, both
    // settings files, and the skills, agents and commands the run is being
    // held to. The two rules are load-bearing together: loosen either and
    // an agent can rewrite its own brief mid-run with nothing saying so.
    '.claude/',
    'droost.workflow.yml',
    // Init appends its ignore rule here.
    '.gitignore',
    'droost/baseline/',
    'droost/evidence/',
    // Dependency bookkeeping. The lock files were here and their MANIFESTS
    // were not, which made the product's own documented install step —
    // `composer require --dev droost/workflow` — a scope-creep block carrying
    // an agent fault, on a file the agent did not choose to write. Installing a
    // dependency the plan asked for is not undeclared work; the declaration is
    // the requirement, and the manifest is how a package manager records it.
    'composer.json',
    'composer.lock',
    'package.json',
    'package-lock.json',
    'yarn.lock',
    // And the trees those manifests fill. `vendor/` and `node_modules/` are
    // routinely gitignored, so most repositories never see them here — the
    // ones that commit them saw every transitive dependency of a single
    // `require` reported as undeclared creep.
    'vendor/',
    'node_modules/',
    // Caches the GATES THEMSELVES write. phpunit drops
    // `.phpunit.result.cache` on every run, and a second ticket in the same
    // repo was then blocked — `declared_files / agent`, "There is no waiver for
    // it" — for a file the product had written and the agent had never
    // touched. A walk found it as the first wall between a completed run and
    // the next one. The others here are the same shape from the same toolchain,
    // added now rather than discovered one wall at a time.
    '.phpunit.result.cache',
    '.phpunit.cache/',
    '.phpcs-cache',
    '.phpcs.cache',
    '.php-cs-fixer.cache',
    '.phpstan.cache/',
    '.eslintcache',
    '.stylelintcache',
    'infection.log',
    '.infection/',
  ];

  /**
   * Constructs a DeclarationAudit.
   *
   * @param list<string> $declaredFiles
   *   The paths the plan said would change, project-relative.
   * @param list<string> $declaredTests
   *   The tests the plan said would run — class names, methods or paths.
   * @param list<string> $changedFiles
   *   What the diff actually shows, project-relative.
   * @param \Droost\Workflow\Evidence\WorkType|null $workType
   *   What the plan said this run is, or NULL when nothing was declared.
   * @param list<string> $measuredGates
   *   Gates that actually measured something this run — `satisfied` or
   *   `recorded`, never `off` or `skipped`. A type names the gates its kind of
   *   work rests on, and "passed" is not the same claim as "looked".
   * @param list<string> $gatesOff
   *   Gates this run's LEVEL turned off. Nothing here may be held against the
   *   agent: an operator moving the dial to `low` is deciding that phpunit does
   *   not run, and blocking the agent for the absence of a result the operator
   *   removed blames somebody for somebody else's decision — and cannot be
   *   cleared, because no phase can turn a gate back on. That is a deadlock,
   *   and this project has shipped that shape twice already.
   */
  public function __construct(
    private readonly array $declaredFiles,
    private readonly array $declaredTests,
    private readonly array $changedFiles,
    private readonly ?WorkType $workType = NULL,
    private readonly array $measuredGates = [],
    private readonly array $gatesOff = [],
  ) {}

  /**
   * Files changed that nobody declared.
   *
   * A declared DIRECTORY covers the files under it: an agent that says it will
   * work in `web/modules/custom/kchockey` has declared its scope, and making it
   * enumerate every file it will create would make the declaration a chore
   * nobody writes honestly.
   *
   * @return list<string>
   *   The undeclared paths.
   */
  public function undeclared(): array {
    return array_values(array_filter(
      $this->changedFiles,
      fn (string $file): bool => !$this->covered($file) && !$this->exempt($file),
    ));
  }

  /**
   * Files declared that the diff does not show.
   *
   * Recorded, never blocking.
   *
   * @return list<string>
   *   The paths that were planned and not touched.
   */
  public function untouched(): array {
    return array_values(array_filter(
      $this->declaredFiles,
      fn (string $declared): bool => !$this->anyChangeUnder($declared),
    ));
  }

  /**
   * Tests the plan promised that nothing ran.
   *
   * @return list<string>
   *   The missing tests.
   */
  public function missingTests(): array {
    // DELIBERATELY EMPTY, and the emptiness is the honest answer.
    //
    // This used to substring-search the test gates' summary and invocation for
    // each declared name. Neither string ever contains a test name: a passing
    // phpunit gate stores "phpunit passed — 3 test(s), 7 assertion(s)" and an
    // argv with no filter and no path. So the search could only ever fail on a
    // real name and succeed on an accident:
    //
    //     --tests=WidgetTest::testReturns   blocked, forever, zero budget spent
    //     --tests=WidgetTest                blocked, forever
    //     --tests=phpunit                   advanced immediately
    //     --tests=test                      advanced, on a flag name
    //
    // An agent naming its tests honestly was wedged at every level above `low`;
    // an agent naming a meaningless word walked through. That is the exact
    // inversion this class's docblock says it exists to prevent, shipped inside
    // the class itself — and the shipped worked example in
    // `pack/skills/workflow-plan/SKILL.md` is the trigger.
    //
    // droost cannot currently observe WHICH tests ran, so it must not pretend
    // to. The declaration is still recorded and still shown; the test gate's
    // own verdict — did the suite pass, how many tests — is in §4 and is real.
    // Making this answerable needs the phpunit gate to emit JUnit XML and the
    // executor to read the class and method names out of it; until it does, a
    // promise droost cannot check is a promise droost records.
    return [];
  }

  /**
   * The audit as checks, ready for the store.
   *
   * Separate items because they fail for different reasons and a reader needs
   * to know which — and separate PHASES for the same reason, which is a lesson
   * this method learned the hard way.
   *
   * Scope is a code-phase question: did you build what you said you would.
   * Coverage is a test-phase question: did you run what you said you would.
   * Asking the second one at code makes it unanswerable — no test-shaped gate
   * runs at code, so `ranTests()` is empty BY CONSTRUCTION, every promised test
   * reads as never run, and the phase can never pass. The plan brief instructs
   * the declaration that then makes the run impossible, which is exactly the
   * shape of the SpecFreeze deadlock this project already shipped once.
   *
   * @param string|null $phase
   *   The phase being audited, or NULL for every check at once (tests).
   *
   * @return list<\Droost\Workflow\Evidence\CheckRecord>
   *   The adjudicated items due at this phase.
   */
  public function checks(?string $phase = NULL): array {
    $undeclared = $this->undeclared();
    $untouched = $this->untouched();

    $scopeSummary = $undeclared === []
      ? sprintf(
        '%d declared path(s), no undeclared changes%s',
        count($this->declaredFiles),
        $untouched === [] ? '' : sprintf('; %d declared and not touched: %s', count($untouched), implode(', ', $untouched)),
      )
      : sprintf(
        'changed without being declared: %s. Scope found mid-build belongs in the spec first, not in the diff quietly.',
        implode(', ', $undeclared),
      );

    // WHICH PHASES REACH HERE IS DECIDED BY THE CALLER, and this condition has
    // to agree with it or it is fiction. `WorkflowFacade::auditDeclarations()`
    // calls this at code and test only, so the `'complete'` this used to name
    // was a branch no run could enter — a rule that looked enforced, read as
    // enforced, and was not. Asking at complete would add nothing anyway: the
    // test phase has already asked, and a run that reached complete answered.
    //
    // NULL means "no phase named", which is how the audit is exercised
    // directly; both questions are then due.
    $scopeIsDue = $phase === NULL || $phase === 'code';
    // CODE TOO, now that each gate is asked about where it runs: phpcs and
    // phpstan are code-phase gates, and asking about them only at test is what
    // made this check unanswerable. `gatesDueAt()` narrows it to the gates the
    // phase can speak for, so `code` never reports phpunit as unmeasured.
    $coverageIsDue = $phase === NULL || $phase === 'code' || $phase === 'test';
    $checks = [];
    if ($scopeIsDue) {
      $checks[] = new CheckRecord(
        'declaration',
        'declared_files',
        $undeclared === [] ? CheckState::Satisfied : CheckState::Blocked,
        $undeclared === [] ? Fault::None : Fault::Agent,
        $scopeSummary,
      );
    }
    if ($this->workType !== NULL && $scopeIsDue) {
      // Exempt paths filtered FIRST. Without it the audit was blocked by the
      // database recording the block: evidence.sqlite, its -wal and -shm, and
      // run.json counted as "not that kind of work" and outvoted the diff.
      $subject = array_values(array_filter(
        $this->changedFiles,
        fn (string $file): bool => !$this->exempt($file),
      ));
      $unexpected = $this->workType->contradictedBy($subject);
      // Contradiction, not a census — and still proportional, because one file
      // that happens to match is not the shape of a false declaration.
      $total = max(count($subject), 1);
      $adrift = count($unexpected) / $total > 0.5 && count($unexpected) > 1;
      $checks[] = new CheckRecord(
        'declaration',
        'work_type',
        $adrift ? CheckState::Blocked : CheckState::Satisfied,
        $adrift ? Fault::Agent : Fault::None,
        $adrift
          ? sprintf(
            'declared "%s" (%s), but %d of %d changed file(s) contradict it: %s. '
            . 'The type decides which gates must have measured, so declaring one and building '
            . 'another means the wrong things were checked. Re-declare with the type this '
            . 'really is.',
            $this->workType->value,
            $this->workType->label(),
            count($unexpected),
            count($subject),
            implode(', ', array_slice($unexpected, 0, 5)),
          )
          : sprintf('declared "%s" (%s); the diff matches', $this->workType->value, $this->workType->label()),
      );

    }
    if ($this->workType !== NULL && $coverageIsDue) {
      // ASKED WHERE THE GATE RUNS. This asked about all three of `code` work's
      // gates at TEST, and phpcs and phpstan do not run at test — so a phpcs
      // whose `paths` lever pointed at nothing blocked the run one phase after
      // the last moment anything could change the answer. Nothing inside test
      // can make a code-phase gate measure, the lever is frozen for the run and
      // the guard refuses it anyway, the mandatory trio carries no waiver, and
      // a Blocked outcome spends no budget — so the run could not end, could
      // not advance, and could not be fixed. Two reviewers reached it
      // independently; one drove 56 identical invocations to be sure.
      //
      // Each gate is now asked about at a phase it actually runs at, so the
      // same fact surfaces while it can still be acted on.
      $due = self::gatesDueAt($phase);
      $subject = $due === NULL
        ? $this->workType->mustMeasure()
        : array_values(array_intersect($this->workType->mustMeasure(), $due));
      $missed = array_values(array_diff($subject, $this->measuredGates));
      // A gate the LEVEL turned off is not a gate the agent failed to satisfy.
      $missed = array_values(array_diff($missed, $this->gatesOff));
      $rests = array_values(array_diff($subject, $this->gatesOff));
      if ($rests === [] && $this->workType->mustMeasure() !== []) {
        // Every gate this type rests on is off at this level. Green would be a
        // verification nobody performed; blocked would be unclearable, since no
        // phase can turn a gate back on.
        $checks[] = new CheckRecord(
          'declaration',
          'type_coverage',
          CheckState::NotApplicable,
          Fault::None,
          // NOT "the level". Three things land a gate in this list and only one
          // of them is the dial: the level turned it off, the SURFACE could not
          // run it (a site gate with no site — which a `content_model` ticket
          // through the CLI hits every time), or the OPERATOR waived it. Naming
          // the level for all three told a reader about a preset decision
          // nobody made, and hid the fact that the same ticket through drush
          // would have verified it.
          sprintf(
            '%s work rests on %s, and none of them could produce a measurement here — turned off '
            . 'by the level, unreachable on this surface, or waived by the operator. Nothing in '
            . 'this phase is verified by a gate, and that is not a pass.',
            $this->workType->value,
            implode(', ', $this->workType->mustMeasure()),
          ),
        );
      }
      elseif ($rests !== []) {
        $checks[] = new CheckRecord(
          'declaration',
          'type_coverage',
          $missed === [] ? CheckState::Satisfied : CheckState::Blocked,
          // ENVIRONMENT, not agent. A gate that RAN and examined nothing is
          // describing its own configuration — a `paths` lever pointing at a
          // directory that does not exist, a standard the tool cannot load, a
          // declaration that does not match the diff. None of that is work the
          // agent can do by trying harder, and the guidance attached to an
          // agent fault says "This is the work, not the setup: fix the cause
          // and re-run. There is no waiver for it", which was false in every
          // observed case and named no way out of any of them.
          //
          // Environment carries a remedy and lets the OPERATOR record an
          // unblock, which is the difference between a block and a wedge.
          $missed === [] ? Fault::None : Fault::Environment,
          $missed === []
            ? sprintf('%s work: %s all measured something', $this->workType->value, implode(', ', $rests))
            : sprintf(
              '%s work rests on %s, and %s ran without examining anything. A gate that passes '
              . 'over an empty path set has not checked the thing this ticket is about — so this '
              . 'is the gate\'s configuration talking, not the code.',
              $this->workType->value,
              implode(', ', $rests),
              implode(', ', $missed),
            ),
          $missed === [] ? NULL : sprintf(
            'Point the gate at the code: set gates.%1$s.paths in droost.workflow.yml (or give '
            . '%1$s its own config file), then re-run this phase. If the declared work type is '
            . 'wrong for this diff, `droost-workflow declare-changes --type=<type>` is the other '
            . 'answer. Levers are frozen per run, so an operator editing them now is editing the '
            . 'next run — ask them to clear this one with `reset --force` after.',
            $missed[0],
          ),
        );
      }
    }
    if ($this->declaredTests !== [] && $coverageIsDue) {
      // `ranTests()` reads phpunit, playwright, coverage and mutation and
      // nothing else. Turn all four off — which `low` does — and that list is
      // empty BY CONSTRUCTION, so every declared test reads as "never run" and
      // the phase can never advance. An agent that named its tests honestly was
      // wedged forever while one that named none walked through: the same
      // inversion this audit exists to prevent, arriving one phase later.
      $noRunner = array_diff(self::TEST_GATES, $this->gatesOff) === [];
      $checks[] = new CheckRecord(
        'declaration',
        'declared_tests',
        $noRunner ? CheckState::NotApplicable : CheckState::Recorded,
        Fault::None,
        $noRunner
          ? sprintf(
            '%d planned test(s), and this level runs no test gate at all, so nothing here could '
            . 'say whether they ran.',
            count($this->declaredTests),
          )
          // FRONT-LOADED, because the report caps a cell at 200 characters and
          // the first cut put the disclaimer last — so the table amputated it
          // at "not a ve", leaving a green-adjacent state, an empty fault and a
          // list of test names, which reads exactly like a check that passed.
          // The clause that matters most has to survive the cut.
          : sprintf(
            'NOT VERIFIED — droost cannot see which tests a suite ran, only that one ran and how '
            . 'many. %d planned, on record: %s.',
            count($this->declaredTests),
            implode(', ', $this->declaredTests),
          ),
      );
    }

    return $checks;
  }

  /**
   * The mandatory trio, and whether each of them measured anything.
   *
   * NOT a declaration check, and writing it as one made it unreachable in the
   * case its own docblock named. The facade returns early when a run declared
   * no files, no tests and no work type — rightly, because with nothing
   * declared every changed file reads as undeclared and the scope check would
   * block the lot. So the check written for "an agent that declares nothing"
   * was the one thing that agent never got.
   *
   * It stands alone now and the facade calls it unconditionally, because "did
   * phpcs actually look at anything" is a question about the GATES, and the
   * answer does not depend on what anybody promised.
   *
   * RECORDED, not blocked. The remedy is a lever — `gates.phpstan.paths` — and
   * levers freeze at `begin`, so a block here could not be cleared from inside
   * the run it stopped; this project has shipped that deadlock twice.
   *
   * WHERE IT ACTUALLY LANDS, because an earlier version of this sentence
   * claimed more: the EVALUATION, rendered with its own text, and a queryable
   * row in the store. NOT the stop hook's checklist — that query is `state IN
   * ('blocked','pending')` and a recorded check is neither, by design, since
   * the stop hook holds a turn on unresolved WORK. The agent is not told at
   * stop time; the human reading the evaluation is.
   *
   * The cost is real and stated rather than hidden: a phase advances over a
   * mandatory gate that measured nothing, and the only thing between that and
   * a reader is a document somebody has to open.
   *
   * @param list<string> $measuredGates
   *   Gates that measured something this run.
   * @param list<string> $gatesOff
   *   Gates this run's level turned off, which are nobody's failure.
   * @param string|null $phase
   *   The phase, or NULL to ask regardless.
   * @param list<string> $blockedGates
   *   Gates holding a blocked row. These RAN; a failure is not an absence.
   * @param \Droost\Workflow\Evidence\WorkType|null $workType
   *   The declared work type, so this stays quiet where `type_coverage`
   *   already blocks on the same facts.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord|null
   *   The record, or NULL when the trio measured or the phase is too early.
   */
  public static function mandatoryMeasured(
    array $measuredGates,
    array $gatesOff,
    ?string $phase = NULL,
    array $blockedGates = [],
    ?WorkType $workType = NULL,
  ): ?CheckRecord {
    // A work type that already rests on the trio has `type_coverage`, which
    // BLOCKS on the same facts. Two rows with near-identical prose and opposite
    // verdicts — `recorded/none` beside `blocked/agent` — invite a reader to
    // discount the one that matters, and `WorkType::Code` rests on exactly this
    // trio, so the commonest work type got both.
    if ($workType !== NULL && array_diff(['phpcs', 'phpstan', 'phpunit'], $workType->mustMeasure()) === []) {
      return NULL;
    }
    // Test and complete only: at code, phpunit has not run, and saying so would
    // be a false alarm on every run.
    if ($phase !== NULL && $phase !== 'test' && $phase !== 'complete') {
      return NULL;
    }
    // BLOCKED gates are subtracted too. A gate with a blocked row ran — it
    // found violations, or its tool errored, or its binary was absent — and
    // none of that is "measured nothing". Without this, a phpcs that found
    // nineteen violations was recorded as having measured nothing and told to
    // set `gates.phpcs.paths`: false about what happened, and useless as
    // advice.
    $hollow = array_values(array_diff(
      array_values(array_diff(['phpcs', 'phpstan', 'phpunit'], $gatesOff)),
      $measuredGates,
      $blockedGates,
    ));
    if ($hollow === []) {
      return NULL;
    }

    return new CheckRecord(
      'declaration',
      'mandatory_measured',
      CheckState::Recorded,
      Fault::None,
      sprintf(
        '%s ran and measured nothing this run. A gate that analysed an empty path set has '
        . 'not checked anything, whatever colour it reported — point it with gates.%s.paths '
        . 'in droost.workflow.yml, or give the tool its own config. The levers for THIS run '
        . 'were frozen when it began, so this is the next run\'s to fix.',
        implode(', ', $hollow),
        $hollow[0],
      ),
    );
  }

  /**
   * The gates a phase actually runs, or NULL when the phase is unnamed.
   *
   * Read from `PhaseGateMap::DEFAULT`, which is the same table the engine
   * dispatches from — so "did this gate measure anything" can only be asked
   * where the gate had a chance to.
   *
   * @param string|null $phase
   *   The phase name.
   *
   * @return list<string>|null
   *   Gate names, or NULL for an unnamed phase (every gate is then in scope,
   *   which is how the audit is exercised directly).
   */
  private static function gatesDueAt(?string $phase): ?array {
    if ($phase === NULL) {
      return NULL;
    }

    return PhaseGateMap::DEFAULT[$phase] ?? [];
  }

  /**
   * Whether a changed file falls under something declared.
   *
   * @param string $file
   *   The changed path.
   *
   * @return bool
   *   TRUE when it was declared, directly or by a declared directory.
   */
  private function covered(string $file): bool {
    $file = self::normalise($file);
    foreach ($this->declaredFiles as $declared) {
      $declared = self::normalise($declared);
      if ($declared === '') {
        continue;
      }
      if ($file === $declared || str_starts_with($file, rtrim($declared, '/') . '/')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether anything under a declared path actually changed.
   *
   * @param string $declared
   *   The declared path.
   *
   * @return bool
   *   TRUE when the diff touched it.
   */
  private function anyChangeUnder(string $declared): bool {
    $declared = self::normalise($declared);
    foreach ($this->changedFiles as $file) {
      $file = self::normalise($file);
      if ($file === $declared || str_starts_with($file, rtrim($declared, '/') . '/')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether a path is one no plan should have to mention.
   *
   * @param string $file
   *   The changed path.
   *
   * @return bool
   *   TRUE when it is exempt.
   */
  private function exempt(string $file): bool {
    $file = self::normalise($file);
    // THE GATE BINARIES ARE NOT EXEMPT, whatever tree they sit in. `vendor/`
    // and `node_modules/` were added so a `composer require` would stop being
    // charged to the agent — and they took `vendor/bin/` and
    // `node_modules/.bin/` with them, which is where every gate droost runs
    // actually lives. Replacing `vendor/bin/phpstan` with `#!/bin/sh\nexit 0`
    // used to be undeclared scope: blocked, agent fault, no waiver. The
    // exemption made it invisible, and a reviewer drove it end to end — all
    // three binaries stubbed, the code phase PASSED, and phpcs and phpstan
    // recorded `satisfied` with `measured = 1`. Not even a labelled pass: an
    // ordinary green over a tool that did nothing.
    //
    // Checked before the prefixes, because the prefixes would otherwise match —
    // and only when the package manager did NOT run. Composer rewrites
    // `vendor/bin/*` on every install, so making them unconditionally
    // undeclared meant an ordinary `composer update` blocked the run with no
    // waiver: a worse wall than the hole it closed, and the third time today I
    // have moved one.
    //
    // The audit cannot see WHO wrote a file. It can see whether the package
    // manager ran, and a manifest or lock file in the same diff is that. A
    // binary rewritten with no manifest change is an agent rewriting the tool
    // droost is about to trust; a binary rewritten beside a changed lock is
    // composer doing its job. The guard is the layer that knows who, and
    // refuses the agent's write outright — this is the backstop for a host
    // with no hooks, where enforcement is advisory and nothing else is
    // watching.
    foreach (self::TOOL_DIRS as $tools => $manifests) {
      $tools = self::normalise($tools);
      if (!str_starts_with($file, rtrim($tools, '/') . '/')) {
        continue;
      }
      foreach ($manifests as $manifest) {
        $manifest = self::normalise($manifest);
        foreach ($this->changedFiles as $changed) {
          if (self::normalise($changed) === $manifest) {
            return TRUE;
          }
        }
      }

      return FALSE;
    }
    foreach (self::NEVER_CREEP as $prefix) {
      // Both sides normalised: comparing a normalised path against a raw
      // prefix is how `.droost-workflow/` stopped matching itself.
      $prefix = self::normalise($prefix);
      if ($file === $prefix || str_starts_with($file, rtrim($prefix, '/') . '/')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * A path in one shape, so two spellings of the same file compare equal.
   *
   * @param string $path
   *   The path.
   *
   * @return string
   *   The normalised path.
   */
  private static function normalise(string $path): string {
    $path = str_replace('\\', '/', trim($path));
    $path = preg_replace('#/+#', '/', $path) ?? $path;
    // Leading "./" SEGMENTS, not a character set. `ltrim($path, './')` strips
    // any run of dots and slashes, so `.droost-workflow/run.json` became
    // `droost-workflow/run.json` — matching neither exemption prefix, which
    // made a legacy project's own run record read as scope creep. The H1 fix
    // put the evidence store in that directory, so this went from latent to
    // live: the audit would have been blocked by its own database again, on
    // exactly the projects H1 was about.
    while (str_starts_with($path, './')) {
      $path = substr($path, 2);
    }

    return ltrim($path, '/');
  }

}
