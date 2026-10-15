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
   * Private so every instance goes through a named constructor — the
   * prefix and the format reminder are applied here, once.
   *
   * @param string $problem
   *   The problem.
   * @param bool $aboutTheFormat
   *   Whether the format reminder applies (not when the ledger is well formed
   *   and refused for another reason).
   */
  private function __construct(string $problem, bool $aboutTheFormat = TRUE) {
    if (!$aboutTheFormat) {
      parent::__construct('seeker ledger: ' . $problem);
      return;
    }
    parent::__construct(sprintf(
      'seeker ledger: %s — a ledger is a "## Seeker Inspection" section '
      . 'holding either finding rows (| ID | Severity | Location | Finding '
      . '| Status |) or the literal "(no findings)" sentinel',
      $problem,
    ));
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
    return new self($detail);
  }

  /**
   * A ledger labelled independent with no seeker run behind it.
   *
   * A client's first observed run: the main agent appended a second ledger itself, labelled it
   * "Inspector: independent" and marked every finding resolved, and the
   * checkpoint took it as clean. The guard records each subagent that
   * returns (PostToolUse on Agent and Task), so a ledger that says it is
   * independent is filed only after a `workflow-seeker` has returned since
   * the last one.
   *
   * @param string $since
   *   When the last ledger was filed, or the run began.
   *
   * @return self
   *   The error.
   */
  public static function notDispatched(string $since): self {
    return new self(sprintf(
      'no workflow-seeker has returned since %s, so this ledger is not a '
      . 'seeker\'s. Dispatch the workflow-seeker agent on the current diff '
      . 'and file the ledger it writes, verbatim. A ledger the main agent '
      . 'writes (a status update after fixes) is a self-review: label it '
      . '"Inspector: self-reviewed". It is recorded as one, counts as no '
      . 'inspection, and releases no finding seekers.holds holds the run on',
      $since,
    ), FALSE);
  }

}
