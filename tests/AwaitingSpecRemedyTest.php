<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\WorkflowFacade;

/**
 * The first thing a run tells the agent is where to write the spec.
 *
 * A bare `run` opens the run and waits for a spec (F-25), with one blocked
 * row whose remedy names the file to write. That remedy was built from the
 * run record's label, which is the FILE, so every run since 2026-09-16 was
 * told to write `droost/droost-workflow/run.json/spec-<slug>.md`. Agents
 * routed around it by reading the skill; P6 run 6's said so.
 */
final class AwaitingSpecRemedyTest extends WorkflowTestCase {

  /**
   * The remedy names the state directory, not the run record inside it.
   */
  public function testTheRemedyNamesTheStateDirectory(): void {
    $root = $this->makeRoot();
    $executor = new class implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 5, 'ok');
      }

    };
    $facade = new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-23T12:00:00+00:00',
      static fn (): string => 'run-awaiting',
    );

    $outcome = $facade->run($root);

    $this->assertSame(Outcome::Blocked, $outcome->outcome, 'a bare run opens and waits');
    $this->assertCount(1, $outcome->blocked);
    $row = $outcome->blocked[0];
    $this->assertSame('spec', $row['check']);
    $this->assertStringContainsString('Write droost/droost-workflow/spec-<slug>.md', $row['remedy']);
    $this->assertStringNotContainsString('run.json/', $row['remedy'], 'a file is not a directory');
  }

}
