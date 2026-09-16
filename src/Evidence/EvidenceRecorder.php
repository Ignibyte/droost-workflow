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
      $this->ingestToolCalls($store, $state->runId, $phase);
      $this->ingestGuardCalls($store, $state->runId, $phase);
      $emitted = [];
      foreach ($report->results as $result) {
        $levers = $state->resolvedGates[$result->gate] ?? [];
        $store->record(
          $state->runId,
          $phase,
          CheckRecord::fromGate(
            $result,
            // The lever when the operator set one; otherwise what the executor
            // says it handed the tool. The lever alone left every gate on a
            // stock project unfingerprinted, and a green that cannot expire
            // is the failure the whole mechanism exists to prevent.
            SubjectHasher::hash(
              $this->projectRoot,
              SubjectHasher::fromLever($levers['paths'] ?? NULL) ?: $result->subjects,
            ),
            self::declaredFaults($levers),
            self::remedy($levers),
          ),
          $now,
        );
        $emitted[] = $result->gate;
      }
      // GATES TOO. Declarations and contributed checks were retired when a
      // pass stopped emitting them; gates were not, and a gate's blocked row
      // reaches the stop hook (`unresolved()` has no kind filter) while the
      // run envelope skips it (`blockingChecks()` does) — so a contributed
      // gate that blocked under drush and then vanished when the same phase
      // was re-run through the standalone binary held the turn for ever, with
      // no name, no reason and no way out, and the ceiling blind to it. Same
      // rule, third kind.
      $store->retireUnemitted($state->runId, $phase, 'gate', $emitted, $now);
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
   * Moves droost's tool-call ledger into the store, tagged with the phase.
   *
   * THE LEDGER WAS NEVER IN THE DATABASE. `EvidenceStore::recordToolCall()`
   * existed, the `tool_call` table existed, and nothing in production ever
   * called it — so §4a of every generated evaluation said "The ledger is
   * empty for this run. Not one droost tool was called", while
   * `droost/droost-workflow/tool-calls.jsonl` sat beside it holding the real
   * calls. Measured on a live round: twelve calls in the file, an empty table,
   * and a document reporting that the run had asked the codebase nothing.
   *
   * §4a's own text calls the ledger "the only place in the system that is not
   * the subject's own account", and the observability chain counts it as the
   * link that corroborates the spec's grounding claims. A link that is always
   * dark corroborates nothing.
   *
   * The file is written by the Drupal side (the MCP surface). Since 0.9 each
   * row carries the run it was made under (or `null` for a call made with no
   * run open); the recorder still supplies the phase, which the writer does
   * not know. ONLY THIS RUN'S ROWS ARE TAKEN — the same rule
   * ingestGuardCalls() below has always applied, and the same reason: a
   * count watermark from zero on an append-only file attributed every earlier
   * run's calls to the new one. Measured on a live pair of rounds, the second
   * opened holding the first's 51 tool calls, and a drift check that reads
   * this ledger was satisfied by a previous round having called the tool
   * (F-6). Rows already ingested are then skipped by count WITHIN this run's
   * rows, so re-recording a phase does not double it, and `reset` archives
   * the file into history/ with the rest of the state.
   *
   * A file written before 0.9 has no `run` key on any row and cannot be
   * attributed; it is taken whole, as before, so a site mid-upgrade keeps its
   * ledger. In the new format a row's `run` is NULL when no run was open —
   * and those rows are THIS run's too. The plan phase grounds, writes the
   * spec and only then opens the run, so every knowledge call that grounding
   * makes lands before run.json exists; skipping them would report the run as
   * having asked the codebase nothing, at the phase where it asked most.
   * `reset` archives the file, so a NULL row can only belong to the run that
   * opens next. Rows naming ANOTHER run are the ones excluded.
   *
   * @param \Droost\Workflow\Evidence\EvidenceStore $store
   *   The store.
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase these calls are being attributed to.
   */
  private function ingestToolCalls(EvidenceStore $store, string $runId, string $phase): void {
    foreach (['droost/droost-workflow', '.droost-workflow'] as $dir) {
      $path = rtrim($this->projectRoot, '/') . '/' . $dir . '/tool-calls.jsonl';
      if (!is_file($path)) {
        continue;
      }
      $lines = array_values(array_filter(
        preg_split('/\R/', (string) @file_get_contents($path)) ?: [],
        static fn (string $line): bool => trim($line) !== '',
      ));
      $rows = [];
      $attributed = FALSE;
      foreach ($lines as $line) {
        $row = json_decode($line, TRUE);
        if (!is_array($row) || !is_string($row['tool'] ?? NULL)) {
          continue;
        }
        if (array_key_exists('run', $row)) {
          $attributed = TRUE;
        }
        $rows[] = $row;
      }
      $mine = $attributed
        ? array_values(array_filter($rows, static fn (array $row): bool => in_array($row['run'] ?? NULL, [$runId, NULL], TRUE)))
        : $rows;
      foreach (array_slice($mine, $store->toolCallCount($runId)) as $row) {
        $store->recordToolCall(
          $runId,
          $phase,
          $row['tool'],
          is_string($row['outcome'] ?? NULL) ? $row['outcome'] : 'unknown',
          is_string($row['at'] ?? NULL) ? $row['at'] : NULL,
        );
      }
      return;
    }
  }

  /**
   * Moves the guard's append-only invocation ledger into the store.
   *
   * THE ANSWER TO "DID ENFORCEMENT HOLD", which no column could give before.
   * `run.json` carries the level somebody REQUESTED and the status document
   * infers `effective` from the host the session DECLARED — so a run could
   * report `hard`, pass every phase and render a clean evaluation with the
   * hook never invoked once, and nothing would contradict it. Every
   * evaluation to date lists this as a blind spot, and names it the largest.
   *
   * The guard writes the ledger itself, because it is the only thing that
   * knows it ran; it stays a strictly read-only READER of this database (see
   * its `unresolved_checks()`), which is why this is a jsonl ingest rather
   * than a write from the hook. Same shape as the tool-call ledger above, for
   * the same reason.
   *
   * @param \Droost\Workflow\Evidence\EvidenceStore $store
   *   The store.
   * @param string $runId
   *   The run. Lines carrying any other run — the guard fires outside runs
   *   too, which is the require_run wall's whole job — are not this run's and
   *   are left where they are.
   * @param string $phase
   *   The phase to attribute them to; the ledger cannot know it.
   */
  private function ingestGuardCalls(EvidenceStore $store, string $runId, string $phase): void {
    foreach (['droost/droost-workflow', '.droost-workflow'] as $dir) {
      $path = rtrim($this->projectRoot, '/') . '/' . $dir . '/guard-calls.jsonl';
      if (!is_file($path)) {
        continue;
      }
      $lines = array_values(array_filter(
        preg_split('/\R/', (string) @file_get_contents($path)) ?: [],
        static fn (string $line): bool => trim($line) !== '',
      ));
      // ONLY THIS RUN'S. The guard fires outside runs too — that is the whole
      // point of the require_run wall — and those lines carry a null run, or a
      // previous one. Attributing them here would credit this run with a wall
      // that fired before it existed, which is the sort of number that makes a
      // record worth less than none.
      $mine = [];
      foreach ($lines as $line) {
        $row = json_decode($line, TRUE);
        if (is_array($row) && ($row['run'] ?? NULL) === $runId) {
          $mine[] = $row;
        }
      }
      foreach (array_slice($mine, $store->guardCallCount($runId)) as $row) {
        $store->recordGuardCall(
          $runId,
          $phase,
          is_string($row['mode'] ?? NULL) && $row['mode'] !== '' ? $row['mode'] : 'unknown',
          is_string($row['verdict'] ?? NULL) && $row['verdict'] !== '' ? $row['verdict'] : 'invoked',
          is_string($row['rule'] ?? NULL) && $row['rule'] !== '' ? $row['rule'] : NULL,
          is_string($row['at'] ?? NULL) ? $row['at'] : NULL,
        );
      }
      return;
    }
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
