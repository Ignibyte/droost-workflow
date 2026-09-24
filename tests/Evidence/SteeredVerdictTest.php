<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The record names a config the run changed beside the verdict it steered.
 *
 * F-102. P6 run 7's agent wrote the stylelint config its CSS was judged by,
 * tuned to that CSS: 0 problems under it, 89 under core's config on the same
 * seven files. §4 said `satisfied`, and nothing in the document said whose
 * rules those were. The files are a column in the store, inside the row's
 * digest, and a column in §4 beside the baseline's.
 */
#[CoversClass(EvidenceStore::class)]
#[CoversClass(EvaluationReport::class)]
final class SteeredVerdictTest extends TestCase {

  use ReadsTheReport;
  use ReadsTheStore;

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-steered-' . bin2hex(random_bytes(6));
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
   * §4 names the files beside the steered verdict and a dash beside the rest.
   */
  public function testTheSteeredVerdictNamesItsConfig(): void {
    $store = $this->steeredRun();

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertSame(
      '**`.stylelintrc.json`, `web/modules/custom/example_camps/.stylelintignore`**',
      $this->gateCell($report, 'stylelint', 'Config the run changed'),
    );
    $this->assertSame('—', $this->gateCell($report, 'phpcs', 'Config the run changed'));
    $this->assertStringContainsString('**1 of those verdicts was reached under config this run changed.**', $report);
    $this->assertSame('satisfied', $this->gateCell($report, 'stylelint', 'State'), 'the verdict itself is unchanged');
  }

  /**
   * A run with no steered verdict says nothing about it beyond the dashes.
   */
  public function testNoSteeredVerdictAddsNoNote(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'phpcs passed', NULL, NULL, 0, 'phpcs', NULL, 800,
    ));

    $report = (new EvaluationReport($store))->render('r1');

    $this->assertSame('—', $this->gateCell($report, 'phpcs', 'Config the run changed'));
    $this->assertStringNotContainsString('reached under config this run changed.**', $report);
  }

  /**
   * The files are kept whole, and the honest record verifies.
   */
  public function testTheFilesAreStoredAndTheRecordHolds(): void {
    $store = $this->steeredRun();

    $this->assertNull($store->integrity('r1'));
    $this->assertSame(
      ".stylelintrc.json\nweb/modules/custom/example_camps/.stylelintignore",
      $this->storeRows($this->raw(), "SELECT steered_by FROM check_result WHERE name = 'stylelint'")[0]['steered_by'] ?? NULL,
    );
    $phpcs = $this->storeRow($this->raw(), "SELECT steered_by FROM check_result WHERE name = 'phpcs'");
    $this->assertIsArray($phpcs);
    $this->assertArrayHasKey('steered_by', $phpcs);
    $this->assertNull($phpcs['steered_by'], 'NULL, not an empty string, when nothing steered it');
  }

  /**
   * Erasing the files from a row is a forgery the chain catches.
   *
   * The disclosure is only worth what it costs to remove. An agent that could
   * clear the column would leave a verdict that reads as the project's rules.
   */
  public function testErasingTheFilesBreaksTheChain(): void {
    $this->steeredRun();

    $pdo = $this->raw();
    $pdo->exec("UPDATE check_result SET steered_by = NULL WHERE name = 'stylelint'");
    unset($pdo);

    $this->assertIsArray((new EvidenceStore($this->root))->integrity('r1'));
  }

  /**
   * A store holding a steered stylelint verdict and an unsteered phpcs one.
   *
   * @return \Droost\Workflow\Evidence\EvidenceStore
   *   The store.
   */
  private function steeredRun(): EvidenceStore {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'xhigh']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'phpcs passed', NULL, NULL, 0, 'phpcs', NULL, 800,
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'stylelint', CheckState::Satisfied, Fault::None,
      'stylelint passed [steered by config this run changed: .stylelintrc.json, web/modules/custom/example_camps/.stylelintignore]',
      NULL, NULL, 0, 'stylelint', NULL, 700,
      steeredBy: ['.stylelintrc.json', 'web/modules/custom/example_camps/.stylelintignore'],
    ));

    return $store;
  }

  /**
   * The connection a test uses to read or damage the file directly.
   *
   * @return \PDO
   *   The connection.
   */
  private function raw(): \PDO {
    return new \PDO('sqlite:' . EvidenceStore::pathFor($this->root));
  }

}
