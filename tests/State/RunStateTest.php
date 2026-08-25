<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\State;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\Provenance;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a run remembers, and which transitions it refuses.
 */
class RunStateTest extends TestCase {

  /**
   * A new run starts at its first phase with the rest pending.
   */
  public function testNewRunStartsAtItsFirstPhase(): void {
    $state = $this->begin();

    $this->assertSame(Phase::Plan, $state->currentPhase);
    $this->assertSame(PhaseStatus::Active, $state->statusOf(Phase::Plan));
    $this->assertSame(PhaseStatus::Pending, $state->statusOf(Phase::Code));
    $this->assertSame(Provenance::BuiltIn, $state->provenance);
    $this->assertSame(2, $state->maxGateRetries);
  }

  /**
   * The phases key is deprecated: every run walks the canonical sequence.
   *
   * 0.3 made the four working phases mandatory — what varies per repo is
   * gate weight, never the path. A file still naming a subset is validated
   * (typos must surface), then superseded, with the supersession recorded.
   */
  public function testPhasesKeyIsDeprecatedNeverDropping(): void {
    $config = WorkflowConfig::fromArray(
      ['phases' => ['plan', 'code', 'complete']],
      'test',
    );
    $this->assertNotEmpty($config->deprecations);
    $this->assertStringContainsString('deprecated', $config->deprecations[0]);

    $state = RunState::begin('r', 't', $config);
    $this->assertSame(PhaseStatus::Pending, $state->statusOf(Phase::Complete));
    $this->assertSame(PhaseStatus::Pending, $state->statusOf(Phase::Test));
    $this->assertSame(PhaseStatus::Pending, $state->statusOf(Phase::Code));

    // A malformed subset still errors — deprecation is not amnesty.
    $this->expectException(ConfigError::class);
    WorkflowConfig::fromArray(['phases' => ['plan', 'deploy']], 'test');
  }

  /**
   * Advancing records the phase left behind as passed.
   */
  public function testAdvancingRecordsThePhaseLeftAsPassed(): void {
    $state = $this->begin()->advanceTo(Phase::Code);

    $this->assertSame(PhaseStatus::Passed, $state->statusOf(Phase::Plan));
    $this->assertSame(PhaseStatus::Active, $state->statusOf(Phase::Code));
    $this->assertSame(Phase::Code, $state->currentPhase);
  }

  /**
   * A failed phase is never laundered into a pass by moving on.
   *
   * The regression guard for the worst defect this package could have: the
   * run's own report would otherwise claim a gate succeeded that failed.
   *
   * @param \Droost\Workflow\State\PhaseStatus $status
   *   The status the current phase is left in.
   */
  #[DataProvider('nonAdvanceableStatuses')]
  public function testCannotAdvanceAwayFromNonActivePhase(
    PhaseStatus $status,
  ): void {
    $state = $this->begin()->withPhaseStatus(Phase::Plan, $status);

    $this->assertFalse($state->canAdvanceTo(Phase::Code));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Cannot advance away from "plan"');
    $state->advanceTo(Phase::Code);
  }

  /**
   * Statuses a run may not simply walk away from.
   *
   * @return array<string, array{\Droost\Workflow\State\PhaseStatus}>
   *   Case name to status.
   */
  public static function nonAdvanceableStatuses(): array {
    return [
      'failed' => [PhaseStatus::Failed],
      'skipped' => [PhaseStatus::Skipped],
      'pending' => [PhaseStatus::Pending],
      'already passed' => [PhaseStatus::Passed],
    ];
  }

  /**
   * Clearing a failure is possible, but has to be said out loud.
   */
  public function testFailureCanBeClearedDeliberately(): void {
    $state = $this->begin()
      ->withPhaseStatus(Phase::Plan, PhaseStatus::Failed)
      ->withPhaseStatus(Phase::Plan, PhaseStatus::Active);

    $this->assertTrue($state->canAdvanceTo(Phase::Code));
    $this->assertSame(
      PhaseStatus::Passed,
      $state->advanceTo(Phase::Code)->statusOf(Phase::Plan),
    );
  }

