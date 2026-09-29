<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;

/**
 * Where a failure at test lies: in the ticket, or in earlier work (0.11).
 *
 * The fast flow's loop sends a failure in the ticket back to code and writes
 * one in earlier work up as a follow-up. What code's gates measured, and
 * whether it moved, is `Evidence\RunSubject`.
 */
final class TestLoop {

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
