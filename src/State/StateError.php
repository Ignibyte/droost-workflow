<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

use Droost\Workflow\Support\DataError;

/**
 * Run state could not be read or written.
 *
 * Every read failure refuses to overwrite the file it could not understand.
 * A state file the tool cannot parse is still evidence of what a run was
 * doing, and a tool that reacts to confusion by clobbering the only record of
 * it destroys the thing an operator needs most.
 */
final class StateError extends \RuntimeException {

  /**
   * Constructs a StateError.
   *
   * @param string $path
   *   The state file's path, as shown to the operator.
   * @param string $problem
   *   The problem.
   * @param \Throwable|null $previous
   *   The underlying error, when there is one.
   */
  private function __construct(
    string $path,
    string $problem,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($path . ': ' . $problem, 0, $previous);
  }

  /**
   * There is no run to act on — the state file is absent, not corrupt.
   *
   * Distinct from corrupt() on purpose: a command that needs a run (declare
   * a browser, record a seeker, answer, swap) must tell an operator to START
   * one, not that their (nonexistent) file is unreadable and should be moved
   * aside. Conflating the two produced "unreadable run state (there is no
   * run in progress) — move it aside" for a file that was never there.
   *
   * @param string $path
   *   The state file's path, as shown to the operator.
   *
   * @return self
   *   The error.
   */
  public static function noRun(string $path): self {
    return new self(
      $path,
      'there is no run in progress — start one with /droost:workflow:start '
      . '(or vendor/bin/droost-workflow run) before this command applies',
    );
  }

  /**
   * The run has ended — its record is history, not something to act on.
   *
   * Distinct from noRun(): the file exists, but the run reached its terminal
   * gate (or spent its retry budget). Mutating verbs (declare a browser,
   * record a seeker, answer, swap) must refuse rather than silently rewrite
   * a closed record — the archived history would otherwise claim work was
   * verified by things that happened after it finished.
   *
   * @param string $path
   *   The state file's path, as shown to the operator.
   *
   * @return self
   *   The error.
   */
  public static function runEnded(string $path): self {
    return new self(
      $path,
      'this run has ended — its record is history. Clear it with reset '
      . '(drush droost:workflow:reset, or vendor/bin/droost-workflow reset), '
      . 'then start the next run',
    );
  }

  /**
   * A run is still in progress; clearing it must be said out loud.
   *
   * @param string $path
   *   The state file's path.
   * @param string $phase
   *   The phase the run is in.
   *
   * @return self
   *   The error.
   */
  public static function runInProgress(string $path, string $phase): self {
    return new self($path, sprintf(
      'a run is still in progress (phase "%s") — advance it, or abandon it '
      . 'deliberately with reset --force',
      $phase,
    ));
  }

  /**
   * The finished record could not be archived.
   *
   * Raised instead of pretending: a reset that says "cleared" while run.json
   * is still in place strands the next start with no visible cause.
   *
   * @param string $path
   *   The archive target path.
   * @param string $why
   *   What went wrong, in prose.
   *
   * @return self
   *   The error.
   */
  public static function archiveFailed(string $path, string $why): self {
    return new self($path, sprintf(
      'could not archive the run record (%s) — nothing was cleared',
      $why,
    ));
  }

  /**
   * The file is present but not valid state.
   *
   * @param string $path
   *   The state file's path.
   * @param string $why
   *   What went wrong, in prose.
   * @param \Throwable|null $previous
   *   The underlying error, when there is one.
   *
   * @return self
   *   The error.
   */
  public static function corrupt(
    string $path,
    string $why,
    ?\Throwable $previous = NULL,
  ): self {
    return new self($path, sprintf(
      'unreadable run state (%s) — refusing to overwrite it; move it aside '
      . 'to start a new run',
      $why,
    ), $previous);
  }

  /**
   * The file was written by a build that speaks a different schema.
   *
   * @param string $path
   *   The state file's path.
   * @param int $found
   *   The schema version found in the file.
   * @param int $supported
   *   The schema version this build reads.
   *
   * @return self
   *   The error.
   */
  public static function unsupportedVersion(
    string $path,
    int $found,
    int $supported,
  ): self {
    return new self($path, sprintf(
      'run state schema v%d is not supported (this build reads v%d) — '
      . 'refusing to overwrite it',
      $found,
      $supported,
    ));
  }

  /**
   * The state could not be written.
   *
   * @param string $path
   *   The state file's path.
   * @param string $why
   *   What went wrong, in prose.
   *
   * @return self
   *   The error.
   */
  public static function unwritable(string $path, string $why): self {
    return new self($path, sprintf('could not write run state (%s)', $why));
  }

  /**
   * A field had the wrong type.
   *
   * @param string $path
   *   The state file's path.
   * @param \Droost\Workflow\Support\DataError $error
   *   The reader's error, which names the field.
   *
   * @return self
   *   The error.
   */
  public static function fromData(string $path, DataError $error): self {
    return self::corrupt($path, $error->getMessage(), $error);
  }

}
