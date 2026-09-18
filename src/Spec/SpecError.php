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
   * The spec names no route for rendered_check, and does not say `none`.
   *
   * @param string $path
   *   The spec.
   * @param bool $present
   *   Whether the section exists at all (empty) or is missing outright.
   *
   * @return self
   *   The error.
   */
  public static function routesUndeclared(string $path, bool $present): self {
    return new self(sprintf(
      '%s %s — rendered_check renders what this section names, at test and at '
      . 'complete, beside the front page. List the paths this change adds or '
      . 'alters, one per line, as the site serves them (`/camps`, never a '
      . 'node id), or write `none — <why>` when the change touches no route. '
      . 'Three live rounds rendered `/` while the ticket\'s own page was the '
      . 'one that could have failed. Add it, then re-run.',
      $path,
      $present ? 'has a "## Routes" section that names no route and does not say none' : 'has no "## Routes" section',
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
   * Acceptance criteria not tied to a test at complete.
   *
   * @param string $path
   *   The spec, project-relative.
   * @param list<string> $ids
   *   The criterion ids without a verification.
   * @param bool $columnMissing
   *   Whether the table has no such column at all.
   * @param list<string> $unnamed
   *   The subset of those ids whose cell WAS filled in but named no test.
   *   An empty cell and a sentence of prose fail the same contract and need
   *   different fixes, so the refusal says which rows have which problem.
   *
   * @return self
   *   The error, carrying the remedy.
   */
  public static function criteriaUnverified(
    string $path,
    array $ids,
    bool $columnMissing,
    array $unnamed = [],
  ): self {
    if ($columnMissing) {
      $diagnosis = 'the acceptance-criteria table has no such column';
    }
    elseif ($unnamed === []) {
      $diagnosis = 'the cells are empty';
    }
    elseif (count($unnamed) === count($ids)) {
      $diagnosis = 'the cells name no test';
    }
    else {
      $diagnosis = sprintf('%s name no test, and the rest are empty', implode(', ', $unnamed));
    }
    return new self(sprintf(
      '%s: %d acceptance criteri%s without a "%s" entry (%s) — %s. Fill it now (the test phase is where it belongs, and the cell is the ONE cell of a frozen row you may write): a reference to the test that proves each row — a PHPUnit method or class (`FooTest::testBar`, `FooTest`), or a test file (`tests/e2e/rink.spec.ts`, `features/login.feature`) — or `manual — <reason>` for a criterion no test can prove; the report prints manual as manual, never as passed. The cell has to POINT at something a reader can open, so prose describing what was checked does not fill it. If the table has no such column, APPEND one — a new header cell and one new cell per row — and leave every other header and cell exactly as it is: the plan froze those, and rewriting or reordering them while adding the column is refused as a change to the contract. Then re-run.',
      $path,
      count($ids),
      count($ids) === 1 ? 'on' : 'a',
      SpecContract::VERIFIED_COLUMN,
      implode(', ', $ids),
      $diagnosis,
    ));
  }

  /**
   * A frozen contract section changed after the plan phase passed.
   *
   * The spec is the contract, and the agent writes it. That is fine while the
   * plan is open — it is what the plan phase is for. Afterwards the run is held
   * to it, and every later phase used to re-read the file from disk, so a
   * grounding table that satisfied the plan gate could be rewritten before the
   * code gate looked and nothing anywhere would know.
   *
   * @param string $path
   *   The spec, project-relative.
   * @param list<string> $sections
   *   The headings whose content moved, when they can be named.
   *
   * @return self
   *   The error.
   */
  public static function contractChanged(string $path, array $sections): self {
    return new self(sprintf(
      '%s broke the contract the plan phase recorded%s. ADDING is expected and '
      . 'legal: the code phase adds its grounding rows, the test phase fills '
      . '"Verified By", complete appends "## Realized", and the seeker appends '
      . 'its ledgers — all to this same file. What may not happen is a row that '
      . 'was there at plan being REMOVED or REWRITTEN, because the gates after '
      . 'plan are graded against what was promised, not against what the file '
      . 'says once the work turned out to be harder. Put the original rows '
      . 'back, or reset the run and plan again with the contract you mean. '
      . 'Adding a COLUMN is not a change to a row — only the cells that were '
      . 'there are compared, and the "Verified By" cell is never compared — so '
      . 'if you were adding one, some OTHER cell moved: diff the rows named '
      . 'above against the frozen text.',
      $path,
      $sections === [] ? '' : ' — changed: ' . implode(', ', $sections),
    ));
  }

}
