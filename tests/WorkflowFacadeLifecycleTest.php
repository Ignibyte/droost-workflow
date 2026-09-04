<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\State\StateError;
use Droost\Workflow\WorkflowFacade;

/**
 * The run's whole life: pair mode walks to the end, and the end stays ended.
 *
 * Three contracts pinned here were each broken in the field before they were
 * tested. Pair mode paused after every passing phase and answering only
 * cleared the question — the same question re-asked forever, so a pair run
 * could never finish. The mutating verbs accepted a FINISHED run and rewrote
 * its record. And reset trusted things it should not have: the lever file's
 * parseability (archiving a live run when the yml had a typo) and rename()'s
 * success (reporting "cleared" over a file still in place).
 */
final class WorkflowFacadeLifecycleTest extends WorkflowTestCase {

  /**
   * In pair mode, answering the check-in advances — all the way to the end.
   */
  public function testPairModeWalksToCompletionThroughAnswers(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $executor = $this->allGatesPass();

    // Plan passes its (empty) gate set and pauses for the check-in.
    $paused = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Paused, $paused->outcome);
    $this->assertSame(Phase::Plan, $paused->state->currentPhase);

    // The answer IS the check-in: the run moves to code.
    $answered = $this->facade($executor)->answer($root, 'yes, continue');
    $this->assertSame(Phase::Code, $answered->currentPhase);
    $this->assertSame(PhaseStatus::Passed, $answered->statusOf(Phase::Plan));
    $this->assertNull($answered->awaiting, 'the pause is consumed');
    $this->assertCount(1, $answered->qaHistory, 'the exchange is recorded');

    // Walk the rest: each phase pauses once, each answer advances once. The
    // seeker checkpoint still holds a green code/complete phase FIRST — the
    // answer cannot skip it — so a clean inspection is recorded when due.
    for ($i = 0; $i < 12; $i++) {
      $facade = $this->facade($executor);
      $outcome = $facade->run($root);
      if ($outcome->outcome === Outcome::InspectionDue) {
        $facade->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n");
        continue;
      }
      if ($outcome->outcome === Outcome::Paused) {
        $this->facade($executor)->answer($root, 'continue');
        continue;
      }
      break;
    }