  /**
   * Phases run once and in order.
   *
   * @param \Droost\Workflow\Config\Phase $from
   *   The phase to advance from.
   * @param \Droost\Workflow\Config\Phase $to
   *   The illegal target.
   */
  #[DataProvider('illegalMoves')]
  public function testBackwardAndSidewaysMovesAreRefused(
    Phase $from,
    Phase $to,
  ): void {
    $state = $this->begin();
    while ($state->currentPhase !== $from) {
      $next = $state->currentPhase;
      $this->assertNotNull($next);
      $state = $state->advanceTo(Phase::canonical()[(int) array_search($next, Phase::canonical(), TRUE) + 1]);
    }

    $this->assertFalse($state->canAdvanceTo($to));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('phases run once, in order');
    $state->advanceTo($to);
  }

  /**
   * Moves that go backward or nowhere.
   *
   * @return array<string, array{\Droost\Workflow\Config\Phase, \Droost\Workflow\Config\Phase}>
   *   Case name to the from and to phases.
   */
  public static function illegalMoves(): array {
    return [
      'backward one step' => [Phase::Code, Phase::Plan],
      'backward several' => [Phase::Complete, Phase::Code],
      'to itself' => [Phase::Code, Phase::Code],
    ];
  }

  /**
   * A run that has ended cannot be restarted by advancing.
   */
  public function testAnEndedRunCannotAdvance(): void {
    $config = WorkflowConfig::builtIn();
    $ended = new RunState(
      'r',
      't',
      Mode::Automated,
      NULL,
      'factory',
      2,
      Provenance::BuiltIn,
      $config->resolvedGates(),
      ['plan' => PhaseStatus::Passed, 'complete' => PhaseStatus::Passed],
      NULL,
    );

    $this->assertFalse($ended->canAdvanceTo(Phase::Complete));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('This run has ended');
    $ended->advanceTo(Phase::Complete);
  }

  /**
   * A phase a run's own document does not carry is not a destination.
   *
   * New configs cannot drop phases any more (0.3), but a run document
   * written under an older engine still rules its own run: the guard is on
   * the frozen state, not the current vocabulary.
   */
  public function testCannotAdvanceToUnconfiguredPhase(): void {
    $state = new RunState(
      'r',
      't',
      Mode::Automated,
      NULL,
      'custom',
      2,
      Provenance::File,
      [],
      ['plan' => PhaseStatus::Active, 'complete' => PhaseStatus::Pending],
      Phase::Plan,
    );

    $this->assertFalse($state->canAdvanceTo(Phase::Test));
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('does not execute the "test" phase');
    $state->advanceTo(Phase::Test);
  }

  /**
   * A mid-run swap outranks the configured mode.
   */
  public function testAnOverrideOutranksTheConfiguredMode(): void {
    $config = WorkflowConfig::fromArray(['mode' => 'pair'], 'test');
    $state = RunState::begin('r', 't', $config);

    $this->assertSame(Mode::Pair, $state->effectiveMode());
    $this->assertSame(
      Mode::Automated,
      $state->withModeOverride(Mode::Automated)->effectiveMode(),
    );
    // The configured mode is remembered, not overwritten.
    $this->assertSame(
      Mode::Pair,
      $state->withModeOverride(Mode::Automated)->mode,
    );
  }

  /**
   * The document carries the schema version this build writes.
   */
  public function testTheDocumentIsVersioned(): void {
    // Asserting the document against the constant, not the constant against
    // itself: what can actually drift is the writer forgetting to stamp it.
    $this->assertSame(
      RunState::SCHEMA_VERSION,
      $this->begin()->toArray()['v'],
    );
  }

