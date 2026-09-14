<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\VcsInterface;
use Droost\Workflow\WorkflowFacade;

/**
 * The machine checks the shape of a diff before a reviewer judges it.
 *
 * The seeker checkpoint lives inside the engine and stops a phase SHORT of
 * `Advanced` — it returns `InspectionDue`. The declaration audit ran only on
 * `Advanced`. So the order was: gates pass, seeker reviews the diff, seeker
 * reports clean, phase advances, and only THEN does anything ask whether the
 * diff is inside the scope the run declared.
 *
 * Two things are wrong with that, and the second is the one that lasts:
 *
 *   * the seeker spends a full review on work that is about to be blocked —
 *     a scope block costs one `declare-changes`, a seeker round costs a
 *     review, and the expensive one went first;
 *   * the run ends up carrying a CLEAN inspection of a diff that was out of
 *     bounds. The ledger says a reviewer looked and found nothing; it does
 *     not say they were looking at files the run never declared.
 *
 * Found by a reviewer driving three tickets through one repository.
 */
final class ScopeBeforeJudgementTest extends WorkflowTestCase {

  /**
   * A facade whose gates pass and whose diff is whatever the test says.
   *
   * @param list<string> $changed
   *   The files version control reports as changed.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facadeSeeing(array $changed): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
    $vcs = new class($changed) implements VcsInterface {

      /**
       * @param list<string> $changed
       *   The changed files this repository reports.
       */
      public function __construct(private readonly array $changed) {}

      /**
       * {@inheritdoc}
       */
      public function head(string $projectRoot): ?string {
        // A base commit the run can freeze. NULL is also a real answer from a
        // project with no repository, and the audit behaves the same either
        // way — what matters here is the changed list, which this fake owns.
        return $projectRoot === '' ? NULL : 'abc1234';
      }

