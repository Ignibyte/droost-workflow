<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The spec's structure is rows a tool call wrote, not prose someone parsed.
 *
 * The measurement this exists to answer (F-35): across Part 3's three runs
 * every code-quality gate passed every time, and all three stoppages were
 * the shape of a markdown file — a fenced route list, a rewritten frozen
 * section, a data table under the wrong heading. The gates worked. The
 * contract serving them killed the runs.
 *
 * A row cannot be mis-shaped. `declare_route /camps` either happened or it
 * did not.
 */
final class SpecAsRowsTest extends WorkflowTestCase {

  /**
   * The moment every test here uses.
   */
  private const NOW = '2026-09-18T10:00:00+00:00';

  /**
   * Routes are rows, deduplicated by path, in declaration order.
   */
  public function testRoutesAreRowsInDeclarationOrder(): void {
    $store = $this->store();
    $store->declareRoute('r1', 'plan', '/rinks', 'the new listing', self::NOW);
    $store->declareRoute('r1', 'plan', '/camps', NULL, self::NOW);
    // Re-declared at a later phase: idempotent, one answer, no exception.
    $store->declareRoute('r1', 'code', '/rinks', 'still the listing', self::NOW);

    $routes = $store->specRoutes('r1');
    $this->assertSame(['/rinks', '/camps'], array_column($routes, 'path'));
    $this->assertSame('still the listing', $routes[0]['reason'], 'the latest revision answers');
    $this->assertSame('code', $routes[0]['phase']);
  }

  /**
   * A criterion restated is a visible revision, not an overwrite.
   *
   * This is what `SpecFreeze` was for, and the reason it had to refuse: prose
   * has no revision number, so the only way to protect "I promised X" was to
   * forbid the edit. A row has one.
   */
  public function testRestatingOneCriterionKeepsBothStatements(): void {
    $store = $this->store();
    $store->declareCriterion('r1', 'plan', 'AC-1', 'the listing shows every published rink', self::NOW);
    $store->declareCriterion('r1', 'code', 'AC-1', 'the listing shows every published rink, newest first', self::NOW);

    $criteria = $store->specCriteria('r1');
    $this->assertCount(1, $criteria, 'one current answer per ref');
    $this->assertSame(
      'the listing shows every published rink, newest first',
      $criteria[0]['statement'],
    );
    $this->assertSame(2, $criteria[0]['revisions'], 'and the original is still in the table');
  }

  /**
   * Restating a criterion drops its proof.
   *
   * The circularity this whole apparatus exists to prevent is a criterion
   * retrofitted to whatever happened to pass. Carrying `verified_by` across a
   * changed statement would be exactly that, in one tool call: verify AC-1,
   * then rewrite AC-1 into something the same test does not prove.
   */
  public function testTheRestatementLosesItsVerification(): void {
    $store = $this->store();
    $store->declareCriterion('r1', 'plan', 'AC-1', 'every published rink is listed', self::NOW);
    $this->assertTrue($store->verifyCriterion('r1', 'test', 'AC-1', 'RinkListTest::testEveryPublished', self::NOW));
    $this->assertSame(
      'RinkListTest::testEveryPublished',
      $store->specCriteria('r1')[0]['verified_by'],
    );

    $store->declareCriterion('r1', 'test', 'AC-1', 'every rink is listed, published or not', self::NOW);
    $current = $store->specCriteria('r1')[0];
    $this->assertNull($current['verified_by'], 'the new sentence is unproven until something proves it');
    $this->assertNull($current['verified_at']);

    // And re-declaring the SAME sentence is idempotent: it keeps the proof,
    // because nothing about what was promised changed.
    $store->verifyCriterion('r1', 'test', 'AC-1', 'RinkListTest::testAll', self::NOW);
    $store->declareCriterion('r1', 'test', 'AC-1', 'every rink is listed, published or not', self::NOW);
    $this->assertSame('RinkListTest::testAll', $store->specCriteria('r1')[0]['verified_by']);
  }

  /**
   * Verification may not restate what it verifies.
   */
  public function testVerificationCarriesTheStatementAsDeclared(): void {
    $store = $this->store();
    $store->declareCriterion('r1', 'plan', 'AC-2', 'the camp page shows its coach', self::NOW);
    $store->verifyCriterion('r1', 'test', 'AC-2', '/camps/summer-skills rendered', self::NOW);

    $this->assertSame(
      'the camp page shows its coach',
      $store->specCriteria('r1')[0]['statement'],
    );
  }

