<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\WorkflowFacade;

/**
 * An operator waiver reopens a phase that a gate failed terminally.
 *
 * D70 round 2 (T26 at max): rendered_check failed inside the gate run on a
 * page that rendered 200 every other way. Had it spent the budget, the only
 * recovery was reset — throwing away a four-hour governed run over a gate the
 * operator was willing to sign for. The waiver is the operator's signed,
 * reasoned act; it now lifts a terminal failure too, and the gate is recorded
 * as WAIVED, never as passed.
 */
class WorkflowFacadeWaiverRescueTest extends WorkflowTestCase {

  /**
   * Exhausted, refused, waived, reopened — and the waived gate is not a pass.
   */
  public function testWaivingTheKillingGateReopensTheTerminalPhase(): void {
    $root = $this->makeRootWithConfig(
      // Seekers off so the reopened code phase ADVANCES rather than holding at
      // the inspection checkpoint — the hold is correct behaviour, but it is
      // not what this test measures.
      "preset: custom\nmax_gate_retries: 0\nseekers: { on: false }\ngates:\n  eslint: { on: true }\n",
    );
    $executor = $this->failing('eslint');

    // Plan is gateless: advances to code.
    $this->assertSame(Outcome::Advanced, $this->facade($executor)->run($root)->outcome);

    // Code: eslint fails with no retry budget — terminal at once.
    $failed = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $failed->outcome);
    $this->assertTrue($failed->exhausted());
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertSame(PhaseStatus::Failed, $reloaded->statusOf(Phase::Code));

    // Refused while unwaived: nothing executes.
    $before = $executor->executions;
    $refused = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $refused->outcome);
    $this->assertNull($refused->report);
    $this->assertSame($before, $executor->executions, 'a terminal phase runs nothing');

    // A waiver on a gate that did NOT kill the phase changes nothing.
    $this->facade($executor)->waiveGate($root, 'stylelint', 'unrelated');
    $stillRefused = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $stillRefused->outcome);
    $this->assertNull($stillRefused->report);

    // The operator waives the gate that killed it: the phase reopens, runs,
    // and advances — eslint recorded as waived with the reason, never passed.
    $this->facade($executor)->waiveGate($root, 'eslint', 'linter false positive on vendored fixture; verified by hand');
    $rescued = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Advanced, $rescued->outcome);
    $this->assertFalse($rescued->exhausted());
    $this->assertSame('test', $rescued->state->currentPhase?->value);
    $this->assertNotNull($rescued->report);
    $eslint = NULL;
    foreach ($rescued->report->results as $result) {
      if ($result->gate === 'eslint') {
        $eslint = $result;
      }
    }
    $this->assertInstanceOf(GateResult::class, $eslint);
    $this->assertSame(GateStatus::Waived, $eslint->status);
    $this->assertStringContainsString('verified by hand', $eslint->summary);
    $this->assertGreaterThan(0, $rescued->report->tally()['passed'], 'the other gates ran normally');
  }

  /**
   * A facade over the given executor, with no site and a fixed clock.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The gate executor.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $executor): WorkflowFacade {
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-07T20:00:00+00:00',
      static fn (): string => 'run-rescue',
    );
  }

  /**
   * An executor that fails one named gate and passes the rest.
   *
   * @param string $gate
   *   The gate to fail.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface&object{executions: array<string, int>}
   *   The executor, counting executions per gate.
   */
  private function failing(string $gate): GateExecutorInterface {
    return new class($gate) implements GateExecutorInterface {

      /**
       * Executions per gate.
       *
       * @var array<string, int>
       */
      public array $executions = [];

      public function __construct(private readonly string $failing) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $this->executions[$gate->name] = ($this->executions[$gate->name] ?? 0) + 1;
        if ($gate->name === $this->failing) {
          return GateResult::ran($gate->name, GateStatus::Failed, 1, 1, $gate->name . ' failed', [], $gate->name);
        }
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
  }

}
