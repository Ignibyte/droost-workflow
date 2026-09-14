<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Evidence\CheckAdjudicatorInterface;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceRecorder;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
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
   * How many times a phase may be blocked by a non-gate check before asking.
   *
   * Deliberately far above a retry budget. `max_gate_retries` is 2 or 3 because
   * a failing gate is a tool saying the same thing about the same code, and a
   * third identical answer teaches nobody anything. A declaration or check
   * block is different in kind: the agent is meant to go away, change something
   * real, and come back, and a legitimate correction cycle can be long. A
   * ceiling that bites at ten would interrupt honest work.
   *
   * What it exists for is the case where the work is NOT progressing — an
   * unclearable block, or an agent looping on a condition it has misread. Four
   * unclearable blocks shipped in a single day; every one of them would have
   * been retried forever rather than ending.
   *
   * And it ASKS rather than failing. The engine cannot tell a stuck run from a
   * slow one, and the person watching can. Ending the run here would throw away
   * a plan and a code phase over a judgement the engine is not equipped to
   * make.
   */
  private const int BLOCK_CEILING = 60;

  /**
   * Constructs a ModeEngine.
   *
   * @param \Droost\Workflow\Gate\GateRunner $runner
   *   Executes a phase's gates.
   * @param \Droost\Workflow\Mode\QuestionSinkInterface $sink
   *   Delivers a pending question. A notification, not the record.
   * @param \Droost\Workflow\Evidence\CheckAdjudicatorInterface|null $checks
   *   The questions a shell command cannot ask, from the modules that can
   *   answer them. NULL when the site contributes none, which is most.
   */
  public function __construct(
    private readonly GateRunner $runner,
    private readonly QuestionSinkInterface $sink,
    private readonly ?CheckAdjudicatorInterface $checks = NULL,
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
    // The same verdicts, as rows droost can query rather than a blob it can
    // only round-trip. run.json keeps the phase's summary because five
    // surfaces read it; the evidence store keeps every attempt, every finding
    // as its own row, and a fingerprint of what each gate examined — which is
    // what lets a green expire when the code under it moves.
    //
    // A failure here DOES fail the phase, which reverses what this comment used
    // to say. `lastError()` was written, documented as "so the surface that
    // cares can say the record is broken", and read by nothing — the recorder
    // was constructed and discarded on a single line. So a read-only store file
    // (a botched chmod, a restored backup, a container UID mismatch) made every
    // verdict vanish while the phase reported `passed: 2` and advanced. The
    // Stop hook then found no unresolved rows, the audit had nothing to audit,
    // and the evaluation rendered its "this run has no rows" banner telling
    // the reader to check `lastError()` — a value no surface exposed.
    //
    // A phase whose evidence could not be written has not been verified in any
    // sense this system can defend, so it stops. ErrorToolMissing because that
    // maps to blocked/environment: an unwritable file is not the agent's doing,
    // and an operator can fix it.
    $recorder = new EvidenceRecorder($projectRoot);
    $recorder->recordPhase($state, $phase->value, $report, $now);
    $recordError = $recorder->lastError();
    if ($recordError !== NULL) {
      $report = $report->with(GateResult::ran(
        'evidence_record',
        GateStatus::ErrorToolMissing,
        1,
        0,
        sprintf(
          'The run\'s evidence could not be written, so this phase has no record: %s. Every '
          . 'gate above ran and none of it was kept. Check the permissions on the state '
          . 'directory and its evidence.sqlite, then run the phase again.',
          $recordError,
        ),
        [],
        'evidence store',
      ));

      // Through recordFailure, not around it — and the augmented report back
      // into state on the way. Returning Failed directly left the run with two
      // holes: the `evidence_record` result existed only in the envelope, so
      // `run.json` and every surface reading it showed a phase that failed for
      // no stated reason; and no retry budget was spent, so the same unwritable
      // file produced the same failure on every continue, for ever. A blocker
      // nobody can clear and nobody is told about is the worst of both — the
      // run has to end SOMEWHERE, and after `max_gate_retries` this one ends as
      // a failed phase an operator can see and fix.
      $state = $state->withGateReport($phase->value, $report->toArray());

      return $this->recordFailure($state, $phase, $report);
    }

    if (!$report->advance()) {
      return $this->recordFailure($state, $phase, $report);
    }

    // The questions a shell command cannot ask, from the modules that can
    // answer them. After the gates, because a check should see the world the
    // gates just measured; before advancing, because a blocked check has to
    // stop a phase exactly as a failed gate does — otherwise contributing one
    // is contributing a comment.
    if (!$this->adjudicateChecks($state, $phase, $projectRoot)) {
      $stuck = $this->stuckOutcome($state, $phase, $projectRoot, $report, $now);

      // A contributed check or a spec condition, not a gate: nothing was spent
      // and the agent may fix and return. The block ceiling above is what stops
      // that becoming endless.
      //
      // WITH THE REASON. This returned `blocked: []`, and at PLAN — which runs
      // no gates, so the report is empty too — the agent got the word `blocked`
      // and nothing else, three times running against a broken contributed
      // check. The stop hook does name the rows, because it reads the store; an
      // agent reading the `run` envelope had no way to learn what was wrong.
      return $stuck ?? new RunOutcome(
        Outcome::Blocked,
        $state,
        $report,
        NULL,
        $this->blockingChecksFor($state, $phase, $projectRoot),
      );
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
   * The question a phase asks when it has been blocked too many times.
   *
   * Not a failure. The engine knows the count and nothing else: it cannot tell
   * a run making slow honest progress from one wedged against a block it cannot
   * clear, and the person watching can tell instantly. So it stops, says what
   * it has seen, and asks.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param int $blocks
   *   How many non-gate blocks have been recorded for it.
   * @param string $now
   *   The current time.
   *
   * @return \Droost\Workflow\Mode\PendingQuestion
   *   The question.
   */
  private function stuckQuestion(Phase $phase, int $blocks, string $now): PendingQuestion {
    return new PendingQuestion(
      $phase,
      sprintf(
        'This phase has been blocked %d times by checks that are not gates, and none of them '
        . 'has cleared. Is the work progressing, or is it stuck on something it cannot fix?',
        $blocks,
      ),
      sprintf('%s: %d unresolved non-gate blocks', $phase->value, $blocks),
      $now,
      sprintf('%s has not cleared a block in %d attempts', $phase->value, $blocks),
      [
        'The gates are not the problem: a failing gate spends a retry and ends '
        . 'the phase by itself. These are declarations, contributed checks or '
        . 'spec conditions, and they cost nothing to retry — so a run can sit '
        . 'here indefinitely without anything saying so.',
        'Read §4 of `droost-workflow evidence` for what is blocked and why.',
        'A block with an ENVIRONMENT fault names a remedy an operator can run.',
        'A block with an AGENT fault has no waiver: the work itself has to change.',
        'If a block cannot be cleared by any action, that is a defect in droost '
        . 'rather than in the work, and the run should be abandoned and reported.',
      ],
      [
        'keep going — the work is progressing and I expect it to clear',
        'stop here — this is stuck and I will look at it',
      ],
      PendingQuestion::KIND_STUCK,
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
   * @param string $projectRoot
   *   The repository, so an answer that ENDS the run can be recorded as a
   *   verdict rather than only as a phase status. Empty skips that, for the
   *   callers that have no root to give.
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
    string $projectRoot = '',
  ): RunState {
    if ($state->awaiting === NULL) {
      throw new \InvalidArgumentException(
        'This run is not waiting for an answer.',
      );
    }
    $asked = PendingQuestion::fromArray($state->awaiting);
    $answered = $state->answered($answer, $now);
    // Most answers do nothing beyond being recorded, and that is right: for a
    // conversation hold the human's consent to advance IS the act.
    //
    // The stuck question is not one of those. It offers "stop here — this is
    // stuck and I will look at it", and nothing read the reply: a walk answered
    // "stop here" and then "banana", and both printed `answered — now at code`
    // and carried on. A question whose answer changes nothing is theatre, and
    // it sits behind the one wall that exists because the run cannot tell a
    // slow correction cycle from a wedge.
    if ($asked !== NULL && $asked->answerEndsTheRun($answer)) {
      // Recorded as a verdict, not only as a phase status. A human deciding a
      // run is stuck is the most consequential thing that happens in one, and
      // the evaluation rendered nothing about it: the phase was failed, the
      // envelope carried `report: null, blocked: []`, and the surface said
      // "answered — now at code". A decision somebody made belongs in the
      // record that decision produced.
      //
      // Best-effort: an unwritable store is already reported by the phase that
      // could not write it, and losing this row must not lose the stop.
      try {
        (new EvidenceStore($projectRoot))->record(
          $state->runId,
          $asked->phase->value,
          new CheckRecord(
            'check',
            'stopped_by_operator',
            CheckState::Blocked,
            Fault::Environment,
            sprintf(
              'A human was asked whether this run was stuck and answered "%s". The phase ends '
              . 'here on their word, not on a gate.',
              trim($answer),
            ),
            'droost-workflow reset --force, once whatever made it stick is understood',
          ),
          $now,
        );
      }
      catch (\Throwable) {
        // See above.
      }

      return $answered->withPhaseStatus($asked->phase, PhaseStatus::Failed);
    }

    return $answered;
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
    // A STUCK QUESTION IS NOT A CONVERSATION PAUSE, and `released()` cleared
    // both alike. So the ceiling's question — the one the engine asks after
    // sixty blocks because it can see the count and a human can see the run —
    // was dismissed by `swap agentic`: the pause cleared, the counter reset,
    // nothing recorded, and `run` x60 -> `swap` -> repeat, for ever, with no
    // human ever asked and no trace in the record that a question had been
    // put. `swap` is not in the operator-commands guard either, so it is the
    // agent's own verb.
    //
    // The two ways past a stuck question stay what they were: answer it, or
    // `answer "stop here"`, both of which are recorded as somebody's decision.
    $pending = $this->pendingQuestion($state);
    if ($pending !== NULL && $pending->kind === PendingQuestion::KIND_STUCK) {
      throw new \InvalidArgumentException(
        'This run is stuck and waiting on an answer, and swapping mode is not '
        . 'an answer to it — it would clear the question, reset the counter '
        . 'and leave nothing in the record saying it was ever asked. Answer '
        . 'the question, or end the run with `answer "stop here"`, which fails '
        . 'the phase and records who stopped it.',
      );
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

  /**
   * Runs the contributed checks for a phase, and says whether it may advance.
   *
   * Every verdict is recorded whatever it says — a satisfied check is evidence
   * too, and a report that only shows failures cannot be used to ask "did this
   * provider ever actually run".
   *
   * A throwing adjudicator is recorded as blocked with an environment fault and
   * never takes the phase down with it. The phase still stops, because a check
   * that could not run has not passed; the fault is environment because a
   * broken plugin is not the agent's doing, and telling an agent to try harder
   * would wedge a run over somebody else's bug.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that just ran its gates.
   * @param string $projectRoot
   *   The repository.
   *
   * @return bool
   *   TRUE when nothing contributed blocks the phase.
   */
  private function adjudicateChecks(RunState $state, Phase $phase, string $projectRoot): bool {
    if ($this->checks === NULL) {
      return TRUE;
    }
    try {
      $records = $this->checks->adjudicate($projectRoot, $phase->value, $state->runId);
    }
    catch (\Throwable $e) {
      $records = [
        new CheckRecord(
          'check',
          'contributed_checks',
          CheckState::Blocked,
          Fault::Environment,
          'The contributed checks could not be adjudicated: ' . $e::class,
          'Check the log of the module contributing checks, then run the phase again.',
        ),
      ];
    }

    $store = new EvidenceStore($projectRoot);
    $advance = TRUE;
    foreach ($records as $record) {
      try {
        $store->record($state->runId, $phase->value, $record);
      }
      catch (\Throwable) {
        // Recording is never allowed to fail a phase; the verdict below still
        // holds, so a check that blocks still blocks even if the row is lost.
      }
      if ($record->state->blocksAdvance()) {
        $advance = FALSE;
      }
    }

    return $advance;
  }

  /**
   * A pause when a phase has been blocked past the ceiling, or NULL.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Gate\PhaseReport|null $report
   *   The phase report, when there is one.
   * @param string $now
   *   The current time.
   *
   * @return \Droost\Workflow\Mode\RunOutcome|null
   *   The paused outcome, or NULL to let the caller block as usual.
   */
  public function stuckOutcome(
    RunState $state,
    Phase $phase,
    string $projectRoot,
    ?PhaseReport $report,
    string $now,
  ): ?RunOutcome {
    try {
      $blocks = (new EvidenceStore($projectRoot))->blockedAttempts($state->runId, $phase->value);
    }
    catch (\Throwable) {
      // No store, no count, no ceiling. A missing record is already reported
      // by the phase that could not write it.
      return NULL;
    }
    if ($blocks < self::BLOCK_CEILING) {
      return NULL;
    }
    $question = $this->stuckQuestion($phase, $blocks, $now);
    // The mark the next count starts from, so answering buys another budget
    // rather than another pause. Recorded BEFORE the pause is announced: a
    // question asked and not marked would re-ask on the next invocation.
    try {
      (new EvidenceStore($projectRoot))->record(
        $state->runId,
        $phase->value,
        new CheckRecord(
          'check',
          'block_ceiling',
          CheckState::Recorded,
          Fault::None,
          sprintf('%d unresolved non-gate blocks; asked whether the run is stuck', $blocks),
        ),
        $now,
      );
    }
    catch (\Throwable) {
      // Unwritable store. The phase that could not write its evidence already
      // says so, and asking twice is better than not asking.
    }
    // State first, sink second. Always.
    $state = $state->awaiting($question->toArray());
    $this->sink->emit($question);

    return new RunOutcome(Outcome::Paused, $state, $report, $question);
  }

  /**
   * Why this phase is blocked, for the run envelope.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param string $projectRoot
   *   The repository.
   *
   * @return list<array{check: string, fault: string, why: string, remedy: string, guidance: string}>
   *   The unresolved checks.
   */
  private function blockingChecksFor(RunState $state, Phase $phase, string $projectRoot): array {
    try {
      return (new EvidenceStore($projectRoot))->blockingChecks($state->runId, $phase->value);
    }
    catch (\Throwable) {
      return [];
    }
  }

}
