<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Gate;

use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Gate\GateResult;
use Drupal\droost_workflow\Gate\GateStatus;
use Drupal\droost_workflow\Gate\PhaseReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The verdict, and the rule the whole package turns on.
 */
class PhaseReportTest extends TestCase {

  /**
   * REQ-007: only a blocking outcome stops the run.
   *
   * @param \Drupal\droost_workflow\Gate\GateStatus $status
   *   The single gate's outcome.
   * @param bool $advances
   *   Whether the phase should advance.
   */
  #[DataProvider('everyStatus')]
  public function testAdvanceDependsOnlyOnBlockingOutcomes(
    GateStatus $status,
    bool $advances,
  ): void {
    $report = (new PhaseReport(Phase::Test))
      ->with(new GateResult('phpcs', $status));

    $this->assertSame($advances, $report->advance());
  }

  /**
   * Each status and whether a phase containing only it may advance.
   *
   * @return array<string, array{\Drupal\droost_workflow\Gate\GateStatus, bool}>
   *   Case name to status and expected advance.
   */
  public static function everyStatus(): array {
    return [
      'passed' => [GateStatus::Passed, TRUE],
      'failed' => [GateStatus::Failed, FALSE],
      'skipped, no site' => [GateStatus::SkippedNoSite, TRUE],
      'tool missing' => [GateStatus::ErrorToolMissing, FALSE],
      'off' => [GateStatus::Off, TRUE],
    ];
  }

  /**
   * A skip is never a pass, in any combination.
   *
   * This is the doctrine, so it gets the whole matrix rather than an example.
   * Every pair of statuses is checked: a skip must never turn a blocking
   * outcome into an advance, and must never be counted among the passes.
   *
   * @param \Drupal\droost_workflow\Gate\GateStatus $first
   *   The first gate's outcome.
   * @param \Drupal\droost_workflow\Gate\GateStatus $second
   *   The second gate's outcome.
   */
  #[DataProvider('everyPair')]
  public function testSkippedIsNeverCountedAsPassed(
    GateStatus $first,
    GateStatus $second,
  ): void {
    $report = (new PhaseReport(Phase::Test))
      ->with(new GateResult('phpcs', $first))
      ->with(new GateResult('phpstan', $second));

    $expectedPasses = (int) $first->isPass() + (int) $second->isPass();
    $this->assertSame($expectedPasses, $report->tally()['passed']);

    $blocking = $first->blocksAdvance() || $second->blocksAdvance();
    $this->assertSame(!$blocking, $report->advance());

    // A skip is always visible, whatever it is paired with.
    $skips = (int) ($first === GateStatus::SkippedNoSite)
      + (int) ($second === GateStatus::SkippedNoSite);
    $this->assertCount($skips, $report->skipped());
  }

  /**
   * Every ordered pair of statuses.
   *
   * @return array<string, array{\Drupal\droost_workflow\Gate\GateStatus, \Drupal\droost_workflow\Gate\GateStatus}>
   *   Case name to the two statuses.
   */
  public static function everyPair(): array {
    $cases = [];
    foreach (GateStatus::cases() as $first) {
      foreach (GateStatus::cases() as $second) {
        $cases[$first->value . ' + ' . $second->value] = [$first, $second];
      }
    }
    return $cases;
  }

  /**
   * A phase where nothing failed but things were skipped cannot read as clean.
   *
   * The summary line is built from the tally rather than a pass count
   * precisely so this sentence is impossible to produce.
   */
  public function testSummaryCannotHideSkips(): void {
    $report = (new PhaseReport(Phase::Test))
      ->with(new GateResult('phpcs', GateStatus::Passed))
      ->with(new GateResult('rendered_check', GateStatus::SkippedNoSite))
      ->with(new GateResult('playwright', GateStatus::SkippedNoSite));

    $summary = $report->summaryLine();

    $this->assertTrue($report->advance());
    $this->assertStringContainsString('1 passed', $summary);
    $this->assertStringContainsString('2 skipped-no-site', $summary);
  }

  /**
   * REQ-005: the serialized shape is stable.
   */
  public function testSerializesTheAgreedShape(): void {
    $report = (new PhaseReport(Phase::Test))
      ->with(GateResult::ran(
        'phpcs',
        GateStatus::Failed,
        2,
        150,
        'phpcs failed (exit 2)',
        [['file' => 'a.php']],
        'vendor/bin/phpcs -q',
      ))
      ->with(GateResult::skippedNoSite('rendered_check'))
      ->with(GateResult::off('mutation'))
      ->with(GateResult::toolMissing('phpstan', 'vendor/bin/phpstan analyse'));

    $document = $report->toArray();

    $this->assertSame('test', $document['phase']);
    $this->assertFalse($document['advance']);
    $this->assertSame([
      'passed' => 0,
      'failed' => 1,
      'skipped-no-site' => 1,
      'error-tool-missing' => 1,
      'off' => 1,
    ], $document['tally']);

    $gates = $document['gates'];
    $this->assertIsArray($gates);
    $this->assertCount(4, $gates);
    $this->assertSame([
      'gate' => 'phpcs',
      'status' => 'failed',
      'exit_code' => 2,
      'duration_ms' => 150,
      'summary' => 'phpcs failed (exit 2)',
      'findings' => [['file' => 'a.php']],
      'truncated' => FALSE,
      'skip_reason' => NULL,
      'invocation' => 'vendor/bin/phpcs -q',
    ], $gates[0]);
  }

  /**
   * A skip always carries the reason it was skipped.
   */
  public function testSkipsCarryTheirReason(): void {
    $result = GateResult::skippedNoSite('rendered_check');

    $this->assertSame(
      'no booted site (CLI surface)',
      $result->skipReason,
    );
    $this->assertStringContainsString('not run', $result->summary);
  }

  /**
   * A missing tool reports the invocation, which is the useful part.
   */
  public function testToolMissingNamesTheInvocation(): void {
    $result = GateResult::toolMissing('phpcs', 'vendor/bin/phpcs -q');

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertSame('vendor/bin/phpcs -q', $result->invocation);
    $this->assertTrue($result->status->blocksAdvance());
  }

  /**
   * Findings are capped, and say so when they are.
   */
  public function testFindingsAreCappedAndFlagged(): void {
    $many = array_map(
      static fn (int $i): array => ['n' => $i],
      range(1, GateResult::FINDINGS_CAP + 10),
    );

    $result = GateResult::ran(
      'phpcs',
      GateStatus::Failed,
      1,
      10,
      'many',
      $many,
      'vendor/bin/phpcs',
    );

    $this->assertCount(GateResult::FINDINGS_CAP, $result->findings);
    $this->assertTrue($result->truncated);
  }

  /**
   * An empty phase advances rather than blocking.
   */
  public function testPhaseWithNoGatesAdvances(): void {
    $report = new PhaseReport(Phase::Document);

    $this->assertTrue($report->advance());
    $this->assertStringContainsString('no gates configured', $report->summaryLine());
  }

}
