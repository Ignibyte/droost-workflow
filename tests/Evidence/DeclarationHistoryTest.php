<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\WorkType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The plan's prediction survives a re-declaration, and the audit uses it.
 *
 * Re-declaring replaces, because a wrong declaration needs a legal way out.
 * It used to replace by DELETING, and in P6 run 2 that was the way round the
 * audit: the agent declared 23 paths as the code phase opened, then re-declared
 * by piping `git status` into `declare-changes` just before the audit ran, and
 * `declared_files` said "25 declared path(s), no undeclared changes". The diff
 * was held to a copy of itself. Two of those paths, `playwright.config.ts` and
 * a Canvas folder config, were never in the plan, and nothing could say so
 * (F-54).
 */
#[CoversClass(DeclarationAudit::class)]
#[CoversClass(EvidenceStore::class)]
final class DeclarationHistoryTest extends TestCase {

  /**
   * P6 run 2's shape: paths only the re-declaration covers are named.
   */
  public function testRedeclaredPathsThePlanNeverNamedAreRecorded(): void {
    $audit = new DeclarationAudit(
      [
        'web/modules/custom/example_directory',
        'config/sync/node.type.a.yml',
        'config/sync/folder.yml',
        'playwright.config.ts',
      ],
      [],
      [
        'web/modules/custom/example_directory/src/Thing.php',
        'config/sync/node.type.a.yml',
        'config/sync/folder.yml',
        'playwright.config.ts',
      ],
      WorkType::Mixed,
      firstDeclaredFiles: ['web/modules/custom/example_directory', 'config/sync/node.type.a.yml'],
      firstDeclaredAt: '2026-09-23T13:40:00+10:00',
    );

    $files = $this->check($audit, 'declared_files');
    $summary = (string) $files->summary;
    $this->assertSame(CheckState::Recorded, $files->state, 'recorded, because the plan did not predict all of it');
    $this->assertFalse($files->state->blocksAdvance(), 'and it does not block: re-declaring is allowed');
    $this->assertStringContainsString('2 changed path(s) are covered only by a later re-declaration', $summary);
    $this->assertStringContainsString('config/sync/folder.yml, playwright.config.ts', $summary);
    $this->assertStringContainsString('2026-09-23T13:40:00+10:00', $summary, 'it says when the plan declared');
    $this->assertStringNotContainsString('Thing.php', $summary, 'a file the first declaration covered is not late');
    $this->assertSame(['config/sync/folder.yml', 'playwright.config.ts'], $audit->declaredLate());
  }

  /**
   * A first declaration that predicted the whole diff stays satisfied.
   */
  public function testFirstDeclarationThatPredictedEverythingIsSatisfied(): void {
    $audit = new DeclarationAudit(
      ['src', 'config/sync/a.yml'],
      [],
      ['src/One.php', 'config/sync/a.yml'],
      firstDeclaredFiles: ['src', 'config/sync/a.yml', 'config/sync/b.yml'],
      firstDeclaredAt: '2026-09-23T13:40:00+10:00',
    );

    $files = $this->check($audit, 'declared_files');
    $this->assertSame(CheckState::Satisfied, $files->state);
    $this->assertStringNotContainsString('re-declaration', (string) $files->summary);
    $this->assertSame([], $audit->declaredLate());
  }

