<?php

declare(strict_types=1);

namespace Droost\Workflow\Intake;

/**
 * An intake could not be opened, read, audited, answered or closed.
 *
 * Every message names what to do next, because the reader is an agent or an
 * operator at a command line, and the CLI prints it as it stands (exit 2).
 */
final class IntakeError extends \RuntimeException {

  /**
   * An intake verb that needs an open intake found none.
   *
   * @return self
   *   The error.
   */
  public static function noneOpen(): self {
    return new self('no intake is open: `droost-workflow intake start --request="<the human\'s words>" --source=<path or URL>` opens one.');
  }

  /**
   * An intake is open already.
   *
   * @param string $id
   *   The open intake.
   *
   * @return self
   *   The error.
   */
  public static function alreadyOpen(string $id): self {
    return new self(sprintf('intake %s is open: finish it (`intake check`, then the operator approves it) or have the operator abandon it (`intake abandon "<reason>"`) before opening another.', $id));
  }

  /**
   * A run is open, and an intake plans the runs that come after it.
   *
   * @param string $runId
   *   The open run.
   *
   * @return self
   *   The error.
   */
  public static function runOpen(string $runId): self {
    return new self(sprintf('run %s is open: an intake plans a site before its runs, so finish or reset the run first.', $runId));
  }

  /**
   * A run was asked for while an intake is open.
   *
   * @param string $id
   *   The open intake.
   *
   * @return self
   *   The error.
   */
  public static function buildBeforeApproval(string $id): self {
    return new self(sprintf(
      'intake %s is open and not approved, and nothing builds until the human approves the roadmap: '
      . 'finish the intake (`droost-workflow intake check` says what is left), ask the human to '
      . 'approve the roadmap, and on their yes run `droost-workflow intake approve`. To build '
      . 'without it, the operator abandons it (`intake abandon "<reason>"`).',
      $id,
    ));
  }

  /**
   * The agent asked to approve with no yes from the human on record.
   *
   * @param string|null $answer
   *   The human's latest answer to the approval question, or NULL for none.
   *
   * @return self
   *   The error.
   */
  public static function noYes(?string $answer): self {
    return new self($answer === NULL
      ? 'no yes is on record: ask the human "Approve this roadmap?" (Approve, Not yet) through AskUserQuestion or `intake ask`, and approve only on their yes. An operator at a terminal records their own: `intake answer "Approve this roadmap?" "Approve"`.'
      : sprintf('the human answered "%s" to "Approve this roadmap?", which is not a yes: change what they asked for, ask again, and approve only on their yes.', $answer));
  }

  /**
   * The approval question was asked again and is not answered yet.
   *
   * @return self
   *   The error.
   */
  public static function approvalPending(): self {
    return new self('"Approve this roadmap?" is pending: the human has not answered it since it was last asked. Wait for their answer.');
  }

  /**
   * A verb was given what it cannot use.
   *
   * @param string $usage
   *   The verb's usage line.
   *
   * @return self
   *   The error.
   */
  public static function usage(string $usage): self {
    return new self($usage);
  }

  /**
   * Approval was asked for while the intake's checks fail.
   *
   * @param list<\Droost\Workflow\Intake\IntakeFinding> $findings
   *   What fails.
   *
   * @return self
   *   The error.
   */
  public static function notReady(array $findings): self {
    $lines = array_map(static fn (IntakeFinding $f): string => sprintf('  - [%s] %s', $f->check, $f->message), $findings);
    return new self(sprintf("the intake is not ready to approve; %d finding(s):\n%s", count($findings), implode("\n", $lines)));
  }

  /**
   * The audit could not be run.
   *
   * @param string $why
   *   What went wrong.
   *
   * @return self
   *   The error.
   */
  public static function auditFailed(string $why): self {
    return new self(sprintf('the source audit did not run: %s.', $why));
  }

  /**
   * The intake's state file could not be read or written.
   *
   * @param string $path
   *   The file.
   * @param string $why
   *   What went wrong.
   *
   * @return self
   *   The error.
   */
  public static function stateUnreadable(string $path, string $why): self {
    return new self(sprintf('%s: %s.', $path, $why));
  }

}
