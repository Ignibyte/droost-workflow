<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Mode;

use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Gate\GateResult;
use Drupal\droost_workflow\Gate\GateRunner;
use Drupal\droost_workflow\Gate\PhaseReport;
use Drupal\droost_workflow\State\PhaseStatus;
use Drupal\droost_workflow\State\RunState;

/**
 * How much the human is in the loop, applied at every phase gate.
 *
 * Wraps the gate runner rather than living inside it: the runner knows how to
 * execute gates and nothing about who is watching, which is why pair mode
 * could be added here without touching it.
 *
 * The pause is written to run state BEFORE any sink is notified. That
 * ordering is the whole design. A crash between deciding to pause and
 * delivering the question leaves a run that is visibly waiting rather than
 * one that silently continued, and it means a surface with no transport at
 * all still produces a correct paused run — which matters, because the
 * surface this was designed alongside cannot currently relay a worker's
 * question to a human.
 */
final class ModeEngine {

  /**
   * Constructs a ModeEngine.
   *
   * @param \Drupal\droost_workflow\Gate\GateRunner $runner
   *   Executes a phase's gates.
   * @param \Drupal\droost_workflow\Mode\QuestionSinkInterface $sink
   *   Delivers a pending question. A notification, not the record.
   */
  public function __construct(
    private readonly GateRunner $runner,
    private readonly QuestionSinkInterface $sink,
  ) {}

  /**
   * The mode actually in force right now.
   *
   * Resolved per call rather than captured when the run began: that is what
   * makes a swap take effect at the next gate, and equally what lets an edit
   * to the lever file apply when no override is set. The override wins, so
   * flipping to automated never requires editing a version-controlled file
   * while a run is in flight.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   *
   * @return \Drupal\droost_workflow\Config\Mode
   *   The effective mode.
   */
  public function effectiveMode(RunState $state): Mode {
    return $state->effectiveMode();
  }

  /**
   * Works one phase: runs its gates, then pauses or advances.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase to work.
   * @param string $projectRoot
   *   The repository.
   * @param string $now
   *   The current time, as a caller-supplied ISO-8601 string.
   *
   * @return \Drupal\droost_workflow\Mode\RunOutcome
   *   What happened, and the run afterwards. Nothing is persisted here.
   */
  public function runPhase(
    RunState $state,
    Phase $phase,
    string $projectRoot,
    string $now,
  ): RunOutcome {
    // Re-entering an already-paused run must not re-run its gates. It
    // re-presents the same question, because the run has not moved and the
    // work has already been done once.
    $pending = $this->pendingQuestion($state);
    if ($pending !== NULL) {
      $this->sink->emit($pending);
      return new RunOutcome(Outcome::Paused, $state, NULL, $pending);
    }

    $report = $this->runner->run($state, $phase, $projectRoot);
    $state = $state->withGateReport($phase->value, $report->toArray());

    if (!$report->advance()) {
      return $this->recordFailure($state, $phase, $report);
    }

    if ($this->effectiveMode($state) === Mode::Pair) {
      $question = new PendingQuestion(
        $phase,
        sprintf(
          'The %s phase passed its gates. Continue to the next phase?',
          $phase->value,
        ),
        $report->summaryLine(),
        $now,
      );
      // State first, sink second. Always.
      $state = $state->awaiting($question->toArray());
      $this->sink->emit($question);
      return new RunOutcome(Outcome::Paused, $state, $report, $question);
    }

    return new RunOutcome(
      $phase === Phase::Complete ? Outcome::Completed : Outcome::Advanced,
      $state,
      $report,
    );
  }

