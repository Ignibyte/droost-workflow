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
 * It lived in drupal/droost, behind a drush command, until droost left
 * Drupal (2026-10-19), when it would have gone with the module: the
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
   * @param array{tools?: array<string, array{calls: int, failed: int}>, raw?: array<int, string>} $site
   *   What the run did to the site from the shell (SiteCalls): the droost
   *   module's tool calls by tool, and the raw drush php and SQL calls.
   *
   * @return list<string>
   *   The lines, in order.
   */
  public static function lines(array $status, array $bypass, array $site = []): array {
    $run = is_array($status['run'] ?? NULL) ? $status['run'] : NULL;
    if ($run === NULL || ($run['run_id'] ?? NULL) === NULL) {
      return ['No run recorded: no run.json in droost/droost-workflow/. Start one with /droost:workflow:start.'];
    }
    $lines = [];
    $lines[] = 'Workflow run ' . self::str($run['run_id'] ?? NULL);
    $lines[] = sprintf(
      '  started %s · effort %s · mode %s%s · browser %s · tasks %s',
      self::str($run['started_at'] ?? NULL),
      self::str($run['preset'] ?? NULL),
      self::str($run['effective_mode'] ?? NULL),
      is_string($run['lead'] ?? NULL) ? ' · lead ' . $run['lead'] : '',
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
    $history = is_array($run['seeker_history'] ?? NULL) ? array_values($run['seeker_history']) : [];
    $latest = $history === [] ? NULL : $history[count($history) - 1];
    if (is_array($run['seeker'] ?? NULL)) {
      array_push($lines, ...self::seekerLines($run['seeker'], $history));
    }
    elseif (is_array($latest)) {
      // The current verdict is cleared at every advance, because the next
      // phase has not been inspected, so a finished run has none; and this
      // printed nothing, hiding a live run's two inspections and the defect
      // the first one caught. The trail is the record (F-329).
      $at = self::str($latest['phase'] ?? NULL, '');
      array_push($lines, ...self::seekerLines($latest, $history, $at === '' ? 'latest inspection' : 'latest inspection, at ' . $at));
    }
    // A paused run's question is the one thing its human must see here.
    if (is_array($run['awaiting'] ?? NULL)) {
      $lines[] = '  ? ' . self::str($run['awaiting']['question'] ?? NULL);
      $lines[] = '  waiting — answer with: droost workflow:answer "<answer>"';
    }
    $released = self::int($run['released'] ?? 0);
    $byAgent = self::int($run['answered_by_agent'] ?? 0);
    $byQuestion = self::int($run['answered_by_question'] ?? 0);
    if (self::int($run['answered'] ?? 0) > 0 || $released > 0) {
      $lines[] = sprintf(
        '  check-ins answered: %d%s%s%s',
        self::int($run['answered'] ?? 0),
        $byAgent > 0 ? sprintf(' · %d by the agent, not from the operator\'s terminal', $byAgent) : '',
        $byQuestion > 0 ? sprintf(' · %d by the operator, answering the agent\'s question in the session', $byQuestion) : '',
        $released > 0 ? sprintf(' · %d released unanswered (a later step went on before anyone answered)', $released) : '',
      );
    }
    array_push($lines, ...self::siteLines($site['tools'] ?? [], $site['raw'] ?? []));
    if (($bypass['active'] ?? FALSE) === TRUE) {
      $lines[] = '  • require_run bypass: ACTIVE — ' . self::str($bypass['reason'] ?? NULL, '');
    }

    return $lines;
  }

  /**
   * The run's site tool calls and raw calls, when it made any.
   *
   * A tool call is recorded and held to the run; a raw `drush php:eval` or
   * SQL call is neither, so the report names each verb and how often.
   *
   * @param array<string, array{calls: int, failed: int}> $tools
   *   Tool id => its calls.
   * @param array<int, string> $raw
   *   Each raw call's drush verb.
   *
   * @return list<string>
   *   No line, one or two.
   */
  private static function siteLines(array $tools, array $raw): array {
    $lines = [];
    if ($tools !== []) {
      $total = array_sum(array_column($tools, 'calls'));
      $failed = array_sum(array_column($tools, 'failed'));
      $each = [];
      foreach ($tools as $tool => $tally) {
        $each[] = sprintf('%s %d', $tool, $tally['calls']);
      }
      $lines[] = sprintf(
        '  site tools: %d call(s)%s — %s',
        $total,
        $failed > 0 ? sprintf(', %d failed or refused', $failed) : '',
        implode(', ', $each),
      );
    }
    if ($raw !== []) {
      $verbs = array_count_values($raw);
      ksort($verbs);
      $each = [];
      foreach ($verbs as $verb => $count) {
        $each[] = sprintf('drush %s ×%d', $verb, $count);
      }
      $lines[] = sprintf('  raw site calls: %d — %s (no tool recorded what they changed)', count($raw), implode(', ', $each));
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
      // A result built off without a skip reason still has its summary, and
      // a bare `off` is the one thing this row must never print (F-330).
      $why = self::str($gate['skip_reason'] ?? NULL, '');
      $why = $why !== '' ? $why : self::str($gate['summary'] ?? NULL, '');
    }
    elseif ($status !== 'passed') {
      $reason = self::str($gate['skip_reason'] ?? NULL, '');
      $why = $reason !== '' ? $reason : self::str($gate['summary'] ?? NULL, '');
    }
    elseif (($gate['labelled'] ?? FALSE) === TRUE) {
      // A labelled pass examined nothing: say so in the row, as the phase's
      // tally already counts it (F-326).
      $why = 'labelled: it ran and examined nothing — ' . self::str($gate['summary'] ?? NULL, '');
    }
    else {
      // A pass prints bare, except what droost bracketed onto it: "[its file …
      // changed since this run began]", "[steered by config this run changed
      // …]", "[the run … recorded …]". Dropping those printed a loosened
      // gate's pass as a plain pass, the falsehood by omission this report
      // exists to prevent. Only droost's own notes: a tool's log tag (drush's
      // "[notice]") is not one (F-320).
      preg_match_all('/\[(?:its file|steered by|the run )[^\]]*\]/', self::str($gate['summary'] ?? NULL, ''), $notes);
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
   * @param string $which
   *   Which inspection `$seeker` is, when it is not the current verdict.
   *
   * @return list<string>
   *   The rows.
   */
  private static function seekerLines(array $seeker, array $history, string $which = ''): array {
    $observations = self::int($seeker['observations'] ?? 0);
    $open = array_key_exists('open', $seeker) ? sprintf(', %d still open', self::int($seeker['open'])) : '';
    $lines = [sprintf(
      '  seeker%s: %s (critical %d, high %d, medium %d, low %d found%s)%s%s',
      $which === '' ? '' : ' (' . $which . ')',
      self::str($seeker['status'] ?? NULL),
      self::int($seeker['critical'] ?? 0),
      self::int($seeker['high'] ?? 0),
      self::int($seeker['medium'] ?? 0),
      self::int($seeker['low'] ?? 0),
      $open,
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
        // HIGH is counted and printed (F-362): left out, three HIGH findings
        // read as none, and an inspection of HIGHs alone as "no findings".
        $found = self::int($inspection['critical'] ?? 0) + self::int($inspection['high'] ?? 0) + self::int($inspection['medium'] ?? 0) + self::int($inspection['low'] ?? 0);
        $lines[] = sprintf(
          '      %d. %s — %s%s',
          $i,
          self::str($inspection['status'] ?? NULL),
          $found === 0 ? 'no findings' : sprintf(
            'critical %d, high %d, medium %d, low %d',
            self::int($inspection['critical'] ?? 0),
            self::int($inspection['high'] ?? 0),
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
