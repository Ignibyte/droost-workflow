<?php

declare(strict_types=1);

namespace Droost\Workflow\Cli;

/**
 * The current or last run as a readable workchain: `droost workflow:report`.
 *
 * Every phase walked, every gate's verdict with the reason a non-pass
 * carries, the criteria against their proof, the seeker's verdict and its
 * history, a paused run's question, an active bypass. Printed from the run
 * record (the facade's status), never inferred.
 *
 * It lived in drupal/droost as `drush droost:workflow:report` until droost
 * left Drupal (2026-10-19), when it would have gone with the module: the
 * observability the workflow trigger doctrine calls first-class
 * (WORKFLOW-TRIGGER-AND-OBSERVABILITY §2) would have had no door at all. It
 * renders the same record the same way, in plain text.
 */
final class RunReport {

  /**
   * The report's lines.
   *
   * @param array<string, mixed> $status
   *   The facade's status (`run` is the run record, or NULL).
   * @param array<string, mixed> $bypass
   *   The facade's bypassState().
   *
   * @return list<string>
   *   The lines, in order.
   */
  public static function lines(array $status, array $bypass): array {
    $run = is_array($status['run'] ?? NULL) ? $status['run'] : NULL;
    if ($run === NULL || ($run['run_id'] ?? NULL) === NULL) {
      return ['No run recorded: no run.json in droost/droost-workflow/. Start one with /droost:workflow:start.'];
    }
    $lines = [];
    $lines[] = 'Workflow run ' . self::str($run['run_id'] ?? NULL);
    $lines[] = sprintf(
      '  started %s · effort %s · mode %s · browser %s · tasks %s',
      self::str($run['started_at'] ?? NULL),
      self::str($run['preset'] ?? NULL),
      self::str($run['effective_mode'] ?? NULL),
      self::str($run['browser'] ?? NULL, 'not declared'),
      self::str($run['tasks'] ?? NULL, 'not declared'),
    );
    $reports = is_array($run['gate_reports'] ?? NULL) ? $run['gate_reports'] : [];
    $retries = is_array($run['retries'] ?? NULL) ? $run['retries'] : [];
    foreach (is_array($run['phases'] ?? NULL) ? $run['phases'] : [] as $phase => $phaseStatus) {
      $state = self::str($phaseStatus);
      $mark = match ($state) {
        'passed' => '✓',
        'failed' => '✗',
        default => '•',
      };
      $lines[] = sprintf('  %s %s: %s', $mark, self::str($phase), $state);
      $phaseReport = is_array($reports[$phase] ?? NULL) ? $reports[$phase] : [];
      foreach (is_array($phaseReport['gates'] ?? NULL) ? $phaseReport['gates'] : [] as $gate) {
        if (is_array($gate)) {
          $lines[] = self::gateLine($gate, $retries);
        }
      }
    }
    if (is_array($run['criteria'] ?? NULL)) {
      $lines[] = self::criteriaLine($run['criteria']);
    }
    if (is_array($run['seeker'] ?? NULL)) {
      array_push($lines, ...self::seekerLines($run['seeker'], is_array($run['seeker_history'] ?? NULL) ? $run['seeker_history'] : []));
    }
    // A paused run's question is the one thing its human must see here.
    if (is_array($run['awaiting'] ?? NULL)) {
      $lines[] = '  ? ' . self::str($run['awaiting']['question'] ?? NULL);
      $lines[] = '  waiting — answer with: droost workflow:answer "<answer>"';
    }
    if (self::int($run['answered'] ?? 0) > 0) {
      $lines[] = sprintf('  check-ins answered: %d', self::int($run['answered'] ?? 0));
    }
    if (($bypass['active'] ?? FALSE) === TRUE) {
      $lines[] = '  • require_run bypass: ACTIVE — ' . self::str($bypass['reason'] ?? NULL, '');
    }

    return $lines;
  }

