<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

use Droost\Workflow\Evidence\SubjectHasher;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;

/**
 * What the fast flow's loop measures: moved code, and failures out of scope.
 *
 * In the strict flow complete re-runs every gate, so an edit made after code
 * is measured before the run ends. The fast flow's complete runs none, and
 * test runs only the browser, so an edit made at test or at complete would
 * stand under code's green unmeasured: a green that could not have failed.
 * The fingerprint of what code's gates measured, taken each time gates run,
 * is what says the code moved, and a moved subject sends the run back to
 * code rather than letting the green stand.
 */
final class TestLoop {

  /**
   * The subject code's gates measure: every changed file but these.
   *
   * Markdown is the documentation complete writes (the wiki, the evidence),
   * and `droost/` is the workflow's own output (the record, parity's
   * captures, the tickets). Neither is code a later phase must re-measure.
   */
  public const CODE = 'code';

  /**
   * The subject test's gates measure: code's, and the browser specs.
   */
  public const TEST = 'test';

  /**
   * The fingerprint of what a phase's gates measure, or NULL when unknown.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string>|null $changed
   *   The files the run changed, or NULL when the repository cannot say.
   * @param string $which
   *   self::CODE (no browser specs) or self::TEST (with them).
   *
   * @return string|null
   *   A digest of the paths and their content, or NULL.
   */
  public static function subject(string $projectRoot, ?array $changed, string $which): ?string {
    if ($changed === NULL) {
      return NULL;
    }
    $paths = self::measured($changed, $which);
    sort($paths);
    $existing = array_values(array_filter(
      $paths,
      static fn (string $path): bool => file_exists(rtrim($projectRoot, '/') . '/' . $path),
    ));

    // The paths as well as their content: a deleted file changes the list and
    // hashes to nothing.
    return hash('xxh128', implode("\n", $paths) . "\0" . (SubjectHasher::hash($projectRoot, $existing) ?? ''));
  }

  /**
   * The changed files a subject covers.
   *
   * @param list<string> $changed
   *   The files the run changed.
   * @param string $which
   *   self::CODE or self::TEST.
   *
   * @return list<string>
   *   The files, normalised.
   */
  public static function measured(array $changed, string $which): array {
    $out = [];
    foreach ($changed as $path) {
      $path = ltrim(str_replace('\\', '/', trim($path)), '/');
      if ($path === '' || str_starts_with($path, 'droost/') || preg_match('/\.md$/i', $path) === 1) {
        continue;
      }
      if ($which === self::CODE && preg_match(ShellGateExecutor::SPEC_FILE, $path) === 1) {
        continue;
      }
      $out[] = $path;
    }

    return array_values(array_unique($out));
  }

  /**
   * Whether a failed gate's failures all lie outside the ticket.
   *
   * Only a browser suite run at full scope can fail outside the ticket: at
   * ticket scope it ran the ticket's own specs and nothing else. A failure
   * whose spec the run did not change is a lower rung's, and becomes a
   * follow-up rather than a loop (owner, 2026-09-29). A failure that names no
   * spec cannot be placed, and is the ticket's.
   *
   * @param \Droost\Workflow\Gate\GateResult $result
   *   The gate's result.
   * @param array<string, mixed> $levers
   *   The gate's frozen levers.
   * @param list<string>|null $changed
   *   The files the run changed, or NULL when unknown.
   *
   * @return bool
   *   TRUE when every failure names a spec the run did not change.
   */
  public static function outOfScope(GateResult $result, array $levers, ?array $changed): bool {
    if ($result->gate !== 'playwright'
      || $result->status !== GateStatus::Failed
      || ($levers['scope'] ?? 'full') === 'ticket'
      || $changed === NULL) {
      return FALSE;
    }
    $failing = self::failingSpecs($result);
    if ($failing === []) {
      return FALSE;
    }
    foreach ($failing as $spec) {
      foreach ($changed as $path) {
        $path = ltrim(str_replace('\\', '/', $path), '/');
        // The runner prints a spec relative to its config, which may sit
        // below the project root (a gate's `root`).
        if ($path === $spec || str_ends_with($path, '/' . $spec)) {
          return FALSE;
        }
      }
    }

    return TRUE;
  }

  /**
   * The spec files a failed browser result names.
   *
   * @param \Droost\Workflow\Gate\GateResult $result
   *   The result.
   *
   * @return list<string>
   *   The files, as the runner printed them.
   */
  public static function failingSpecs(GateResult $result): array {
    $specs = [];
    foreach ($result->findings as $finding) {
      $file = $finding['file'] ?? NULL;
      if (is_string($file) && $file !== '' && ($finding['detail'] ?? NULL) === 'failed') {
        $specs[] = ltrim(str_replace('\\', '/', $file), '/');
      }
    }

    return array_values(array_unique($specs));
  }

}
