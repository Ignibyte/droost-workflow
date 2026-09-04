<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Event\NullWorkflowListener;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\WorkflowFacade;

/**
 * Lifecycle events fire in order, and a throwing listener can't break the run.
 *
 * This is the seam a Drupal hook bridge (and, through it, the Jira plugin)
 * hangs off. Two contracts are pinned: the events map exactly onto the
 * transitions the facade already persists — one start, one per real advance,
 * one completion — and a listener that throws on every call (a Jira token that
 * is wrong, a network that is down) never turns a saved, correct run into a
 * failed one. That tolerance is the whole reason the notification is kept
 * separate from the record.
 */
final class WorkflowFacadeEventsTest extends WorkflowTestCase {

  /**
   * An agentic run emits start, one phase-change per advance, then complete.
   */
  public function testLifecycleEventsFireInOrderAcrossAnAgenticRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\n");
    $executor = $this->allGatesPass();
    $listener = new class() extends NullWorkflowListener {

      /**
       * The events seen, in order.
       *
       * @var list<string>
       */
      public array $log = [];

      /**
       * {@inheritdoc}
       */
      public function onRunStart(RunState $state): void {
        $this->log[] = 'start';
      }

      /**
       * {@inheritdoc}
       */
      public function onPhaseChange(RunState $state, Phase $from, Phase $to): void {
        $this->log[] = 'phase:' . $from->value . '>' . $to->value;
      }

      /**
       * {@inheritdoc}
       */
      public function onRunComplete(RunState $state): void {
        $this->log[] = 'complete';
      }

    };

    // Same facade config each process, as a real surface would rebuild it;
    // the one listener instance accumulates across them.
    for ($i = 0; $i < 12; $i++) {
      $outcome = $this->facade($executor, $listener)->run($root);
      if ($outcome->outcome !== Outcome::Advanced) {
        break;
      }
    }

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertNull($state->currentPhase, 'the agentic run reached its end');

    $this->assertSame(
      [
        'start',
        'phase:' . Phase::Plan->value . '>' . Phase::Code->value,
        'phase:' . Phase::Code->value . '>' . Phase::Test->value,
        'phase:' . Phase::Test->value . '>' . Phase::Complete->value,
        'complete',
      ],
      $listener->log,
      'events map one-to-one onto the persisted transitions',
    );
    $this->assertCount(
      1,
      array_filter($listener->log, static fn (string $e): bool => $e === 'start'),
      'a run starts exactly once, however many invocations drive it',
    );
  }

  /**
   * A listener that throws on every call never stops the run.
   */
  public function testThrowingListenerNeverBreaksTheRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\n");
    $executor = $this->allGatesPass();
    $listener = new class() extends NullWorkflowListener {

      /**
       * {@inheritdoc}
       */
      public function onRunStart(RunState $state): void {
        throw new \RuntimeException('the work-item backend is down');
      }

      /**
       * {@inheritdoc}
       */
      public function onPhaseChange(RunState $state, Phase $from, Phase $to): void {
        throw new \RuntimeException('the work-item backend is down');
      }

      /**
       * {@inheritdoc}
       */
      public function onRunComplete(RunState $state): void {
        throw new \RuntimeException('the work-item backend is down');
      }

    };

    for ($i = 0; $i < 12; $i++) {
      $outcome = $this->facade($executor, $listener)->run($root);
      if ($outcome->outcome !== Outcome::Advanced) {
        break;
      }
    }

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertNull(
      $state->currentPhase,
      'the run completed despite every listener call throwing',
    );
  }

  /**
   * In interactive mode the same events fire — driven by answers, not advances.
   */
  public function testLifecycleEventsFireInInteractiveMode(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nseekers:\n  on: false\n");
    $executor = $this->allGatesPass();
    $listener = new class() extends NullWorkflowListener {

      /**
       * The events seen, in order.
       *
       * @var list<string>
       */
      public array $log = [];

      /**
       * {@inheritdoc}
       */
      public function onRunStart(RunState $state): void {
        $this->log[] = 'start';
      }

      /**
       * {@inheritdoc}
       */
      public function onPhaseChange(RunState $state, Phase $from, Phase $to): void {
        $this->log[] = 'phase:' . $from->value . '>' . $to->value;
      }

      /**
       * {@inheritdoc}
       */
      public function onRunComplete(RunState $state): void {
        $this->log[] = 'complete';
      }

    };

    // Interactive holds at every phase: run() begins and pauses, and the ANSWER
    // advances. So phase-change and completion fire from the answer path, not
    // just the agentic advance path — the mode the Jira work must run in too.
    for ($i = 0; $i < 16; $i++) {
      $outcome = $this->facade($executor, $listener)->run($root);
      if ($outcome->outcome === Outcome::Completed) {
        break;
      }
      if ($outcome->outcome !== Outcome::Paused) {
        break;
      }
      $answered = $this->facade($executor, $listener)->answer($root, 'continue');
      if ($answered->currentPhase === NULL) {
        break;
      }
    }

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertNull($state->currentPhase, 'the interactive run reached its end');
    $this->assertSame(
      [
        'start',
        'phase:' . Phase::Plan->value . '>' . Phase::Code->value,
        'phase:' . Phase::Code->value . '>' . Phase::Test->value,
        'phase:' . Phase::Test->value . '>' . Phase::Complete->value,
        'complete',
      ],
      $listener->log,
      'interactive fires the same lifecycle events, via the answer path',
    );
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
   * A fresh facade over a shared executor and listener.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The shared executor.
   * @param \Droost\Workflow\Event\NullWorkflowListener $listener
   *   The lifecycle listener to observe with.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(
    GateExecutorInterface $executor,
    NullWorkflowListener $listener,
  ): WorkflowFacade {
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-03T12:00:00+00:00',
      static fn (): string => 'run-events',
      $listener,
    );
  }

}
