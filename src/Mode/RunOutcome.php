<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Mode;

use Drupal\droost_workflow\Gate\PhaseReport;
use Drupal\droost_workflow\State\PhaseStatus;
use Drupal\droost_workflow\State\RunState;

/**
 * What happened when a phase was worked.
 *
 * Carries the resulting state rather than mutating anything, so a caller that
 * forgets to persist has visibly not persisted, instead of believing it had.
 */
final class RunOutcome {

  /**
   * Constructs a RunOutcome.
   *
   * @param \Drupal\droost_workflow\Mode\Outcome $outcome
   *   Which of the four things happened.
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run afterwards. Not yet saved.
   * @param \Drupal\droost_workflow\Gate\PhaseReport|null $report
   *   The phase's gate report, when gates ran.
   * @param \Drupal\droost_workflow\Mode\PendingQuestion|null $question
   *   The question the run is waiting on, when it paused.
   */
  public function __construct(
    public readonly Outcome $outcome,
    public readonly RunState $state,
    public readonly ?PhaseReport $report = NULL,
    public readonly ?PendingQuestion $question = NULL,
  ) {}

  /**
   * Whether the run is waiting for an answer.
   *
   * @return bool
   *   TRUE when paused.
   */
  public function isPaused(): bool {
    return $this->outcome === Outcome::Paused;
  }

  /**
   * Whether the current phase has spent its retry budget and stopped.
   *
   * The one bit that separates "failed, fix it and invoke run again" from
   * "failed, and run will now refuse". Exit codes cannot carry it — both
   * cases exit non-zero so scripts stay simple — so it lives in the
   * envelope.
   *
   * @return bool
   *   TRUE when the current phase is terminally failed.
   */
  public function exhausted(): bool {
    $phase = $this->state->currentPhase;
    return $phase !== NULL
      && $this->state->statusOf($phase) === PhaseStatus::Failed;
  }

  /**
   * The one run envelope every surface renders.
   *
   * Until this existed the same five fields were assembled three times — in
   * the bin, the drush command and the MCP tool — which is exactly the
   * second-implementation drift the facade exists to prevent. The surfaces
   * differ in how they PRINT this, never in what it says.
   *
   * @return array<string, mixed>
   *   The envelope: outcome, current_phase, report, awaiting, and the
   *   retries block (attempts per gate, the bound, and whether the budget
   *   is exhausted).
   */
  public function toArray(): array {
    return [
      'outcome' => $this->outcome->value,
      'current_phase' => $this->state->currentPhase?->value,
      'report' => $this->report?->toArray(),
      'awaiting' => $this->question?->toArray(),
      'retries' => [
        'attempts' => $this->state->feedbackAttempts,
        'max_gate_retries' => $this->state->maxGateRetries,
        'exhausted' => $this->exhausted(),
      ],
    ];
  }

}
