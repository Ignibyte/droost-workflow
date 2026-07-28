<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Mode;

use Drupal\droost_workflow\Gate\PhaseReport;
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

}
