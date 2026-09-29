<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * At low, the test phase takes the browser run the agent recorded (0.11).
 *
 * The owner, 2026-09-29: at low, playwright records its runs and need not
 * always run them. `droost-workflow specs` is droost running the gate, not
 * the agent reporting a run, so what the phase takes is a measurement. It is
 * taken only on the tree it was made on, and the row says it was.
 */
class RecordedRunTest extends WorkflowTestCase {

  /**
   * A recorded pass on an unchanged tree is taken, and says so.
   */
  public function testRecordedPassIsTakenOnTheSameTree(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);
    $this->walkToTest($root, $gates);

    $recorded = $this->facade($gates)->recordSpecs($root);
    $this->assertSame('passed', $recorded['result']['status'] ?? NULL);
    $this->assertTrue($recorded['reusable']);
    $this->assertSame(1, $gates->executions['playwright'] ?? 0);

    $outcome = $this->facade($gates)->run($root);
    $this->assertSame(Outcome::Advanced, $outcome->outcome);
    $this->assertSame(1, $gates->executions['playwright'], 'the suite did not run a second time');
    $browser = $this->gateResult($outcome->report->results ?? [], 'playwright');
    $this->assertSame(GateStatus::Passed, $browser?->status);
    $this->assertStringContainsString('on this tree: not run again', $browser->summary);
  }

  /**
   * A tree that moved after the recording runs the suite again.
   */
  public function testAnEditAfterTheRecordingRunsTheSuite(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);
    $this->walkToTest($root, $gates);

    $this->facade($gates)->recordSpecs($root);
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "test('changed after the recording', () => {});\n");
    $outcome = $this->facade($gates)->run($root);

    $this->assertSame(2, $gates->executions['playwright'] ?? 0);
    $browser = $this->gateResult($outcome->report->results ?? [], 'playwright');
    $this->assertStringNotContainsString('not run again', $browser->summary ?? '');
  }

  /**
   * Medium never takes a recording: the level runs the suite itself.
   */
  public function testMediumRunsTheSuiteWhateverWasRecorded(): void {
    $root = $this->project("preset: medium\nseekers:\n  on: false\n");
    $gates = $this->gates([]);
    $this->walkToTest($root, $gates);

    $recorded = $this->facade($gates)->recordSpecs($root);
    $this->assertFalse($recorded['reusable'], 'recorded, but this level does not take it');
    $this->facade($gates)->run($root);

    $this->assertSame(2, $gates->executions['playwright'] ?? 0);
  }

  /**
   * A recorded failure is a failure: the run goes back to code.
   */
  public function testRecordedFailureSendsTheRunBack(): void {
    $root = $this->project("preset: low\n");
    $failing = GateResult::ran(
      'playwright',
      GateStatus::Failed,
      1,
      1,
      'playwright failed (exit 1): 1 failed, at tests/e2e/mine.spec.ts:2',
      [['file' => 'tests/e2e/mine.spec.ts', 'line' => 2, 'detail' => 'failed']],
      'playwright test tests/e2e/mine.spec.ts',
    );
    $gates = $this->gates(['playwright' => [$failing]]);
    $this->walkToTest($root, $gates);

    $this->facade($gates)->recordSpecs($root);
    $outcome = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Returned, $outcome->outcome);
    $this->assertSame(1, $gates->executions['playwright'], 'the recorded failure was taken, not re-run');
  }

  /**
   * A tool that could not run is never taken for a measurement.
   */
  public function testToolErrorIsNeverTaken(): void {
    $root = $this->project("preset: low\n");
    $crashed = new GateResult('playwright', GateStatus::ErrorToolFailed, 1, 1, 'playwright could not run: the browser crashed');
    $gates = $this->gates(['playwright' => [$crashed]]);
    $this->walkToTest($root, $gates);

    $recorded = $this->facade($gates)->recordSpecs($root);
    $this->assertFalse($recorded['reusable']);
    $this->facade($gates)->run($root);

    $this->assertSame(2, $gates->executions['playwright'] ?? 0);
  }

  /**
   * Specs are recorded at test and nowhere else.
   */
  public function testSpecsAreRecordedOnlyAtTest(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);
    $this->facade($gates)->run($root);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('this run is at code');
    $this->facade($gates)->recordSpecs($root);
  }

  /**
   * A reuse value that is neither is refused by name.
   */
  public function testUnknownReuseIsRefused(): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('must be "recorded"');
    WorkflowConfig::fromArray(['preset' => 'low', 'gates' => ['playwright' => ['reuse' => 'sometimes']]], 'test');
  }

  /**
   * The result a report holds for a gate.
   *
   * @param list<\Droost\Workflow\Gate\GateResult> $results
   *   The report's results.
   * @param string $gate
   *   The gate.
   *
   * @return \Droost\Workflow\Gate\GateResult|null
   *   Its result, or NULL.
   */
  private function gateResult(array $results, string $gate): ?GateResult {
    foreach ($results as $result) {
      if ($result->gate === $gate) {
        return $result;
      }
    }

    return NULL;
  }

  /**
   * A project in a repository, with a source tree and a spec.
   *
   * @param string $yaml
   *   The lever file.
   *
   * @return string
   *   The root.
   */
  private function project(string $yaml): string {
    $root = $this->makeRootWithConfig($yaml . "mode: agentic\n");
    mkdir($root . '/src', 0755, TRUE);
    mkdir($root . '/tests/e2e', 0755, TRUE);
    file_put_contents($root . '/src/Thing.php', "<?php\n");
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "\n");
    exec(sprintf(
      'cd %s && git init -q . && git add -A && git -c user.name=droost-test -c user.email=test@example.invalid commit -q -m base 2>&1',
      escapeshellarg($root),
    ), $output, $exit);
    $this->assertSame(0, $exit, implode("\n", $output));

    return $root;
  }

  /**
   * Drives a run to test, with the ticket's spec changed at code.
   *
   * @param string $root
   *   The project.
   * @param \Droost\Workflow\Gate\GateExecutorInterface $gates
   *   The executor.
   */
  private function walkToTest(string $root, GateExecutorInterface $gates): void {
    $facade = $this->facade($gates);
    $facade->run($root);
    $facade->declareChanges($root, ['src', 'tests'], [], 'code');
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "test('the ticket', () => {});\n");
    for ($i = 0; $i < 6 && (new RunStateStore($root))->load()?->currentPhase !== Phase::Test; $i++) {
      $this->facade($gates)->run($root);
    }
    $this->assertSame(Phase::Test, (new RunStateStore($root))->load()?->currentPhase);
  }

  /**
   * An executor that passes everything but the queued results.
   *
   * @param array<string, list<\Droost\Workflow\Gate\GateResult>> $queued
   *   Results each gate returns, in order, before it starts passing.
   *
   * @return object{executions: array<string, int>}&\Droost\Workflow\Gate\GateExecutorInterface
   *   The double.
   */
  private function gates(array $queued): object {
    return new class($queued) implements GateExecutorInterface {

      /**
       * Executions per gate.
       *
       * @var array<string, int>
       */
      public array $executions = [];

      /**
       * Constructs the double.
       *
       * @param array<string, list<\Droost\Workflow\Gate\GateResult>> $queued
       *   The queued results.
       */
      public function __construct(private array $queued) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $this->executions[$gate->name] = ($this->executions[$gate->name] ?? 0) + 1;
        if (($this->queued[$gate->name] ?? []) !== []) {
          return array_shift($this->queued[$gate->name]);
        }

        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed — 2 passed', [], $gate->name);
      }

    };
  }

  /**
   * A fresh facade, as a new process would build it.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $gates
   *   The shared executor.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $gates): WorkflowFacade {
    return new WorkflowFacade(
      $gates,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-29T12:00:00+00:00',
      static fn (): string => 'run-recorded',
    );
  }

}
