<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A green that subtracted inherited debt says so where the verdict is read.
 *
 * F-7. The adoption baseline is a deliberate, audited exception to "green
 * means measured": a consulting gate sets aside the findings a tree already
 * carried and passes on the rest. The numbers existed —
 * `ShellGateExecutor` composes `passed — 0 new, 123 inherited` and
 * `GateResult` carries both — and the evidence boundary kept only the
 * sentence. §4 printed `satisfied`, with nothing saying the verdict had
 * subtracted anything, and §2's own blind-spot table said the baseline hash
 * "lives in run.json, not here" — a file inside the state directory, in a
 * document whose premise is that the record must not be the subject's
 * account of itself.
 *
 * An exception invisible where the verdict is read is not audited by anybody.
 */
final class BaselineSubsidyIsVisibleTest extends WorkflowTestCase {

  use ReadsTheReport;

  /**
   * The split reaches the store as columns, from the gate result.
   */
  public function testTheSplitSurvivesTheEvidenceBoundary(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->record('r1', 'code', CheckRecord::fromGate(
      (new GateResult('phpcs', GateStatus::Passed, 0, 880, 'passed — 0 new, 123 inherited', [], FALSE, NULL, 'phpcs'))
        ->withBaselineCounts(123, 0),
    ));

    $rows = $store->checklist('r1', 'code');
    $this->assertCount(1, $rows);
    $this->assertSame(123, $rows[0]['inherited']);
    $this->assertSame(0, $rows[0]['new_findings']);
  }

  /**
   * §4 names it, counts it, and points at which baseline.
   */
  public function testSectionFourShowsWhatTheVerdictSetAside(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('r1', ['preset' => 'medium', 'baseline_hash' => 'ba5e11ne']);
    $store->record('r1', 'code', CheckRecord::fromGate(
      (new GateResult('phpcs', GateStatus::Passed, 0, 880, 'passed', [], FALSE, NULL, 'phpcs'))
        ->withBaselineCounts(123, 0),
    ));
    // A gate that consulted the baseline and inherited nothing, which is NOT
    // the same fact as a gate that never consulted one.
    $store->record('r1', 'code', CheckRecord::fromGate(
      (new GateResult('phpstan', GateStatus::Passed, 0, 900, 'passed', [], FALSE, NULL, 'phpstan'))
        ->withBaselineCounts(0, 0),
    ));
    // And one that never asked.
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'config_clean', CheckState::Satisfied, Fault::None, 'zero diff', NULL, NULL, 0, 'drush', NULL, 40,
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertSame(
      '**123 inherited**, 0 new',
      $this->gateCell($report, 'phpcs', 'Baseline'),
      'the pass over debt is readable in the row, not only in §8a prose',
    );
    $this->assertSame(
      '0 inherited, 0 new',
      $this->gateCell($report, 'phpstan', 'Baseline'),
      'consulted and subsidised nothing',
    );
    $this->assertSame(
      '—',
      $this->gateCell($report, 'config_clean', 'Baseline'),
      'never consulted — and an em dash, not a blank cell',
    );
    $this->assertStringContainsString(
      '**1 of those verdicts subtracted inherited debt.**',
      $report,
      'and the reader is given the count without adding the column up',
    );
    $this->assertStringContainsString(
      '`ba5e11ne`',
      $report,
      'which baseline, from the run row rather than from run.json',
    );
  }

  /**
   * An archived store written before v9 still verifies clean.
   *
   * This is what makes the migration safe, and it is not a detail. The
   * digest covers EVERY stored column by design — "a digest over part of a
   * row invites the question which part" — so appending two fields
   * unconditionally would have changed the recompute input for every row
   * written before this version, and three completed dogfood series' archived
   * evidence would read as forged the moment a newer build opened it. A
   * record that cries tampering on an untouched archive teaches its reader to
   * ignore it.
   */
  public function testTheRowsOfAnUpgradedStoreStillVerify(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('r1', ['preset' => 'low']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'passed', NULL, NULL, 0, 'phpcs', NULL, 880,
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Satisfied, Fault::None, 'passed', NULL, NULL, 0, 'phpstan', NULL, 900,
    ));
    $this->assertNull($store->integrity('r1'), 'clean before the wind-back');

    // Wind the file back to v8: drop what v9 added, restamp the version.
    $pdo = new \PDO('sqlite:' . $root . '/droost/droost-workflow/evidence.sqlite');
    $pdo->exec('ALTER TABLE check_result DROP COLUMN inherited');
    $pdo->exec('ALTER TABLE check_result DROP COLUMN new_findings');
    $pdo->exec('ALTER TABLE run DROP COLUMN baseline_hash');
    $pdo->exec('PRAGMA user_version = 8');
    unset($pdo);

    $upgraded = new EvidenceStore($root);
    $this->assertNull(
      $upgraded->integrity('r1'),
      'rows written before the columns existed must not read as forged',
    );

    // And a row written AFTER the upgrade, carrying counts, chains onto them.
    $upgraded->record('r1', 'code', CheckRecord::fromGate(
      (new GateResult('phpcs', GateStatus::Passed, 0, 880, 'passed', [], FALSE, NULL, 'phpcs'))
        ->withBaselineCounts(7, 0),
    ));
    $this->assertNull($upgraded->integrity('r1'), 'and the chain continues');
  }

  /**
   * Rewriting a count breaks the digest, which is why it is in the digest.
   *
   * The abuse this refuses: a subsidised pass edited to look unsubsidised —
   * `UPDATE check_result SET inherited = 0` — so §4's column reports a
   * measurement nobody made. Undigested columns would have made that free.
   */
  public function testRewritingTheSubsidyIsCaught(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('r1', ['preset' => 'low']);
    $store->record('r1', 'code', CheckRecord::fromGate(
      (new GateResult('phpcs', GateStatus::Passed, 0, 880, 'passed', [], FALSE, NULL, 'phpcs'))
        ->withBaselineCounts(123, 0),
    ));
    $this->assertNull($store->integrity('r1'));

    $pdo = new \PDO('sqlite:' . $root . '/droost/droost-workflow/evidence.sqlite');
    $pdo->exec('UPDATE check_result SET inherited = 0 WHERE name = "phpcs"');
    unset($pdo);

    $caught = (new EvidenceStore($root))->integrity('r1');
    $this->assertIsArray($caught, 'a rewritten subsidy is tampering, and reads as it');
    $this->assertSame('phpcs', $caught['name']);
  }

  /**
   * Moving a count OUT of NULL is caught too.
   *
   * The conditional digest is what keeps old rows verifiable, and the
   * question it invites is whether it opens a hole: can a row with no counts
   * be given some, or a row with counts have them removed, and stay clean?
   * No — either move changes the digest input, which is the point of doing it
   * by NULL-ness rather than by schema version.
   */
  public function testInventingSubsidyOnAnUnsubsidisedRowIsCaught(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('r1', ['preset' => 'low']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'passed', NULL, NULL, 0, 'phpcs', NULL, 880,
    ));

    $pdo = new \PDO('sqlite:' . $root . '/droost/droost-workflow/evidence.sqlite');
    $pdo->exec('UPDATE check_result SET inherited = 500, new_findings = 0 WHERE name = "phpcs"');
    unset($pdo);

    $this->assertIsArray(
      (new EvidenceStore($root))->integrity('r1'),
      'a row invented a subsidy it never had',
    );
  }

}
