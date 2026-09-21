<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\WorkflowFacade;

/**
 * The dial must SAY how many inspections it resolved to.
 *
 * `seekers.rounds` shipped as a lever the engine enforces and as a value
 * nothing reported: `status` carried `seekers: true` and no count, so an
 * operator could set it, could be held by it, and had no way to read back
 * what governed the run. Caught while preparing a round that exists partly
 * to exercise the lever — by running `status` and finding the key absent.
 *
 * That is this project's own rule turned on itself. The observability
 * harness's `set-dial.sh` prints "RESOLVED — what the status command says,
 * not what the file asked for", and it could not say this one.
 */
final class WorkflowFacadeSeekerRoundsVisibleTest extends WorkflowTestCase {

  /**
   * With no run open, the levers view reports the resolved count.
   */
  public function testTheLeversViewReportsTheResolvedCount(): void {
    $levers = $this->levers(
      $this->makeRootWithConfig("preset: medium\nseekers: { on: true, rounds: 3 }\n"),
    );

    $this->assertArrayHasKey('seeker_rounds', $levers, 'the dial must say what it resolved');
    $this->assertSame(3, $levers['seeker_rounds']);
    $this->assertTrue($levers['seekers']);
  }

  /**
   * The default is reported too — a silent 1 is still a fact about the run.
   */
  public function testTheDefaultIsReportedRatherThanOmitted(): void {
    $levers = $this->levers($this->makeRootWithConfig("preset: medium\n"));

    $this->assertSame(
      1,
      $levers['seeker_rounds'],
      'a file that says nothing still resolves to one, and the reader is owed it',
    );
  }

  /**
   * The levers block of the status document.
   *
   * @param string $root
   *   The project root.
   *
   * @return array<string, mixed>
   *   The block.
   */
  private function levers(string $root): array {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, 'passed', [], $gate->name);
      }

    };
    $facade = new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-08T00:00:00+00:00',
      static fn (): string => 'run-rounds',
    );
    $status = $facade->status($root);
    $this->assertIsArray($status['levers']);

    return $status['levers'];
  }

}
