<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * A whole run, making every write the workflow itself demands.
 *
 * This test class exists because its absence let a deadlock ship under a green
 * suite of 659. The first cut of SpecFreeze froze `## Routes.
 *
 * none — fixture
 *
 * ## Grounding` and
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

    ## Routes

    none — fixture

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
  public function testRewritingPlanCriterionMidRunIsRecorded(): void {
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

    $facade->run($root, $spec);

    // PHASE A DELIBERATELY DOWNGRADES THIS, AND IT IS THE ONE DOWNGRADE THAT
    // COSTS SOMETHING REAL.
    //
    // Rewriting a criterion you could not satisfy into one you could is the
    // circularity the whole apparatus exists to prevent — not a formatting
    // lint. Phase A records it rather than refusing, because the freeze cannot
    // tell this apart from the honest case that cost P3-T1-a2 its run (F-33):
    // an agent correcting a tooling plan it no longer meant, having explicitly
    // refused to fake tool calls to satisfy the old one. One check, two
    // opposite intents, and it fired on the honest one in every live round.
    //
    // Phase B makes the cheat UNREACHABLE instead of detected: criteria become
    // `spec_criterion` rows written by a tool call, rows are append-only, and
    // a correction is a new row that says it is one. There is nothing to
    // rewrite. Until then the drift is recorded and named, so a reader of the
    // record still sees it.
    $query = (new EvidenceStore($root))->connection()
      ->query("SELECT rule, message FROM finding WHERE rule = 'spec_freeze.drift'");
    $this->assertNotFalse($query, 'the finding table is unreadable');
    $drift = $query->fetchAll();
    $this->assertNotSame([], $drift, 'the rewritten criterion was not recorded at all');
    $this->assertStringContainsString(
      'criteria',
      strtolower(json_encode($drift) ?: ''),
      'the drift finding does not name the criteria section',
    );
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
    $this->assertSame(['src/wrong'], $store->firstDeclaration($runId, 'file')['values'], 'replaced, not erased (F-54)');
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

  /**
   * An unmeasured mandatory gate is recorded for a run that declared NOTHING.
   *
   * That is the case the check was written for, and the case it could not
   * reach. It lived in `DeclarationAudit::checks()`, and the facade returns
   * early when a run declared no files, no tests and no work type — rightly,
   * because with nothing declared every changed file reads as undeclared and
   * the scope check would block the lot. So the check meant for an agent that
   * declares nothing was the one thing that agent never got: a reviewer drove
   * such a run and found zero declaration rows in the store.
   *
   * Driven through the facade rather than asserted about, because the defect
   * was entirely in the WIRING — the logic was right and unreachable.
   */
  public function testMandatoryMeasuredReachesRunsThatDeclaredNothing(): void {
    // `low`, because it disarms the seeker checkpoint — this test is about the
    // wiring of one check, not about driving an inspection. phpunit is off at
    // that level and so is correctly absent from the complaint; phpcs and
    // phpstan are mandatory at every level and are what it names.
    $root = $this->makeRootWithConfig("mode: agentic\npreset: low\nenforcement: soft\n");
    $spec = 'droost/droost-workflow/spec-hollow.md';
    @mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/' . $spec, $this->planExitSpec());

    // Gates that PASS while measuring nothing — phpcs over an empty path set,
    // phpstan with no path. The shape the whole labelled-pass machinery exists
    // to make visible.
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::labelledPass($gate->name, 0, 1, 'nothing to analyse', $gate->name);
      }

    };
    $facade = new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-13T00:00:00+00:00',
      static fn (): string => 'run-hollow',
    );

    $facade->run($root, $spec);
    $this->appendRows($root, $spec, [
      '| code | custom | named already? | nothing | `none: thing` |',
      '| code | contrib | the API? | ViewsData | `Drupal\views\ViewsData` |',
      '| code | core | the constructor? | NodeType | `Drupal\node\Entity\NodeType` |',
    ]);
    $facade->run($root, $spec);
    $this->replace(
      $root,
      $spec,
      '| AC1 | the page renders | curl / | |',
      '| AC1 | the page renders | curl / | ThingTest::testIt |',
    );
    // The test phase, where the trio has run and the question is due.
    $facade->run($root, $spec);

    $store = new EvidenceStore($root);
    $rows = array_values(array_filter(
      $store->unresolved('run-hollow', 'test'),
      static fn (array $row): bool => $row['name'] === 'mandatory_measured',
    ));
    // `unresolved()` lists what BLOCKS, and this deliberately does not — so it
    // is read straight from the store instead.
    $pdo = new \PDO('sqlite:' . EvidenceStore::pathFor($root));
    $query = $pdo->query("SELECT summary FROM check_result WHERE name = 'mandatory_measured'");
    $this->assertNotFalse($query);
    $found = $query->fetchAll(\PDO::FETCH_COLUMN);

    $this->assertNotSame(
      [],
      $found,
      'a run that declared nothing still gets told its gates measured nothing',
    );
    $summary = $found[0] ?? '';
    $this->assertIsString($summary);
    $this->assertStringContainsString('measured nothing this run', $summary);
    $this->assertStringContainsString(
      'phpcs, phpstan',
      $summary,
      'naming the gates that looked at nothing — phpunit is off at this level '
      . 'and is correctly not held against anybody',
    );
    $this->assertSame([], $rows, 'and it does not block the phase');
  }

  /**
   * A facade whose every gate passes.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
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
    // `recorded`, not `not_applicable`, since the browser suite came on at
    // every preset. `not_applicable` carries the sentence "this level runs
    // no test gate at all", and `low` now runs one — so the old state would
    // be a lie about the level even though the PHP tests are still
    // unverifiable. Either way it is neither a pass nor a block.
    $this->assertSame(
      ['recorded'],
      $states,
      'an unverifiable declaration is recorded as unverified, not as a pass and not as a block',
    );
  }

  /**
   * A spec with no acceptance criteria: recorded below `high`, refused above.
   *
   * The cheapest cheat in the system, and a reviewer ran it: sixteen lines of
   * spec, zero code, zero tests, zero declarations, zero acceptance criteria —
   * and `outcome: completed` with all four phases passed. The `Verified By`
   * contract only fires when a criteria TABLE exists, so writing the criteria
   * as prose, or omitting them, turned the whole contract off in silence.
   *
   * The quasi-spec exemption is deliberate and stays: a `low` run is told to
   * write ten lines and must not be punished for obeying its own brief. What
   * changes is that the absence becomes a row somebody can read, and above
   * `medium` it refuses, because a level that asked for verification cannot be
   * handed none.
   */
  public function testMediumRecordsMissingCriteriaTableAndFinishes(): void {
    $root = $this->makeRootWithConfig("preset: medium\nmode: agentic\n");
    $spec = $this->writeSpec($root);
    $facade = $this->facade();

    $state = $this->driveToEnd($facade, $root, $spec);

    $found = [];
    foreach ((new EvidenceStore($root))->checklist($state->runId, 'complete') as $row) {
      if (($row['name'] ?? '') === 'criteria_table') {
        $found[] = $row['state'];
      }
    }
    $this->assertSame(['recorded'], $found, 'recorded, and the run still finishes');
  }

  /**
   * At high, an absent criteria table is recorded and the run still finishes.
   *
   * This asserted a REFUSAL and then that doing what the refusal asked cleared
   * it. Phase A removes the refusal (F-35): a document's shape does not end a
   * run whose gates are green. What is still worth pinning is that the absence
   * is recorded rather than passed over in silence — silence being the
   * cheapest cheat in the system — and that a spec which HAS the table records
   * `satisfied`, so the two cases are distinguishable in the record.
   */
  public function testHighRecordsMissingCriteriaTableAndFinishes(): void {
    $root = $this->makeRootWithConfig("preset: high\nmode: agentic\n");
    $spec = $this->writeSpec($root);
    $facade = $this->facade();

    $state = $this->driveToEnd($facade, $root, $spec);

    $states = [];
    foreach ((new EvidenceStore($root))->checklist($state->runId, 'complete') as $row) {
      if (($row['name'] ?? '') === 'criteria_table') {
        $value = $row['state'] ?? '';
        $states[] = is_scalar($value) ? (string) $value : '';
      }
    }
    $this->assertContains('recorded', $states, 'the absent table was not recorded at high');
    $this->assertNotContains(
      'blocked',
      $states,
      'a blocked row while the phase advances is a record that contradicts the run',
    );
    $this->assertNull($state->currentPhase, 'the run finished despite the absent table');
  }

  /**
   * A spec that HAS the table records satisfied, so the two cases differ.
   */
  public function testCriteriaTablePresentRecordsSatisfied(): void {
    $root = $this->makeRootWithConfig("preset: high\nmode: agentic\n");
    $spec = $this->writeSpec($root);
    file_put_contents(
      $root . '/' . $spec,
      "\n## Acceptance criteria\n\n| ID | Criterion | Check | Verified By |\n|---|---|---|---|\n"
      . "| AC1 | the page renders | curl / | RinkTest::testIt |\n",
      FILE_APPEND,
    );
    $facade = $this->facade();

    $state = $this->driveToEnd($facade, $root, $spec);

    $states = [];
    foreach ((new EvidenceStore($root))->checklist($state->runId, 'complete') as $row) {
      if (($row['name'] ?? '') === 'criteria_table') {
        $value = $row['state'] ?? '';
        $states[] = is_scalar($value) ? (string) $value : '';
      }
    }
    $this->assertContains('satisfied', $states);
  }

  /**
   * Drives a run to completion, satisfying the seeker checkpoint on the way.
   *
   * @param \Droost\Workflow\WorkflowFacade $facade
   *   The facade.
   * @param string $root
   *   The project.
   * @param string $spec
   *   The spec path.
   *
   * @return \Droost\Workflow\State\RunState
   *   The final run state.
   */
  private function driveToEnd(WorkflowFacade $facade, string $root, string $spec): RunState {
    $outcome = $facade->run($root, $spec);
    for ($i = 0; $i < 7; $i++) {
      if ($outcome->outcome === Outcome::InspectionDue) {
        $facade->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n");
      }
      if ($outcome->outcome === Outcome::Completed) {
        break;
      }
      $outcome = $facade->run($root, $spec);
    }

    return $outcome->state;
  }

  /**
   * A phase blocked past the ceiling stops and asks, and the answer is heard.
   *
   * A failing gate spends a retry and the phase ends when the budget does.
   * Nothing else did — a declaration block, a contributed check, a spec
   * condition — each cost nothing, so a phase could be re-entered forever at
   * zero price. The blocks come from the AGENT, which means they keep coming:
   * an agent that has misread a condition will retry it until something stops
   * it, and nothing did.
   *
   * The ceiling is far above a retry budget on purpose. A gate retry is 2 or 3
   * because a tool saying the same thing about the same code a third time
   * teaches nobody anything; a declaration block is the agent being asked to go
   * away and change something real, and an honest correction cycle can be long.
   * Sixty is "this is not progressing", not "try harder".
   *
   * And it ASKS rather than failing, because the engine knows the count and
   * nothing else. It cannot tell a slow honest run from one wedged on a block
   * it cannot clear; the person watching can tell instantly. Ending the run
   * here would throw away a plan and a code phase over a judgement the engine
   * is not equipped to make.
   */
  public function testPhaseBlockedPastTheCeilingAsks(): void {
    $root = $this->makeRootWithConfig("preset: low\nmode: agentic\n");
    // A real repository, because the audit compares declarations against the
    // DIFF: with no git there is no diff, nothing is ever undeclared, and the
    // test would pass by never reaching the condition it exists to drive.
    $this->commitBase($root);
    $spec = $this->writeSpec($root);
    $facade = $this->facade();

    $facade->run($root, $spec);
    $facade->declareChanges($root, ['src'], [], 'code');
    file_put_contents($root . '/undeclared.php', "<?php // never declared\n");

    $outcomes = [];
    $paused = NULL;
    for ($i = 0; $i < 70; $i++) {
      $outcome = $facade->run($root, $spec);
      $outcomes[] = $outcome->outcome;
      if ($outcome->outcome === Outcome::Paused) {
        $paused = $outcome;
        break;
      }
    }

    $this->assertNotNull($paused, 'the run stops asking rather than looping forever');
    $this->assertGreaterThan(
      10,
      count($outcomes),
      'and it does not interrupt an honest correction cycle: the ceiling is well above a retry budget',
    );
    $this->assertNotNull($paused->question);
    $this->assertStringContainsString('stuck', $paused->question->question);
    $this->assertNotSame([], $paused->question->options, 'it offers answers rather than only a verdict');

    // The answer has to buy another budget. Counting from the start of the
    // phase made "keep going" pause again on the very next invocation, and then
    // forever — an unbounded PAUSE loop, which is worse than the unbounded
    // failure loop it replaced, because that one at least kept working.
    $facade->answer($root, 'keep going');
    $this->assertSame(
      Outcome::Blocked,
      $facade->run($root, $spec)->outcome,
      'answering resumes ordinary blocking rather than re-asking immediately',
    );
    $this->assertSame(
      Outcome::Blocked,
      $facade->run($root, $spec)->outcome,
      'and keeps resuming it',
    );
  }

  /**
   * A non-gate block says why, in the envelope the caller reads.
   *
   * A reviewer drove a real run as a new user and hit a wall nothing could
   * explain: `outcome: failed`, `failed: 0`, `advance: true`, every gate green,
   * `awaiting: null`, retries not exhausted, and no reason anywhere in the
   * output. `status` said the phase was active. Re-running repeated it.
   * `answer` said the run was not waiting. The cause existed only as a row
   * inside `evidence.sqlite`, and they escaped by opening that file with a
   * third-party tool.
   *
   * An agent has no such option. It sees a green report and a bare `failed`,
   * and loops until something else stops it — which is what the block ceiling
   * was built for, and a ceiling is a poor substitute for saying why.
   */
  public function testNonGateBlockTellsTheCallerWhy(): void {
    $root = $this->makeRootWithConfig("preset: low\nmode: agentic\n");
    $this->commitBase($root);
    $spec = $this->writeSpec($root);
    $facade = $this->facade();

    $facade->run($root, $spec);
    $facade->declareChanges($root, ['src'], [], 'code');
    file_put_contents($root . '/undeclared.php', "<?php // never declared\n");

    $outcome = $facade->run($root, $spec);
    // Blocked rather than failed — nothing was spent, and the caller is being
    // told what to fix rather than that the run is over.
    $this->assertSame(Outcome::Blocked, $outcome->outcome);

    $envelope = $outcome->toArray();
    $this->assertArrayHasKey('blocked', $envelope, 'the envelope has somewhere to say why');
    $blocked = $envelope['blocked'];
    $this->assertIsArray($blocked);
    $this->assertNotSame([], $blocked, 'and it is not empty when something blocks');

    $first = $blocked[0];
    $this->assertIsArray($first);
    $this->assertSame('declared_files', $first['check']);
    $this->assertIsString($first['why']);
    $this->assertStringContainsString('undeclared.php', $first['why'], 'it names the file');
    $this->assertIsString($first['guidance']);
    $this->assertStringContainsString(
      'Nothing on your side waives it',
      $first['guidance'],
      'and says what kind of problem it is: work to do, not setup to fix',
    );
  }

  /**
   * A re-declaration copied from the diff is recorded, not vouched for.
   *
   * P6 run 2, end to end (F-54): the agent declared its scope as the code phase
   * opened, built, then re-declared by piping `git status` into
   * `declare-changes`. The audit compared the diff with that copy of itself and
   * reported "no undeclared changes". Now the first declaration survives the
   * re-declaration, and the file only the copy covers is named.
   */
  public function testRedeclarationFromTheDiffIsRecordedNotVouchedFor(): void {
    $root = $this->makeRootWithConfig("preset: low\nmode: agentic\n");
    $this->commitBase($root);
    $spec = $this->writeSpec($root);
    $facade = $this->facade();

    $facade->run($root, $spec);
    $facade->declareChanges($root, ['src'], [], 'code');
    @mkdir($root . '/src', 0775, TRUE);
    file_put_contents($root . '/src/Planned.php', "<?php // the plan named src\n");
    file_put_contents($root . '/unplanned.php', "<?php // the plan never named this\n");
    $facade->declareChanges($root, ['src', 'unplanned.php'], [], 'code');

    $outcome = $facade->run($root, $spec);
    $this->assertNotSame(Outcome::Blocked, $outcome->outcome, 're-declaring is still a legal way to cover a path');

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $files = NULL;
    foreach ((new EvidenceStore($root))->checklist($state->runId, 'code') as $row) {
      if ($row['name'] === 'declared_files') {
        $files = $row;
      }
    }
    $this->assertIsArray($files, 'the code phase audited the declaration');
    $this->assertSame('recorded', $files['state'], 'recorded, not satisfied: the plan did not predict it');
    $this->assertIsString($files['summary']);
    $this->assertStringContainsString('covered only by a later re-declaration', $files['summary']);
    $this->assertStringContainsString('unplanned.php', $files['summary']);
    $this->assertStringNotContainsString('src/Planned.php', $files['summary']);
  }

  /**
   * A repository with no commits yet is still audited.
   *
   * It has no HEAD, and F-36's first fix read "no HEAD" as "no repository", so
   * the audit said NOT MEASURED and the phase advanced over an undeclared
   * file that `git status` lists plainly. Measured on the CI runner, where the
   * fixtures' first commit failed for want of an identity and two tests about
   * blocking watched the run advance instead.
   */
  public function testRepositoryWithNoCommitsIsStillAudited(): void {
    $root = $this->makeRootWithConfig("preset: low\nmode: agentic\n");
    exec(sprintf('cd %s && git init -q . 2>&1', escapeshellarg($root)), $output, $exit);
    $this->assertSame(0, $exit, implode("\n", $output));
    $spec = $this->writeSpec($root);
    $facade = $this->facade();

    $facade->run($root, $spec);
    $facade->declareChanges($root, ['src'], [], 'code');
    file_put_contents($root . '/undeclared.php', "<?php // never declared\n");

    $this->assertSame(Outcome::Blocked, $facade->run($root, $spec)->outcome, 'the undeclared file blocks, as it does with a commit');
  }

  /**
   * Makes the project a repository with one commit, or fails the test.
   *
   * The commit names its own author. A CI runner has no git identity, so a
   * bare `git commit` there failed, `exec()` ignored the failure, and every
   * test built on this fixture ran against a repository with no commits. That
   * turned tests about blocking into tests about advancing, and they failed
   * on the runner while passing on any machine with a global identity.
   *
   * @param string $root
   *   The project root.
   */
  private function commitBase(string $root): void {
    exec(sprintf(
      'cd %s && git init -q . && git add -A && git -c user.name=droost-test -c user.email=test@example.invalid commit -q -m base 2>&1',
      escapeshellarg($root),
    ), $output, $exit);
    $this->assertSame(0, $exit, 'the fixture commit failed: ' . implode("\n", $output));
  }

}
