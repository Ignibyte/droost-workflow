<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Enforcement;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\PresetResolver;
use Droost\Workflow\Config\Provenance;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Support\TypedArray;

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
   * The host task surfaces an agent may declare.
   *
   * A closed set, like the browser tier's, so a typo is a refusal rather
   * than a silently-recorded fiction. `other` is deliberate: a host with a
   * task list we have not named should be able to say it has one, because
   * the useful distinction is "a human can see the phases" versus "they
   * cannot", not which vendor provides it.
   *
   * @var list<string>
   */
  public const TASK_SURFACES = ['claude-code', 'codex', 'other', 'none'];

  /**
   * Constructs a RunState.
   *
   * @param string $runId
   *   The run's identifier, supplied by the surface that began it.
   * @param string $startedAt
   *   When the run began, as an ISO-8601 string supplied by the caller.
   * @param \Droost\Workflow\Config\Mode $mode
   *   The mode the lever file asked for.
   * @param \Droost\Workflow\Config\Mode|null $modeOverride
   *   A mid-run swap, or NULL when none has happened.
   * @param string $preset
   *   The preset the levers were resolved from.
   * @param int $maxGateRetries
   *   The bound the feedback_attempts counters are measured against. Recorded
   *   because a count without its limit does not tell a reader whether a run
   *   gave up early or exhausted its budget.
   * @param \Droost\Workflow\Config\Provenance $provenance
   *   Whether these levers came from a committed file or the built-in
   *   defaults — the difference between "the repo asked for this" and "the
   *   repo said nothing", which a report must not blur.
   * @param array<string, array<string, int|string|bool>> $resolvedGates
   *   The gate levers this run is held to, recorded so a report can be read
   *   without also reading the config file as it stands later. The boolean in
   *   the union is each gate's own "on" flag and nothing else — every option
   *   value is int|string, which is why the reader accepts a bool for "on"
   *   alone.
   * @param array<string, \Droost\Workflow\State\PhaseStatus> $phases
   *   Status per configured phase, keyed by phase name.
   * @param \Droost\Workflow\Config\Phase|null $currentPhase
   *   The phase in progress, or NULL once the run has ended.
   * @param array<array-key, mixed> $gateResults
   *   Reserved for the gate runner; round-tripped verbatim.
   * @param array<array-key, mixed>|null $awaiting
   *   Reserved for pair mode's pending question; round-tripped verbatim.
   * @param list<mixed> $qaHistory
   *   Reserved for pair mode's answered questions; round-tripped verbatim.
   * @param array<string, int> $feedbackAttempts
   *   How many times each gate has driven the feedback loop.
   * @param array<string, list<string>> $phaseGates
   *   Which gates are due at which configured phase — the PhaseGateMap as it
   *   stood when the run began (custom gates woven in at their configured
   *   phase), frozen for the same reason the resolved levers are: a run is
   *   held to the map it started under.
   * @param \Droost\Workflow\Config\Enforcement $enforcement
   *   The enforcement level frozen at begin. Defaults to Off so a document
   *   written before the lever existed reads as what it was: unenforced.
   * @param bool $seekers
   *   Whether the adversarial-review checkpoint is armed, frozen at begin.
   *   Defaults to FALSE for the same reason enforcement defaults to Off: a
   *   document written before the lever existed was not held to it.
   * @param array<string, int|string>|null $seeker
   *   The latest recorded inspection — status, per-severity counts,
   *   observation count, reported_at — or NULL when none has been recorded.
   *   Written only by withSeekerReport(), whose counts come from parsing
   *   the ledger text, never from a self-report.
   * @param string|null $browser
   *   The browser capability the running agent declared at run start
   *   (playwright-mcp, native, or none), or NULL when undeclared. Session-
   *   scoped truth only the agent can know; recorded so the test phase and
   *   the report can say which verification tier actually ran.
   * @param string|null $tasks
   *   The host task surface the running agent declared (claude-code, codex,
   *   other, or none), or NULL when undeclared. Recorded for the same reason
   *   as the browser tier and by the same route: whether this session can
   *   show a human where the run is, as one task per phase, is something only
   *   the agent can see, and a report that claimed phase visibility the host
   *   never had would be worse than one that says "none".
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
    public readonly array $phaseGates = [],
    public readonly Enforcement $enforcement = Enforcement::Off,
    public readonly bool $seekers = FALSE,
    public readonly ?array $seeker = NULL,
    public readonly ?string $browser = NULL,
    public readonly ?string $tasks = NULL,
  ) {}

  /**
   * Begins a run from a resolved configuration.
   *
   * @param string $runId
   *   The run's identifier.
   * @param string $startedAt
   *   When the run began, as an ISO-8601 string.
   * @param \Droost\Workflow\Config\WorkflowConfig $config
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
      phaseGates: self::weaveCustomGates(
        PhaseGateMap::forPhases($config->phaseNames()),
        $config->gates,
      ),
      enforcement: $config->enforcement,
      seekers: $config->seekers,
    );
  }

  /**
   * Adds each custom gate to the frozen map at its configured phase.
   *
   * The engine-owned map cannot know a repo's custom gates, so they join at
   * freeze time: once at their declared phase, and once at complete, where
   * everything enabled re-runs. Weaving them into the FROZEN map (rather
   * than special-casing the due-gate lookup) keeps one invariant: a run is
   * measured against exactly what its own document says.
   *
   * @param array<string, list<string>> $phaseGates
   *   The engine map for the configured phases.
   * @param array<string, \Droost\Workflow\Config\GateSettings> $gates
   *   The resolved gate set.
   *
   * @return array<string, list<string>>
   *   The map with custom gates placed.
   */
  private static function weaveCustomGates(array $phaseGates, array $gates): array {
    foreach ($gates as $name => $gate) {
      if (!GateSettings::isCustom($name)) {
        continue;
      }
      $at = $gate->option('phase');
      if (is_string($at) && isset($phaseGates[$at])) {
        $phaseGates[$at][] = $name;
      }
      if (isset($phaseGates['complete'])) {
        $phaseGates['complete'][] = $name;
      }
    }
    return $phaseGates;
  }

  /**
   * The resolved gates due at one phase, in resolved order.
   *
   * The intersection of the two frozen records: the levers say whether each
   * gate runs at all, this run's phase map says when. The runner iterates
   * this instead of the whole resolved set, which is what stops a plan phase
   * from running a browser suite.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase being gated.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its recorded levers, for the gates due at this phase,
   *   preserving resolved-gate order so reports stay stably ordered.
   */
  public function gatesDueFor(Phase $phase): array {
    $due = $this->phaseGates[$phase->value] ?? [];
    $gates = [];
    foreach ($this->resolvedGates as $name => $levers) {
      if (in_array($name, $due, TRUE)) {
        $gates[$name] = $levers;
      }
    }
    return $gates;
  }

  /**
   * The mode actually in force.
   *
   * A mid-run swap outranks the lever file, so that flipping pair to
   * automated does not require editing a version-controlled file while a run
   * is in flight.
   *
   * @return \Droost\Workflow\Config\Mode
   *   The override when one is set, otherwise the configured mode.
   */
  public function effectiveMode(): Mode {
    return $this->modeOverride ?? $this->mode;
  }

  /**
   * One phase's status.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   *
   * @return \Droost\Workflow\State\PhaseStatus|null
   *   The status, or NULL when this run does not execute that phase.
   */
  public function statusOf(Phase $phase): ?PhaseStatus {
    return $this->phases[$phase->value] ?? NULL;
  }

  /**
   * This run with one phase's status changed.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param \Droost\Workflow\State\PhaseStatus $status
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
   * @param \Droost\Workflow\Config\Phase $to
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
   * This run at its terminal gate: the final phase passed, nothing left to run.
   *
   * The last phase has no phase to advance TO, so advanceTo() cannot record
   * its pass or retire the run — yet a run that reached and passed its final
   * gate is finished, and every reader (status, report, reset, and run()'s own
   * "already completed" short-circuit) tells that from currentPhase being
   * NULL. This records the final phase passed, exactly as advanceTo() records
   * the phase it leaves, and drops currentPhase to NULL so the finished run
   * reads as finished rather than forever "active".
   *
   * Completing an already-terminal run is a no-op, not an error: run() may see
   * a completed run again and re-affirm it.
   *
   * @return self
   *   A new instance at its terminal gate, or this one unchanged when it is
   *   already there.
   */
  public function complete(): self {
    if ($this->currentPhase === NULL) {
      return $this;
    }
    $phases = $this->phases;
    $phases[$this->currentPhase->value] = PhaseStatus::Passed;
    // with() cannot set currentPhase to NULL (it reads NULL as "keep"), so the
    // terminal state is built directly, as rebuild() does for its own field.
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $phases,
      NULL,
      $this->gateResults,
      $this->awaiting,
      $this->qaHistory,
      $this->feedbackAttempts,
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
    );
  }

  /**
   * Whether advanceTo() would be accepted.
   *
   * @param \Droost\Workflow\Config\Phase $to
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
   * @param \Droost\Workflow\Config\Phase $to
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
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
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
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
    );
  }

  /**
   * This run paused at a gate, waiting on a question.
   *
   * @param array<string, string|list<string>> $question
   *   The serialized pending question. The lists are the conversation an
   *   interactive hold carries — its detail lines and the options worth
   *   offering — which is why this is not a flat string map.
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
   * @param array<string, string|list<string>>|null $awaiting
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
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
    );
  }

  /**
   * This run with a fresh seeker inspection recorded.
   *
   * @param array<string, int|string> $record
   *   The parsed ledger's record (SeekerLedger::toRecord()).
   *
   * @return self
   *   A new instance.
   */
  public function withSeekerReport(array $record): self {
    return $this->with(seeker: $record);
  }

  /**
   * This run with the agent's declared browser capability recorded.
   *
   * @param string $browser
   *   One of: playwright-mcp, native, none.
   *
   * @return self
   *   A new instance.
   */
  public function withBrowser(string $browser): self {
    return $this->with(browser: $browser);
  }

  /**
   * This run with the agent's declared host task surface recorded.
   *
   * @param string $tasks
   *   The surface: claude-code, codex, other, or none.
   *
   * @return self
   *   The run, with the declaration recorded.
   */
  public function withTasks(string $tasks): self {
    return $this->with(tasks: $tasks);
  }

  /**
   * This run with a mid-run mode swap applied.
   *
   * @param \Droost\Workflow\Config\Mode $to
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
      'enforcement' => $this->enforcement->value,
      'resolved_gates' => $this->resolvedGates,
      'phase_gates' => $this->phaseGates,
      'phases' => $phases,
      'current_phase' => $this->currentPhase?->value,
      'gate_results' => $this->gateResults,
      'awaiting' => $this->awaiting,
      'qa_history' => $this->qaHistory,
      'feedback_attempts' => $this->feedbackAttempts,
      'seekers' => $this->seekers,
      'seeker' => $this->seeker,
      'browser' => $this->browser,
      'tasks' => $this->tasks,
    ];
  }

  /**
   * Rebuilds a run from the data written to disk.
   *
   * The caller has already checked the schema version.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
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
   * @throws \Droost\Workflow\Support\DataError
   *   When a field is absent or has the wrong type.
   * @throws \Droost\Workflow\State\StateError
   *   When a name is outside its vocabulary.
   */
  public static function fromArray(TypedArray $node, string $label): self {
    $modeName = $node->string('mode');
    // resolve(), not tryFrom(): a run started before the rename recorded
    // "automated" or "pair", and refusing to read it would strand an
    // in-flight run rather than rename a lever.
    $mode = Mode::resolve($modeName);
    if ($mode === NULL) {
      throw StateError::corrupt($label, sprintf(
        'unknown mode "%s"',
        $modeName,
      ));
    }

    $overrideName = $node->optionalString('mode_override');
    $override = NULL;
    if ($overrideName !== NULL) {
      $override = Mode::resolve($overrideName);
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

    $phases = self::readPhases($node, $label);

    return new self(
      $node->string('run_id'),
      $node->string('started_at'),
      $mode,
      $override,
      self::readPreset($node, $label),
      $node->int('max_gate_retries'),
      self::readProvenance($node, $label),
      self::readResolvedGates($node, $label),
      $phases,
      $current,
      $node->optionalChild('gate_results')?->toArray() ?? [],
      $node->optionalChild('awaiting')?->toArray(),
      self::readQaHistory($node, $label),
      self::readFeedbackAttempts($node),
      self::readPhaseGates($node, $label, $phases),
      Enforcement::tryFrom(
        $node->optionalString('enforcement', Enforcement::Off->value)
          ?? Enforcement::Off->value,
      ) ?? Enforcement::Off,
      $node->optionalBool('seekers', FALSE),
      self::readSeeker($node, $label),
      self::readBrowser($node, $label),
      self::readTasks($node, $label),
    );
  }

  /**
   * Reads the recorded seeker inspection, when one exists.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, int|string>|null
   *   The record.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When a field has the wrong type.
   * @throws \Droost\Workflow\State\StateError
   *   When the status word is outside its vocabulary.
   */
  private static function readSeeker(
    TypedArray $node,
    string $label,
  ): ?array {
    $seekerNode = $node->optionalChild('seeker');
    if ($seekerNode === NULL) {
      return NULL;
    }
    $status = $seekerNode->string('status');
    if (!in_array($status, ['clean', 'findings'], TRUE)) {
      throw StateError::corrupt($label, sprintf(
        'unknown seeker status "%s" (known: clean, findings)',
        $status,
      ));
    }
    return [
      'status' => $status,
      'critical' => $seekerNode->int('critical'),
      'medium' => $seekerNode->int('medium'),
      'low' => $seekerNode->int('low'),
      'observations' => $seekerNode->int('observations'),
      'reported_at' => $seekerNode->string('reported_at'),
    ];
  }

  /**
   * Reads the declared browser capability, when one was declared.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return string|null
   *   The capability.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When the word is outside its vocabulary.
   */
  private static function readBrowser(
    TypedArray $node,
    string $label,
  ): ?string {
    $browser = $node->optionalString('browser');
    if ($browser === NULL) {
      return NULL;
    }
    if (!in_array($browser, ['playwright-mcp', 'native', 'none'], TRUE)) {
      throw StateError::corrupt($label, sprintf(
        'unknown browser capability "%s" (known: playwright-mcp, native, '
        . 'none)',
        $browser,
      ));
    }
    return $browser;
  }

  /**
   * Reads the declared host task surface, when one was declared.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return string|null
   *   The surface.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When the word is outside its vocabulary.
   */
  private static function readTasks(
    TypedArray $node,
    string $label,
  ): ?string {
    $tasks = $node->optionalString('tasks');
    if ($tasks === NULL) {
      return NULL;
    }
    if (!in_array($tasks, self::TASK_SURFACES, TRUE)) {
      throw StateError::corrupt($label, sprintf(
        'unknown task surface "%s" (known: %s)',
        $tasks,
        implode(', ', self::TASK_SURFACES),
      ));
    }
    return $tasks;
  }

  /**
   * Reads the frozen phase-to-gates map.
   *
   * A document written before the map existed has no phase_gates field. The
   * engine default is the only honest reconstruction — those runs WERE
   * executing under "the engine decides" — so absence synthesizes the
   * default for the phases the document configures. A PRESENT field is the
   * run's own frozen record and is never second-guessed, empty or not.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   * @param array<string, \Droost\Workflow\State\PhaseStatus> $phases
   *   The configured phases, already read and validated.
   *
   * @return array<string, list<string>>
   *   Phase name to its due gates.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When a value is not a list of strings.
   * @throws \Droost\Workflow\State\StateError
   *   When a phase or gate name is outside its vocabulary.
   */
  private static function readPhaseGates(
    TypedArray $node,
    string $label,
    array $phases,
  ): array {
    $gatesNode = $node->optionalChild('phase_gates');
    if ($gatesNode === NULL) {
      $due = [];
      foreach (array_keys($phases) as $name) {
        $phase = Phase::tryFrom($name);
        if ($phase !== NULL) {
          $due[$name] = PhaseGateMap::gatesFor($phase);
        }
      }
      return $due;
    }

    $due = [];
    foreach ($gatesNode->keys() as $name) {
      if (Phase::tryFrom($name) === NULL) {
        // The phases reader above has already turned a 0.3 document into
        // the migration message; anything else here is genuine corruption.
        throw StateError::corrupt($label, sprintf(
          'unknown phase "%s" in phase_gates (known: %s)',
          $name,
          implode(', ', Phase::names()),
        ));
      }
      $gates = $gatesNode->stringList($name);
      foreach ($gates as $gate) {
        if (!GateSettings::isKnown($gate)) {
          throw StateError::corrupt($label, sprintf(
            'unknown gate "%s" in phase_gates (known: %s)',
            $gate,
            implode(', ', GateSettings::KNOWN_GATES),
          ));
        }
      }
      $due[$name] = $gates;
    }
    return $due;
  }

  /**
   * Reads the recorded preset name.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return string
   *   The preset name.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the field is absent or not a string.
   * @throws \Droost\Workflow\State\StateError
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
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return \Droost\Workflow\Config\Provenance
   *   Where the levers this run is held to came from.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the field is absent or not a string.
   * @throws \Droost\Workflow\State\StateError
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
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return list<mixed>
   *   The history, in order.
   *
   * @throws \Droost\Workflow\State\StateError
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
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its recorded levers.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the shape is wrong.
   * @throws \Droost\Workflow\State\StateError
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
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, \Droost\Workflow\State\PhaseStatus>
   *   Phase name to status.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the shape is wrong.
   * @throws \Droost\Workflow\State\StateError
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
        // "document" is not garbage — it is a run that began under 0.3,
        // whose five-phase vocabulary this build no longer speaks. Runs are
        // short-lived by design, so there is no migration: the message names
        // the two honest exits instead of calling the file corrupt.
        if ($name === 'document') {
          throw StateError::corrupt($label, sprintf(
            'this run began under droost/workflow 0.3, whose five phases '
            . 'included "document" (folded into complete in 0.4.0) — finish '
            . 'the run on 0.3, or remove %s and start a new run',
            $label,
          ));
        }
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
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   *
   * @return array<string, int>
   *   Gate name to attempt count.
   *
   * @throws \Droost\Workflow\Support\DataError
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
   * @param array<string, \Droost\Workflow\State\PhaseStatus>|null $phases
   *   The new phase statuses, or NULL to keep the current ones.
   * @param \Droost\Workflow\Config\Phase|null $currentPhase
   *   The new current phase; only applied when $moveCurrent is TRUE.
   * @param \Droost\Workflow\Config\Mode|null $modeOverride
   *   The new override, or NULL to keep the current one.
   * @param array<string, int|string>|null $seeker
   *   The new inspection record, or NULL to keep the current one.
   * @param string|null $browser
   *   The new declared capability, or NULL to keep the current one.
   * @param string|null $tasks
   *   The new declared task surface, or NULL to keep the current one.
   *
   * @return self
   *   A new instance.
   */
  private function with(
    ?array $phases = NULL,
    ?Phase $currentPhase = NULL,
    ?Mode $modeOverride = NULL,
    ?array $seeker = NULL,
    ?string $browser = NULL,
    ?string $tasks = NULL,
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
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $seeker ?? $this->seeker,
      $browser ?? $this->browser,
      $tasks ?? $this->tasks,
    );
  }

}
