<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\RunState;

/**
 * Executes a phase's gates and says whether the run may continue.
 *
 * WHICH gates run, and WHEN, come from the RUN: the resolved on/off set and
 * the phase map are frozen at begin, so an edit made mid-run cannot switch a
 * gate off under a half-finished run or make two surfaces disagree about it.
 *
 * HOW a gate runs — its tuning options (standard, level, paths, thresholds,
 * routes) — is re-read from the lever file at gate time, when the file
 * exists, and any difference from the frozen record is named in the gate's
 * own summary. Round 24 (R24-F4): a subject's phpcs gate walked node_modules,
 * the subject pointed `phpcs.standard` at a ruleset that excluded it — the
 * feedback loop's intended move — and the gate silently re-ran with the old
 * standard until the retries were spent. Fixing a ruleset IS the loop;
 * refusing to see the fix while counting down the budget was the defect.
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
  public const SITE_GATES = ['rendered_check', 'config_clean'];

  /**
   * Constructs a GateRunner.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   Runs the gates that need only a checkout.
   * @param \Droost\Workflow\Gate\SiteDriverInterface $driver
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
   * @param \Droost\Workflow\State\RunState $state
   *   The run, carrying the resolved levers and the frozen phase map.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase being gated.
   * @param string $projectRoot
   *   The repository to run in.
   * @param callable(\Droost\Workflow\Gate\GateResult): void|null $onResult
   *   Called after each gate. The attach point for pair mode, which needs to
   *   act at a gate boundary without this class knowing anything about modes.
   *
   * @return \Droost\Workflow\Gate\PhaseReport
   *   Every due gate's outcome.
   */
  public function run(
    RunState $state,
    Phase $phase,
    string $projectRoot,
    ?callable $onResult = NULL,
  ): PhaseReport {
    $report = new PhaseReport($phase);
    $live = $this->liveTuning($projectRoot);

    foreach ($state->gatesDueFor($phase) as $name => $levers) {
      $waiver = $state->gateWaivers[$name] ?? NULL;
      if ($waiver !== NULL) {
        // Waived by the OPERATOR for this run (never an agent's move — the
        // waiver enters only through the CLI). The gate does not execute,
        // the status is visibly distinct from every kind of pass, and the
        // reason rides the result into the report.
        $result = new GateResult(
          $name,
          GateStatus::Waived,
          summary: sprintf('waived by the operator: %s', $waiver['reason']),
          skipReason: $waiver['reason'],
        );
      }
      else {
        [$levers, $drift] = $this->withLiveTuning($name, $levers, $live[$name] ?? NULL);
        $result = $this->runOne($name, $levers, $projectRoot);
        if ($drift !== []) {
          $result = new GateResult(
            $result->gate,
            $result->status,
            $result->exitCode,
            $result->durationMs,
            rtrim($result->summary, '. ') . sprintf(' [levers re-read at gate time: %s]', implode('; ', $drift)),
            $result->findings,
            $result->truncated,
            $result->skipReason,
            $result->invocation,
          );
        }
      }
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
   * @param \Droost\Workflow\State\RunState $state
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
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $gate
   *   The gate that failed.
   *
   * @return \Droost\Workflow\State\RunState
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
   * @return \Droost\Workflow\Gate\GateResult
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
   * The lever file's CURRENT tuning options per gate, or [] when unreadable.
   *
   * Only when a lever file exists at the root: a repo with no file resolved
   * its frozen levers from the built-in preset and has nothing to re-read. A
   * file that no longer parses leaves the frozen record in force — the run
   * must not stall on a half-edited YAML — and the gate summary says nothing,
   * because there is nothing coherent to compare against.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its currently resolved levers.
   */
  private function liveTuning(string $projectRoot): array {
    if (!is_file(rtrim($projectRoot, '/') . '/' . WorkflowConfig::FILENAME)) {
      return [];
    }
    try {
      return WorkflowConfig::load($projectRoot)->resolvedGates();
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Overlays the file's current tuning onto the frozen levers of one gate.
   *
   * `on` is never taken from the file — that is the frozen half. Custom gates
   * are left alone too: their `cmd` is the gate, not a tuning of it.
   *
   * @param string $name
   *   The gate.
   * @param array<string, int|string|bool> $frozen
   *   The levers recorded at begin.
   * @param array<string, int|string|bool>|null $current
   *   The file's levers for the same gate, or NULL when absent.
   *
   * @return array{array<string, int|string|bool>, list<string>}
   *   The levers to run with, and a human line per option that changed.
   */
  private function withLiveTuning(string $name, array $frozen, ?array $current): array {
    if ($current === NULL || GateSettings::isCustom($name)) {
      return [$frozen, []];
    }
    $drift = [];
    $levers = $frozen;
    foreach (GateSettings::optionNames($name) as $option) {
      $was = $frozen[$option] ?? NULL;
      $now = $current[$option] ?? NULL;
      if ($now === $was || !(is_int($now) || is_string($now))) {
        continue;
      }
      $levers[$option] = $now;
      $drift[] = sprintf('%s %s → %s', $option, $was === NULL ? '(unset)' : (string) $was, (string) $now);
    }
    return [$levers, $drift];
  }

  /**
   * Rebuilds a GateSettings from the levers recorded in run state.
   *
   * @param string $name
   *   The gate name.
   * @param array<string, int|string|bool> $levers
   *   The recorded levers.
   *
   * @return \Droost\Workflow\Config\GateSettings
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
