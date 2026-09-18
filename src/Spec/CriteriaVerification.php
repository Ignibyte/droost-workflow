<?php

declare(strict_types=1);

namespace Droost\Workflow\Spec;

use Droost\Workflow\Evidence\EvidenceStore;

/**
 * Promise against proof: which criteria have one, which do not.
 *
 * One answer for every surface that asks, from the declared rows where a run
 * has them and from the markdown table where it does not.
 *
 * WHY THE ROWS WIN. The table's shape was inferred from a heading's position:
 * anything under `## Acceptance criteria` was criteria, so `P3-T2`'s spec
 * documented its six source records under that heading and the engine
 * demanded a `Verified By` cell on six rows that are not criteria — then
 * refused the correction as a frozen-section breach. The agent's own words:
 * "confirmed deadlock, and it's structural rather than something more editing
 * will fix", about a run whose build was complete, live and verified (F-35).
 *
 * A declared criterion cannot be mistaken for a data table.
 *
 * TWO BUCKETS THE ROWS DO NOT HAVE, and dropping them is the point:
 *
 * - `unnamed` — a proof that is filled in and "points at nothing anyone can
 *   open". Deciding that is a regex's opinion about whether a string looks
 *   like a test, which is judgement, and judgement is what this redesign
 *   takes out of the blocking path. What a declared proof names is the test
 *   gate's question; whether it was declared is this one's.
 * - `column_missing` — a table can omit a column. Rows cannot.
 *
 * `manual` stays, because it is not a judgement: `verify-criterion AC-1
 * manual` is a legitimate declaration and the report must print it as manual
 * and never as passed. That is one literal word compared, not a verdict.
 */
final class CriteriaVerification {

  /**
   * The run's criteria, counted.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string|null $runId
   *   The open run, whose declared rows answer first. NULL skips them.
   * @param string|null $specPath
   *   The governing spec, project-relative, for the fallback. NULL skips it.
   *
   * @return array{total: int, verified: list<string>, manual: list<string>, unverified: list<string>, unnamed: list<string>, column_missing: bool, source: string}|null
   *   The counts, with `source` naming which answered (`declared` or
   *   `parsed`), or NULL when neither source carries any criteria — which is
   *   a different fact from "none are verified" and must not read as one.
   */
  public static function resolve(string $projectRoot, ?string $runId, ?string $specPath): ?array {
    $declared = $runId === NULL ? [] : self::declared($projectRoot, $runId);
    if ($declared !== []) {
      return self::count($declared);
    }
    if ($specPath === NULL) {
      return NULL;
    }
    $parsed = SpecContract::criteriaVerification($projectRoot, $specPath);

    return $parsed === NULL ? NULL : [...$parsed, 'source' => 'parsed'];
  }

  /**
   * The declared rows, or an empty list.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $runId
   *   The run.
   *
   * @return list<array{ref: string, statement: string, verified_by: string|null, verified_at: string|null, phase: string, revisions: int}>
   *   The criteria. Empty when none were declared or the store cannot be
   *   read: an unreachable store is not evidence that nothing was promised,
   *   and the phase that could not write it says so already.
   */
  private static function declared(string $projectRoot, string $runId): array {
    try {
      return (new EvidenceStore($projectRoot))->specCriteria($runId);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Sorts declared criteria into the three buckets.
   *
   * @param list<array{ref: string, statement: string, verified_by: string|null, verified_at: string|null, phase: string, revisions: int}> $criteria
   *   The declared criteria.
   *
   * @return array{total: int, verified: list<string>, manual: list<string>, unverified: list<string>, unnamed: list<string>, column_missing: bool, source: string}
   *   The counts.
   */
  private static function count(array $criteria): array {
    $verified = [];
    $manual = [];
    $unverified = [];
    foreach ($criteria as $criterion) {
      $by = trim((string) ($criterion['verified_by'] ?? ''));
      if ($by === '') {
        $unverified[] = $criterion['ref'];
      }
      elseif (preg_match('/^manual\b/i', $by) === 1) {
        $manual[] = $criterion['ref'];
      }
      else {
        $verified[] = $criterion['ref'];
      }
    }

    return [
      'total' => count($criteria),
      'verified' => $verified,
      'manual' => $manual,
      'unverified' => $unverified,
      'unnamed' => [],
      'column_missing' => FALSE,
      'source' => 'declared',
    ];
  }

}
