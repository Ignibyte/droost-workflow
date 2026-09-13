<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
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

}
