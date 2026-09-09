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
      $root . '/droost/droost-workflow/spec-test-run.md',
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
      'droost/droost-workflow/spec-test-run.md',
      $outcome->state->specPath,
      'the governing spec is recorded on the run',
    );
    // And persisted: the next surface reads the same document.
    $reloaded = (new RunStateStore($root))->load();
    $this->assertSame('droost/droost-workflow/spec-test-run.md', $reloaded?->specPath);
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
   * Every criterion is classified by its "Verified By" cell.
   *
   * A test reference verifies; `manual — <reason>` is verified by hand and
   * never counted as passed; an empty cell or a placeholder is unverified.
   * An escaped pipe inside a cell is data, not a column boundary.
   */
  public function testCriteriaVerificationClassifiesEveryRow(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $spec = 'droost/droost-workflow/spec-test-run.md';
    file_put_contents($root . '/' . $spec, <<<'MD'
# Spec

## Tooling plan

- x

## Acceptance criteria (EARS)

| ID | Criterion | Check | Verified By |
|---|---|---|---|
| AC1 | When a user submits, the system shall save. | drush | `FormTest::testSubmitSaves` |
| **AC2** | While logged out, the system shall show `a \| b`. | curl | manual — no browser suite in this repo |
| AC3 | If the CSV is malformed, then the system shall refuse it. | phpunit | |
| AC4 | The system shall log every export. | grep | — |

## Realized

Words.
MD
    );

    $criteria = SpecContract::criteriaVerification($root, $spec);
    $this->assertNotNull($criteria);
    $this->assertSame(4, $criteria['total']);
    $this->assertSame(['AC1'], $criteria['verified']);
    $this->assertSame(['AC2'], $criteria['manual'], 'bold ids and escaped pipes are read through');
    $this->assertSame(['AC3', 'AC4'], $criteria['unverified'], 'an empty cell and a dash are both unverified');
    $this->assertFalse($criteria['column_missing']);
  }

  /**
   * A table without the column counts every row unverified, and says so.
   */
  public function testCriteriaTableWithoutTheColumnIsAllUnverified(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $spec = 'droost/droost-workflow/spec-test-run.md';
    file_put_contents($root . '/' . $spec, "# Spec\n\n## Acceptance criteria\n\n| ID | Criterion | Check |\n|---|---|---|\n| AC1 | x | y |\n| AC2 | x | y |\n");

    $criteria = SpecContract::criteriaVerification($root, $spec);
    $this->assertNotNull($criteria);
    $this->assertTrue($criteria['column_missing']);
    $this->assertSame(['AC1', 'AC2'], $criteria['unverified']);
  }

  /**
   * A spec with no criteria table is not held to one.
   */
  public function testNoCriteriaTableIsNothingToHold(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $this->assertNull(SpecContract::criteriaVerification($root, 'droost/droost-workflow/spec-test-run.md'), 'the fixture spec has no table');
    file_put_contents($root . '/droost/droost-workflow/spec-test-run.md', "# Spec\n\n## Acceptance criteria\n\nProse only, no table.\n");
    $this->assertNull(SpecContract::criteriaVerification($root, 'droost/droost-workflow/spec-test-run.md'));
  }

  /**
   * Complete refuses while a criterion's "Verified By" is empty.
   *
   * The pipeline this workflow descends from failed completion on exactly
   * this; the first real site on droost shipped three criteria of nine with
   * no test and passed, because the link was advice. The refusal names the
   * rows and the remedy.
   */
  public function testCompleteRefusesWithUnverifiedCriteria(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $this->writeCriteriaSpec($root, "| AC1 | When x, the system shall y. | drush | `XTest::testY` |\n| AC2 | When p, the system shall q. | drush | |\n");
    $facade = $this->facadeForCli();
    $facade->run($root);
    $facade->run($root);
    $facade->run($root);

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/1 acceptance criterion without a "Verified By" entry \(AC2\).*manual — <reason>/s');
    $facade->run($root);
  }

  /**
   * Every criterion verified, by test or honest manual: complete runs.
   */
  public function testCompleteRunsWhenEveryCriterionIsVerified(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $this->writeCriteriaSpec($root, "| AC1 | When x, the system shall y. | drush | `XTest::testY` |\n| AC2 | When p, the system shall q. | eye | manual — config-only, checked on the page |\n");
    $facade = $this->facadeForCli();
    $facade->run($root);
    $facade->run($root);
    $facade->run($root);

    $outcome = $facade->run($root);

    $this->assertNull($outcome->state->currentPhase, 'the run finished');
    $status = $facade->status($root);
    $run = $status['run'];
    $this->assertIsArray($run);
    $criteria = $run['criteria'];
    $this->assertIsArray($criteria);
    $this->assertSame(['AC1'], $criteria['verified']);
    $this->assertSame(['AC2'], $criteria['manual'], 'manual is its own column in the record, never a pass');
    $this->assertSame([], $criteria['unverified']);
  }

  /**
   * A spec with a tooling plan, a realized capture and the given AC rows.
   *
   * @param string $root
   *   The project root.
   * @param string $rows
   *   The table body rows, one per line.
   */
  private function writeCriteriaSpec(string $root, string $rows): void {
    file_put_contents(
      $root . '/droost/droost-workflow/spec-test-run.md',
      "# Spec: test run\n\n## Tooling plan\n\n- everything: hand-written (fixture)\n\n"
      . "## Acceptance criteria\n\n| ID | Criterion | Check | Verified By |\n|---|---|---|---|\n" . $rows
      . "\n## Realized\n\nFixture capture.\n",
    );
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
      $root . '/droost/droost-workflow/realized-test-run.md',
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
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);

    // Zero candidates: the refusal teaches the bootstrap order.
    try {
      SpecContract::resolve($root, NULL);
      $this->fail('zero candidates must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('no spec found', $e->getMessage());
    }

    // One candidate: adopted, project-relative.
    file_put_contents($root . '/droost/droost-workflow/spec-a.md', "# a\n");
    $this->assertSame(
      'droost/droost-workflow/spec-a.md',
      SpecContract::resolve($root, NULL),
    );

    // Two candidates: a guess would swap the run's criteria — refuse.
    file_put_contents($root . '/droost/droost-workflow/spec-b.md', "# b\n");
    try {
      SpecContract::resolve($root, NULL);
      $this->fail('two candidates must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('2 spec files', $e->getMessage());
    }

    // Declared wins over ambiguity; a declared ghost refuses.
    $this->assertSame(
      'droost/droost-workflow/spec-b.md',
      SpecContract::resolve($root, $root . '/droost/droost-workflow/spec-b.md'),
    );
    try {
      SpecContract::resolve($root, 'droost/droost-workflow/spec-ghost.md');
      $this->fail('a missing declared spec must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('does not exist', $e->getMessage());
    }

    // A recorded spec cannot be silently swapped by a new declaration.
    try {
      SpecContract::resolve(
        $root,
        'droost/droost-workflow/spec-b.md',
        'droost/droost-workflow/spec-a.md',
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
    $this->assertSame('droost/droost-workflow/spec-test-run.md', $run['spec']);
  }

}
