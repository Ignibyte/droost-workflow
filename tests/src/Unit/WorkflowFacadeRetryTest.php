<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit;

use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Gate\GateExecutorInterface;
use Drupal\droost_workflow\Gate\GateResult;
use Drupal\droost_workflow\Gate\GateStatus;
use Drupal\droost_workflow\Gate\NullSiteDriver;
use Drupal\droost_workflow\Mode\Outcome;
use Drupal\droost_workflow\Mode\RunStateOnlySink;
use Drupal\droost_workflow\State\PhaseStatus;
use Drupal\droost_workflow\State\RunStateStore;
use Drupal\droost_workflow\WorkflowFacade;

/**
 * REQ-004: the feedback loop, end to end, across separate invocations.
 *
 * Every invocation below builds a FRESH facade over the same executor, the
 * way real invocations arrive as separate processes: the only thing carrying
 * the budget between them is .droost-workflow/run.json. An engine-level test
 * cannot prove that half, and it is the half that was missing — the counters
 * existed and were unit-tested while nothing in production consulted them.
 */
class WorkflowFacadeRetryTest extends WorkflowTestCase {

  /**
   * One failing gate retries exactly max_gate_retries times, then refuses.
   */
  public function testFailingGateRetriesToItsBoundThenRefuses(): void {
    $root = $this->makeRootWithConfig(
      "preset: custom\nmax_gate_retries: 1\n",
    );
    $executor = $this->phpcsAlwaysFails();

    // Invocation 1 — plan, which the phase map leaves gateless: advances.
    $first = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Advanced, $first->outcome);
    $this->assertSame('code', $first->state->currentPhase?->value);
    $this->assertSame(0, $executor->executions['phpcs'] ?? 0);

    // Invocation 2 — code: phpcs fails, budget 0 < 1, one attempt counted.
    $second = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $second->outcome);
    $this->assertFalse($second->exhausted());
    $this->assertSame(['phpcs' => 1], $second->state->feedbackAttempts);
    $this->assertSame(1, $executor->executions['phpcs']);

    // Invocation 3 — code again: phpcs fails, 1 < 1 is out of budget. The
    // phase is terminally failed, recorded as such on disk.
    $third = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $third->outcome);
    $this->assertTrue($third->exhausted());
    $this->assertSame(2, $executor->executions['phpcs']);
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertSame(PhaseStatus::Failed, $reloaded->statusOf(Phase::Code));

    // Invocation 4 — refused: outcome failed, and NOTHING executed. A
    // terminal run silently restarted would be the retry bound un-enforced.
    $fourth = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $fourth->outcome);
    $this->assertTrue($fourth->exhausted());
    $this->assertNull($fourth->report);
    $this->assertSame(
      2,
      $executor->executions['phpcs'],
      'a terminally failed phase must not execute gates again',
    );

    // The envelope carries the whole story for a caller.
    $envelope = $fourth->toArray();
    $this->assertSame(
      ['outcome', 'current_phase', 'report', 'awaiting', 'retries'],
      array_keys($envelope),
    );
    $this->assertSame(
      ['attempts' => ['phpcs' => 1], 'max_gate_retries' => 1, 'exhausted' => TRUE],
      $envelope['retries'],
    );
  }

  /**
   * The attempt counters survive the process boundary.
   *
   * Belt and braces over the scenario above: assert directly that a counter
   * written by one facade is read back by a store the next facade uses.
   */
  public function testAttemptCountersPersistBetweenFacades(): void {
    $root = $this->makeRootWithConfig(
      "preset: custom\nmax_gate_retries: 3\n",
    );
    $executor = $this->phpcsAlwaysFails();

    $this->facade($executor)->run($root);
    $this->facade($executor)->run($root);

    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertSame(['phpcs' => 1], $reloaded->feedbackAttempts);
  }

  /**
   * An executor where phpcs always fails and everything else passes.
   *
   * @return object{executions: array<string, int>}&\Drupal\droost_workflow\Gate\GateExecutorInterface
   *   The double, counting executions per gate.
   */
  private function phpcsAlwaysFails(): object {
    return new class() implements GateExecutorInterface {

      /**
       * Executions per gate name.
       *
       * @var array<string, int>
       */
      public array $executions = [];

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        $this->executions[$gate->name] =
          ($this->executions[$gate->name] ?? 0) + 1;
        return $gate->name === 'phpcs'
          ? new GateResult('phpcs', GateStatus::Failed, 1, 1, 'nope')
          : new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
  }

  /**
   * A fresh facade over a shared executor, as a new process would build it.
   *
   * @param \Drupal\droost_workflow\Gate\GateExecutorInterface $executor
   *   The shared executor.
   *
   * @return \Drupal\droost_workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $executor): WorkflowFacade {
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-08-13T10:00:00+00:00',
      static fn (): string => 'run-retry',
    );
  }

}
