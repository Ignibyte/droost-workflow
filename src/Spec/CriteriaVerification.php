<?php

declare(strict_types=1);

namespace Droost\Workflow\Spec;

use Droost\Workflow\Evidence\CheckState;
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
 * THREE BUCKETS THE ROWS DO NOT HAVE, and dropping them is the point:
 *
 * - `unnamed` — a proof that is filled in and "points at nothing anyone can
 *   open". Deciding that is a regex's opinion about whether a string looks
 *   like a test, which is judgement, and judgement is what this redesign
 *   takes out of the blocking path. What a declared proof names is the test
 *   gate's question; whether it was declared is this one's.
 * - `column_missing` — a table can omit a column. Rows cannot.
 * - `misshapen` — likewise: a declared row has no cells to miscount.
 *
 * `manual` stays, because it is not a judgement: `verify-criterion AC-1
 * manual` is a legitimate declaration and the report must print it as manual
 * and never as passed. That is one literal word compared, not a verdict.
 *
 * A PROOF IS COUNTED BY WHAT IT CAME TO, NOT BY BEING THERE (F-357). A run's
 * AC-10 recorded "Parity: FAILS D5 on the frame at 1280px on all 7 routes"
 * and was counted among the verified. Two things now keep a proof from
 * counting: the outcome it was recorded with (`--outcome=partly|failed`),
 * and a gate it names whose latest verdict in the run is blocked or was
 * lifted by an operator. The second needs no honesty from the agent: a gate
 * droost ran and recorded failing cannot prove anything. Naming is a gate's
 * name as a word (`parity`, `playwright`, a custom gate without its
 * `custom:` prefix), never a guess about what a test file is.
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
   * @return array{total: int, verified: list<string>, manual: list<string>, unverified: list<string>, unnamed: list<string>, misshapen: list<string>, column_missing: bool, source: string}|null
   *   The counts, with `source` naming which answered (`declared` or
   *   `parsed`), or NULL when neither source carries any criteria — which is
   *   a different fact from "none are verified" and must not read as one.
   */
  public static function resolve(string $projectRoot, ?string $runId, ?string $specPath): ?array {
    $declared = $runId === NULL ? [] : self::declared($projectRoot, $runId);
    if ($declared !== [] && $runId !== NULL) {
      return self::count($declared, self::gateStates($projectRoot, $runId));
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
   * @return list<array{ref: string, statement: string, verified_by: string|null, verified_at: string|null, phase: string, revisions: int, outcome: string|null}>
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
   * The run's gates' latest verdicts, or none when the store cannot say.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $runId
   *   The run.
   *
   * @return array<string, string>
   *   The CheckState value by gate name.
   */
  private static function gateStates(string $projectRoot, string $runId): array {
    try {
      return (new EvidenceStore($projectRoot))->latestGateStates($runId);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Where one criterion stands: verified, manual or unverified, and why not.
   *
   * @param string|null $verifiedBy
   *   Its latest proof, or NULL.
   * @param string|null $outcome
   *   What the proof was recorded as coming to; NULL reads as `proven`.
   * @param array<string, string> $gateStates
   *   The run's gates' latest verdicts (EvidenceStore::latestGateStates()).
   *
   * @return array{standing: string, why: string|null}
   *   `verified`, `manual` or `unverified`, and for an unverified criterion
   *   with a proof, why the proof does not count.
   */
  public static function standing(?string $verifiedBy, ?string $outcome, array $gateStates): array {
    $by = trim((string) $verifiedBy);
    if ($by === '') {
      return ['standing' => 'unverified', 'why' => NULL];
    }
    if ($outcome !== NULL && $outcome !== 'proven') {
      return ['standing' => 'unverified', 'why' => sprintf('its proof was recorded as %s', $outcome)];
    }
    if (preg_match('/^manual\b/i', $by) === 1) {
      return ['standing' => 'manual', 'why' => NULL];
    }
    foreach ($gateStates as $gate => $state) {
      if ($state !== CheckState::Blocked->value && $state !== CheckState::Unblocked->value) {
        continue;
      }
      // A project's gate is named for a product (snyk, gitleaks, semgrep),
      // and prose about the product is not a reference to the gate: an
      // adopter's proof "…the Snyk MCP…" was refused as naming custom:snyk
      // (F-375). So a custom gate counts as named by its full id, or by its
      // bare name in the "name: result" shape a proof gives a gate's verdict. A
      // built-in gate's name is not prose, and the bare word still counts.
      $custom = str_starts_with($gate, 'custom:');
      $name = $custom ? substr($gate, strlen('custom:')) : $gate;
      $word = '(?<![\w-])' . preg_quote($name, '/') . '(?![\w-])';
      $pattern = $custom
        ? '/' . preg_quote($gate, '/') . '(?![\w-])|' . $word . '\s*:/i'
        : '/' . $word . '/i';
      if ($name !== '' && preg_match($pattern, $by) === 1) {
        return [
          'standing' => 'unverified',
          'why' => sprintf('its proof names %s, whose latest verdict in this run is %s', $gate, $state),
        ];
      }
    }

    return ['standing' => 'verified', 'why' => NULL];
  }

  /**
   * Sorts declared criteria into the three buckets.
   *
   * @param list<array{ref: string, statement: string, verified_by: string|null, verified_at: string|null, phase: string, revisions: int, outcome: string|null}> $criteria
   *   The declared criteria.
   * @param array<string, string> $gateStates
   *   The run's gates' latest verdicts.
   *
   * @return array{total: int, verified: list<string>, manual: list<string>, unverified: list<string>, unnamed: list<string>, misshapen: list<string>, column_missing: bool, source: string}
   *   The counts.
   */
  private static function count(array $criteria, array $gateStates): array {
    $verified = [];
    $manual = [];
    $unverified = [];
    foreach ($criteria as $criterion) {
      $standing = self::standing($criterion['verified_by'], $criterion['outcome'], $gateStates)['standing'];
      match ($standing) {
        'verified' => $verified[] = $criterion['ref'],
        'manual' => $manual[] = $criterion['ref'],
        default => $unverified[] = $criterion['ref'],
      };
    }

    return [
      'total' => count($criteria),
      'verified' => $verified,
      'manual' => $manual,
      'unverified' => $unverified,
      'unnamed' => [],
      'misshapen' => [],
      'column_missing' => FALSE,
      'source' => 'declared',
    ];
  }

}
