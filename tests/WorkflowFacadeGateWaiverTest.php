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
use Droost\Workflow\Mode\RunOutcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\State\StateError;
use Droost\Workflow\WorkflowFacade;

/**
 * The scoped gate waiver: one gate, one run, one recorded reason.
 *
 * Born from two live rounds reaching for `droost:workflow:bypass` in the
 * belief it cleared a gate — it drops the require_run wall instead, arming
 * ungoverned edits: the wrong hammer, twice. The waiver is the right one,
 * and these are its edges: operator-only (no MCP surface exists), never the
 * mandatory trio, never reason-less, dies with the run, renders as its own
 * status rather than any kind of pass.
 */
final class WorkflowFacadeGateWaiverTest extends WorkflowTestCase {

  /**
   * A waived gate stops blocking, never executes, and shows its reason.
   */
  public function testWaiverUnblocksWithoutExecutingAndCarriesItsReason(): void {
    $root = $this->makeRootWithConfig(
      "preset: custom\nmode: agentic\ngates:\n  mutation: { on: true, msi_min: 80 }\n",
    );
    $this->writeSpec($root);
    $executor = $this->mutationFails();

    // Drive to the block: each run() advances one phase; mutation executes
    // at test and fails there.
    $blocked = $this->driveUntilSettled($executor, $root);
    $this->assertSame(Outcome::Failed, $blocked->outcome);
    $this->assertSame(Phase::Test, $blocked->state->currentPhase);

    // The operator waives it, with the reason on the record.
    $waived = $this->facade($executor)->waiveGate(
      $root,
      'mutation',
      'infection is not installed on this project yet — tracked as chore #12',
    );
    $this->assertArrayHasKey('mutation', $waived->gateWaivers);

    // Re-run: the gate no longer executes, the phase advances, and the
    // result is WAIVED — visibly distinct from every kind of pass.
    $ran = new \ArrayObject();
    $after = $this->driveUntilSettled($this->recorder($ran), $root);
    $this->assertNotContains('mutation', $ran, 'a waived gate must never execute');
    $this->assertNotSame(Outcome::Failed, $after->outcome);
    $report = $after->state->gateResults[Phase::Test->value] ?? NULL;
    $gates = is_array($report) && is_array($report['gates'] ?? NULL) ? $report['gates'] : [];
    $mutation = NULL;
    foreach ($gates as $result) {
      if (is_array($result) && ($result['gate'] ?? '') === 'mutation') {
        $mutation = $result;
      }
    }
    $this->assertIsArray($mutation, 'the waived gate still appears in the record');
    $this->assertSame(GateStatus::Waived->value, $mutation['status'] ?? NULL);
    $summary = $mutation['summary'] ?? '';
    $this->assertStringContainsString('chore #12', is_string($summary) ? $summary : '');

    // And the waiver survives the advance — the 0.5.2 lesson, applied.
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertArrayHasKey('mutation', $reloaded->gateWaivers);
    $this->assertSame(
      'infection is not installed on this project yet — tracked as chore #12',
      $reloaded->gateWaivers['mutation']['reason'],
    );
  }

  /**
   * The mandatory trio carries no switch and no waiver.
   */
  public function testTheMandatoryTrioCannotBeWaived(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $this->writeSpec($root);
    $this->facade($this->allPass())->run($root);

    foreach (['phpcs', 'phpstan', 'phpunit'] as $gate) {
      try {
        $this->facade($this->allPass())->waiveGate($root, $gate, 'nope');
        $this->fail('Expected a refusal for ' . $gate);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('mandatory trio', $e->getMessage());
      }
    }
  }

  /**
   * Unknown gates, empty reasons, and runless projects all refuse by name.
   */
  public function testTheRefusals(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $this->writeSpec($root);

    // No run yet.
    try {
      $this->facade($this->allPass())->waiveGate($root, 'config_clean', 'why');
      $this->fail('Expected a StateError with no active run');
    }
    catch (StateError $e) {
      $this->assertNotSame('', $e->getMessage());
    }

    $this->facade($this->allPass())->run($root);
    try {
      $this->facade($this->allPass())->waiveGate($root, 'ghost_gate', 'why');
      $this->fail('Expected a refusal for an unknown gate');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('known:', $e->getMessage());
    }
    try {
      $this->facade($this->allPass())->waiveGate($root, 'config_clean', '   ');
      $this->fail('Expected a refusal for an empty reason');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('reason', $e->getMessage());
    }
  }

  /**
   * Drives run() until it fails or completes, clearing seeker checkpoints.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The executor to drive with.
   * @param string $root
   *   The project root.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
   *   The settled outcome.
   */
  private function driveUntilSettled(GateExecutorInterface $executor, string $root): RunOutcome {
    $outcome = NULL;
    for ($i = 0; $i < 10; $i++) {
      $outcome = $this->facade($executor)->run($root);
      if ($outcome->outcome === Outcome::InspectionDue) {
        $this->facade($executor)->recordSeeker(
          $root,
          "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n",
        );
        continue;
      }
      if ($outcome->outcome !== Outcome::Advanced) {
        return $outcome;
      }
    }
    return $outcome;
  }

  /**
   * The facade under test, with the null site driver and a fixed clock.
   */
  private function facade(GateExecutorInterface $executor): WorkflowFacade {
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-01T12:00:00+00:00',
      static fn (): string => 'run-waiver',
    );
  }

  /**
   * An executor that fails mutation and passes everything else.
   */
  private function mutationFails(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        if ($gate->name === 'mutation') {
          return new GateResult($gate->name, GateStatus::Failed, 1, 5, 'MSI below minimum');
        }
        return new GateResult($gate->name, GateStatus::Passed, 0, 5, 'ok');
      }

    };
  }

  /**
   * An executor that records what ran (into the given bag) and passes all.
   *
   * @param \ArrayObject<int, string> $ran
   *   Filled with the gate names the executor actually saw.
   */
  private function recorder(\ArrayObject $ran): GateExecutorInterface {
    return new class($ran) implements GateExecutorInterface {

      /**
       * Constructs the recorder.
       *
       * @param \ArrayObject<int, string> $ran
       *   The bag the gate names land in.
       */
      public function __construct(private readonly \ArrayObject $ran) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $this->ran->append($gate->name);
        return new GateResult($gate->name, GateStatus::Passed, 0, 5, 'ok');
      }

    };
  }

  /**
   * An executor that passes everything.
   */
  private function allPass(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 5, 'ok');
      }

    };
  }

}
