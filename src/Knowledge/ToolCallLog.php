<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\State\RunStateStore;

/**
 * The record of which droost tools a run actually called.
 *
 * One line of JSON per call, `<state dir>/tool-calls.jsonl`: the tool, its
 * outcome, when, and the run and phase open at the time. Arguments are never
 * recorded: they carry content (node bodies, config values, a credential a
 * careless caller passed), and this file travels with every evaluation. The
 * question it answers is "was this tool reached", which the id alone answers.
 *
 * It lived in droost until 2026-10-15, which a Drupal-free reader could not
 * reach: `plan_grounded` reads it, and a plan held only where Drupal boots is
 * a plan the standalone binary closed unasked (F-276). The droost command's
 * knowledge calls (`code:wiki`, `code:tree`, …) append here, with no Drupal.
 *
 * Deliberately append-only, outside the run record (a run may not be open,
 * and a ledger that needed one would miss the calls made while deciding
 * whether to open it), and mirrored into the evidence store when a run is
 * open. A row with no run was made before one opened, and belongs to the run
 * that opened next (F-6); ofRun() says which that is, since completing a run
 * leaves the file in place and only `reset` archives it (F-331).
 */
final class ToolCallLog {

  /**
   * The ledger's file name, in the state directory.
   */
  public const FILE = 'tool-calls.jsonl';

  /**
   * The ledger the project's state directory holds, absolute.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return string
   *   The path, whether or not the file exists yet.
   */
  public static function path(string $projectRoot): string {
    $root = rtrim($projectRoot, '/');

    return $root . '/' . RunStateStore::resolveStateDir($root) . '/' . self::FILE;
  }

  /**
   * Appends one call to the ledger.
   *
   * Never throws. A ledger that could break a tool call would be worse than
   * no ledger: the tools are the product, and this is bookkeeping about them.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param string $tool
   *   The tool id.
   * @param string $outcome
   *   'ok' or 'fail'. A refusal is still a call, and the most interesting
   *   kind: it is how a disarmed gate becomes visible after the fact.
   * @param array<string, mixed> $detail
   *   What the tool answered beyond the call, never its arguments; usually
   *   empty, and then the row has no `detail` key at all.
   */
  public static function append(string $projectRoot, string $tool, string $outcome, array $detail = []): void {
    $run = self::openRun($projectRoot);
    try {
      $path = self::path($projectRoot);
      $dir = dirname($path);
      if (!is_dir($dir) && !@mkdir($dir, 0755, TRUE) && !is_dir($dir)) {
        return;
      }
      $row = [
        'tool' => $tool,
        'outcome' => $outcome,
        'at' => date('c'),
        'run' => $run['id'],
        'phase' => $run['phase'],
      ];
      if ($detail !== []) {
        $row['detail'] = $detail;
      }
      $line = json_encode($row, JSON_UNESCAPED_SLASHES);
      if ($line !== FALSE) {
        @file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX);
      }
    }
    catch (\Throwable) {
      // Bookkeeping never breaks the thing it books.
    }
    // The file is the one ledger. The evidence recorder moves this run's rows
    // into the store when it records a phase (EvidenceRecorder); a second
    // writer here made the store hold calls twice and lose others, since the
    // importer counts what is already there (S4-6).
  }

  /**
   * Every call recorded, oldest first.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param string|null $runId
   *   A run to filter by. With one, rows made under ANOTHER run are skipped,
   *   and so are rows made under no run that belong to another (ofRun()).
   *
   * @return list<array{tool: string, outcome: string, at: string, run: string|null, phase: string|null}>
   *   The calls.
   */
  public static function read(string $projectRoot, ?string $runId = NULL): array {
    $root = rtrim($projectRoot, '/');
    $resolved = RunStateStore::resolveStateDir($root);
    $other = $resolved === RunStateStore::STATE_DIR ? RunStateStore::LEGACY_STATE_DIR : RunStateStore::STATE_DIR;
    foreach ([$resolved, $other] as $dir) {
      $path = $root . '/' . $dir . '/' . self::FILE;
      if (!is_file($path)) {
        continue;
      }
      $raw = @file_get_contents($path);
      if ($raw === FALSE) {
        continue;
      }
      $calls = [];
      foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '') {
          continue;
        }
        $decoded = json_decode($line, TRUE);
        if (!is_array($decoded) || !isset($decoded['tool']) || !is_string($decoded['tool'])) {
          continue;
        }
        $calls[] = [
          'tool' => $decoded['tool'],
          'outcome' => is_string($decoded['outcome'] ?? NULL) ? $decoded['outcome'] : 'ok',
          'at' => is_string($decoded['at'] ?? NULL) ? $decoded['at'] : '',
          'run' => is_string($decoded['run'] ?? NULL) ? $decoded['run'] : NULL,
          'phase' => is_string($decoded['phase'] ?? NULL) ? $decoded['phase'] : NULL,
        ];
      }
      return $runId === NULL
        ? $calls
        : array_map(static fn (int $i): array => $calls[$i], self::ofRun(array_column($calls, 'run'), $runId));
    }

    return [];
  }

  /**
   * The rows that are one run's: its own, and the run-less rows it inherits.
   *
   * A row made with no run open belongs to the run that opened NEXT, which is
   * when a plan grounds. Every run-less row since the last `reset` was read
   * as this run's, and completing a run does not rotate the file, so a
   * lookup made before an EARLIER run, or between two runs for another
   * purpose, counted as this plan's consult (a live run's, F-331). In file
   * order: run-less rows wait; a row of this run claims those waiting; a row
   * of another run means they were that run's, and drops them. Rows still
   * waiting at the end came after every run's rows, so they are the next
   * run's: this one only when it has not called anything itself yet (a run
   * whose rows they follow had ended when they were made).
   *
   * @param list<string|null> $runs
   *   Each row's run, oldest first.
   * @param string $runId
   *   The run.
   *
   * @return list<int>
   *   The positions of its rows, oldest first.
   */
  public static function ofRun(array $runs, string $runId): array {
    $mine = [];
    $waiting = [];
    foreach ($runs as $i => $run) {
      if ($run === NULL) {
        $waiting[] = $i;
      }
      elseif ($run === $runId) {
        array_push($mine, ...$waiting, ...[$i]);
        $waiting = [];
      }
      else {
        $waiting = [];
      }
    }

    return $mine === [] ? $waiting : $mine;
  }

  /**
   * The run open in this project right now, or its absence.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return array{id: string|null, phase: string|null}
   *   The run id and phase, NULL for none.
   */
  public static function openRun(string $projectRoot): array {
    $none = ['id' => NULL, 'phase' => NULL];
    $root = rtrim($projectRoot, '/');
    $record = @file_get_contents($root . '/' . RunStateStore::resolveStateDir($root) . '/' . RunStateStore::STATE_FILE);
    if ($record === FALSE) {
      return $none;
    }
    $decoded = json_decode($record, TRUE);
    $id = is_array($decoded) && is_string($decoded['run_id'] ?? NULL) && $decoded['run_id'] !== '' ? $decoded['run_id'] : NULL;
    if ($id === NULL) {
      return $none;
    }
    $phase = is_string($decoded['current_phase'] ?? NULL) ? $decoded['current_phase'] : NULL;

    return ['id' => $id, 'phase' => $phase];
  }

}
