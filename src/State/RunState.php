<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\State;

use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\PresetResolver;
use Drupal\droost_workflow\Config\Provenance;
use Drupal\droost_workflow\Config\WorkflowConfig;
use Drupal\droost_workflow\Support\TypedArray;

/**
 * Everything a run remembers between one gate and the next.
 *
 * Immutable: every change returns a new instance, so a caller cannot half-
 * apply an advance and persist the wreckage.
 *
 * Four fields — gate_results, awaiting, qa_history and feedback_attempts — are
 * written by later parts of the workflow and are round-tripped verbatim here.
 * Reserving them now keeps the schema at v1 across the whole build-out: a
 * state file written today stays readable by the gate runner and the mode
 * engine without a migration.
 *
 * Nothing in this class reads a clock or generates an identifier. The run id
 * and the start timestamp are supplied by whichever surface began the run,
 * which is what makes every test of it deterministic.
 */
final class RunState {

  /**
   * The schema version this build reads and writes.
   */
  public const SCHEMA_VERSION = 1;

  /**
   * Constructs a RunState.
   *
   * @param string $runId
   *   The run's identifier, supplied by the surface that began it.
   * @param string $startedAt
   *   When the run began, as an ISO-8601 string supplied by the caller.
   * @param \Drupal\droost_workflow\Config\Mode $mode
   *   The mode the lever file asked for.
   * @param \Drupal\droost_workflow\Config\Mode|null $modeOverride
   *   A mid-run swap, or NULL when none has happened.
   * @param string $preset
   *   The preset the levers were resolved from.
   * @param int $maxGateRetries
   *   The bound the feedback_attempts counters are measured against. Recorded
   *   because a count without its limit does not tell a reader whether a run
   *   gave up early or exhausted its budget.
   * @param \Drupal\droost_workflow\Config\Provenance $provenance
   *   Whether these levers came from a committed file or the built-in
   *   defaults — the difference between "the repo asked for this" and "the
   *   repo said nothing", which a report must not blur.
   * @param array<string, array<string, int|string|bool>> $resolvedGates
   *   The gate levers this run is held to, recorded so a report can be read
   *   without also reading the config file as it stands later. The boolean in
   *   the union is each gate's own "on" flag and nothing else — every option
   *   value is int|string, which is why the reader accepts a bool for "on"
   *   alone.
   * @param array<string, \Drupal\droost_workflow\State\PhaseStatus> $phases
   *   Status per configured phase, keyed by phase name.
   * @param \Drupal\droost_workflow\Config\Phase|null $currentPhase
   *   The phase in progress, or NULL once the run has ended.
   * @param array<array-key, mixed> $gateResults
   *   Reserved for the gate runner; round-tripped verbatim.
   * @param array<array-key, mixed>|null $awaiting
   *   Reserved for pair mode's pending question; round-tripped verbatim.
   * @param list<mixed> $qaHistory
   *   Reserved for pair mode's answered questions; round-tripped verbatim.
   * @param array<string, int> $feedbackAttempts
   *   How many times each gate has driven the feedback loop.
   */
  public function __construct(
    public readonly string $runId,
    public readonly string $startedAt,
    public readonly Mode $mode,
    public readonly ?Mode $modeOverride,
    public readonly string $preset,
    public readonly int $maxGateRetries,
    public readonly Provenance $provenance,
    public readonly array $resolvedGates,
    public readonly array $phases,
    public readonly ?Phase $currentPhase,
    public readonly array $gateResults = [],
    public readonly ?array $awaiting = NULL,
    public readonly array $qaHistory = [],
    public readonly array $feedbackAttempts = [],
  ) {}

  /**
   * Begins a run from a resolved configuration.
   *
   * @param string $runId
   *   The run's identifier.
   * @param string $startedAt
   *   When the run began, as an ISO-8601 string.
   * @param \Drupal\droost_workflow\Config\WorkflowConfig $config
   *   The resolved levers.
   *
   * @return self
   *   A run positioned at its first configured phase.
   */
  public static function begin(
    string $runId,
    string $startedAt,
    WorkflowConfig $config,
  ): self {
    $phases = [];
    foreach ($config->phases as $index => $phase) {
      $phases[$phase->value] = $index === 0
        ? PhaseStatus::Active
        : PhaseStatus::Pending;
    }

    return new self(
      $runId,
      $startedAt,
      $config->mode,
      NULL,
      $config->preset,
      $config->maxGateRetries,
      $config->provenance,
      $config->resolvedGates(),
      $phases,
      $config->phases[0] ?? NULL,
    );
  }

