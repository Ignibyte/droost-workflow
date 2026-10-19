<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Knowledge\PlanGroundedCheck;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\Evidence\ReadsTheStore;
use Droost\Workflow\Tests\Levers;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Plan is held to having looked, on both doors (F-276).
 *
 * The check lived in droost, so only a booted Drupal asked it; the standalone
 * binary closed plan with it recorded as skipped. It is the library's now.
 */
final class PlanGroundedCheckTest extends WorkflowTestCase {

  use KnowledgeFixture;
  use ReadsTheStore;

  /**
   * A project droost can be asked on, with a spec citing a file it holds.
   *
   * @return string
   *   The root.
   */
  private function project(): string {
    $root = $this->makeRoot();
    $this->drupalTree($root);
    $this->put($root, 'vendor/bin/droost', "#!/usr/bin/env php\n");
    $this->groundedSpec($root, [['custom', 'web/modules/custom/camps/camps.info.yml']]);

    return $root;
  }

  /**
   * The check's one verdict at plan.
   *
   * @param string $root
   *   The project.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord
   *   The record.
   */
  private function verdict(string $root): CheckRecord {
    $records = (new PlanGroundedCheck())->adjudicate($root, 'plan', 'run-pg');
    $this->assertCount(1, $records);
    $this->assertSame('plan_grounded', $records[0]->name);

    return $records[0];
  }

  /**
   * Only plan is held, and a run with no spec is left to the engine.
   */
  public function testOnlyPlanWithSpecIsAsked(): void {
    $root = $this->project();
    $this->assertSame([], (new PlanGroundedCheck())->adjudicate($root, 'code', 'run-pg'));

    unlink($root . '/droost/droost-workflow/spec-test-run.md');
    $this->assertSame([], (new PlanGroundedCheck())->adjudicate($root, 'plan', 'run-pg'));
  }

  /**
   * A plan that asked droost nothing is blocked, and told how to look.
   */
  public function testPlanThatAskedNothingIsBlocked(): void {
    $record = $this->verdict($this->project());

    $this->assertSame(CheckState::Blocked, $record->state);
    $this->assertSame(Fault::Agent, $record->fault);
    $this->assertStringContainsString('has not consulted `droost code:wiki` and `droost code:tree`', $record->summary);
    $this->assertStringContainsString('droost search:arch', $record->summary, 'and where the documentation is found');
  }

  /**
   * A plan that asked and cites what resolves is satisfied.
   */
  public function testPlanThatLookedIsSatisfied(): void {
    $root = $this->project();
    $this->ledger($root, self::looked());

    $record = $this->verdict($root);

    $this->assertSame(CheckState::Satisfied, $record->state, $record->summary);
    $this->assertStringContainsString('code:wiki ×1', $record->summary);
    $this->assertStringContainsString('code:tree ×1', $record->summary);
    $this->assertStringContainsString('the code index is absent', $record->summary, 'the index status is said');
    $this->assertSame(PlanGroundedCheck::PROVIDER, $record->provider);
  }

  /**
   * Citations that are only `none:` claims are not looking (S4-2).
   */
  public function testOnlyNegativeCitationsAreNotLooking(): void {
    $root = $this->project();
    $this->codeIndex($root, [], []);
    $this->groundedSpec($root, [['custom', 'none: QzxvMadeUp']]);
    $this->ledger($root, [['tool' => 'droost_search', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL]]);

    $record = $this->verdict($root);

    $this->assertSame(CheckState::Blocked, $record->state, $record->summary);
    $this->assertStringContainsString('resolves on any site', $record->summary);
  }

  /**
   * A directory, or the spec itself, is no citation (S4-2).
   */
  public function testDirectoriesAndTheSpecAreNoCitation(): void {
    $root = $this->project();
    $this->groundedSpec($root, [
      ['custom', './'],
      ['custom', 'web/modules/'],
      ['custom', 'droost/droost-workflow/spec-test-run.md'],
    ]);
    $this->ledger($root, [['tool' => 'droost_search', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL]]);

    $this->assertSame(CheckState::Blocked, $this->verdict($root)->state);
  }

  /**
   * Drush is no evidence of droost (S4-3).
   */
  public function testDrushAloneIsNoDroost(): void {
    $root = $this->project();
    unlink($root . '/vendor/bin/droost');
    $this->put($root, 'vendor/bin/drush', "#!/usr/bin/env php\n");

    $record = $this->verdict($root);

    $this->assertSame(CheckState::Skipped, $record->state);
    $this->assertSame(Fault::None, $record->fault);
  }

  /**
   * Droost one directory down is the environment's to settle (S4-11).
   */
  public function testDroostOneLevelDownIsSaid(): void {
    $root = $this->project();
    unlink($root . '/vendor/bin/droost');
    $this->put($root, 'site/vendor/bin/droost', "#!/usr/bin/env php\n");

    $record = $this->verdict($root);

    $this->assertSame(CheckState::Blocked, $record->state);
    $this->assertSame(Fault::Environment, $record->fault);
    $this->assertStringContainsString('installed in `site/`', $record->summary);
  }

