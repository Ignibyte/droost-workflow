<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Baseline\BaselineAwareExecutorInterface;
use Droost\Workflow\Baseline\BaselineContext;
use Droost\Workflow\Baseline\BaselineManifest;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\VcsInterface;

/**
 * The runner and the baseline: frozen at begin, verified at every phase.
 */
final class GateRunnerBaselineTest extends WorkflowTestCase {

  /**
   * A run frozen with a baseline hands the context to the consulting gates.
   */
  public function testConsultingGatesReceiveTheContext(): void {
    $root = $this->makeRootWithConfig("preset: max\n");
    $this->writeBaseline($root);
    $executor = $this->awareExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver(), $this->vcs(['web/x.php']));
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', WorkflowConfig::load($root), 'abc', BaselineStore::hash($root));

    $report = $runner->run($state, Phase::Code, $root);

    $this->assertTrue($report->advance());
    $this->assertSame(['phpcs', 'phpstan', 'eslint', 'stylelint', 'prettier'], $executor->withBaseline, 'every consulting shell gate went through the baseline path');
    $this->assertSame([], $executor->plain);
    $this->assertSame(['web/x.php'], $executor->changed, 'the run\'s changed files ride the context');
  }

  /**
   * Held to no baseline (lever off), gates run plain even when one exists.
   */
  public function testLeverOffFreezesNoBaselineAndRunsPlain(): void {
    $root = $this->makeRootWithConfig("preset: max\nbaseline: { on: false }\n");
    $this->writeBaseline($root);
    $config = WorkflowConfig::load($root);
    $this->assertFalse($config->baseline);
    $executor = $this->awareExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver());
    // The facade freezes NULL when the lever is off; the runner then sees a
    // present baseline against a NULL freeze — which is exactly a change.
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', $config, NULL, NULL);
    // So strict mode is the disk agreeing with the freeze: no baseline dir.
    $this->removeBaseline($root);

    $report = $runner->run($state, Phase::Code, $root);

    $this->assertSame([], $executor->withBaseline);
    $this->assertSame(['phpcs', 'phpstan', 'eslint', 'stylelint', 'prettier'], $executor->plain);
    $this->assertTrue($report->advance());
  }

  /**
   * A baseline edited under a run fails every consulting gate, by name.
   */
  public function testBaselineChangedMidRunFailsTheConsultingGates(): void {
    $root = $this->makeRootWithConfig("preset: max\n");
    $this->writeBaseline($root);
    $executor = $this->awareExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver());
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', WorkflowConfig::load($root), NULL, BaselineStore::hash($root));

    // The agent "sweeps" a finding into the baseline mid-run.
    file_put_contents($root . '/droost/baseline/phpcs.json', '{"v":1,"findings":[{"key":"swept"}]}');

    $report = $runner->run($state, Phase::Test, $root);

    $phpunit = $this->resultFor($report, 'phpunit');
    $this->assertSame(GateStatus::Passed, $phpunit->status, 'a gate that never consults the baseline still runs');
    $coverage = $this->resultFor($report, 'coverage');
    $this->assertSame(GateStatus::Failed, $coverage->status);
    $this->assertStringContainsString('the baseline changed during the run', $coverage->summary);
    $this->assertStringContainsString('frozen ' . BaselineStore::short($state->baselineHash), $coverage->summary);
    $this->assertSame('baseline integrity check', $coverage->invocation);
    $this->assertFalse($report->advance());
    $this->assertSame([], $executor->withBaseline, 'nothing consulting was executed');
  }

  /**
   * A baseline that appears mid-run (none was frozen) is a change too.
   */
  public function testBaselineAddedMidRunIsChange(): void {
    $root = $this->makeRootWithConfig("preset: max\n");
    $executor = $this->awareExecutor();
    $runner = new GateRunner($executor, new NullSiteDriver());
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', WorkflowConfig::load($root), NULL, NULL);
    $this->writeBaseline($root);

    $report = $runner->run($state, Phase::Code, $root);

    $this->assertSame(GateStatus::Failed, $this->resultFor($report, 'phpcs')->status);
    $this->assertStringContainsString('frozen none, now ', $this->resultFor($report, 'phpcs')->summary);
  }

  /**
   * The two frozen facts survive the state file.
   */
  public function testBaseCommitAndHashRoundTripThroughTheStore(): void {
    $root = $this->makeRootWithConfig("preset: max\n");
    $this->writeBaseline($root);
    $hash = BaselineStore::hash($root);
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', WorkflowConfig::load($root), 'abc123', $hash);
    $store = new RunStateStore($root);
    $store->save($state->withSpecPath('spec.md')->withFeedbackAttempt('phpcs'));

    $loaded = $store->load();
    $this->assertNotNull($loaded);
    $this->assertSame('abc123', $loaded->baseCommit);
    $this->assertSame($hash, $loaded->baselineHash);
    $document = $loaded->toArray();
    $this->assertSame('abc123', $document['base_commit']);
    $this->assertSame($hash, $document['baseline_hash']);
    $this->assertNull(RunState::begin('r', 't', WorkflowConfig::load($root))->baselineHash, 'begin() without a hash is held to none');
  }

  /**
   * Writes a minimal baseline covering phpcs.
   *
   * @param string $root
   *   The root.
   */
  private function writeBaseline(string $root): void {
    $files = ['phpcs.json' => '{"v":1,"gate":"phpcs","findings":[]}'];
    BaselineStore::write($root, new BaselineManifest('2026-09-08T00:00:00+00:00', 'abc', 'max', [
      'phpcs' => ['file' => 'phpcs.json', 'count' => 0, 'sha256' => hash('sha256', $files['phpcs.json'])],
    ]), $files);
  }

  /**
   * Removes the baseline directory.
   *
   * @param string $root
   *   The root.
   */
  private function removeBaseline(string $root): void {
    foreach (glob($root . '/droost/baseline/*') ?: [] as $file) {
      unlink($file);
    }
    rmdir($root . '/droost/baseline');
  }

  /**
   * A fake vcs answering a fixed changed-file list.
   *
   * @param list<string> $changed
   *   The files.
   *
   * @return \Droost\Workflow\Vcs\VcsInterface
   *   The vcs.
   */
  private function vcs(array $changed): VcsInterface {
    return new class($changed) implements VcsInterface {

      /**
       * Constructs the fake.
       *
       * @param list<string> $changed
       *   The files.
       */
      public function __construct(private readonly array $changed) {}

      /**
       * {@inheritdoc}
       */
      public function head(string $projectRoot): string {
        return 'abc';
      }

      /**
       * {@inheritdoc}
       */
      public function changedFiles(string $projectRoot, ?string $base): array {
        return $this->changed;
      }

    };
  }

  /**
   * An executor recording which path each gate took; everything passes.
   *
   * @return \Droost\Workflow\Baseline\BaselineAwareExecutorInterface&object{withBaseline: list<string>, plain: list<string>, changed: list<string>}
   *   The executor.
   */
  private function awareExecutor(): BaselineAwareExecutorInterface {
    return new class() implements BaselineAwareExecutorInterface {

      /**
       * Gates run against the baseline.
       *
       * @var list<string>
       */
      public array $withBaseline = [];

      /**
       * Gates run plain.
       *
       * @var list<string>
       */
      public array $plain = [];

      /**
       * The changed files the last context carried.
       *
       * @var list<string>
       */
      public array $changed = [];

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $this->plain[] = $gate->name;
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

      /**
       * {@inheritdoc}
       */
      public function executeWithBaseline(GateSettings $gate, string $projectRoot, BaselineContext $context): GateResult {
        $this->withBaseline[] = $gate->name;
        $this->changed = $context->changedFiles;
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed — 0 new, 0 inherited', [], $gate->name)
          ->withBaselineCounts(0, 0);
      }

    };
  }

  /**
   * The result for one gate.
   *
   * @param \Droost\Workflow\Gate\PhaseReport $report
   *   The report.
   * @param string $gate
   *   The gate.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The result.
   */
  private function resultFor(PhaseReport $report, string $gate): GateResult {
    foreach ($report->results as $result) {
      if ($result->gate === $gate) {
        return $result;
      }
    }
    $this->fail('no result for ' . $gate);
  }

}