  /**
   * Counts a blocking report against the retry budget, or ends the phase.
   *
   * This is the production caller GateRunner::mayRetry() and
   * recordAttempt() were built for and then shipped without — until now a
   * failed phase stayed Active and `run` would re-execute it forever, with
   * max_gate_retries recorded in every state file and consulted by nothing.
   *
   * A "retry" is one more `run` invocation of the still-Active failed
   * phase: the agent fixes the cause between invocations, so the bound is
   * counted across invocations in run state rather than looped here. Both
   * blocking statuses spend budget — a missing tool re-invoked forever is
   * the worst infinite loop, and installing the tool between invocations is
   * a legitimate retry.
   *
   * When ANY blocking gate is out of budget the phase is marked Failed —
   * terminal. advanceTo() already refuses to move away from a Failed phase,
   * and the facade refuses to re-run one, so the mark is what turns "try
   * again" into "stop".
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run, with the report already recorded.
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase that blocked.
   * @param \Drupal\droost_workflow\Gate\PhaseReport $report
   *   The blocking report.
   *
   * @return \Drupal\droost_workflow\Mode\RunOutcome
   *   A Failed outcome — retryable when budget remains, terminal when not.
   */
  private function recordFailure(
    RunState $state,
    Phase $phase,
    PhaseReport $report,
  ): RunOutcome {
    $blocking = array_filter(
      $report->results,
      static fn (GateResult $r): bool => $r->status->blocksAdvance(),
    );

    foreach ($blocking as $result) {
      if (!$this->runner->mayRetry($state, $result->gate)) {
        $state = $state->withPhaseStatus($phase, PhaseStatus::Failed);
        return new RunOutcome(Outcome::Failed, $state, $report);
      }
    }

    foreach ($blocking as $result) {
      $state = $this->runner->recordAttempt($state, $result->gate);
    }
    return new RunOutcome(Outcome::Failed, $state, $report);
  }

  /**
   * Answers the question a paused run is waiting on.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The paused run.
   * @param string $answer
   *   What the human said.
   * @param string $now
   *   The current time, as a caller-supplied ISO-8601 string.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The run, no longer awaiting, with the exchange recorded.
   *
   * @throws \InvalidArgumentException
   *   When the run is not waiting for anything. Answering a question nobody
   *   asked would silently append a decision to the record.
   */
  public function answer(
    RunState $state,
    string $answer,
    string $now,
  ): RunState {
    if ($state->awaiting === NULL) {
      throw new \InvalidArgumentException(
        'This run is not waiting for an answer.',
      );
    }
    return $state->answered($answer, $now);
  }

  /**
   * Swaps the mode mid-run.
   *
   * Only pair to automated is supported. The design names one direction —
   * "flip to automated at any gate to finish unattended" — and the reverse
   * would be an interruption path nobody has asked for and nothing has
   * tested.
   *
   * A swap to automated also RELEASES any current pause. The point of the
   * swap is to stop being asked; one that still required an answer to the
   * outstanding question first would not do the thing it exists for.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   * @param \Drupal\droost_workflow\Config\Mode $to
   *   The mode to switch to.
   * @param string $now
   *   The current time, as a caller-supplied ISO-8601 string.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The swapped run.
   *
   * @throws \InvalidArgumentException
   *   When asked to swap to pair.
   */
  public function swap(
    RunState $state,
    Mode $to,
    string $now,
  ): RunState {
    if ($to !== Mode::Automated) {
      throw new \InvalidArgumentException(sprintf(
        'Only a swap to automated is supported mid-run, not to "%s".',
        $to->value,
      ));
    }
    return $state->withModeOverride($to)->released($now);
  }

  /**
   * The question this run is waiting on, if any.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   *
   * @return \Drupal\droost_workflow\Mode\PendingQuestion|null
   *   The question, or NULL when the run is not paused.
   */
  public function pendingQuestion(RunState $state): ?PendingQuestion {
    if ($state->awaiting === NULL) {
      return NULL;
    }
    return PendingQuestion::fromArray($state->awaiting);
  }

  /**
   * The per-gate callback a caller may pass through to the runner.
   *
   * Exposed so a surface can watch gates finish without reaching past this
   * class into the runner.
   *
   * @param callable(\Drupal\droost_workflow\Gate\GateResult): void $watcher
   *   Called once per gate.
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase.
   * @param string $projectRoot
   *   The repository.
   *
   * @return \Drupal\droost_workflow\Gate\PhaseReport
   *   The report.
   */
  public function observe(
    callable $watcher,
    RunState $state,
    Phase $phase,
    string $projectRoot,
  ): PhaseReport {
    return $this->runner->run(
      $state,
      $phase,
      $projectRoot,
      static function (GateResult $result) use ($watcher): void {
        $watcher($result);
      },
    );
  }

}
