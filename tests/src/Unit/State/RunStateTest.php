<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\State;

use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\Provenance;
use Drupal\droost_workflow\Config\WorkflowConfig;
use Drupal\droost_workflow\State\PhaseStatus;
use Drupal\droost_workflow\State\RunState;
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
   * A dropped phase has no status at all, rather than a skipped one.
   */
  public function testDroppedPhaseIsAbsentNotSkipped(): void {
    $config = WorkflowConfig::fromArray(
      ['phases' => ['plan', 'code', 'complete']],
      'test',
    );
    $state = RunState::begin('r', 't', $config);

    $this->assertNull($state->statusOf(Phase::Document));
    $this->assertNull($state->statusOf(Phase::Test));
    $this->assertSame(PhaseStatus::Pending, $state->statusOf(Phase::Code));
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
   * @param \Drupal\droost_workflow\State\PhaseStatus $status
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
   * @return array<string, array{\Drupal\droost_workflow\State\PhaseStatus}>
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
   * @param \Drupal\droost_workflow\Config\Phase $from
   *   The phase to advance from.
   * @param \Drupal\droost_workflow\Config\Phase $to
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
   * @return array<string, array{\Drupal\droost_workflow\Config\Phase, \Drupal\droost_workflow\Config\Phase}>
   *   Case name to the from and to phases.
   */
  public static function illegalMoves(): array {
    return [
      'backward one step' => [Phase::Code, Phase::Plan],
      'backward several' => [Phase::Document, Phase::Code],
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
   * A phase this run does not execute is not a destination.
   */
  public function testCannotAdvanceToUnconfiguredPhase(): void {
    $config = WorkflowConfig::fromArray(
      ['phases' => ['plan', 'complete']],
      'test',
    );
    $state = RunState::begin('r', 't', $config);

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
   * A run begun from the built-in defaults.
   *
   * @return \Drupal\droost_workflow\State\RunState
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
