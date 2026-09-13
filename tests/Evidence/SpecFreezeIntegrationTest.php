<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Spec\SpecError;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * A whole run, making every write the workflow itself demands.
 *
 * This test class exists because its absence let a deadlock ship under a green
 * suite of 659. The first cut of SpecFreeze froze `## Grounding` and
 * `## Acceptance criteria` whole — and the workflow REQUIRES the code phase to
 * add grounding rows and the test phase to fill `Verified By`. Every run was
 * impossible: make the mandated write and the freeze refused; omit it and the
 * phase gate refused.
 *
 * Eight unit tests passed throughout, because every one of them exercised
 * SpecFreeze in isolation, and the shared spec fixture already carried both
 * phases' rows and never changed mid-run. Nothing drove a run across phases
 * while editing the spec the way the briefs tell an agent to.
 *
 * So that is what this does, and it is the only test here that matters: plan,
 * then code writes its grounding rows, then test fills the verification
 * column, then complete appends its capture. If any of those becomes illegal
 * again, this goes red and the unit tests will not.
 */
final class SpecFreezeIntegrationTest extends WorkflowTestCase {

  use ReadsTheStore;

  /**
   * A spec as a plan phase leaves it: criteria unverified, no code grounding.
   *
   * @return string
   *   The markdown.
   */
  private function planExitSpec(): string {
    return <<<'MD'
    # T-integration

    ## Tooling plan

    | # | Construct | Surface |
    |---|---|---|
    | 1 | a bundle | `droost_structure_create` |

    ## Grounding

    | Phase | Tier | Asked | Found | Evidence |
    |---|---|---|---|---|
    | plan | custom | anything already? | nothing | `none: thing` |
    | plan | contrib | what serves it? | views | `Drupal\views\Entity\View` |
    | plan | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |

    ## Acceptance criteria

    | ID | Criterion | Check | Verified By |
    |---|---|---|---|
    | AC1 | the page renders | curl / | |

    MD;
  }

  /**
   * A run reaches complete while making every write the pack mandates.
   */
  public function testFullRunSurvivesTheWritesTheWorkflowDemands(): void {
    $root = $this->makeRootWithConfig("mode: agentic\npreset: low\nenforcement: soft\n");
    $spec = 'droost/droost-workflow/spec-integration.md';
    @mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/' . $spec, $this->planExitSpec());

    $facade = $this->facade();

    $plan = $facade->run($root, $spec);
    $this->assertSame(Outcome::Advanced, $plan->outcome, 'plan advances');

    // What workflow-code/SKILL.md requires: grounding rows tagged `code`, in
    // all three tiers. Under the first cut of the freeze this threw.
    $this->appendRows($root, $spec, [
      '| code | custom | named already? | nothing | `none: thing` |',
      '| code | contrib | the API? | ViewsData | `Drupal\views\ViewsData` |',
      '| code | core | the constructor? | NodeType | `Drupal\node\Entity\NodeType` |',
    ]);
    $code = $facade->run($root, $spec);
    $this->assertSame(Outcome::Advanced, $code->outcome, 'code advances after adding its own grounding');

    // What workflow-test/SKILL.md requires: fill Verified By, which complete
    // refuses to gate without. Under the first cut of the freeze this threw.
    $this->replace($root, $spec, '| AC1 | the page renders | curl / | |', '| AC1 | the page renders | curl / | ThingTest::testIt |');
    $test = $facade->run($root, $spec);
    $this->assertSame(Outcome::Advanced, $test->outcome, 'test advances after verifying its criteria');

    // What workflow-complete/SKILL.md requires.
    file_put_contents($root . '/' . $spec, "\n\n## Realized\n\nBuilt the bundle.\n", FILE_APPEND);
    $complete = $facade->run($root, $spec);

    $this->assertSame(Outcome::Completed, $complete->outcome, 'the run can actually finish');
    $this->assertNull($complete->state->currentPhase);
  }