  /**
   * Without history the audit behaves as it always did.
   */
  public function testNoHistoryMeansNoLateVerdict(): void {
    $audit = new DeclarationAudit(['src'], [], ['src/One.php']);

    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'declared_files')->state);
    $this->assertSame([], $audit->declaredLate());
  }

  /**
   * Scope creep still blocks, and the late list does not dilute the verdict.
   */
  public function testUndeclaredChangesStillBlock(): void {
    $audit = new DeclarationAudit(
      ['src', 'late.php'],
      [],
      ['src/One.php', 'late.php', 'creep.php'],
      firstDeclaredFiles: ['src'],
      firstDeclaredAt: '2026-09-23T13:40:00+10:00',
    );

    $files = $this->check($audit, 'declared_files');
    $this->assertSame(CheckState::Blocked, $files->state);
    $this->assertStringContainsString('creep.php', (string) $files->summary);
    $this->assertStringNotContainsString('re-declaration', (string) $files->summary, 'one verdict at a time');
  }

  /**
   * Scope is asked again at test and at complete, where the diff still grows.
   *
   * It was asked at code alone. In P6 run 3 a wiki page written at complete
   * reached the diff after the only audit that could have seen it, and a fix
   * made during the test phase would have gone the same way.
   */
  public function testScopeIsAskedWhereverTheDiffCanStillGrow(): void {
    $audit = new DeclarationAudit(['src'], [], ['src/A.php', 'docs/late.md']);

    foreach (['test', 'complete'] as $phase) {
      $files = NULL;
      foreach ($audit->checks($phase) as $check) {
        if ($check->name === 'declared_files') {
          $files = $check;
        }
      }
      $this->assertNotNull($files, sprintf('%s asks the scope question', $phase));
      $this->assertSame(CheckState::Blocked, $files->state, $phase);
      $this->assertStringContainsString('docs/late.md', (string) $files->summary, $phase);
    }
  }

  /**
   * What the browser tool droost wires writes is not the agent's scope.
   *
   * P6 run 3 was blocked twice on `.playwright-mcp/` snapshots and screenshots
   * and got past the audit by deleting its own browser evidence.
   */
  public function testBrowserToolArtifactsAreNotScopeCreep(): void {
    $audit = new DeclarationAudit(
      ['src'],
      [],
      ['src/A.php', '.playwright-mcp/page-2026-09-23T05-38-28-716Z.yml', '.playwright-mcp/t3-rinks.png'],
    );

    $this->assertSame([], $audit->undeclared());
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'declared_files')->state);
  }

  /**
   * A phpunit pass over a suite the run did not touch says so.
   *
   * In P6 runs 3 and 4, at `medium`, phpunit passed on one test, a T1
   * scaffold's attribute check. Run 4 had added an importer and no test, and
   * the record read "phpunit passed — 1 test(s)" either way.
   */
  public function testPhpunitPassOverAnUntouchedSuiteIsRecorded(): void {
    $changed = ['web/modules/custom/example_directory/src/Importer.php', 'config/sync/node.type.a.yml'];
    $declared = ['web/modules/custom/example_directory', 'config/sync'];

    $untested = new DeclarationAudit($declared, [], $changed, NULL, ['phpcs', 'phpunit']);
    $row = NULL;
    foreach ($untested->checks('test') as $check) {
      if ($check->name === 'tests_in_diff') {
        $row = $check;
      }
    }
    $this->assertNotNull($row, 'the test phase records what the green was about');
    $this->assertSame(CheckState::Recorded, $row->state);
    $this->assertFalse($row->state->blocksAdvance());
    $this->assertStringContainsString('Importer.php', (string) $row->summary);

    $withTest = [...$changed, 'web/modules/custom/example_directory/tests/src/Kernel/ImporterTest.php'];
    $tested = new DeclarationAudit($declared, [], $withTest, NULL, ['phpcs', 'phpunit']);
    $unmeasured = new DeclarationAudit($declared, [], $changed, NULL, ['phpcs']);
    foreach ([$tested, $unmeasured] as $audit) {
      foreach ($audit->checks('test') as $check) {
        $this->assertNotSame('tests_in_diff', $check->name, 'silent when a test changed, or when phpunit measured nothing');
      }
    }
  }

  /**
   * The store keeps the first declaration when a later one replaces it.
   */
  public function testRedeclaringKeepsTheFirstDeclaration(): void {
    $store = new EvidenceStore($this->makeRoot());
    $store->upsertRun('r1', ['preset' => 'low']);
    $store->declare('r1', 'code', 'file', 'src', '2026-09-23T13:40:00+10:00');

    $store->supersedeDeclarations('r1', 'file', '2026-09-23T13:52:47+10:00');
    foreach (['playwright.config.ts', 'src'] as $path) {
      $store->declare('r1', 'code', 'file', $path, '2026-09-23T13:52:47+10:00');
    }

    $this->assertSame(['playwright.config.ts', 'src'], $store->declared('r1', 'file'), 'the latest declaration is what is declared');
    $this->assertSame(
      ['at' => '2026-09-23T13:40:00+10:00', 'values' => ['src']],
      $store->firstDeclaration('r1', 'file'),
      'and the first one survives beside it',
    );
    $this->assertSame(['at' => NULL, 'values' => []], $store->firstDeclaration('r1', 'test'));
  }

  /**
   * A store written before V11 gains the column and keeps its rows.
   */
  public function testAnOlderStoreGainsTheSupersededColumn(): void {
    $root = $this->makeRoot();
    (new EvidenceStore($root))->upsertRun('r1', ['preset' => 'low']);
    $pdo = new \PDO('sqlite:' . $root . '/droost/droost-workflow/evidence.sqlite');
    $pdo->exec('DROP TABLE declaration');
    $pdo->exec('CREATE TABLE declaration (run_id TEXT NOT NULL, phase TEXT NOT NULL, kind TEXT NOT NULL, value TEXT NOT NULL, declared_at TEXT NOT NULL)');
    $pdo->exec("INSERT INTO declaration VALUES ('r1', 'plan', 'file', 'src', '2026-09-23T13:40:00+10:00')");
    $pdo->exec('PRAGMA user_version = 10');
    unset($pdo);

    $upgraded = new EvidenceStore($root);
    $this->assertSame(['src'], $upgraded->declared('r1', 'file'), 'an old row reads as current');
    $upgraded->supersedeDeclarations('r1', 'file', '2026-09-23T13:52:47+10:00');
    $this->assertSame([], $upgraded->declared('r1', 'file'), 'and can be superseded');
    $this->assertSame(['src'], $upgraded->firstDeclaration('r1', 'file')['values']);
  }

  /**
   * One check from an audit's code-phase answer, by name.
   *
   * @param \Droost\Workflow\Evidence\DeclarationAudit $audit
   *   The audit.
   * @param string $name
   *   The check name.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord
   *   The check.
   */
  private function check(DeclarationAudit $audit, string $name): CheckRecord {
    foreach ($audit->checks('code') as $check) {
      if ($check->name === $name) {
        return $check;
      }
    }
    $this->fail('no ' . $name . ' check in the code phase');
  }

  /**
   * A scratch project root with a state directory.
   *
   * @return string
   *   The root.
   */
  private function makeRoot(): string {
    $root = sys_get_temp_dir() . '/droost-declaration-history-' . bin2hex(random_bytes(6));
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);

    return $root;
  }

}
