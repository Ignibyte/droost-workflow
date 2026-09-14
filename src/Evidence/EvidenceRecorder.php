<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\RunState;

/**
 * Writes a phase's checklist to the evidence store.
 *
 * One collaborator, one call site, and it never throws outward. A run that
 * cannot write its evidence has still run its gates, and taking the run down
 * because the record failed would trade the work for the paperwork. The failure
 * is surfaced — `lastError()` — so the surface that cares can say the record is
 * incomplete, which is a better outcome than a silent gap nobody notices until
 * someone tries to evaluate the round.
 */
final class EvidenceRecorder {

  /**
   * What went wrong on the last write, or NULL.
   */
  private ?string $lastError = NULL;

  /**
   * Constructs an EvidenceRecorder.
   *
   * @param string $projectRoot
   *   The repository root.
   */
  public function __construct(private readonly string $projectRoot) {}

  /**
   * Records every gate of one phase as an adjudicated check.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run, for its id and its frozen gate levers.
   * @param string $phase
   *   The phase.
   * @param \Droost\Workflow\Gate\PhaseReport $report
   *   What the gates produced.
   * @param string|null $now
   *   The timestamp, ISO-8601.
   */
  public function recordPhase(RunState $state, string $phase, PhaseReport $report, ?string $now = NULL): void {
    $this->lastError = NULL;
    try {
      $store = new EvidenceStore($this->projectRoot);
      $store->upsertRun($state->runId, [
        'started_at' => $state->startedAt,
        'preset' => $state->preset,
        'mode' => $state->mode->value,
        'enforcement' => $state->enforcement->value,
        'base_commit' => $state->baseCommit,
        'spec_path' => $state->specPath,
      ]);
      foreach ($report->results as $result) {
        $levers = $state->resolvedGates[$result->gate] ?? [];
        $store->record(
          $state->runId,
          $phase,
          CheckRecord::fromGate(
            $result,
            SubjectHasher::hash($this->projectRoot, SubjectHasher::fromLever($levers['paths'] ?? NULL)),
            self::declaredFaults($levers),
            self::remedy($levers),
          ),
          $now,
        );
      }
      // The phase is written; fold the log back into the file so a copy taken
      // between phases is the whole record. See EvidenceStore::checkpoint().
      $store->checkpoint();
    }
    catch (\Throwable $e) {
      // Deliberately broad. PDO throws PDOException, the store throws
      // EvidenceError, and a corrupt database file can throw neither — none of
      // which is a reason to fail a phase whose gates have already run.
      $this->lastError = $e->getMessage();
    }
  }

  /**
   * What went wrong writing the last phase, or NULL when it was written.
   *
   * @return string|null
   *   The message.
   */
  public function lastError(): ?string {
    return $this->lastError;
  }

  /**
   * The exit-code-to-fault map a gate declared for itself.
   *
   * A crashed tool is genuinely ambiguous — phpstan dying on PHP the agent just
   * wrote is the agent's problem, snyk dying unauthenticated is not — and the
   * module that owns the gate is the only place that knows which. It declares
   * it once, in its own definition, rather than leaving every run to guess.
   *
   * @param array<string, mixed> $levers
   *   The gate's frozen settings.
   *
   * @return array<int|string, string>
   *   Exit code to fault name.
   */
  private static function declaredFaults(array $levers): array {
    $faults = $levers['faults'] ?? NULL;
    if (is_string($faults) && $faults !== '') {
      // Frozen through YAML and JSON, so it may arrive as a packed
      // "2:environment,1:agent" string rather than a map.
      $parsed = [];
      foreach (explode(',', $faults) as $pair) {
        $bits = explode(':', $pair, 2);
        if (count($bits) === 2 && trim($bits[0]) !== '') {
          $parsed[(int) trim($bits[0])] = trim($bits[1]);
        }
      }

      return $parsed;
    }

    if (!is_array($faults)) {
      return [];
    }
    $map = [];
    foreach ($faults as $exit => $fault) {
      if (is_scalar($fault)) {
        $map[(int) $exit] = (string) $fault;
      }
    }

    return $map;
  }

  /**
   * The command that clears an environment fault on this gate, if it named one.
   *
   * @param array<string, mixed> $levers
   *   The gate's frozen settings.
   *
   * @return string|null
   *   The remedy.
   */
  private static function remedy(array $levers): ?string {
    $remedy = $levers['remedy'] ?? NULL;

    return is_string($remedy) && trim($remedy) !== '' ? trim($remedy) : NULL;
  }

}
