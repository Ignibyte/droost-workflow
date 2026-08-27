<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunState;

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
   * @param \Droost\Workflow\Gate\GateRunner $runner
   *   Executes a phase's gates.
   * @param \Droost\Workflow\Mode\QuestionSinkInterface $sink
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
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   *
   * @return \Droost\Workflow\Config\Mode
   *   The effective mode.
   */
  public function effectiveMode(RunState $state): Mode {
    return $state->effectiveMode();
  }

  /**
   * Works one phase: runs its gates, then pauses or advances.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase to work.
   * @param string $projectRoot
   *   The repository.
   * @param string $now
   *   The current time, as a caller-supplied ISO-8601 string.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
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

    // The seeker checkpoint. Gates verify rules; the seeker verifies
    // judgment — so it holds the run AFTER the machines are satisfied
    // (inspection is of code that already lints, analyses and tests), at
    // the two boundaries the pattern names: leaving code, and completing.
    // It sits before the pair question on purpose: there is no point asking
    // a human to advance a run the engine itself will not advance.
    if (($phase === Phase::Code || $phase === Phase::Complete)
      && $state->seekers
      && ($state->seeker['status'] ?? NULL) !== 'clean') {
      return new RunOutcome(Outcome::InspectionDue, $state, $report);
    }

    if ($this->effectiveMode($state)->holdsForConversation()) {
      $question = $this->conversationAt($phase, $report, $now);
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
   * @param \Droost\Workflow\State\RunState $state
   *   The run, with the report already recorded.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that blocked.
   * @param \Droost\Workflow\Gate\PhaseReport $report
   *   The blocking report.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
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
   * @param \Droost\Workflow\State\RunState $state
   *   The paused run.
   * @param string $answer
   *   What the human said.
   * @param string $now
   *   The current time, as a caller-supplied ISO-8601 string.
   *
   * @return \Droost\Workflow\State\RunState
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
   * Only interactive to agentic is supported. The design names one
   * direction — "flip to agentic at any gate to finish without stopping" —
   * and the reverse would be an interruption path nobody has asked for and
   * nothing has tested.
   *
   * A swap to agentic also RELEASES any current pause. The point of the
   * swap is to stop being asked; one that still required an answer to the
   * outstanding question first would not do the thing it exists for.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Mode $to
   *   The mode to switch to.
   * @param string $now
   *   The current time, as a caller-supplied ISO-8601 string.
   *
   * @return \Droost\Workflow\State\RunState
   *   The swapped run.
   *
   * @throws \InvalidArgumentException
   *   When asked to swap to interactive.
   */
  public function swap(
    RunState $state,
    Mode $to,
    string $now,
  ): RunState {
    if ($to !== Mode::Agentic) {
      throw new \InvalidArgumentException(sprintf(
        'Only a swap to agentic is supported mid-run, not to "%s".',
        $to->value,
      ));
    }
    return $state->withModeOverride($to)->released($now);
  }

  /**
   * The question this run is waiting on, if any.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   *
   * @return \Droost\Workflow\Mode\PendingQuestion|null
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
   * @param callable(\Droost\Workflow\Gate\GateResult): void $watcher
   *   Called once per gate.
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param string $projectRoot
   *   The repository.
   *
   * @return \Droost\Workflow\Gate\PhaseReport
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

  /**
   * Builds the conversation a phase holds for in interactive mode.
   *
   * Interactive mode exists because a yes/no at a phase boundary is the
   * wrong question. "The code phase passed its gates, continue?" tells a
   * human nothing they could act on, so the only available answer is yes,
   * and a hold whose answer is always yes is a form rather than a decision.
   *
   * What each phase hands over is different, so what is worth asking at each
   * boundary is different too, and the phrasing here is deliberately the
   * question a careful colleague would ask at that moment. The plan question
   * in particular is the one live agents were already asking unprompted —
   * "this is the cheapest moment to change the spec" — which is a strong
   * argument that it is the right question rather than a novel one.
   *
   * The engine can only speak to what it knows: which phase finished, what
   * its gates said, and what comes next. Anything the AGENT knows — what
   * grounding turned up, which trade-off it took, what it recommends — is
   * added when it presents this question to the human, and comes back in the
   * recorded answer. That split is why this stays a value object built from
   * run state rather than a hook the agent has to feed.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that just passed.
   * @param \Droost\Workflow\Gate\PhaseReport $report
   *   Its gate report.
   * @param string $now
   *   The current time, as a caller-supplied ISO-8601 string.
   *
   * @return \Droost\Workflow\Mode\PendingQuestion
   *   The question, with the options worth offering.
   */
  private function conversationAt(
    Phase $phase,
    PhaseReport $report,
    string $now,
  ): PendingQuestion {
    [$headline, $question, $detail, $options] = match ($phase) {
      Phase::Plan => [
        'The spec is written and the plan phase passed.',
        'Before any code is written: is the spec what you want built — its '
        . 'approach, its scope, and its acceptance criteria?',
        [
          'Changing the spec now costs nothing. Changing it after the code '
          . 'phase means changing the code too.',
          'Next: the code phase builds only what the spec describes, and '
          . 'scope found mid-build has to come back here first.',
        ],
        [
          'Looks right — start building',
          'Change the spec first',
          'Abandon the run',
        ],
      ],
      Phase::Code => [
        'The code phase passed its gates and the seeker is satisfied.',
        'The work builds and the inspection is clean. Do you want to see the '
        . 'diff before it goes to the test phase?',
        [
          'Gates verify rules and the seeker verifies judgment; neither '
          . 'verifies that this is the change you wanted.',
          'Next: the test phase runs the configured suites and the '
          . 'verification tier this run declared.',
        ],
        [
          'Go on to testing',
          'Show me the diff first',
          'Keep working in code',
        ],
      ],
      Phase::Test => [
        'The test phase passed.',
        'The suites this run configured have run. Anything you want covered '
        . 'that they did not cover?',
        [
          'A gate that could not run is reported as such rather than as a '
          . 'pass — worth reading before you accept the phase.',
          'Next: the complete phase captures why the work was done and '
          . 're-runs the full gate set.',
        ],
        [
          'Accept and complete the run',
          'Add a test first',
          'Keep working in test',
        ],
      ],
      Phase::Complete => [
        'Every phase has passed and the work is captured.',
        'This is the last hold: finishing ends the run and leaves the record '
        . 'in place for review. Ready?',
        [
          'The run record persists after finishing; resetting it is a '
          . 'separate, deliberate act.',
          'Until a new run opens, the write gates close again.',
        ],
        [
          'Finish the run',
          'Not yet — something still needs work',
        ],
      ],
    };

    return new PendingQuestion(
      $phase,
      $question,
      $report->summaryLine(),
      $now,
      $headline,
      $detail,
      $options,
    );
  }

}
