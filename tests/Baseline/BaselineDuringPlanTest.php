<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Baseline;

use Droost\Workflow\Baseline\BaselineError;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Cli\CliProcess;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * A run still in plan takes its first baseline (F-371).
 *
 * An adopter opened a run with the lever on and nothing recorded, could not
 * record a baseline while the run was open, and had to reset it. While the
 * run is in plan, opened with none, and no gate has judged anything, the
 * first baseline is written and frozen into the run.
 */
final class BaselineDuringPlanTest extends WorkflowTestCase {

  /**
   * The levers: every gate the writer measures is off, so it records none.
   */
  private const LEVERS = "preset: custom\nmode: agentic\ngates:\n  phpcs: { on: false }\n  phpstan: { on: false }\n"
    . "  phpunit: { on: false }\n  playwright: { on: false }\n  parity: { on: false }\n  house_rules: { on: false }\n";

  /**
   * In plan, the first baseline is written and the run is held to it.
   */
  public function testPlanTakesTheFirstBaseline(): void {
    // No spec: the run opens and waits in plan.
    $root = $this->makeRoot();
    mkdir($root . '/droost', 0755, TRUE);
    file_put_contents($root . '/droost/droost.yml', self::LEVERS);
    $facade = $this->facade();
    $facade->run($root);
    $open = (new RunStateStore($root))->load();
    $this->assertNotNull($open);
    $this->assertSame(Phase::Plan, $open->currentPhase);
    $this->assertNull($open->baselineHash);

    $written = $facade->baselineWrite($root);
    $this->assertSame($open->runId, $written['frozen_into_run']);
    $held = (new RunStateStore($root))->load();
    $this->assertNotNull($held);
    $this->assertSame(BaselineStore::hash($root), $held->baselineHash);
  }

  /**
   * A run past plan still waits for the run to end.
   */
  public function testLaterStillWaits(): void {
    $root = $this->makeRootWithConfig(self::LEVERS);
    $facade = $this->facade();
    $facade->run($root);
    $open = (new RunStateStore($root))->load();
    $this->assertNotNull($open);
    $this->assertNotSame(Phase::Plan, $open->currentPhase, 'with a spec the run is past plan');
    $this->expectException(BaselineError::class);
    $facade->baselineWrite($root);
  }

  /**
   * A refresh waits for the run to end even in plan.
   */
  public function testRefreshWaits(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost', 0755, TRUE);
    file_put_contents($root . '/droost/droost.yml', self::LEVERS);
    $facade = $this->facade();
    $facade->run($root);
    $this->expectException(BaselineError::class);
    $facade->baselineWrite($root, TRUE);
  }

  /**
   * A facade that measures with the shell.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    return new WorkflowFacade(
      new ShellGateExecutor(
        static fn (array $argv, string $cwd, int $timeout): array => CliProcess::run($argv, $cwd, $timeout),
        static fn (): int => 0,
      ),
      new RunStateOnlySink(),
      static fn (): string => '2026-10-24T00:00:00+00:00',
      static fn (): string => 'run-baseline-plan',
    );
  }

}