    // Answering the FINAL phase's check-in finalizes the run: pair mode can
    // actually reach the terminal state.
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertNull($reloaded->currentPhase, 'the pair run finished');
    $this->assertSame(PhaseStatus::Passed, $reloaded->statusOf(Phase::Complete));
  }

  /**
   * A finished run refuses every mutating verb: the record is closed.
   */
  public function testMutatingVerbsRefuseTheFinishedRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmax_gate_retries: 1\n");
    $executor = $this->allGatesPass();
    $this->driveToCompletion($executor, $root);

    $verbs = [
      'declareBrowser' => fn () => $this->facade($executor)->declareBrowser($root, 'native'),
      'declareTasks' => fn () => $this->facade($executor)->declareTasks($root, 'claude-code'),
      'recordSeeker' => fn () => $this->facade($executor)->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n"),
      'swap' => fn () => $this->facade($executor)->swap($root, Mode::Agentic),
      'answer' => fn () => $this->facade($executor)->answer($root, 'yes'),
    ];
    foreach ($verbs as $name => $verb) {
      try {
        $verb();
        $this->fail($name . ' must refuse a finished run');
      }
      catch (StateError $e) {
        $this->assertStringContainsString('ended', $e->getMessage(), $name);
        $this->assertStringContainsString('reset', $e->getMessage(), $name);
      }
    }

    // The record itself is untouched by the refusals.
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertNull($reloaded->browser, 'no browser was written post-completion');
  }

  /**
   * Reset archives a finished run and clears the way — and only then.
   */
  public function testResetArchivesTheFinishedRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $executor = $this->allGatesPass();
    $this->driveToCompletion($executor, $root);
    touch($root . '/droost/droost-workflow/.guard-warned-require-run');

    $archived = $this->facade($executor)->reset($root);

    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/run.json');
    $this->assertFileExists($archived);
    $this->assertStringStartsWith($root . '/droost/droost-workflow/history/', $archived);
    $record = json_decode((string) file_get_contents($archived), TRUE);
    $this->assertIsArray($record, 'the archive is the record, not a copy of nothing');
    $this->assertFileDoesNotExist(
      $root . '/droost/droost-workflow/.guard-warned-require-run',
      'per-run warn-once markers do not outlive the run',
    );

    // The next run starts fresh over the cleared state.
    $next = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Advanced, $next->outcome);
    $this->assertSame(Phase::Code, $next->state->currentPhase);
  }

  /**
   * A live run is refused without force, whatever the lever file looks like.
   *
   * Classification reads the RUN STATE alone: a typo in droost.workflow.yml —
   * a file the plan phase explicitly allows editing mid-run — must not turn
   * "may I clear this run" into "archive the live run".
   */
  public function testResetRefusesTheLiveRunOverBrokenLevers(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $executor = $this->allGatesPass();
    $this->facade($executor)->run($root);

    // Break the lever file AFTER the run began (its levers are frozen).
    file_put_contents($root . '/droost.workflow.yml', "presett: custom\n");

    try {
      $this->facade($executor)->reset($root);
      $this->fail('a live run must not be cleared without force');
    }
    catch (StateError $e) {
      $this->assertStringContainsString('in progress', $e->getMessage());
      $this->assertStringContainsString('plan', $e->getMessage());
    }
    $this->assertFileExists($root . '/droost/droost-workflow/run.json');

    // Abandoning it stays possible — said out loud.
    $archived = $this->facade($executor)->reset($root, force: TRUE);
    $this->assertFileExists($archived);
    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/run.json');
  }

  /**
   * An unreadable run.json is clearable, and collisions never overwrite.
   */
  public function testResetArchivesCorruptRecordsWithoutOverwriting(): void {
    $root = $this->makeRoot();
    $executor = $this->allGatesPass();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);

    file_put_contents($root . '/droost/droost-workflow/run.json', 'FIRST-GARBAGE');
    $first = $this->facade($executor)->reset($root);
    file_put_contents($root . '/droost/droost-workflow/run.json', 'SECOND-GARBAGE');
    $second = $this->facade($executor)->reset($root);

    $this->assertNotSame($first, $second, 'the second archive gets its own name');
    $this->assertSame('FIRST-GARBAGE', file_get_contents($first));
    $this->assertSame('SECOND-GARBAGE', file_get_contents($second));
  }

  /**
   * A failed archive is an error, never a false "cleared".
   */
  public function testResetFailsLoudlyWhenTheArchiveCannotBeWritten(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $executor = $this->allGatesPass();
    $this->driveToCompletion($executor, $root);

    // With history in the way as a FILE, mkdir and rename both must fail.
    file_put_contents($root . '/droost/droost-workflow/history', 'in the way');

    try {
      $this->facade($executor)->reset($root);
      $this->fail('an unarchivable record must not be reported cleared');
    }
    catch (StateError $e) {
      $this->assertStringContainsString('archive', $e->getMessage());
      $this->assertStringContainsString('nothing was cleared', $e->getMessage());
    }
    $this->assertFileExists(
      $root . '/droost/droost-workflow/run.json',
      'nothing was deleted on the failed archive',
    );
  }

  /**
   * Resetting nothing says so.
   */
  public function testResetWithNoRunSaysStartOne(): void {
    $root = $this->makeRoot();
    $this->expectException(StateError::class);
    $this->expectExceptionMessage('no run in progress');
    $this->facade($this->allGatesPass())->reset($root);
  }

  /**
   * Drives an automated run to its terminal state.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The gate executor.
   * @param string $root
   *   The project root.
   */
  private function driveToCompletion(
    GateExecutorInterface $executor,
    string $root,
  ): void {
    for ($i = 0; $i < 16; $i++) {
      $facade = $this->facade($executor);
      $outcome = $facade->run($root);
      if ($outcome->outcome === Outcome::InspectionDue) {
        $facade->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n");
        continue;
      }
      if ($outcome->outcome !== Outcome::Advanced) {
        break;
      }
    }
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertNull($state->currentPhase, 'the fixture run reached its end');
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
      static fn (): string => '2026-08-26T12:00:00+00:00',
      static fn (): string => 'run-lifecycle',
    );
  }

}
