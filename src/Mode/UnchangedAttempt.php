<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\State\RunStateStore;

/**
 * An attempt that changed nothing spends no retry (F-399, 2026-11-02).
 *
 * A retry is "the agent fixes the cause between invocations" (ModeEngine::
 * recordFailure()). On a supervised run an agent ran the code phase three times in
 * three minutes, each time with the same files and the same two failing
 * gates, and the run ended with a working build behind it: the budget had
 * been spent on attempts that could not have passed. The budget exists to
 * stop a loop that is not converging, and an attempt that repeats the last
 * one exactly is not a step of any loop: so it is answered with the same
 * failure and not counted.
 *
 * "Exactly" is two things together: the content of every file the run
 * changed (RunSubject, the digest the loop already uses), and what each
 * blocking gate said. A fix to a file changes the first; a fix made outside
 * the tree (a scan run through an MCP tool, a tool installed) changes the
 * second. When the run's changes cannot be measured (no VCS), nothing is
 * remembered and every attempt counts, as before.
 *
 * The record is a side file in the state directory, keyed by run and phase,
 * so a reset or a new run starts clean and run.json's shape is untouched.
 */
final class UnchangedAttempt {

  /**
   * The record, in the state directory.
   */
  public const FILE = 'attempt-fingerprints.json';

  /**
   * The digest of one failed attempt, or NULL when it cannot be measured.
   *
   * @param string|null $subject
   *   RunSubject's digest of the run's changed files, NULL when unknown.
   * @param list<\Droost\Workflow\Gate\GateResult> $blocking
   *   The gates that blocked.
   *
   * @return string|null
   *   The digest.
   */
  public static function fingerprint(?string $subject, array $blocking): ?string {
    if ($subject === NULL) {
      return NULL;
    }
    $said = array_map(
      static fn (GateResult $r): string => $r->gate . "\0" . $r->status->value . "\0" . $r->summary,
      $blocking,
    );
    sort($said);
    return hash('xxh128', $subject . "\0\0" . implode("\n", $said));
  }

  /**
   * Whether this attempt repeats the phase's last failed one exactly.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   * @param string $fingerprint
   *   This attempt's digest.
   *
   * @return bool
   *   TRUE when nothing changed since the last failed attempt.
   */
  public static function repeats(string $projectRoot, string $runId, string $phase, string $fingerprint): bool {
    return (self::read($projectRoot)[$runId][$phase] ?? NULL) === $fingerprint;
  }

  /**
   * Remembers a counted failed attempt.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase.
   * @param string $fingerprint
   *   The attempt's digest.
   */
  public static function remember(string $projectRoot, string $runId, string $phase, string $fingerprint): void {
    // Only this run's rows are kept: an earlier run's are history nobody reads.
    $rows = [$runId => (self::read($projectRoot)[$runId] ?? [])];
    $rows[$runId][$phase] = $fingerprint;
    $file = self::path($projectRoot);
    @mkdir(dirname($file), 0777, TRUE);
    @file_put_contents($file, (string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
  }

  /**
   * The row an envelope carries when an attempt was not counted.
   *
   * @return array{check: string, fault: string, why: string, remedy: string, guidance: string}
   *   The row, in the shape of the other `blocked` rows.
   */
  public static function row(): array {
    return [
      'check' => 'run',
      'fault' => 'none',
      'why' => 'nothing changed since the last failed attempt (the same files, and every blocking gate said the same), so this attempt was not counted against the retry budget',
      'remedy' => 'Read each failing gate in full (`droost workflow:status`), fix it, and run again; an attempt that changes something is counted as usual.',
      'guidance' => '',
    ];
  }

  /**
   * The record, or nothing.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, array<string, string>>
   *   Fingerprints by run and phase.
   */
  private static function read(string $projectRoot): array {
    $raw = @file_get_contents(self::path($projectRoot));
    $decoded = is_string($raw) ? json_decode($raw, TRUE) : NULL;
    $rows = [];
    // A hand-edited or half-written record is read for what is well formed in
    // it; anything else is dropped, so the worst case is one attempt counted.
    foreach (is_array($decoded) ? $decoded : [] as $run => $phases) {
      if (!is_string($run) || !is_array($phases)) {
        continue;
      }
      foreach ($phases as $phase => $fingerprint) {
        if (is_string($phase) && is_string($fingerprint)) {
          $rows[$run][$phase] = $fingerprint;
        }
      }
    }
    return $rows;
  }

  /**
   * Where the record lives.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return string
   *   The file.
   */
  private static function path(string $projectRoot): string {
    return rtrim($projectRoot, '/') . '/' . RunStateStore::resolveStateDir($projectRoot) . '/' . self::FILE;
  }

}
