<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

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

  private const array NEVER_CREEP = [
    'droost/droost-workflow/',
    '.droost-workflow/',
    // Everything `droost-workflow init` writes, plus the lever file the briefs
    // tell the agent to edit.
    '.claude/',
    'droost.workflow.yml',
    // Init appends its ignore rule here.
    '.gitignore',
    'droost/baseline/',
    'droost/evidence/',
    'composer.lock',
    'package-lock.json',
    'yarn.lock',
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
      fn (string $file): bool => !$this->covered($file) && !self::exempt($file),
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

    $scopeIsDue = $phase === NULL || $phase === 'code';
    $coverageIsDue = $phase === NULL || $phase === 'test' || $phase === 'complete';
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
        static fn (string $file): bool => !self::exempt($file),
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
      $missed = array_values(array_diff($this->workType->mustMeasure(), $this->measuredGates));
      // A gate the LEVEL turned off is not a gate the agent failed to satisfy.
      $missed = array_values(array_diff($missed, $this->gatesOff));
      $rests = array_values(array_diff($this->workType->mustMeasure(), $this->gatesOff));
      if ($rests === [] && $this->workType->mustMeasure() !== []) {
        // Every gate this type rests on is off at this level. Green would be a
        // verification nobody performed; blocked would be unclearable, since no
        // phase can turn a gate back on.
        $checks[] = new CheckRecord(
          'declaration',
          'type_coverage',
          CheckState::NotApplicable,
          Fault::None,
          sprintf(
            '%s work rests on %s, and this level runs none of them. Nothing here is verified by a '
            . 'gate — that is the trade the level makes, and it is not a pass.',
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
          $missed === [] ? Fault::None : Fault::Agent,
          $missed === []
            ? sprintf('%s work: %s all measured something', $this->workType->value, implode(', ', $rests))
            : sprintf(
              '%s work rests on %s, and %s measured nothing this run. A gate that passes over an '
              . 'empty path set has not checked the thing this ticket is about.',
              $this->workType->value,
              implode(', ', $rests),
              implode(', ', $missed),
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
  private static function exempt(string $file): bool {
    $file = self::normalise($file);
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
