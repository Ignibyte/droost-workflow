<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\PendingQuestion;
use Droost\Workflow\Mode\QuestionSinkInterface;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\WorkflowFacade;

/**
 * A notification that throws never turns a saved run into a failed one.
 *
 * The lifecycle listener this file was named for left with the Drupal hook
 * bridge it served (2026-10-21); the run-event log records the transitions
 * (RunEventLogFacadeTest). What stays is the sink's half of the contract.
 */
final class WorkflowFacadeEventsTest extends WorkflowTestCase {

  /**
   * F-142, through the facade: a sink that throws cannot lose the pause.
   */
  public function testThrowingSinkLeavesThePauseSaved(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\nseekers:\n  on: false\n");
    $throwing = new class() implements QuestionSinkInterface {

      /**
       * {@inheritdoc}
       */
      public function emit(PendingQuestion $question): void {
        throw new \RuntimeException('the transport is down');
      }

    };
    $facade = new WorkflowFacade(
      $this->allGatesPass(),
      $throwing,
      static fn (): string => '2026-09-03T12:00:00+00:00',
      static fn (): string => 'run-sink',
    );

    $outcome = $facade->run($root);

    $this->assertSame(Outcome::Paused, $outcome->outcome, 'the run paused, the sink\'s throw caught');
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertNotNull($state->awaiting, 'and the pause is in run.json');
    $this->assertSame(Outcome::Paused, $facade->run($root)->outcome, 'a re-entered run re-presents it');
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

}
