<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Gate;

use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\State\RunState;

/**
 * Executes a phase's gates and says whether the run may continue.
 *
 * Reads the resolved lever set from the RUN, never from the config file. A
 * run is held to the levers it started under; re-reading the file would let
 * an edit made mid-run change what a half-finished run is measured against,
 * and would make two surfaces disagree about the same run.
 */
final class GateRunner {

  /**
   * The gates that cannot run without a booted site.
   *
   * Only rendered_check. phpunit is deliberately NOT here: unit and kernel
   * suites run perfectly well against a checkout, and treating the whole gate
   * as site-bound would skip checks that could have run. Functional suites do
   * need a site, but selecting them is an argument to phpunit rather than a
   * fact about the gate — a distinction this package cannot make yet, and
   * recorded rather than guessed at.
   *
   * @var list<string>
   */
  public const SITE_GATES = ['rendered_check'];

  /**
   * Constructs a GateRunner.
   *
   * @param \Drupal\droost_workflow\Gate\GateExecutorInterface $executor
   *   Runs the gates that need only a checkout.
   * @param \Drupal\droost_workflow\Gate\SiteDriverInterface $driver
   *   Runs the gates that need a site.
   */
  public function __construct(
    private readonly GateExecutorInterface $executor,
    private readonly SiteDriverInterface $driver,
  ) {}

  /**
   * Runs every gate due at this phase.
   *
   * Iterates the run's own frozen phase map rather than the whole resolved
   * set, for the same reason it reads the run's levers: what a half-finished
   * run is measured against must not change under it. A gate that is not due
   * at this phase is omitted from the report entirely — complete re-runs the
   * full set, so nothing enabled is ever omitted from the run.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run, carrying the resolved levers and the frozen phase map.
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase being gated.
   * @param string $projectRoot
   *   The repository to run in.
   * @param callable(\Drupal\droost_workflow\Gate\GateResult): void|null $onResult
   *   Called after each gate. The attach point for pair mode, which needs to
   *   act at a gate boundary without this class knowing anything about modes.
   *
   * @return \Drupal\droost_workflow\Gate\PhaseReport
   *   Every due gate's outcome.
   */
  public function run(
    RunState $state,
    Phase $phase,
    string $projectRoot,
    ?callable $onResult = NULL,
  ): PhaseReport {
    $report = new PhaseReport($phase);

    foreach ($state->gatesDueFor($phase) as $name => $levers) {
      $result = $this->runOne($name, $levers, $projectRoot);
      $report = $report->with($result);
      if ($onResult !== NULL) {
        $onResult($result);
      }
    }

    return $report;
  }

  /**
   * Whether a failing gate may be retried again.
   *
   * The bound is the run's own recorded `max_gate_retries`, and the count
   * lives in run state — so a process killed mid-loop resumes with its
   * attempts intact rather than starting the budget over.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   * @param string $gate
   *   The gate that failed.
   *
   * @return bool
   *   TRUE when another attempt is within budget.
   */
  public function mayRetry(RunState $state, string $gate): bool {
    return ($state->feedbackAttempts[$gate] ?? 0) < $state->maxGateRetries;
  }

  /**
   * Records one more attempt against a gate.
   *
   * Returns the new state rather than mutating: the caller decides when to
   * persist, and a caller that forgets has not silently lost a count it
   * believed was saved.
   *
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   * @param string $gate
   *   The gate that failed.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The run with the attempt recorded.
   */
  public function recordAttempt(RunState $state, string $gate): RunState {
    return $state->withFeedbackAttempt($gate);
  }

  /**
   * Runs a single gate, choosing where it belongs.
   *
   * @param string $name
   *   The gate name.
   * @param array<string, int|string|bool> $levers
   *   The gate's recorded levers.
   * @param string $projectRoot
   *   The repository to run in.
   *
   * @return \Drupal\droost_workflow\Gate\GateResult
   *   What happened.
   */
  private function runOne(
    string $name,
    array $levers,
    string $projectRoot,
  ): GateResult {
    $on = $levers['on'] ?? FALSE;
    if ($on !== TRUE) {
      return GateResult::off($name);
    }

    $gate = $this->settings($name, $levers);

    if (in_array($name, self::SITE_GATES, TRUE)) {
      if (!$this->driver->available()) {
        return GateResult::skippedNoSite($name);
      }
      if (!in_array($name, $this->driver->supports(), TRUE)) {
        // A site exists but this driver cannot run the gate. That is a
        // misconfiguration, not an environmental skip — reporting it as
        // "no site" would blame the wrong thing and hide a real gap.
        return GateResult::toolMissing(
          $name,
          sprintf('%s (no site driver implements it)', $name),
        );
      }
      return $this->driver->run($gate, $projectRoot);
    }

    return $this->executor->execute($gate, $projectRoot);
  }

  /**
   * Rebuilds a GateSettings from the levers recorded in run state.
   *
   * @param string $name
   *   The gate name.
   * @param array<string, int|string|bool> $levers
   *   The recorded levers.
   *
   * @return \Drupal\droost_workflow\Config\GateSettings
   *   The settings.
   */
  private function settings(
    string $name,
    array $levers,
  ): GateSettings {
    $options = [];
    foreach ($levers as $key => $value) {
      if ($key !== 'on' && (is_int($value) || is_string($value))) {
        $options[$key] = $value;
      }
    }
    return new GateSettings($name, TRUE, $options);
  }

}