      /**
       * {@inheritdoc}
       */
      public function changedFiles(string $projectRoot, ?string $base): array {
        return $this->changed;
      }

    };

    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-13T00:00:00+00:00',
      static fn (): string => 'run-scope',
      NULL,
      $vcs,
    );
  }

  /**
   * A project whose levers arm the seeker and whose spec satisfies plan.
   *
   * @return array{0: string, 1: string}
   *   The project root and the spec's project-relative path.
   */
  private function project(): array {
    $root = $this->makeRootWithConfig(
      "mode: agentic\npreset: low\nenforcement: soft\nseekers: { on: true }\n",
    );
    $spec = 'droost/droost-workflow/spec-scope.md';
    @mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/' . $spec, $this->specText());

    return [$root, $spec];
  }

  /**
   * A spec that satisfies the plan-exit contract.
   *
   * @return string
   *   The document.
   */
  private function specText(): string {
    return "# Scope\n\n## Tooling plan\n\n| Deliverable | Surface |\n|---|---|\n"
      . "| the thing | hand-written: no generator covers it |\n\n"
      . "## Grounding\n\n| Phase | Tier | Question | Answer | Evidence |\n|---|---|---|---|---|\n"
      . "| plan | custom | named already? | nothing | `none: thing` |\n"
      . "| plan | contrib | the API? | ViewsData | `Drupal\\views\\ViewsData` |\n"
      . "| plan | core | the constructor? | NodeType | `Drupal\\node\\Entity\\NodeType` |\n"
      . "| code | custom | named already? | nothing | `none: thing` |\n"
      . "| code | contrib | the API? | ViewsData | `Drupal\\views\\ViewsData` |\n"
      . "| code | core | the constructor? | NodeType | `Drupal\\node\\Entity\\NodeType` |\n\n"
      . "## Acceptance criteria\n\n| ID | Criterion | Check | Verified By |\n|---|---|---|---|\n"
      . "| AC1 | the page renders | curl / | RinkTest::testIt |\n";
  }

  /**
   * An undeclared file blocks before the seeker is ever asked.
   */
  public function testAnUndeclaredFileBlocksBeforeTheSeekerIsAsked(): void {
    [$root, $spec] = $this->project();
    $facade = $this->facadeSeeing(['src/Declared.php', 'src/NeverMentioned.php']);

    $this->assertSame(Outcome::Advanced, $facade->run($root, $spec)->outcome, 'plan ends');
    $facade->declareChanges($root, ['src/Declared.php'], [], 'code');

    $outcome = $facade->run($root, $spec);

    $this->assertSame(
      Outcome::Blocked,
      $outcome->outcome,
      'the scope of the diff is settled before a reviewer judges its contents',
    );
    $this->assertNotSame(
      Outcome::InspectionDue,
      $outcome->outcome,
      'and the seeker is not spent on a diff that is about to be blocked',
    );

    // Nothing was spent, either: a scope block is one `declare-changes` away
    // from cleared, and the ceiling above is what stops it repeating forever.
    $store = new EvidenceStore($root);
    $creep = array_values(array_filter(
      $store->checklist($outcome->state->runId, 'code'),
      static fn (array $row): bool => ($row['kind'] ?? '') === 'declaration'
        && ($row['state'] ?? '') === 'blocked',
    ));
    $this->assertNotSame([], $creep, 'and the record says which promise the diff broke');
  }

  /**
   * A declared diff still reaches the seeker.
   *
   * The counterweight. Moving the audit earlier must not swallow the
   * checkpoint: a run whose scope is honest still stops for its review.
   */
  public function testDeclaredDiffsStillReachTheSeeker(): void {
    [$root, $spec] = $this->project();
    $facade = $this->facadeSeeing(['src/Declared.php']);

    $this->assertSame(Outcome::Advanced, $facade->run($root, $spec)->outcome, 'plan ends');
    $facade->declareChanges($root, ['src/Declared.php'], [], 'code');

    $this->assertSame(
      Outcome::InspectionDue,
      $facade->run($root, $spec)->outcome,
      'an honest diff is still reviewed',
    );
  }

  /**
   * The seeker hold names what is holding it.
   *
   * With an open MEDIUM filed, `run` returned:
   *
   *     outcome  "inspection-due"
   *     report.advance  true
   *     blocked  []
   *     awaiting null
   *
   * and no key anywhere naming the finding. So a reader could not tell "file
   * an inspection" from "your inspection found a blocker, resolve F1" — and
   * read `advance: true`, which is a fact about the GATES, as a fact about the
   * run. The findings were rows in `seeker_finding` the whole time.
   */
  public function testTheSeekerHoldNamesWhatIsHoldingIt(): void {
    [$root, $spec] = $this->project();
    $facade = $this->facadeSeeing(['src/Declared.php']);
    $this->assertSame(Outcome::Advanced, $facade->run($root, $spec)->outcome, 'plan ends');
    $facade->declareChanges($root, ['src/Declared.php'], [], 'code');

    $due = $facade->run($root, $spec);
    $this->assertSame(Outcome::InspectionDue, $due->outcome, 'the seeker is asked for');
    $this->assertSame([], $due->blocked, 'and with nothing filed there is nothing to name');

    // An inspection that carries an open MEDIUM.
    $facade->recordSeeker($root, "## Seeker Inspection\n\nInspector: independent\n\n"
      . "| ID | Severity | Location | Finding | Status |\n|---|---|---|---|---|\n"
      . "| F1 | MEDIUM | src/Declared.php:42 | the total is computed twice and the second one wins | open |\n"
      // A RESOLVED one beside it, so the filter is observable: reporting
      // everything the seeker ever wrote would hand an agent a list of work
      // it has already done and call it the reason the run is held.
      . "| F2 | LOW | src/Declared.php:8 | the docblock says pence and it is pounds | resolved |\n");

    $held = $facade->run($root, $spec);

    $this->assertSame(Outcome::InspectionDue, $held->outcome, 'an open finding still holds it');
    $this->assertNotSame([], $held->blocked, 'and now the envelope says what');
    // The rows themselves, not their JSON: `json_encode` escapes a slash, so
    // asserting against the encoded form tests the encoder.
    $first = $held->blocked[0];
    $this->assertSame('seeker:F1', $first['check'], 'named by its reference');
    $this->assertStringContainsString('MEDIUM', $first['why'], 'with its severity');
    $this->assertStringContainsString('src/Declared.php:42', $first['why'], 'and where it is');
    $this->assertStringContainsString(
      'file a new inspection',
      $first['guidance'],
      'and what clears it',
    );
    $this->assertCount(
      1,
      $held->blocked,
      'only the OPEN one — a resolved finding is work already done, not a reason',
    );
  }

}
