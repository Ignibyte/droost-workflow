<?php

declare(strict_types=1);

namespace Droost\Workflow\Spec;

/**
 * A run refused because the spec does not hold up its end.
 *
 * The spec is the run's living document: the acceptance criteria, the
 * grounding, the inspection ledgers and the realized capture all live in
 * it, and the phases are gated on the parts of it they depend on. These
 * refusals carry the remedy in the message, because the reader is an agent
 * mid-run and the next thing it does is whatever the message says.
 */
final class SpecError extends \RuntimeException {

  /**
   * No spec is declared and none can be adopted.
   *
   * @param string $dir
   *   The state directory that was searched.
   * @param int $candidates
   *   How many spec files were found there.
   *
   * @return self
   *   The error.
   */
  public static function unresolvable(string $dir, int $candidates): self {
    if ($candidates === 0) {
      return new self(sprintf(
        'no spec found under %s — the plan phase produces one before anything '
        . 'else happens. Write %s/spec-<slug>.md, then begin the run with '
        . '--spec=<path> so the run records which document governs it.',
        $dir,
        $dir,
      ));
    }
    return new self(sprintf(
      '%d spec files under %s and no --spec declared — the run cannot guess '
      . 'which document governs it. Begin (or re-run) with '
      . '--spec=<path to this run\'s spec>.',
      $candidates,
      $dir,
    ));
  }

  /**
   * The only candidate spec is one a finished run was already governed by.
   *
   * @param string $path
   *   The spec, project-relative.
   * @param string $dir
   *   The state directory.
   *
   * @return self
   *   The error.
   */
  public static function alreadyGoverned(string $path, string $dir): self {
    return new self(sprintf(
      '%s is the only spec under %s, and a run that has already finished was '
      . 'governed by it. Adopting it now would hold this ticket to the last '
      . 'ticket\'s acceptance criteria without saying so. Either write this '
      . 'ticket\'s spec (%s/spec-<slug>.md) and begin with --spec=<path>, or, '
      . 'if you really are re-running that same ticket, say so: --spec=%s.',
      $path,
      $dir,
      $dir,
      $path,
    ));
  }

  /**
   * The declared spec is not a readable file.
   *
   * @param string $path
   *   The declared path.
   *
   * @return self
   *   The error.
   */
  public static function missing(string $path): self {
    return new self(sprintf(
      'the declared spec %s does not exist or cannot be read. The spec is '
      . 'the run\'s governing document; fix the path or write the file.',
      $path,
    ));
  }

  /**
   * A different spec is already recorded against this run.
   *
   * @param string $recorded
   *   The path the run holds.
   * @param string $declared
   *   The conflicting path.
   *
   * @return self
   *   The error.
   */
  public static function conflict(string $recorded, string $declared): self {
    return new self(sprintf(
      'this run is governed by %s and --spec named %s. A run has ONE spec; '
      . 'to work under a different one, reset and begin a new run.',
      $recorded,
      $declared,
    ));
  }

  /**
   * A verification names a criterion nobody declared.
   *
   * The only refusal on the declare/verify surface, and the reason it is one:
   * a run that can verify unstated criteria proves whatever it happened to
   * do. Everything else about a declaration is a row, because a row cannot be
   * mis-shaped and a refusal in the middle of a phase costs a run.
   *
   * @param string $ref
   *   The ref the verification named.
   * @param list<string> $declared
   *   The refs this run has declared, for the reader to compare against.
   *
   * @return self
   *   The error.
   */
  public static function criterionNotDeclared(string $ref, array $declared): self {
    return new self(sprintf(
      'No criterion "%s" was declared in this run, so there is nothing for '
      . 'that verification to be about. %s Declare it first '
      . '(`declare-criterion %s "<what must be true>"`) and then verify it — '
      . 'in that order, because a criterion written to fit the thing that '
      . 'happened to pass is the one failure this record cannot survive.',
      $ref,
      $declared === []
        ? 'This run has declared none at all.'
        : 'Declared so far: ' . implode(', ', $declared) . '.',
      $ref,
    ));
  }

}
