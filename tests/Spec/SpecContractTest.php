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
   * A cell has to POINT at a test; prose about the work is not a reference.
   *
   * The column is the traceability link, and anything that was not a
   * placeholder scored as verified — so "I tested it by hand" filled it,
   * reading as a pass in the report and leading nowhere from the spec. A
   * method, a class whose name ends Test, or a test file all point; a
   * sentence does not.
   */
  public function testProseInVerifiedByNamesNoTest(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $this->writeCriteriaSpec($root, "| AC1 | When x, the system shall y. | drush | `XTest::testY` |\n"
      . "| AC2 | When p, the system shall q. | eye | I tested it by hand |\n"
      . "| AC3 | When r, the system shall s. | curl | tests/e2e/rink.spec.ts |\n"
      . "| AC4 | When t, the system shall u. | eye | manual — config-only |\n");

    $criteria = SpecContract::criteriaVerification($root, 'droost/droost-workflow/spec-test-run.md');

    $this->assertNotNull($criteria);
    $this->assertSame(['AC1', 'AC3'], $criteria['verified'], 'a method and a spec file both point at a test');
    $this->assertSame(['AC4'], $criteria['manual'], 'the honest answer still classifies as manual');
    $this->assertSame(['AC2'], $criteria['unverified']);
    $this->assertSame(['AC2'], $criteria['unnamed'], 'filled in, pointing at nothing');
  }

  /**
   * A criterion with no ID is counted and reported, never dropped.
   *
   * Skipping the row removed it from the total, the report and the refusal at
   * once — the one shape of missing cell that makes a spec look BETTER than
   * it is. It keeps its place in the table as its name.
   */
  public function testCriterionWithNoIdIsCountedNotDropped(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $spec = 'droost/droost-workflow/spec-test-run.md';
    file_put_contents($root . '/' . $spec, <<<'MD'
# Spec

## Acceptance criteria

| ID | Criterion | Check | Verified By |
|---|---|---|---|
| AC1 | When x, the system shall y. | drush | `XTest::testY` |
|  | When p, the system shall q. | drush | |
|  | When r, the system shall s. | drush | `ZTest::testS` |
| | | | |
MD
    );

    $criteria = SpecContract::criteriaVerification($root, $spec);

    $this->assertNotNull($criteria);
    $this->assertSame(3, $criteria['total'], 'a wholly empty row is padding, an unlabelled criterion is not');
    $this->assertSame(['AC1', 'row 3'], $criteria['verified']);
    $this->assertSame(['row 2'], $criteria['unverified'], 'the row is named by its place in the table');
  }

  /**
   * A single-dash separator is GFM, and is not the table's first criterion.
   */
  public function testSingleDashSeparatorIsNotTheFirstCriterion(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $spec = 'droost/droost-workflow/spec-test-run.md';
    file_put_contents(
      $root . '/' . $spec,
      "# Spec\n\n## Acceptance criteria\n\n| ID | Criterion | Check | Verified By |\n|-|:-|-:|:-:|\n"
      . "| AC1 | When x, the system shall y. | drush | `XTest::testY` |\n",
    );

    $criteria = SpecContract::criteriaVerification($root, $spec);

    $this->assertNotNull($criteria);
    $this->assertSame(1, $criteria['total']);
    $this->assertSame(['AC1'], $criteria['verified']);
    $this->assertSame([], $criteria['unverified'], 'the separator row was being read as an unverified criterion');
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
   * Complete refuses a cell that names no test, and says what shape it wants.
   *
   * An empty cell and a sentence of prose fail the same contract and need
   * different fixes, so the refusal distinguishes them rather than telling an
   * agent its filled-in cell is empty.
   */
  public function testCompleteRefusesProseAndNamesTheShapeItWants(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $this->writeCriteriaSpec($root, "| AC1 | When x, the system shall y. | drush | `XTest::testY` |\n| AC2 | When p, the system shall q. | eye | I tested it by hand |\n");
    $facade = $this->facadeForCli();
    $facade->run($root);
    $facade->run($root);
    $facade->run($root);

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/\(AC2\) — the cells name no test.*FooTest::testBar/s');
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
      // Grounding is a contract at plan and code, so a spec a run may
      // actually advance carries it. These tests are about the criteria
      // contract, not this one; the section is here so they reach it.
      . "## Grounding\n\n| Phase | Tier | Asked | Found |\n|---|---|---|---|\n"
      . "| plan | custom | fixture | fixture |\n| plan | contrib | fixture | fixture |\n"
      . "| plan | core | fixture | fixture |\n| code | custom | fixture | fixture |\n"
      . "| code | contrib | fixture | fixture |\n| code | core | fixture | fixture |\n\n"
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
   * The companion is found for a light run's spec too.
   *
   * The capture is named for the SLUG, and a `medium`/`low` run carries the
   * same slug behind a `tmp-` prefix. The derivation matched `spec-` alone,
   * so a light run mid-transition was told it had left its own document
   * behind while the file sat beside it.
   */
  public function testCompanionRealizedFileIsFoundForTheLightRunSpec(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    $spec = 'droost/droost-workflow/tmp-spec-hh-2.md';
    file_put_contents($root . '/' . $spec, "# hh-2\n\nWhat was asked, what will change.\n");
    $this->assertFalse(SpecContract::hasRealizedCapture($root, $spec));

    file_put_contents($root . '/droost/droost-workflow/realized-hh-2.md', "Capture, companion form.\n");

    $this->assertTrue(SpecContract::hasRealizedCapture($root, $spec));
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
   * A light run's quasi-spec is a candidate like any other.
   *
   * `medium` and `low` write `tmp-spec-<slug>.md`, and the discovery glob had
   * never heard of that name: a light run asked to re-resolve its own
   * governing document was told there was no spec at all, for a file its plan
   * phase had just written. Two live rounds survived it only because begin()
   * had already recorded the path.
   */
  public function testResolutionAdoptsTheLightRunQuasiSpec(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/tmp-spec-hh-1.md', "# hh-1\n");

    $this->assertSame(
      'droost/droost-workflow/tmp-spec-hh-1.md',
      SpecContract::resolve($root, NULL),
    );

    // Finding both shapes is still a guess, and still refuses.
    file_put_contents($root . '/droost/droost-workflow/spec-hh-1.md', "# hh-1\n");
    try {
      SpecContract::resolve($root, NULL);
      $this->fail('a full spec beside a quasi-spec must refuse');
    }
    catch (SpecError $e) {
      $this->assertStringContainsString('2 spec files', $e->getMessage());
    }
  }

  /**
   * Grounding is a contract at plan, not advice.
   *
   * It WAS advice while routing was a contract, and usage followed the
   * contract: across 39 graded rounds the build-surface router was called 179
   * times while the codebase knowledge behind it was called six — symbol,
   * graph, module_patterns and deprecations not once. The plan brief asked for
   * both in one breath; only one produced a row anybody checked.
   */
  public function testPlanRefusesWithoutGrounding(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    file_put_contents(
      $root . '/droost/droost-workflow/spec-test-run.md',
      "# Spec: test run\n\n## Tooling plan\n\n- hand-written (fixture)\n\n## Realized\n\nx\n",
    );

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/has no "## Grounding" section/');
    $this->facadeForCli()->run($root);
  }

  /**
   * Grounding that reached one tier is not grounding.
   *
   * A run that consulted contrib alone has confirmed a prior. The expensive
   * plan-phase mistake is building what this site already has under another
   * name, and no amount of contrib knowledge catches it.
   */
  public function testPlanRefusesWhenOneTierIsNeverReached(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $this->writeGroundingSpec($root, [['plan', 'contrib', 'does views do this', 'yes']]);

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/never reached: custom, core/');
    $this->facadeForCli()->run($root);
  }

  /**
   * A row claiming a lookup with no answer is refused.
   */
  public function testGroundingRefusesRowsWithNoAnswer(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $this->writeGroundingSpec($root, [
      ['plan', 'custom', 'does a rink type exist', ''],
      ['plan', 'contrib', 'what does views offer', 'a page display'],
      ['plan', 'core', 'node bundle API', 'NodeType'],
    ]);

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/no answer/');
    $this->facadeForCli()->run($root);
  }

  /**
   * An answer of "nothing matched" is real and is accepted.
   *
   * The contract asks what came back, not that something came back. A lookup
   * that found nothing is the most useful kind — it is the one that stops a
   * duplicate being built — so recording it must not be harder than silence.
   */
  public function testGroundingAcceptsNothingMatchedAsAnAnswer(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $spec = $this->writeGroundingSpec($root, [
      ['plan', 'custom', 'does a rink type exist', 'nothing matched — no rink bundle here'],
      ['plan', 'contrib', 'what does views offer', 'a page display with an exposed filter'],
      ['plan', 'core', 'node bundle API', 'NodeType config entity'],
      ['code', 'custom', 'existing field names', 'field_city is free'],
      ['code', 'contrib', 'ui_patterns props', 'slots accept render arrays'],
      ['code', 'core', 'FieldConfig::create', 'the documented shape'],
    ]);

    $grounding = SpecContract::grounding($root, $spec);
    $this->assertNotNull($grounding);
    $this->assertSame([], $grounding['unanswered']);
    $this->assertSame(['custom', 'contrib', 'core'], $grounding['phases']['plan']);
    $this->facadeForCli()->run($root);
  }

  /**
   * Each citation carries the phase of the row it came from.
   *
   * A `none:` row asserts absence, and the code phase's job is to end that
   * absence — so the gate must know WHEN the claim was made. `none: rink`
   * written at plan failed at code because the run had built `rink`, which is
   * the run doing what it was asked (F-18). The parser is the only thing that
   * knows the row's phase; the gate cannot recover it from the citation.
   */
  public function testEveryCitationCarriesItsPhase(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    file_put_contents($root . '/droost/droost-workflow/spec-test-run.md', <<<'MD'
# Spec: test run

## Tooling plan

- hand-written (fixture)

## Grounding

| Phase | Tier | Asked | Found | Evidence |
|---|---|---|---|---|
| plan | custom | a rink bundle? | nothing | `none: rink` |
| Code | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |
| code | contrib | the formatter? | ComponentPerItem | — |

## Realized

Fixture capture.
MD);

    $grounding = SpecContract::grounding($root, 'droost/droost-workflow/spec-test-run.md');
    $this->assertNotNull($grounding);
    $this->assertCount(2, $grounding['evidence'], 'the placeholder cell is uncited, not evidence');
    $this->assertSame('plan', $grounding['evidence'][0]['phase']);
    $this->assertSame('none: rink', trim($grounding['evidence'][0]['cite'], '`'));
    $this->assertSame('code', $grounding['evidence'][1]['phase'], 'the phase is lowercased like the tier');
    $this->assertSame(['row 4'], $grounding['uncited']);
  }

  /**
   * An empty cell in fancy dress is still an empty cell.
   *
   * The two tables had each grown their own idea of what unfilled looks like.
   * Grounding compared case-sensitively against five spellings, so `Tbd`,
   * `N/A`, `TODO`, `Pending` and the en-dash all passed as answers it had
   * gone and found — the exact claim the row exists to make checkable.
   */
  public function testGroundingReadsPlaceholdersInAnyCasing(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    $spec = $this->writeGroundingSpec($root, [
      ['plan', 'custom', 'does a rink type exist', 'Tbd'],
      ['plan', 'contrib', 'what does views offer', 'N/A'],
      ['plan', 'core', 'node bundle API', 'TODO'],
      ['code', 'custom', 'existing field names', '–'],
      ['code', 'contrib', 'ui_patterns props', 'Pending'],
      ['code', 'core', 'FieldConfig::create', 'the documented shape'],
    ]);

    $grounding = SpecContract::grounding($root, $spec);
    $this->assertNotNull($grounding);
    $this->assertSame(
      ['row 2', 'row 3', 'row 4', 'row 5', 'row 6'],
      $grounding['unanswered'],
      'every casing and both dashes read as unanswered',
    );

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/no answer/');
    $this->facadeForCli()->run($root);
  }

  /**
   * Writes a spec whose grounding table carries the given rows.
   *
   * @param string $root
   *   The project root.
   * @param list<list<string>> $rows
   *   Rows of [phase, tier, asked, found].
   *
   * @return string
   *   The spec path, project-relative.
   */
  private function writeGroundingSpec(string $root, array $rows): string {
    $table = "## Grounding\n\n| Phase | Tier | Asked | Found |\n|---|---|---|---|\n";
    foreach ($rows as $row) {
      $table .= '| ' . implode(' | ', $row) . " |\n";
    }
    file_put_contents(
      $root . '/droost/droost-workflow/spec-test-run.md',
      "# Spec: test run\n\n## Tooling plan\n\n- hand-written (fixture)\n\n"
      . $table . "\n## Realized\n\nFixture capture.\n",
    );
    return 'droost/droost-workflow/spec-test-run.md';
  }

  /**
   * A heading QUOTED inside a fenced block does not satisfy the contract.
   *
   * Every reader here matches on markdown structure, and a fenced block is
   * full of structure while meaning none of it. A spec that showed the shape
   * of `## Tooling plan` in an example satisfied the requirement on the
   * strength of its own teaching material.
   */
  public function testFencedHeadingDoesNotSatisfyTheSection(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\n");
    file_put_contents($root . '/droost/droost-workflow/spec-test-run.md', <<<'MD'
# Spec: test run

What the section will look like once it is written:

```markdown
## Tooling plan

- the rink list: views, via `drush generate`
```

## Grounding

| Phase | Tier | Asked | Found |
|---|---|---|---|
| plan | custom | does a rink type exist | nothing matched |
| plan | contrib | what does views offer | a page display |
| plan | core | node bundle API | NodeType |

## Realized

Words.
MD
    );

    $this->expectException(SpecError::class);
    $this->expectExceptionMessageMatches('/Tooling plan.*surface that builds it/s');
    $this->facadeForCli()->run($root);
  }

  /**
   * Blanking a fence must not move where the section around it ends.
   *
   * The fenced example here carries a `## Realized` heading, which truncated
   * the acceptance-criteria section before the real table, and a table of its
   * own, which would be read as criteria if the fence were invisible. Both
   * are examples; the section runs on to the REAL heading, and holds only the
   * real row.
   */
  public function testFenceDoesNotShiftTheSectionItSitsIn(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $spec = 'droost/droost-workflow/spec-test-run.md';
    file_put_contents($root . '/' . $spec, <<<'MD'
# Spec

## Acceptance criteria

The shape to fill in at the test phase:

```markdown
## Realized

| ID | Criterion | Check | Verified By |
|---|---|---|---|
| AX | When <trigger>, the system shall <response>. | how | |
```

| ID | Criterion | Check | Verified By |
|---|---|---|---|
| AC1 | When x, the system shall y. | drush | `XTest::testY` |

## Realized

Words.
MD
    );

    $criteria = SpecContract::criteriaVerification($root, $spec);

    $this->assertNotNull($criteria, 'the fenced heading truncated the section before the real table');
    $this->assertSame(1, $criteria['total']);
    $this->assertSame(['AC1'], $criteria['verified']);
    $this->assertSame([], $criteria['unverified'], 'the example row is an example, not a criterion');
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
