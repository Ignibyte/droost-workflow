<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Gate;

use Drupal\Tests\droost_workflow\Unit\WorkflowTestCase;
use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\WorkflowConfig;
use Drupal\droost_workflow\Gate\GateExecutorInterface;
use Drupal\droost_workflow\Gate\GateResult;
use Drupal\droost_workflow\Gate\GateRunner;
use Drupal\droost_workflow\Gate\GateStatus;
use Drupal\droost_workflow\Gate\NullSiteDriver;
use Drupal\droost_workflow\Gate\SiteDriverInterface;
use Drupal\droost_workflow\State\RunState;
use Drupal\droost_workflow\State\RunStateStore;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Executing a phase's gates.
 */
class GateRunnerTest extends WorkflowTestCase {

  /**
   * REQ-001: exactly the due-and-enabled set runs, nothing more or less.
   */
  public function testExecutesExactlyTheDueResolvedSet(): void {
    $executor = $this->recordingExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver());
    $state = $this->beginWith(['preset' => 'custom']);

    $report = $runner->run($state, Phase::Test, '/tmp');

    // Due at test: phpunit, mutation, playwright, coverage, rendered_check.
    // custom: phpunit on; mutation, playwright, coverage off; rendered_check
    // on but site-dependent. phpcs and phpstan are on but belong to the code
    // phase, so the executor must not see them here.
    $this->assertSame(
      ['phpunit'],
      $executor->ran,
      'the executor saw a different set than the phase map named',
    );
    $this->assertCount(5, $report->results);
  }

  /**
   * Plan and document run no gates: there is nothing yet to measure.
   *
   * The regression guard for the defect this map fixed — a live run's PLAN
   * phase executed phpunit, mutation and a browser suite before any code
   * existed.
   *
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The gateless phase.
   */
  #[DataProvider('gatelessPhases')]
  public function testPlanAndDocumentRunNoGates(Phase $phase): void {
    $executor = $this->recordingExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver());

    $report = $runner->run($this->beginWith([]), $phase, '/tmp');

    $this->assertSame([], $executor->ran, 'a gateless phase ran a tool');
    $this->assertSame([], $report->results);
    $this->assertTrue($report->advance());
    $this->assertSame(
      $phase->value . ': no gates configured',
      $report->summaryLine(),
    );
  }

  /**
   * The phases at which nothing is due.
   *
   * @return array<string, array{\Drupal\droost_workflow\Config\Phase}>
   *   Case name to phase.
   */
  public static function gatelessPhases(): array {
    return [
      'plan' => [Phase::Plan],
      'document' => [Phase::Document],
    ];
  }

  /**
   * Code runs static analysis and nothing functional.
   */
  public function testCodeRunsOnlyStaticAnalysis(): void {
    $executor = $this->recordingExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver());

    $report = $runner->run($this->beginWith([]), Phase::Code, '/tmp');

    $this->assertSame(['phpcs', 'phpstan'], $executor->ran);
    $this->assertCount(2, $report->results);
  }

  /**
   * Complete re-runs the full resolved set — the terminal safety net.
   */
  public function testCompleteRunsTheFullResolvedSet(): void {
    $executor = $this->recordingExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver());

    // factory: everything on.
    $report = $runner->run($this->beginWith([]), Phase::Complete, '/tmp');

    $this->assertSame(
      ['phpcs', 'phpstan', 'phpunit', 'mutation', 'playwright', 'coverage'],
      $executor->ran,
      'every non-site gate must execute at complete',
    );
    $this->assertCount(7, $report->results);
    $this->assertCount(1, $report->skipped());
  }

  /**
   * REQ-006: a gate that is configured off records as off.
   */
  public function testDisabledGatesRecordAsOff(): void {
    $runner = new GateRunner($this->recordingExecutor(), new NullSiteDriver());
    $report = $runner->run(
      $this->beginWith(['preset' => 'custom']),
      Phase::Test,
      '/tmp',
    );

    $this->assertSame(3, $report->tally()['off']);
    foreach ($report->withStatus(GateStatus::Off) as $result) {
      $this->assertContains(
        $result->gate,
        ['mutation', 'playwright', 'coverage'],
      );
    }
  }

  /**
   * REQ-002: a site gate with no driver is skipped, and never passed.
   */
  public function testSiteGateWithNoDriverIsSkippedNotPassed(): void {
    $runner = new GateRunner($this->recordingExecutor(), new NullSiteDriver());

    $report = $runner->run(
      $this->beginWith(['preset' => 'custom']),
      Phase::Test,
      '/tmp',
    );

    $skipped = $report->skipped();
    $this->assertCount(1, $skipped);
    $this->assertSame('rendered_check', $skipped[0]->gate);
    $this->assertSame(NullSiteDriver::REASON, $skipped[0]->skipReason);
    // Non-blocking, but never counted among the passes. Under custom at the
    // test phase, phpunit is the one gate that both runs and passes.
    $this->assertTrue($report->advance());
    $this->assertSame(1, $report->tally()['passed']);
  }

  /**
   * A site exists but cannot run the gate: that is a gap, not a skip.
   *
   * Blaming "no site" when there IS a site would point the reader at the
   * wrong problem and hide a real hole in the driver.
   */
  public function testUnsupportedSiteGateIsToolMissing(): void {
    $driver = new class() implements SiteDriverInterface {

      /**
       * {@inheritdoc}
       */
      public function available(): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function supports(): array {
        return [];
      }

      /**
       * {@inheritdoc}
       */
      public function run(GateSettings $gate, string $projectRoot): GateResult {
        throw new \LogicException('Should not be called.');
      }

    };

    $report = (new GateRunner($this->recordingExecutor(), $driver))->run(
      $this->beginWith(['preset' => 'custom']),
      Phase::Test,
      '/tmp',
    );

    $missing = $report->withStatus(GateStatus::ErrorToolMissing);
    $this->assertCount(1, $missing);
    $this->assertSame('rendered_check', $missing[0]->gate);
    $this->assertFalse($report->advance(), 'a real gap must block');
  }

  /**
   * The per-result callback fires once per gate, in order.
   *
   * The attach point pair mode uses, so it is pinned before anything depends
   * on it.
   */
  public function testTheResultCallbackFiresOncePerGate(): void {
    $seen = [];
    $runner = new GateRunner($this->recordingExecutor(), new NullSiteDriver());

    $report = $runner->run(
      $this->beginWith(['preset' => 'custom']),
      Phase::Test,
      '/tmp',
      static function (GateResult $r) use (&$seen): void {
        $seen[] = $r->gate;
      },
    );

    $this->assertSame(
      array_map(static fn (GateResult $r): string => $r->gate, $report->results),
      $seen,
    );
  }

  /**
   * REQ-004: the feedback loop is bounded by the run's own lever.
   */
  public function testFeedbackLoopIsBoundedByTheLever(): void {
    $runner = new GateRunner($this->recordingExecutor(), new NullSiteDriver());
    $state = $this->beginWith(['max_gate_retries' => 2]);

    $this->assertTrue($runner->mayRetry($state, 'phpcs'));
    $state = $runner->recordAttempt($state, 'phpcs');
    $this->assertTrue($runner->mayRetry($state, 'phpcs'));
    $state = $runner->recordAttempt($state, 'phpcs');
    $this->assertFalse($runner->mayRetry($state, 'phpcs'));
    // Other gates keep their own budget.
    $this->assertTrue($runner->mayRetry($state, 'phpstan'));
    $this->assertSame(['phpcs' => 2], $state->feedbackAttempts);
  }

  /**
   * A retry budget of zero means one attempt and no retry.
   */
  public function testZeroRetriesMeansNoRetry(): void {
    $runner = new GateRunner($this->recordingExecutor(), new NullSiteDriver());
    $state = $this->beginWith(['max_gate_retries' => 0]);

    $this->assertFalse($runner->mayRetry($state, 'phpcs'));
  }

  /**
   * REQ-004: attempt counts survive being written and read back.
   *
   * A process killed mid-loop must resume with its budget spent, not reset.
   */
  public function testAttemptCountsSurviveReload(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $runner = new GateRunner($this->recordingExecutor(), new NullSiteDriver());

    $state = $runner->recordAttempt($this->beginWith([]), 'phpcs');
    $store->save($state);

    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertSame(['phpcs' => 1], $reloaded->feedbackAttempts);
    $this->assertFalse(
      $runner->mayRetry($reloaded, 'phpcs')
      && $reloaded->maxGateRetries === 1,
    );
  }

  /**
   * A phase report can be recorded into the run and read back.
   *
   * Until this existed, gate_results was written by nothing — so anything
   * telling a reader to find results there pointed at an empty array.
   */
  public function testGateReportsArePersistedIntoTheRun(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $runner = new GateRunner($this->recordingExecutor(), new NullSiteDriver());

    $state = $this->beginWith(['preset' => 'custom']);
    $report = $runner->run($state, Phase::Test, $root);
    $store->save($state->withGateReport(Phase::Test->value, $report->toArray()));

    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $recorded = $reloaded->gateResults['test'];
    $this->assertIsArray($recorded);
    $this->assertSame($report->toArray(), $recorded);
  }

  /**
   * An executor that records what it was asked to run.
   *
   * @return object{ran: list<string>}&\Drupal\droost_workflow\Gate\GateExecutorInterface
   *   The fake.
   */
  private function recordingExecutor(): object {
    return new class() implements GateExecutorInterface {

      /**
       * Gate names this executor was asked to run, in order.
       *
       * @var list<string>
       */
      public array $ran = [];

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        $this->ran[] = $gate->name;
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
  }

  /**
   * Begins a run from a config document.
   *
   * @param array<array-key, mixed> $raw
   *   The lever document.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The run.
   */
  private function beginWith(array $raw): RunState {
    return RunState::begin(
      'run-1',
      '2026-07-27T09:00:00+00:00',
      WorkflowConfig::fromArray($raw, 'test'),
    );
  }

}