  /**
   * Rewriting a plan promise mid-run is still refused.
   *
   * The protection the freeze exists for, proven through the facade rather
   * than against the class in isolation — so a future relaxation that makes
   * the run work by making the check vacuous fails here.
   */
  public function testRewritingPlanCriterionMidRunIsRefused(): void {
    $root = $this->makeRootWithConfig("mode: agentic\npreset: low\nenforcement: soft\n");
    $spec = 'droost/droost-workflow/spec-integration.md';
    @mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/' . $spec, $this->planExitSpec());

    $facade = $this->facade();
    $facade->run($root, $spec);

    // The cheat: AC1 could not be satisfied, so AC1 becomes something else.
    $this->replace($root, $spec, '| AC1 | the page renders | curl / | |', '| AC1 | the module exists | ls | ThingTest::testIt |');
    $this->appendRows($root, $spec, [
      '| code | custom | named already? | nothing | `none: thing` |',
      '| code | contrib | the API? | ViewsData | `Drupal\views\ViewsData` |',
      '| code | core | the constructor? | NodeType | `Drupal\node\Entity\NodeType` |',
    ]);

    $this->expectException(SpecError::class);
    $this->expectExceptionMessage('broke the contract the plan phase recorded');
    $facade->run($root, $spec);
  }

  /**
   * Appends rows to the grounding table, where the code phase puts them.
   *
   * @param string $root
   *   The project root.
   * @param string $spec
   *   The spec, project-relative.
   * @param list<string> $rows
   *   The markdown rows.
   */
  private function appendRows(string $root, string $spec, array $rows): void {
    $this->replace(
      $root,
      $spec,
      '| plan | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |',
      '| plan | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |' . "\n" . implode("\n", $rows),
    );
  }

  /**
   * Edits the spec on disk, the way an agent would.
   *
   * @param string $root
   *   The project root.
   * @param string $spec
   *   The spec, project-relative.
   * @param string $from
   *   The text to replace.
   * @param string $to
   *   What to replace it with.
   */
  private function replace(string $root, string $spec, string $from, string $to): void {
    $path = $root . '/' . $spec;
    $text = (string) file_get_contents($path);
    $this->assertStringContainsString($from, $text, 'the fixture really contains what the edit targets');
    file_put_contents($path, str_replace($from, $to, $text));
  }

  /**
   * A run that declares its tests, as the plan brief instructs, can finish.
   *
   * The deadlock this pins. `declare-changes --tests=` is step 5 of the plan
   * brief, and the coverage audit was asked at the CODE phase — where no
   * test-shaped gate runs, so `ranTests()` was empty by construction, every
   * promised test read as never run, and the phase could never pass. Obeying
   * the brief made the run impossible.
   *
   * Worse than the SpecFreeze precedent in one way worth remembering: the
   * block was applied by downgrading the outcome after the retry machinery had
   * been bypassed, so no budget was spent, the phase stayed active, and the
   * run looped instead of failing.
   */
  public function testDeclaringTestsDoesNotStrandTheCodePhase(): void {
    $root = $this->makeRootWithConfig("mode: agentic\npreset: low\nenforcement: soft\n");
    $spec = 'droost/droost-workflow/spec-integration.md';
    @mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/' . $spec, $this->planExitSpec());

    $facade = $this->facade();
    $facade->run($root, $spec);
    // Exactly what the plan brief tells the agent to do.
    $facade->declareChanges($root, ['src'], ['ThingTest'], 'code');

    $this->appendRows($root, $spec, [
      '| code | custom | named already? | nothing | `none: thing` |',
      '| code | contrib | the API? | ViewsData | `Drupal\views\ViewsData` |',
      '| code | core | the constructor? | NodeType | `Drupal\node\Entity\NodeType` |',
    ]);

    $code = $facade->run($root, $spec);

    $this->assertSame(
      Outcome::Advanced,
      $code->outcome,
      'declaring tests at plan must not make the code phase unpassable',
    );
  }

  /**
   * Re-declaring replaces, so a bad declaration has a legal move.
   *
   * Insert-only meant an agent correcting a mistake inherited both promises,
   * and with no `undeclare` verb the only exit from an unsatisfiable
   * declaration was abandoning the run.
   */
  public function testRedeclaringReplacesRatherThanAccumulating(): void {
    $root = $this->makeRootWithConfig("mode: agentic\npreset: low\nenforcement: soft\n");
    $spec = 'droost/droost-workflow/spec-integration.md';
    @mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/' . $spec, $this->planExitSpec());

    $facade = $this->facade();
    $facade->run($root, $spec);
    $facade->declareChanges($root, ['src/wrong'], [], NULL);
    $facade->declareChanges($root, ['src/right'], [], NULL);

    $store = new EvidenceStore($root);
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state, 'the run is still open');
    $runId = $state->runId;