  /**
   * Verifying a criterion nobody declared is refused.
   *
   * The one error in this whole surface, and it is the one that matters:
   * a run that can verify unstated criteria proves whatever it happened to
   * do. Everything else about a declaration is a row.
   */
  public function testVerifyingAnUndeclaredCriterionIsRefused(): void {
    $store = $this->store();
    $this->assertFalse($store->verifyCriterion('r1', 'test', 'AC-9', 'something', self::NOW));
    $this->assertSame([], $store->specCriteria('r1'));
  }

  /**
   * Notes carry whatever the prose would have said, keyed by kind.
   */
  public function testNotesAreScopedByKindAndSubject(): void {
    $store = $this->store();
    $store->declareNote('r1', 'plan', 'grounding', 'Drupal\\node\\Entity\\Node', 'the entity the listing reads', self::NOW);
    $store->declareNote('r1', 'plan', 'tooling', 'droost_search', NULL, self::NOW);
    // Same subject, different kind: two notes, not one revision of one.
    $store->declareNote('r1', 'plan', 'decision', 'droost_search', 'used for the view, not the block', self::NOW);
    $store->declareNote('r1', 'code', 'tooling', 'droost_search', 'called 6 times', self::NOW);

    $this->assertSame(
      ['grounding', 'tooling', 'decision'],
      array_column($store->specNotes('r1'), 'kind'),
    );
    $tooling = $store->specNotes('r1', 'tooling');
    $this->assertCount(1, $tooling);
    $this->assertSame('called 6 times', $tooling[0]['detail'], 'the later revision answers');
  }

  /**
   * Rows are scoped to their run, and a second run starts empty.
   *
   * F-6's shape: a ledger that never cleared between runs let run 2 inherit
   * run 1's evidence. Every table added since is asked the same question
   * before it ships.
   */
  public function testEveryTableIsScopedToItsRun(): void {
    $store = $this->store();
    $store->declareRoute('r1', 'plan', '/rinks', NULL, self::NOW);
    $store->declareCriterion('r1', 'plan', 'AC-1', 'x', self::NOW);
    $store->declareNote('r1', 'plan', 'grounding', 'y', NULL, self::NOW);

    $this->assertSame([], $store->specRoutes('r2'));
    $this->assertSame([], $store->specCriteria('r2'));
    $this->assertSame([], $store->specNotes('r2'));
    $this->assertFalse($store->verifyCriterion('r2', 'test', 'AC-1', 'z', self::NOW));
  }

  /**
   * The identifier allowlist refuses a table this class does not own.
   *
   * SQL cannot bind an identifier, so the revision query interpolates one.
   * The values are literals from this class's own callers and always will be
   * — the allowlist is what keeps that a fact rather than a comment.
   */
  public function testTheRevisionQueryRefusesAnUnknownTable(): void {
    $store = $this->store();
    $method = new \ReflectionMethod($store, 'nextRevision');
    $this->expectException(\InvalidArgumentException::class);
    $method->invoke($store, 'run', 'path', 'r1', '/x', NULL);
  }

  /**
   * An older store gains the three tables.
   */
  public function testAnOlderStoreGainsTheSpecTables(): void {
    $root = $this->makeRoot();
    (new EvidenceStore($root))->upsertRun('r1', ['preset' => 'low']);
    $pdo = new \PDO('sqlite:' . $root . '/droost/droost-workflow/evidence.sqlite');
    foreach (['spec_route', 'spec_criterion', 'spec_note'] as $table) {
      $pdo->exec('DROP TABLE IF EXISTS ' . $table);
    }
    $pdo->exec('PRAGMA user_version = 9');
    unset($pdo);

    $upgraded = new EvidenceStore($root);
    $this->assertSame([], $upgraded->specRoutes('r1'), 'the tables are there and empty');
    $upgraded->declareRoute('r1', 'plan', '/rinks', NULL, self::NOW);
    $this->assertCount(1, $upgraded->specRoutes('r1'), 'and they take rows');
  }

  /**
   * A store on a fresh root.
   *
   * @return \Droost\Workflow\Evidence\EvidenceStore
   *   The store, with one run open.
   */
  private function store(): EvidenceStore {
    $store = new EvidenceStore($this->makeRoot());
    $store->upsertRun('r1', ['preset' => 'medium', 'started_at' => self::NOW]);

    return $store;
  }

}