  /**
   * The mode actually in force.
   *
   * A mid-run swap outranks the lever file, so that flipping pair to
   * automated does not require editing a version-controlled file while a run
   * is in flight.
   *
   * @return \Drupal\droost_workflow\Config\Mode
   *   The override when one is set, otherwise the configured mode.
   */
  public function effectiveMode(): Mode {
    return $this->modeOverride ?? $this->mode;
  }

  /**
   * One phase's status.
   *
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase.
   *
   * @return \Drupal\droost_workflow\State\PhaseStatus|null
   *   The status, or NULL when this run does not execute that phase.
   */
  public function statusOf(Phase $phase): ?PhaseStatus {
    return $this->phases[$phase->value] ?? NULL;
  }

  /**
   * This run with one phase's status changed.
   *
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase.
   * @param \Drupal\droost_workflow\State\PhaseStatus $status
   *   The new status.
   *
   * @return self
   *   A new instance.
   */
  public function withPhaseStatus(Phase $phase, PhaseStatus $status): self {
    $phases = $this->phases;
    $phases[$phase->value] = $status;
    return $this->with(phases: $phases);
  }

  /**
   * This run advanced to a later phase.
   *
   * The phase being left is recorded as passed — advancing past a gate is
   * what passing it means. That is precisely why advancing is refused in
   * three situations rather than being made to work:
   *
   * - The outgoing phase FAILED or was SKIPPED. Stamping it passed on the way
   *   out would launder a failed gate into a pass, and the run's own report
   *   would then say a check succeeded that did not. Retrying a failed phase
   *   is legitimate, but it has to be said out loud with withPhaseStatus()
   *   first, so clearing a failure is a deliberate act and not a side effect
   *   of moving on.
   * - The target is at or before the current phase. Phases run at most once
   *   and in order; a run sitting at plan with complete already passed is not
   *   a state any report can describe honestly.
   * - The run has ended (no current phase). Resuming it would silently
   *   restart something that already reached its terminal gate.
   *
   * @param \Drupal\droost_workflow\Config\Phase $to
   *   The phase to enter.
   *
   * @return self
   *   A new instance.
   *
   * @throws \InvalidArgumentException
   *   When the transition is not one a run may make. Every case is a caller
   *   error; use canAdvanceTo() to ask first.
   */
  public function advanceTo(Phase $to): self {
    if (!array_key_exists($to->value, $this->phases)) {
      throw new \InvalidArgumentException(sprintf(
        'This run does not execute the "%s" phase',
        $to->value,
      ));
    }
    if ($this->currentPhase === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'This run has ended; it cannot advance to "%s"',
        $to->value,
      ));
    }
    if (!$this->isLaterThanCurrent($to)) {
      throw new \InvalidArgumentException(sprintf(
        'Cannot advance from "%s" to "%s": phases run once, in order',
        $this->currentPhase->value,
        $to->value,
      ));
    }

    $leaving = $this->phases[$this->currentPhase->value];
    if ($leaving !== PhaseStatus::Active) {
      throw new \InvalidArgumentException(sprintf(
        'Cannot advance away from "%s" while it is %s: that would record it '
        . 'as passed. Set its status deliberately first if you mean to '
        . 'clear it.',
        $this->currentPhase->value,
        $leaving->value,
      ));
    }

    $phases = $this->phases;
    $phases[$this->currentPhase->value] = PhaseStatus::Passed;
    $phases[$to->value] = PhaseStatus::Active;

    return $this->with(phases: $phases, currentPhase: $to);
  }

  /**
   * Whether advanceTo() would be accepted.
   *
   * @param \Drupal\droost_workflow\Config\Phase $to
   *   The phase to enter.
   *
   * @return bool
   *   TRUE when the transition is legal.
   */
  public function canAdvanceTo(Phase $to): bool {
    if (!array_key_exists($to->value, $this->phases)
      || $this->currentPhase === NULL
      || !$this->isLaterThanCurrent($to)) {
      return FALSE;
    }
    return $this->phases[$this->currentPhase->value] === PhaseStatus::Active;
  }

  /**
   * Whether a phase comes after the current one in canonical order.
   *
   * @param \Drupal\droost_workflow\Config\Phase $to
   *   The candidate phase.
   *
   * @return bool
   *   TRUE when it is strictly later.
   */
  private function isLaterThanCurrent(Phase $to): bool {
    if ($this->currentPhase === NULL) {
      return FALSE;
    }
    $order = Phase::canonical();
    return array_search($to, $order, TRUE)
      > array_search($this->currentPhase, $order, TRUE);
  }

  /**
   * This run with a phase's gate report recorded.
   *
   * Until this existed, `gate_results` was a field the writer emitted, the
   * reader round-tripped, and nothing ever populated — which meant any
   * instruction to "read the gate results from run state" pointed at a
   * permanently empty array, and anyone following it would fill the gap from
   * memory. Reporting checks that never ran is the one failure this package
   * exists to prevent, so the field needed a writer or it needed removing.
   *
   * @param string $phase
   *   The phase the report belongs to.
   * @param array<string, mixed> $report
   *   The serialized PhaseReport. Kept as plain data so State does not depend
   *   on Gate.
   *
   * @return self
   *   A new instance.
   */
  public function withGateReport(string $phase, array $report): self {
    $results = $this->gateResults;
    $results[$phase] = $report;
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $this->phases,
      $this->currentPhase,
      $results,
      $this->awaiting,
      $this->qaHistory,
      $this->feedbackAttempts,
    );
  }

  /**
   * This run with one more feedback-loop attempt counted against a gate.
   *
   * @param string $gate
   *   The gate that failed.
   *
   * @return self
   *   A new instance.
   */
  public function withFeedbackAttempt(string $gate): self {
    $attempts = $this->feedbackAttempts;
    $attempts[$gate] = ($attempts[$gate] ?? 0) + 1;
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $this->phases,
      $this->currentPhase,
      $this->gateResults,
      $this->awaiting,
      $this->qaHistory,
      $attempts,
    );
  }

  /**
   * This run paused at a gate, waiting on a question.
   *
   * @param array<string, string> $question
   *   The serialized pending question.
   *
   * @return self
   *   A new instance.
   */
  public function awaiting(array $question): self {
    return $this->rebuild(awaiting: $question, clearAwaiting: FALSE);
  }

  /**
   * This run with its pending question answered.
   *
   * The exchange is appended to the history, never replacing it: what was
   * asked and what was decided is part of the run's record, and the complete
   * phase presents it. Clearing the question but keeping no trace of it would
   * make a pair-mode run indistinguishable from an unattended one after the
   * fact.
   *
   * @param string $answer
   *   What the human said.
   * @param string $answeredAt
   *   When, as a caller-supplied ISO-8601 string.
   *
   * @return self
   *   A new instance, no longer awaiting.
   */
  public function answered(string $answer, string $answeredAt): self {
    $history = $this->qaHistory;
    $history[] = [
      'asked' => $this->awaiting,
      'answer' => $answer,
      'answered_at' => $answeredAt,
    ];
    return $this->rebuild(
      awaiting: NULL,
      clearAwaiting: TRUE,
      qaHistory: $history,
    );
  }

  /**
   * This run with any pending question dropped, unanswered.
   *
   * Used by a swap to automated: the point of that swap is to finish without
   * asking, so leaving the question outstanding would defeat it. The exchange
   * is still recorded, marked as released rather than answered, because a
   * question that was asked and then bypassed is a fact about the run.
   *
   * @param string $releasedAt
   *   When, as a caller-supplied ISO-8601 string.
   *
   * @return self
   *   A new instance, no longer awaiting.
   */
  public function released(string $releasedAt): self {
    if ($this->awaiting === NULL) {
      return $this;
    }
    $history = $this->qaHistory;
    $history[] = [
      'asked' => $this->awaiting,
      'answer' => NULL,
      'released_at' => $releasedAt,
    ];
    return $this->rebuild(
      awaiting: NULL,
      clearAwaiting: TRUE,
      qaHistory: $history,
    );
  }

  /**
   * A copy with the pause-related fields replaced.
   *
   * @param array<string, string>|null $awaiting
   *   The new pending question.
   * @param bool $clearAwaiting
   *   Whether a NULL $awaiting means "clear it" rather than "leave it".
   * @param list<mixed>|null $qaHistory
   *   The new history, or NULL to keep the current one.
   *
   * @return self
   *   A new instance.
   */
  private function rebuild(
    ?array $awaiting,
    bool $clearAwaiting,
    ?array $qaHistory = NULL,
  ): self {
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $this->phases,
      $this->currentPhase,
      $this->gateResults,
      $clearAwaiting ? NULL : ($awaiting ?? $this->awaiting),
      $qaHistory ?? $this->qaHistory,
      $this->feedbackAttempts,
    );
  }

  /**
   * This run with a mid-run mode swap applied.
   *
   * @param \Drupal\droost_workflow\Config\Mode $to
   *   The mode to switch to.
   *
   * @return self
   *   A new instance.
   */
  public function withModeOverride(Mode $to): self {
    return $this->with(modeOverride: $to);
  }

  /**
   * This run's state as the data written to disk.
   *
   * @return array<string, mixed>
   *   The v1 document.
   */
  public function toArray(): array {
    $phases = [];
    foreach ($this->phases as $name => $status) {
      $phases[$name] = $status->value;
    }

    return [
      'v' => self::SCHEMA_VERSION,
      'run_id' => $this->runId,
      'started_at' => $this->startedAt,
      'mode' => $this->mode->value,
      'mode_override' => $this->modeOverride?->value,
      'preset' => $this->preset,
      'max_gate_retries' => $this->maxGateRetries,
      'provenance' => $this->provenance->value,
      'resolved_gates' => $this->resolvedGates,
      'phases' => $phases,
      'current_phase' => $this->currentPhase?->value,
      'gate_results' => $this->gateResults,
      'awaiting' => $this->awaiting,
      'qa_history' => $this->qaHistory,
      'feedback_attempts' => $this->feedbackAttempts,
    ];
  }

  /**
   * Rebuilds a run from the data written to disk.
   *
   * The caller has already checked the schema version.
   *
   * @param \Drupal\droost_workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's path as shown to an operator, so that every message
   *   about this document names the same file. Passing it in rather than
   *   hardcoding "run.json" here is what stops one failure reporting
   *   "run.json" and the next ".droost-workflow/run.json".
   *
   * @return self
   *   The run.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When a field is absent or has the wrong type.
   * @throws \Drupal\droost_workflow\State\StateError
   *   When a name is outside its vocabulary.
   */
  public static function fromArray(TypedArray $node, string $label): self {
    $modeName = $node->string('mode');
    $mode = Mode::tryFrom($modeName);
    if ($mode === NULL) {
      throw StateError::corrupt($label, sprintf(
        'unknown mode "%s"',
        $modeName,
      ));
    }

    $overrideName = $node->optionalString('mode_override');
    $override = NULL;
    if ($overrideName !== NULL) {
      $override = Mode::tryFrom($overrideName);
      if ($override === NULL) {
        throw StateError::corrupt($label, sprintf(
          'unknown mode_override "%s"',
          $overrideName,
        ));
      }
    }

    $currentName = $node->optionalString('current_phase');
    $current = NULL;
    if ($currentName !== NULL) {
      $current = Phase::tryFrom($currentName);
      if ($current === NULL) {
        throw StateError::corrupt($label, sprintf(
          'unknown current_phase "%s"',
          $currentName,
        ));
      }
    }

    return new self(
      $node->string('run_id'),
      $node->string('started_at'),
      $mode,
      $override,
      self::readPreset($node, $label),
      $node->int('max_gate_retries'),
      self::readProvenance($node, $label),
      self::readResolvedGates($node, $label),
      self::readPhases($node, $label),
      $current,
      $node->optionalChild('gate_results')?->toArray() ?? [],
      $node->optionalChild('awaiting')?->toArray(),
      self::readQaHistory($node, $label),
      self::readFeedbackAttempts($node),
    );
  }

  /**
   * Reads the recorded preset name.
   *
   * @param \Drupal\droost_workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return string
   *   The preset name.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When the field is absent or not a string.
   * @throws \Drupal\droost_workflow\State\StateError
   *   When the name is outside the vocabulary.
   */
  private static function readPreset(
    TypedArray $node,
    string $label,
  ): string {
    $preset = $node->string('preset');
    if (!PresetResolver::isKnown($preset)) {
      throw StateError::corrupt($label, sprintf(
        'unknown preset "%s" (known: %s)',
        $preset,
        implode(', ', PresetResolver::KNOWN_PRESETS),
      ));
    }
    return $preset;
  }

  /**
   * Reads the recorded provenance.
   *
   * @param \Drupal\droost_workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return \Drupal\droost_workflow\Config\Provenance
   *   Where the levers this run is held to came from.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When the field is absent or not a string.
   * @throws \Drupal\droost_workflow\State\StateError
   *   When the word is outside the vocabulary.
   */
  private static function readProvenance(
    TypedArray $node,
    string $label,
  ): Provenance {
    $word = $node->string('provenance');
    $provenance = Provenance::tryFrom($word);
    if ($provenance === NULL) {
      throw StateError::corrupt($label, sprintf(
        'unknown provenance "%s"',
        $word,
      ));
    }
    return $provenance;
  }

  /**
   * Reads the answered-question history.
   *
   * @param \Drupal\droost_workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return list<mixed>
   *   The history, in order.
   *
   * @throws \Drupal\droost_workflow\State\StateError
   *   When the field is present but not a list. Silently running
   *   array_values() over a map would reshape a reserved field the caller
   *   handed us verbatim, which is the one thing round-tripping promises not
   *   to do.
   */
  private static function readQaHistory(
    TypedArray $node,
    string $label,
  ): array {
    $raw = $node->optionalChild('qa_history')?->toArray() ?? [];
    if (!array_is_list($raw)) {
      throw StateError::corrupt(
        $label,
        'qa_history must be a list, got a mapping',
      );
    }
    return $raw;
  }

  /**
   * Reads the recorded gate levers.
   *
   * @param \Drupal\droost_workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its recorded levers.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When the shape is wrong.
   * @throws \Drupal\droost_workflow\State\StateError
   *   When a gate name is outside the vocabulary.
   */
  private static function readResolvedGates(
    TypedArray $node,
    string $label,
  ): array {
    $gatesNode = $node->optionalChild('resolved_gates');
    if ($gatesNode === NULL) {
      return [];
    }

    $gates = [];
    foreach ($gatesNode->keys() as $name) {
      // The config side refuses unknown gate names; state has to as well, or
      // a corrupted run.json yields a gate report naming a gate that does not
      // exist — and resolvedGates is what a report renders.
      if (!GateSettings::isKnown($name)) {
        throw StateError::corrupt($label, sprintf(
          'unknown gate "%s" in resolved_gates (known: %s)',
          $name,
          implode(', ', GateSettings::KNOWN_GATES),
        ));
      }
      $levers = $gatesNode->child($name);
      $out = ['on' => $levers->bool('on')];
      foreach ($levers->keys() as $option) {
        if ($option === 'on') {
          continue;
        }
        $out[$option] = $levers->intOrString($option);
      }
      $gates[$name] = $out;
    }
    return $gates;
  }

  /**
   * Reads the per-phase statuses.
   *
   * @param \Drupal\droost_workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, \Drupal\droost_workflow\State\PhaseStatus>
   *   Phase name to status.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When the shape is wrong.
   * @throws \Drupal\droost_workflow\State\StateError
   *   When a status word is outside the vocabulary.
   */
  private static function readPhases(TypedArray $node, string $label): array {
    $phasesNode = $node->optionalChild('phases');
    if ($phasesNode === NULL) {
      return [];
    }

    $phases = [];
    foreach ($phasesNode->keys() as $name) {
      // Validate the KEY, not just the status word. A typo'd or foreign phase
      // name would otherwise load clean, round-trip its garbage back to disk,
      // and then make every legitimate phase unreachable — failing much later
      // and nowhere near the cause.
      if (Phase::tryFrom($name) === NULL) {
        throw StateError::corrupt($label, sprintf(
          'unknown phase "%s" (known: %s)',
          $name,
          implode(', ', Phase::names()),
        ));
      }
      $word = $phasesNode->string($name);
      $status = PhaseStatus::tryFrom($word);
      if ($status === NULL) {
        throw StateError::corrupt($label, sprintf(
          'unknown status "%s" for phase "%s"',
          $word,
          $name,
        ));
      }
      $phases[$name] = $status;
    }
    return $phases;
  }

  /**
   * Reads the feedback-loop counters.
   *
   * @param \Drupal\droost_workflow\Support\TypedArray $node
   *   The decoded document.
   *
   * @return array<string, int>
   *   Gate name to attempt count.
   *
   * @throws \Drupal\droost_workflow\Support\DataError
   *   When a counter is not an integer.
   */
  private static function readFeedbackAttempts(TypedArray $node): array {
    $attemptsNode = $node->optionalChild('feedback_attempts');
    if ($attemptsNode === NULL) {
      return [];
    }

    $attempts = [];
    foreach ($attemptsNode->keys() as $gate) {
      $attempts[$gate] = $attemptsNode->int($gate);
    }
    return $attempts;
  }

  /**
   * A copy with selected fields replaced.
   *
   * @param array<string, \Drupal\droost_workflow\State\PhaseStatus>|null $phases
   *   The new phase statuses, or NULL to keep the current ones.
   * @param \Drupal\droost_workflow\Config\Phase|null $currentPhase
   *   The new current phase; only applied when $moveCurrent is TRUE.
   * @param \Drupal\droost_workflow\Config\Mode|null $modeOverride
   *   The new override, or NULL to keep the current one.
   *
   * @return self
   *   A new instance.
   */
  private function with(
    ?array $phases = NULL,
    ?Phase $currentPhase = NULL,
    ?Mode $modeOverride = NULL,
  ): self {
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $modeOverride ?? $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $phases ?? $this->phases,
      $currentPhase ?? $this->currentPhase,
      $this->gateResults,
      $this->awaiting,
      $this->qaHistory,
      $this->feedbackAttempts,
    );
  }

}