    $this->assertSame(['src/right'], $store->declared($runId, 'file'));
  }

  /**
   * Interactive mode freezes the spec and audits declarations too.
   *
   * Both contracts hung off run()'s non-paused outcome, and an interactive run
   * advances through answer() instead — so neither fired. Measured before the
   * fix: spec_hash NULL, zero declaration rows, and a criterion rewritten
   * mid-run went unnoticed while the SAME rewrite under agentic mode was
   * refused. A discipline that switches itself off in one of the two supported
   * modes is not a discipline, and a mode-shaped hole is invisible to every
   * test that only drives the other one.
   */
  public function testInteractiveModeFreezesAndAudits(): void {
    $root = $this->makeRootWithConfig("mode: interactive\npreset: low\nenforcement: soft\n");
    $spec = 'droost/droost-workflow/spec-integration.md';
    @mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/' . $spec, $this->planExitSpec());

    $facade = $this->facade();
    $this->assertSame(Outcome::Paused, $facade->run($root, $spec)->outcome, 'interactive pauses');
    $facade->answer($root, 'yes');

    $store = new EvidenceStore($root);
    $row = $this->storeRow($store->connection(), 'SELECT spec_hash FROM run');
    $this->assertNotNull($row, 'the run row exists');
    $this->assertIsString($row['spec_hash'] ?? NULL, 'the spec is frozen on the interactive path');

    $facade->declareChanges($root, ['src'], [], 'code');
    $this->appendRows($root, $spec, [
      '| code | custom | named already? | nothing | `none: thing` |',
      '| code | contrib | the API? | ViewsData | `Drupal\views\ViewsData` |',
      '| code | core | the constructor? | NodeType | `Drupal\node\Entity\NodeType` |',
    ]);
    $facade->run($root, $spec);
    $facade->answer($root, 'yes');

    $this->assertGreaterThan(
      0,
      $this->storeCount($store->connection(), "SELECT COUNT(*) FROM check_result WHERE kind='declaration'"),
      'declarations are audited on the interactive path',
    );
  }

  /**
   * The facade under test.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade, with every gate passing.
   */
  private function facade(): WorkflowFacade {
    // Every gate passes: this test is about the spec contract, not the tools.
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };

    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-13T00:00:00+00:00',
      static fn (): string => 'run-integration',
    );
  }

  /**
   * The low-preset run that declared its tests honestly reaches complete.
   *
   * A reviewer took this class's own
   * `testDeclaringTestsDoesNotStrandTheCodePhase`
   * fixture and drove it ONE PHASE FURTHER than the assertion went. It wedged:
   *
   *   plan -> advanced
   *   code -> advanced        <- where the pinning test stopped looking
   * test -> failed declared_tests = blocked (planned and never run:
   * ThingTest)
   *   test -> failed   type_coverage  = blocked (phpunit measured nothing)
   *   test -> failed   ... forever, and no budget spent
   *
   * With no legal exit. `EvidenceStore::ranTests()` reads phpunit, playwright,
   * coverage and mutation, and `low` turns all four off — so the list is empty
   * BY CONSTRUCTION and no test name can ever satisfy it. `declareChanges()`
   * refuses an empty declaration, so a declared test can be replaced but never
   * withdrawn.
   *
   * The agent that named its tests honestly was wedged forever; the one that
   * named none walked through in half the commands. That is the third airing of
   * the same inversion, and the reason a test now drives the whole run rather
   * than stopping at the phase the bug was last seen in.
   */
  public function testDeclaringTestsAtLowDoesNotWedgeTheTestPhase(): void {
    $root = $this->makeRootWithConfig("preset: low\nmode: agentic\n");
    $spec = $this->writeSpec($root);
    $facade = $this->facade();

    $this->assertSame(Outcome::Advanced, $facade->run($root, $spec)->outcome, 'plan');
    $facade->declareChanges($root, ['src'], ['ThingTest'], 'code');
    $this->assertSame(Outcome::Advanced, $facade->run($root, $spec)->outcome, 'code');

    // The phase the reviewer had to go one step past to find the wall.
    $this->assertSame(
      Outcome::Advanced,
      $facade->run($root, $spec)->outcome,
      'test advances: at low there is no test gate, so nothing can say whether a declared test ran',
    );

    $outcome = $facade->run($root, $spec);
    $this->assertContains(
      $outcome->outcome,
      [Outcome::Advanced, Outcome::Completed],
      'and the run can actually finish',
    );

    // Recorded, never green: the level ran no test gate, so nothing verified
    // it.
    $states = [];
    foreach ((new EvidenceStore($root))->checklist($outcome->state->runId, 'test') as $row) {
      if ($row['name'] === 'declared_tests') {
        $states[] = $row['state'];
      }
    }
    $this->assertSame(
      ['not_applicable'],
      $states,
      'an unverifiable declaration is recorded as unverified, not as a pass and not as a block',
    );
  }

}