  /**
   * A run held to some levers records the retry bound they came with.
   *
   * A count of attempts means nothing without the limit it is measured
   * against, so both live in the artefact.
   */
  public function testTheRetryBoundIsRecordedBesideTheCounters(): void {
    $config = WorkflowConfig::fromArray(['max_gate_retries' => 5], 'test');
    $document = RunState::begin('r', 't', $config)->toArray();

    $this->assertSame(5, $document['max_gate_retries']);
    $this->assertArrayHasKey('feedback_attempts', $document);
  }

  /**
   * Beginning a run freezes the phase map for its configured phases.
   */
  public function testBeginFreezesThePhaseMapForConfiguredPhases(): void {
    $this->assertSame(
      PhaseGateMap::DEFAULT,
      $this->begin()->phaseGates,
    );

    $withDeprecatedKey = RunState::begin('r', 't', WorkflowConfig::fromArray(
      ['phases' => ['plan', 'code', 'complete']],
      'test',
    ));
    $this->assertSame(
      ['plan', 'code', 'test', 'complete'],
      array_keys($withDeprecatedKey->phaseGates),
      'the deprecated phases key never thins the frozen map (0.3: phases are mandatory)',
    );
  }

  /**
   * The due set is the intersection of the levers and the phase map.
   */
  public function testGatesDueForIntersectsLeversAndMap(): void {
    $state = $this->begin();

    $this->assertSame([], $state->gatesDueFor(Phase::Plan));
    $this->assertSame(
      ['phpcs', 'phpstan'],
      array_keys($state->gatesDueFor(Phase::Code)),
    );
    // Order comes from the resolved levers, so reports stay stably ordered.
    $this->assertSame(
      ['phpunit', 'mutation', 'playwright', 'coverage', 'rendered_check'],
      array_keys($state->gatesDueFor(Phase::Test)),
    );
    $this->assertSame(
      array_keys($state->resolvedGates),
      array_keys($state->gatesDueFor(Phase::Complete)),
    );
  }

  /**
   * Every wither threads the frozen phase map through.
   *
   * The constructors in with(), rebuild(), withGateReport() and
   * withFeedbackAttempt() are positional; any of them forgetting the field
   * would silently drop it on the first state transition, and the next
   * invocation would gate the wrong set. This walks every transition the
   * class offers.
   */
  public function testEveryWitherPreservesThePhaseGates(): void {
    $state = $this->begin();
    $frozen = $state->phaseGates;
    $this->assertNotSame([], $frozen);

    $transitions = [
      'withPhaseStatus' => static fn (RunState $s): RunState =>
      $s->withPhaseStatus(Phase::Plan, PhaseStatus::Failed),
      'advanceTo' => static fn (RunState $s): RunState =>
      $s->advanceTo(Phase::Code),
      'withGateReport' => static fn (RunState $s): RunState =>
      $s->withGateReport('plan', ['advance' => TRUE]),
      'withFeedbackAttempt' => static fn (RunState $s): RunState =>
      $s->withFeedbackAttempt('phpcs'),
      'awaiting' => static fn (RunState $s): RunState =>
      $s->awaiting(['question' => 'go on?']),
      'answered' => static fn (RunState $s): RunState =>
      $s->awaiting(['question' => 'go on?'])->answered('yes', 't2'),
      'released' => static fn (RunState $s): RunState =>
      $s->awaiting(['question' => 'go on?'])->released('t2'),
      'withModeOverride' => static fn (RunState $s): RunState =>
      $s->withModeOverride(Mode::Automated),
    ];

    foreach ($transitions as $name => $transition) {
      $this->assertSame(
        $frozen,
        $transition($state)->phaseGates,
        $name . '() dropped the frozen phase map',
      );
    }
  }

  /**
   * A run begun from the built-in defaults.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run.
   */
  private function begin(): RunState {
    return RunState::begin(
      'run-1',
      '2026-07-27T09:00:00+00:00',
      WorkflowConfig::builtIn(),
    );
  }

}
