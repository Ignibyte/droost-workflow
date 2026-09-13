<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\WorkType;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A pass that examined nothing is not a measurement, and both readers agree.
 *
* `GateStatus::Passed` is overloaded. Three code paths return it over a run
 * that
 * looked at nothing: a path set resolving to nothing, phpcs exit 16 ("No files
 * were checked"), and phpunit discovering no tests. Each labelled itself in
 * PROSE — one says "a labeled pass, not a measurement" in its own summary — and
 * prose is not a field, so all three were stored as ordinary satisfied gates.
 *
 * The bill: `type_coverage`, the one blocking check derived from "measured",
 * reported "phpcs, phpstan, phpunit all measured something" about a run where
 * two analysed zero files and the third ran zero tests. The check written to
 * catch that case passed in that case. The same store's own report said "1
 * measured something" in the same document — two implementations of one
 * question, and the one that BLOCKS was the looser of the two.
 */
#[CoversClass(EvidenceStore::class)]
final class LabelledPassTest extends TestCase {

  use ReadsTheStore;

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-labelled-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->root)) {
      exec('rm -rf ' . escapeshellarg($this->root));
    }
  }

  /**
   * The store as the reviewer's reproduction left it.
   *
   * Phpcs and phpstan over empty directories, phpunit over an empty suite —
   * every one a pass, and nothing examined.
   *
   * @return \Droost\Workflow\Evidence\EvidenceStore
   *   The store.
   */
  private function runThatMeasuredNothing(): EvidenceStore {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'code', CheckRecord::fromGate(GateResult::labelledPass(
      'phpcs', 0, 0, 'phpcs passed — the configured paths contain nothing to analyse', 'phpcs',
    )));
    $store->record('r1', 'code', CheckRecord::fromGate(GateResult::labelledPass(
      'phpstan', 0, 0, 'phpstan passed — the configured paths contain nothing to analyse', 'phpstan',
    )));
    // Non-zero duration on purpose: the tool really did spawn, so the duration
    // heuristic the report used to lean on cannot save it.
    $store->record('r1', 'test', CheckRecord::fromGate(GateResult::labelledPass(
      'phpunit', 0, 271, 'phpunit passed — NO TESTS RAN.', 'phpunit',
    )));

    return $store;
  }

  /**
   * The coverage check blocks a run that analysed nothing and tested nothing.
   */
  public function testTypeCoverageBlocksRunThatMeasuredNothing(): void {
    $store = $this->runThatMeasuredNothing();

    $this->assertSame([], $store->measuredGates('r1'), 'nothing here measured anything');

    $audit = new DeclarationAudit(
      ['web/modules/custom/x'], [], ['web/modules/custom/x/x.php'], [],
      WorkType::Code, $store->measuredGates('r1'),
    );
    $blocked = [];
    foreach ($audit->checks('test') as $check) {
      if ($check->name === 'type_coverage') {
        $blocked[] = $check->state->value;
      }
    }

    $this->assertSame(['blocked'], $blocked, 'the check written to catch this catches it');
  }

  /**
   * A gate that really examined something still counts.
   *
   * Without this the fix would be indistinguishable from breaking the check.
   */
  public function testRealPassStillCounts(): void {
    $store = $this->runThatMeasuredNothing();
    $store->record('r1', 'code', CheckRecord::fromGate(GateResult::ran(
      'eslint', GateStatus::Passed, 0, 1200, 'eslint passed — 14 files', [], 'eslint',
    )));

    $this->assertSame(['eslint'], $store->measuredGates('r1'));
  }

  /**
   * The report's column and the blocking query give the same answer.
   *
   * They disagreed in the same document: §4 said "1 measured something" while
   * `type_coverage` said all three had. Both read the recorded fact now.
   */
  public function testTheReportAgreesWithTheBlockingQuery(): void {
    $report = (new EvaluationReport($this->runThatMeasuredNothing()))->render('r1');

    foreach (['phpcs', 'phpstan', 'phpunit'] as $gate) {
      $cell = NULL;
      foreach (explode("\n", $report) as $line) {
        $cells = array_map(trim(...), explode('|', $line));
        if (count($cells) === 11 && $cells[1] === '`' . $gate . '`') {
          $cell = $cells[8];
        }
      }
      $this->assertIsString($cell, $gate . ' has a gate row');
      $this->assertStringStartsWith(
        'no —',
        $cell,
        $gate . ' examined nothing, and the column says so rather than "yes"',
      );
    }
  }

  /**
   * The fact survives the round trip into SQLite and back.
   */
  public function testTheFactIsStoredNotDerived(): void {
    $rows = $this->storeRows(
      $this->runThatMeasuredNothing()->connection(),
      "SELECT name, state, measured FROM check_result WHERE kind = 'gate' ORDER BY name",
    );

    $this->assertCount(3, $rows);
    foreach ($rows as $row) {
      $this->assertSame('satisfied', $row['state'], 'it really did pass');
      $this->assertEquals(0, $row['measured'], 'and it really did measure nothing');
    }
  }

}
