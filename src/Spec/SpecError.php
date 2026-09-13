<?php

declare(strict_types=1);

namespace Droost\Workflow\Spec;

/**
 * A run refused because the spec does not hold up its end.
 *
 * The spec is the run's living document: the acceptance criteria, the
 * tooling plan, the inspection ledgers and the realized capture all live in
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
   * A required section is absent at the phase that depends on it.
   *
   * @param string $path
   *   The spec.
   * @param string $heading
   *   The required heading.
   * @param string $why
   *   One sentence on what the section buys, ending with the remedy.
   *
   * @return self
   *   The error.
   */
  public static function sectionMissing(
    string $path,
    string $heading,
    string $why,
  ): self {
    return new self(sprintf('%s has no "%s" section — %s', $path, $heading, $why));
  }

  /**
   * The phase looked nothing up, or looked into too narrow a place.
   *
   * @param string $path
   *   The spec.
   * @param string $phase
   *   The phase that must ground.
   * @param list<string> $missingTiers
   *   Tiers this phase never reached.
   * @param bool $sectionMissing
   *   TRUE when there is no grounding table at all.
   * @param list<string> $unanswered
   *   Rows claiming a lookup with no answer recorded.
   *
   * @return self
   *   The error.
   */
  public static function groundingMissing(
    string $path,
    string $phase,
    array $missingTiers,
    bool $sectionMissing,
    array $unanswered,
  ): self {
    if ($sectionMissing) {
      return new self(sprintf(
        '%s has no "%s" section. The %s phase may not end until it records what it LOOKED UP before it proposed: one row per lookup, naming the phase, the tier (%s), what was asked and what came back. Grounding used to be advice while routing was a contract, and the numbers followed the contract — the build-surface router was called 179 times across 39 rounds while the codebase knowledge behind it was called six. A lookup that produces no row is a lookup nobody can tell you made.',
        $path,
        SpecContract::GROUNDING_HEADING,
        $phase,
        implode(' / ', SpecContract::TIERS),
      ));
    }
    if ($unanswered !== []) {
      return new self(sprintf(
        '%s records grounding rows with no answer: %s. A row whose "Found" cell is empty is a claim to have looked, not a lookup. Write what came back — including "nothing matched", which is a real and useful answer.',
        $path,
        implode(', ', $unanswered),
      ));
    }

    return new self(sprintf(
      'The %s phase grounded, but never reached: %s. Each tier answers a different question and they are not interchangeable — custom is what THIS site already does, contrib is what its installed modules already give you, core is what Drupal expects. The expensive mistakes in this phase are a custom miss (building what exists under another name) and a contrib miss (reimplementing what a module already offers); neither is caught by knowing core well. Consult the missing tier and add its row, or state in the row why it does not apply here.',
      $phase,
      implode(', ', $missingTiers),
    ));
  }

  /**
   * Acceptance criteria whose "Verified By" cell is empty at complete.
   *
   * @param string $path
   *   The spec, project-relative.
   * @param list<string> $ids
   *   The criterion ids without a verification.
   * @param bool $columnMissing
   *   Whether the table has no such column at all.
   *
   * @return self
   *   The error, carrying the remedy.
   */
  public static function criteriaUnverified(string $path, array $ids, bool $columnMissing): self {
    return new self(sprintf(
      '%s: %d acceptance criteri%s without a "%s" entry (%s) — %s. Fill it at the test phase with the test that proves each row (the PHPUnit method or class, or the Playwright spec), or `manual — <reason>` for a criterion no test can prove; the report prints manual as manual, never as passed. Then re-run.',
      $path,
      count($ids),
      count($ids) === 1 ? 'on' : 'a',
      SpecContract::VERIFIED_COLUMN,
      implode(', ', $ids),
      $columnMissing
        ? 'the acceptance-criteria table has no such column'
        : 'the cells are empty',
    ));
  }

}
