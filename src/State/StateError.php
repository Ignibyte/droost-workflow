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
