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
   * The run's own record and the lock files a legitimate build rewrites. An
   * agent cannot plan around composer deciding to reformat a lock file, and a
   * blocked phase over one would be noise that teaches people to declare
   * everything.
   */
  private const array NEVER_CREEP = [
    'droost/droost-workflow/',
    '.droost-workflow/',
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
   * @param list<string> $ranTests
   *   The tests that actually ran, when the caller can tell.
   * @param \Droost\Workflow\Evidence\WorkType|null $workType
   *   What the plan said this run is, or NULL when nothing was declared.
   * @param list<string> $measuredGates
   *   Gates that actually measured something this run — `satisfied` or
   *   `recorded`, never `off` or `skipped`. A type names the gates its kind of
   *   work rests on, and "passed" is not the same claim as "looked".
   */
  public function __construct(
    private readonly array $declaredFiles,
    private readonly array $declaredTests,
    private readonly array $changedFiles,
    private readonly array $ranTests = [],
    private readonly ?WorkType $workType = NULL,
    private readonly array $measuredGates = [],
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
    if ($this->declaredTests === []) {
      return [];
    }
    $ran = implode("\n", $this->ranTests);

    return array_values(array_filter(
      $this->declaredTests,
      static fn (string $test): bool => $test !== '' && stripos($ran, $test) === FALSE,
    ));
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
    $missing = $this->missingTests();

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
      if ($this->workType->mustMeasure() !== []) {
        $checks[] = new CheckRecord(
          'declaration',
          'type_coverage',
          $missed === [] ? CheckState::Satisfied : CheckState::Blocked,
          $missed === [] ? Fault::None : Fault::Agent,
          $missed === []
            ? sprintf('%s work: %s all measured something', $this->workType->value, implode(', ', $this->workType->mustMeasure()))
            : sprintf(
              '%s work rests on %s, and %s measured nothing this run. A gate that passes over an '
              . 'empty path set has not checked the thing this ticket is about.',
              $this->workType->value,
              implode(', ', $this->workType->mustMeasure()),
              implode(', ', $missed),
            ),
        );
      }
    }
    if ($this->declaredTests !== [] && $coverageIsDue) {
      $checks[] = new CheckRecord(
        'declaration',
        'declared_tests',
        $missing === [] ? CheckState::Satisfied : CheckState::Blocked,
        $missing === [] ? Fault::None : Fault::Agent,
        $missing === []
          ? sprintf('%d planned test(s), all run', count($this->declaredTests))
          : sprintf('planned and never run: %s', implode(', ', $missing)),
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
      if ($file === $prefix || str_starts_with($file, $prefix)) {
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

    return ltrim($path, './');
  }

}
