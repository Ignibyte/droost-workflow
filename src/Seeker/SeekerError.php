<?php

declare(strict_types=1);

namespace Droost\Workflow\Seeker;

/**
 * A seeker ledger could not be understood.
 *
 * The ledger is the checkpoint's evidence. A section the parser cannot read
 * is INCOMPLETE — it must never be recorded as clean, and it must never be
 * guessed at: the message says exactly what shape was expected so the agent
 * can re-emit it rather than argue with it.
 */
final class SeekerError extends \RuntimeException {

  /**
   * Constructs a SeekerError.
   *
   * @param string $problem
   *   The problem.
   */
  private function __construct(string $problem) {
    parent::__construct($problem);
  }

  /**
   * A ledger that does not carry what a ledger must carry.
   *
   * @param string $detail
   *   What was wrong.
   *
   * @return self
   *   The error.
   */
  public static function malformed(string $detail): self {
    return new self(sprintf(
      'seeker ledger: %s — a ledger is a "## Seeker Inspection" section '
      . 'holding either finding rows (| ID | Severity | Location | Finding '
      . '| Status |) or the literal "(no findings)" sentinel',
      $detail,
    ));
  }

}