  /**
   * A refused call, another run's call, and a call made later are not looking.
   */
  public function testOnlyThisRunsAnsweredPlanCallsCount(): void {
    $root = $this->project();
    $this->ledger($root, [
      ['tool' => 'droost_symbol', 'outcome' => 'fail', 'at' => 'x', 'run' => 'run-pg', 'phase' => 'plan'],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => 'x', 'run' => 'run-other', 'phase' => 'plan'],
      ['tool' => 'droost_entities', 'outcome' => 'ok', 'at' => 'x', 'run' => 'run-pg', 'phase' => 'code'],
      ['tool' => 'droost_config_set', 'outcome' => 'ok', 'at' => 'x', 'run' => 'run-pg', 'phase' => 'plan'],
    ]);

    $this->assertSame(CheckState::Blocked, $this->verdict($root)->state);
  }

  /**
   * Citations that resolve nowhere block, and the index's absence is said.
   */
  public function testCitationsThatResolveNowhereBlock(): void {
    $root = $this->project();
    $this->groundedSpec($root, [['custom', 'Drupal\camps\Invented']]);
    $this->ledger($root, [['tool' => 'droost_search', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL]]);

    $record = $this->verdict($root);

    $this->assertSame(CheckState::Blocked, $record->state);
    $this->assertStringContainsString('`Drupal\camps\Invented`', $record->summary);
    $this->assertStringContainsString('code index is absent', $record->summary);
  }

  /**
   * A project with no droost to ask is skipped, said, and never passed.
   */
  public function testNoDroostToAskIsSkipped(): void {
    $root = $this->project();
    unlink($root . '/vendor/bin/droost');

    $record = $this->verdict($root);

    $this->assertSame(CheckState::Skipped, $record->state);
    $this->assertFalse($record->state->measured());
    $this->assertStringContainsString('no droost to look with', $record->summary);
  }

  /**
   * The standalone binary holds plan on it, then lets a plan that looked go.
   */
  public function testTheBinaryHoldsPlan(): void {
    $root = $this->project();
    file_put_contents(Levers::path($root), "preset: custom\nseekers: { on: false }\n");
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $binary = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-15T00:00:00+00:00', static fn (): string => 'run-pg');

    $binary->dispatch(['run'], $root);
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertSame('plan', $state->currentPhase?->value, 'held at plan: ' . implode("\n", $lines));
    $row = $this->storeRow(
      (new EvidenceStore($root))->connection(),
      "SELECT state, provider FROM check_result WHERE kind = 'check' AND name = 'plan_grounded' ORDER BY id DESC LIMIT 1",
    );
    $this->assertSame(['state' => 'blocked', 'provider' => 'droost/workflow'], $row);

    $this->ledger($root, [
      ['tool' => 'code:wiki', 'outcome' => 'ok', 'at' => 'x', 'run' => 'run-pg', 'phase' => 'plan'],
      ['tool' => 'code:tree', 'outcome' => 'ok', 'at' => 'x', 'run' => 'run-pg', 'phase' => 'plan'],
    ]);
    $binary->dispatch(['run'], $root);
    $this->assertSame('code', (new RunStateStore($root))->load()?->currentPhase?->value, implode("\n", $lines));
  }

  /**
   * The wiki without the tree, or the tree without the wiki, is not enough.
   */
  public function testBothKnowledgeCommandsAreRequired(): void {
    foreach (['code:wiki' => 'code:tree', 'code:tree' => 'code:wiki'] as $asked => $missing) {
      $root = $this->project();
      $this->ledger($root, [
        ['tool' => $asked, 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL],
        ['tool' => 'code:symbol', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL],
      ]);

      $record = $this->verdict($root);

      $this->assertSame(CheckState::Blocked, $record->state, $asked);
      $this->assertStringContainsString('has not consulted `droost ' . $missing . '`', $record->summary);
      $this->assertStringNotContainsString('`droost ' . $asked . '` and', $record->summary, 'only the one missing is named');
    }
  }

  /**
   * The new brief alone is not looking either.
   */
  public function testTheBriefCommandAloneIsNotLooking(): void {
    $root = $this->project();
    $this->ledger($root, [['tool' => 'brief', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL]]);

    $record = $this->verdict($root);

    $this->assertSame(CheckState::Blocked, $record->state);
    $this->assertStringContainsString('asked droost nothing else', $record->summary);
  }

  /**
   * Droost installed in its own vendor directory is droost at the root.
   */
  public function testToolsDroostIsAtTheRoot(): void {
    $root = $this->makeRoot();
    mkdir($root . '/tools/droost/vendor/bin', 0755, TRUE);
    touch($root . '/tools/droost/vendor/bin/droost');
    $this->assertSame('', PlanGroundedCheck::droostAt($root));
  }

  /**
   * A ledger that consulted both, and looked at a symbol.
   *
   * @return list<array<string, string|null>>
   *   The rows.
   */
  private static function looked(): array {
    return [
      ['tool' => 'code:wiki', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL],
      ['tool' => 'code:tree', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL],
      ['tool' => 'code:symbol', 'outcome' => 'ok', 'at' => 'x', 'run' => NULL, 'phase' => NULL],
    ];
  }

}
