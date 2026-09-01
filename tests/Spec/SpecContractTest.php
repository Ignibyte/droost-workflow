<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Spec;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\Spec\SpecError;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * The spec holds up its end, phase by phase — or the run refuses.
 *
 * The owner's rule mechanized (2026-09-01): the spec is the living document.
 * Leaving plan requires the tooling plan, so "exhaust the generators before
 * hand-writing" is a checked contract; gating complete requires the realized
 * capture, so a run cannot close having left its own document behind. Ten
 * eval rounds ran on the advice-only version of both rules, and the tenth
 * datapoint of composer starvation is why they stopped being advice.
 */
final class SpecContractTest extends WorkflowTestCase {

  /**
   * Leaving plan requires the tooling plan, and the refusal names the fix.
   */
  public function testPlanRefusesWithoutTheToolingPlan(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    // Overwrite the helper's satisfying spec with one missing the section.
    file_put_contents(
      $root . '/.droost-workflow/spec-test-run.md',
      "# Spec: test run\n\n## 1. The request\n\nWords.\n",
    );

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/Tooling plan.*surface that builds it/s');
    $this->facadeForCli()->run($root);
  }

  /**
   * With the section present, plan advances — the contract is satisfiable.
   */
  public function testPlanAdvancesWithTheToolingPlan(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");

    $outcome = $this->facadeForCli()->run($root);

    $this->assertSame('code', $outcome->state->currentPhase?->value);
    $this->assertSame(
      '.droost-workflow/spec-test-run.md',
      $outcome->state->specPath,
      'the governing spec is recorded on the run',
    );
    // And persisted: the next surface reads the same document.
    $reloaded = (new RunStateStore($root))->load();
    $this->assertSame('.droost-workflow/spec-test-run.md', $reloaded?->specPath);
  }

  /**
   * Complete refuses while the realized capture is absent.
   */
  public function testCompleteRefusesWithoutTheRealizedCapture(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $this->writeSpec($root, realized: FALSE);
    $facade = $this->facadeForCli();

    $facade->run($root);
    $facade->run($root);
    $facade->run($root);

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/Realized.*capturing what was actually built/s');
    $facade->run($root);
  }

  /**
   * The companion realized-<slug>.md satisfies the capture during transition.
   *
   * The pack wrote captures to a sibling file before the section moved into
   * the spec; a run mid-transition passes either way.
   */
  public function testCompanionRealizedFileSatisfiesTheCapture(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $this->writeSpec($root, realized: FALSE);
    file_put_contents(
      $root . '/.droost-workflow/realized-test-run.md',
      "Capture, companion form.\n",
    );
    $facade = $this->facadeForCli();

    $facade->run($root);
    $facade->run($root);
    $facade->run($root);
    $outcome = $facade->run($root);

    $this->assertNull($outcome->state->currentPhase, 'the run completed');
  }

  /**
   * Ambiguity refuses; a lone candidate is adopted; conflicts refuse.
   */
  public function testResolutionAdoptsOneAndRefusesGuessesAndSwaps(): void {
    $root = $this->makeRoot();
    mkdir($root . '/.droost-workflow', 0755, TRUE);

    // Zero candidates: the refusal teaches the bootstrap order.
    try {
      SpecContract::resolve($root, NULL);
      $this->fail('zero candidates must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('no spec found', $e->getMessage());
    }

    // One candidate: adopted, project-relative.
    file_put_contents($root . '/.droost-workflow/spec-a.md', "# a\n");
    $this->assertSame(
      '.droost-workflow/spec-a.md',
      SpecContract::resolve($root, NULL),
    );

    // Two candidates: a guess would swap the run's criteria — refuse.
    file_put_contents($root . '/.droost-workflow/spec-b.md', "# b\n");
    try {
      SpecContract::resolve($root, NULL);
      $this->fail('two candidates must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('2 spec files', $e->getMessage());
    }

    // Declared wins over ambiguity; a declared ghost refuses.
    $this->assertSame(
      '.droost-workflow/spec-b.md',
      SpecContract::resolve($root, $root . '/.droost-workflow/spec-b.md'),
    );
    try {
      SpecContract::resolve($root, '.droost-workflow/spec-ghost.md');
      $this->fail('a missing declared spec must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('does not exist', $e->getMessage());
    }

    // A recorded spec cannot be silently swapped by a new declaration.
    try {
      SpecContract::resolve(
        $root,
        '.droost-workflow/spec-b.md',
        '.droost-workflow/spec-a.md',
      );
      $this->fail('a conflicting declaration must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('ONE spec', $e->getMessage());
    }
  }

  /**
   * A facade on the CLI shape: every shell gate passes, no site.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facadeForCli(): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-01T12:00:00+00:00',
      static fn (): string => 'run-spec-contract',
    );
  }

  /**
   * The status document names the governing spec.
   */
  public function testStatusCarriesTheGoverningSpec(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $facade = $this->facadeForCli();
    $facade->run($root);

    $status = $facade->status($root);

    $run = $status['run'];
    $this->assertIsArray($run);
    $this->assertSame('.droost-workflow/spec-test-run.md', $run['spec']);
  }

}
