<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * The evidence store could not be opened or written.
 *
 * Thrown rather than swallowed, and then caught at the one call site that
 * writes evidence — because losing the record of what a run measured must be
 * loud, and must not take the run down with it. A phase that cannot store its
 * evidence has still run its gates; the operator needs to know the record is
 * incomplete, not to lose the work.
 */
final class EvidenceError extends \RuntimeException {

  /**
   * The database file or its directory cannot be written.
   *
   * @param string $path
   *   The path that could not be opened.
   * @param string $why
   *   What the filesystem or driver said.
   *
   * @return self
   *   The error.
   */
  public static function unwritable(string $path, string $why): self {
    return new self(sprintf(
      'The evidence store at %s could not be opened: %s. The run still works — '
      . 'gates run and the phase record is kept in run.json — but nothing is '
      . 'recording what each gate measured, so this run cannot be evaluated '
      . 'afterwards. Check the permissions on the state directory.',
      $path,
      $why,
    ));
  }

  /**
   * The store is newer than this build knows how to write.
   *
   * @param int $found
   *   The schema version on disk.
   * @param int $known
   *   The schema version this build writes.
   *
   * @return self
   *   The error.
   */
  public static function fromTheFuture(int $found, int $known): self {
    return new self(sprintf(
      'The evidence store is at schema %d and this droost writes %d. It was '
      . 'written by a newer droost; upgrade rather than downgrading the store, '
      . 'which would drop columns holding a previous run\'s evidence.',
      $found,
      $known,
    ));
  }

}
