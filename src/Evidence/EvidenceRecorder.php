<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\Knowledge\ToolCallLog;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;

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
        // Whether anything read this diff with intent. Recorded as a word, not
        // a bool, because '' (a run older than schema v7) is not 'off'.
        'seekers' => $state->seekers ? 'on' : 'off',
        'base_commit' => $state->baseCommit,
        // WHICH baseline subsidised the verdicts in §4. Frozen at begin, so a
        // baseline grown under a run disagrees with this and every consulting
        // gate catches it.
        'baseline_hash' => $state->baselineHash,
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
   * The file is written by ToolCallLog, from the `droost` command's own
   * knowledge calls. Each row
   * carries the run it was made under (or `null` for a call made with no run
   * open) and the phase. ONLY THIS RUN'S ROWS ARE TAKEN — the same rule
   * ingestGuardCalls() below has always applied, and the same reason: a
   * count watermark from zero on an append-only file attributed every earlier
   * run's calls to the new one. Measured on a live pair of rounds, the second
   * opened holding the first's 51 tool calls, and a drift check that reads
   * this ledger was satisfied by a previous round having called the tool
   * (F-6). Rows already ingested are then skipped by count WITHIN this run's
   * rows, so re-recording a phase does not double it, and `reset` archives
   * the file into history/ with the rest of the state.
   *
   * This is the store's only writer of these rows (S4-6): ToolCallLog
   * appends to the file and nowhere else, so counting what the store holds
   * for this run is counting what this reader already moved. A row's own
   * phase is kept when it has one; the phase being recorded fills in the
   * rest. A row's `run` is NULL when no run was open, and such a row is no
   * run's: a plan looks once its run is open (owner, 2026-10-20), and a
   * lookup made between runs for another purpose read as the next run's
   * (F-331). ToolCallLog::ofRun() says which rows are this run's.
   *
   * @param \Droost\Workflow\Evidence\EvidenceStore $store
   *   The store.
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase these calls are being attributed to.
   */
  private function ingestToolCalls(EvidenceStore $store, string $runId, string $phase): void {
    foreach ($this->stateDirs() as $dir) {
      $path = rtrim($this->projectRoot, '/') . '/' . $dir . '/tool-calls.jsonl';
      if (!is_file($path)) {
        continue;
      }
      $lines = array_values(array_filter(
        preg_split('/\R/', (string) @file_get_contents($path)) ?: [],
        static fn (string $line): bool => trim($line) !== '',
      ));
      $rows = [];
      foreach ($lines as $line) {
        $row = json_decode($line, TRUE);
        if (!is_array($row) || !is_string($row['tool'] ?? NULL)) {
          continue;
        }
        $rows[] = $row;
      }
      $mine = array_map(
        static fn (int $i): array => $rows[$i],
        ToolCallLog::ofRun(array_map(static fn (array $row): ?string => is_string($row['run'] ?? NULL) ? $row['run'] : NULL, $rows), $runId),
      );
      foreach (array_slice($mine, $store->toolCallCount($runId)) as $row) {
        $store->recordToolCall(
          $runId,
          is_string($row['phase'] ?? NULL) && $row['phase'] !== '' ? $row['phase'] : $phase,
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
    foreach ($this->stateDirs() as $dir) {
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
          // THE PHASE THE HOOK SAW, not the one closing now. `$phase` is the
          // phase being recorded, which is the phase that just ENDED, so
          // every row a phase ingested was filed under it regardless of when
          // the call happened — and a browser call made during code arrived
          // in the store looking like a test-phase one. The hook stamps its
          // own row from run.json since 0.9; `$phase` is the fallback for
          // rows written before that.
          is_string($row['phase'] ?? NULL) && $row['phase'] !== '' ? $row['phase'] : $phase,
          is_string($row['mode'] ?? NULL) && $row['mode'] !== '' ? $row['mode'] : 'unknown',
          is_string($row['verdict'] ?? NULL) && $row['verdict'] !== '' ? $row['verdict'] : 'invoked',
          is_string($row['rule'] ?? NULL) && $row['rule'] !== '' ? $row['rule'] : NULL,
          is_string($row['at'] ?? NULL) ? $row['at'] : NULL,
          is_string($row['tool'] ?? NULL) && $row['tool'] !== '' ? $row['tool'] : NULL,
          is_string($row['subagent'] ?? NULL) && $row['subagent'] !== '' ? $row['subagent'] : NULL,
        );
      }
      return;
    }
  }

  /**
   * The state directories to read a ledger from, the resolved one first.
   *
   * As ToolCallLog::read() resolves them, so the reader and this importer
   * never disagree about where the ledger is (S4-22).
   *
   * @return list<string>
   *   Project-relative directories.
   */
  private function stateDirs(): array {
    $resolved = RunStateStore::resolveStateDir(rtrim($this->projectRoot, '/'));

    return array_values(array_unique([$resolved, RunStateStore::STATE_DIR, RunStateStore::LEGACY_STATE_DIR]));
  }

}