  /**
   * One gate's row: its verdict, why a non-pass is one, whose verdict it is.
   *
   * @param array<mixed> $gate
   *   The gate's report.
   * @param array<mixed> $retries
   *   The run's retry record.
   *
   * @return string
   *   The row.
   */
  private static function gateLine(array $gate, array $retries): string {
    $name = self::str($gate['gate'] ?? NULL);
    $status = self::str($gate['status'] ?? NULL);
    // An off gate names what turned it off, so "off" is never read as
    // "skipped"; anything else that is not a pass carries its own reason,
    // because the status word alone can state a falsehood the record does
    // not.
    $why = '';
    if ($status === 'off') {
      $why = self::str($gate['skip_reason'] ?? NULL, '');
    }
    elseif ($status !== 'passed') {
      $reason = self::str($gate['skip_reason'] ?? NULL, '');
      $why = $reason !== '' ? $reason : self::str($gate['summary'] ?? NULL, '');
    }
    else {
      // A pass prints bare, except what the record bracketed onto it: "[its
      // file … changed since this run began]", "[steered by config this run
      // changed …]". Dropping those printed a loosened gate's pass as a plain
      // pass, the falsehood by omission this report exists to prevent.
      preg_match_all('/\[[^\]]+\]/', self::str($gate['summary'] ?? NULL, ''), $notes);
      $why = implode(' ', $notes[0]);
    }
    // WHOSE VERDICT, AND FROM WHEN (F-21): a gate whose retry budget is spent
    // is never asked again, so what is printed is the last thing it said, not
    // a reading of the tree as it stands.
    $spent = '';
    if ($status !== 'passed' && $status !== 'off') {
      $left = is_array($retries['remaining'] ?? NULL) ? $retries['remaining'] : [];
      $tries = is_array($retries['attempts'] ?? NULL) ? $retries['attempts'] : [];
      if (array_key_exists($name, $left) && self::int($left[$name]) === 0) {
        $spent = sprintf(' [budget spent after %d attempt(s) — LAST VERDICT, not a re-measurement]', self::int($tries[$name] ?? 0) + 1);
      }
    }

    return sprintf('      %-14s %s%s%s', $name, $status, $why === '' ? '' : ' — ' . $why, $spent);
  }

  /**
   * Promise against proof: the criteria against what verified them.
   *
   * @param array<mixed> $criteria
   *   The run's criteria record.
   *
   * @return string
   *   The row.
   */
  private static function criteriaLine(array $criteria): string {
    $count = static fn (mixed $value): int => is_array($value) ? count($value) : 0;
    $unverified = [];
    foreach (is_array($criteria['unverified'] ?? NULL) ? $criteria['unverified'] : [] as $id) {
      if (is_scalar($id)) {
        $unverified[] = (string) $id;
      }
    }

    return sprintf(
      '  criteria: %d verified by a test, %d manual, %d unverified%s',
      $count($criteria['verified'] ?? NULL),
      $count($criteria['manual'] ?? NULL),
      count($unverified),
      $unverified === [] ? '' : sprintf(' (%s — fill "Verified By" before complete)', implode(', ', $unverified)),
    );
  }

  /**
   * The seeker's current verdict, and the arc behind it.
   *
   * @param array<mixed> $seeker
   *   The current inspection.
   * @param array<mixed> $history
   *   Every inspection.
   *
   * @return list<string>
   *   The rows.
   */
  private static function seekerLines(array $seeker, array $history): array {
    $observations = self::int($seeker['observations'] ?? 0);
    $lines = [sprintf(
      '  seeker: %s (critical %d, medium %d, low %d)%s%s',
      self::str($seeker['status'] ?? NULL),
      self::int($seeker['critical'] ?? 0),
      self::int($seeker['medium'] ?? 0),
      self::int($seeker['low'] ?? 0),
      $observations > 0 ? sprintf(' · %d out-of-diff observation(s) — see the spec ledger', $observations) : '',
      ($seeker['self_reviewed'] ?? FALSE) ? ' · SELF-REVIEWED, not independent' : '',
      ),
    ];
    // On a run that found something and fixed it the current inspection is
    // clean, so the line alone hides the catch: print the trail whenever more
    // than one inspection ran.
    if (count($history) > 1) {
      $lines[] = sprintf('    across %d inspection(s):', count($history));
      $i = 0;
      foreach ($history as $inspection) {
        $i++;
        if (!is_array($inspection)) {
          continue;
        }
        $found = self::int($inspection['critical'] ?? 0) + self::int($inspection['medium'] ?? 0) + self::int($inspection['low'] ?? 0);
        $lines[] = sprintf(
          '      %d. %s — %s%s',
          $i,
          self::str($inspection['status'] ?? NULL),
          $found === 0 ? 'no findings' : sprintf(
            'critical %d, medium %d, low %d',
            self::int($inspection['critical'] ?? 0),
            self::int($inspection['medium'] ?? 0),
            self::int($inspection['low'] ?? 0),
          ),
          ($inspection['self_reviewed'] ?? FALSE) ? ' (self-reviewed)' : '',
        );
      }
    }

    return $lines;
  }

  /**
   * A scalar as a string, or the default.
   */
  private static function str(mixed $value, string $default = '?'): string {
    return is_scalar($value) ? (string) $value : $default;
  }

  /**
   * A number as an int, or 0.
   */
  private static function int(mixed $value): int {
    return is_numeric($value) ? (int) $value : 0;
  }

}
