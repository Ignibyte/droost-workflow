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
   * A run whose every gate passes completes — and the terminal state PERSISTS.
   *
   * The final phase has no phase to advance to, so the transition that records
   * a phase passed (advanceTo) never fired for it: run() returned
   * Outcome::Completed while run.json still showed "complete" active with a
   * current phase set. A finished run then read as in-progress to status,
   * report and reset. This drives a full run to completion over separate
   * facades and asserts the RELOADED record is terminal — no current phase, the
   * final phase passed — the half an engine-level test cannot prove.
   */
  public function testCompletedRunPersistsItsTerminalState(): void {
    $root = $this->makeRootWithConfig(
      "preset: custom\nmax_gate_retries: 1\n",
    );
    $executor = $this->allGatesPass();

    // A fresh facade per invocation, as separate processes arrive; more turns
    // than a plan -> code -> test -> complete walk needs.
    $outcome = NULL;
    for ($i = 0; $i < 16; $i++) {
      $facade = $this->facade($executor);
      $outcome = $facade->run($root);
      if ($outcome->outcome === Outcome::InspectionDue) {
        // The seeker checkpoint holds a green phase until a clean inspection is
        // on record; record one and let the walk continue to completion.
        $facade->recordSeeker(
          $root,
          "## Seeker Inspection\n\n(no findings)\n",
        );
        continue;
      }
      if ($outcome->outcome !== Outcome::Advanced) {
        break;
      }
    }
    // The loop runs at least once, so $outcome is set.
    $this->assertSame(
      Outcome::Completed,
      $outcome->outcome,
      'a run whose every gate passes reaches completion',
    );

    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertNull(
      $reloaded->currentPhase,
      'a completed run has reached its terminal gate — no current phase',
    );
    $this->assertSame(
      PhaseStatus::Passed,
      $reloaded->statusOf(Phase::Complete),
      'the final phase is recorded passed, not left active',
    );

    // Re-running a completed run re-affirms it and leaves the record terminal;
    // it does not silently restart.
    $again = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Completed, $again->outcome);
    $this->assertNull((new RunStateStore($root))->load()?->currentPhase);
  }

  /**
   * An executor where phpcs always fails and everything else passes.
   *
   * @return object{executions: array<string, int>}&\Droost\Workflow\Gate\GateExecutorInterface
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
   * An executor where every gate passes.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The double.
   */
  private function allGatesPass(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
  }

  /**
   * A fresh facade over a shared executor, as a new process would build it.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The shared executor.
   *
   * @return \Droost\Workflow\WorkflowFacade
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
