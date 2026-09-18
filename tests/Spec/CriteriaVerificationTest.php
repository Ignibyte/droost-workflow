<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Spec;

use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Spec\CriteriaVerification;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Promise against proof, counted from rows where a run has them.
 *
 * `P3-T2` is why. Its spec documented six source records under
 * `## Acceptance criteria`, the engine read anything under that heading as
 * criteria, demanded a `Verified By` cell on six rows that are not criteria,
 * and then refused the correction as a frozen-section breach. The build was
 * complete, live and verified; the run was structurally deadlocked (F-35).
 */
final class CriteriaVerificationTest extends WorkflowTestCase {

  /**
   * Declared rows answer, and say so.
   */
  public function testDeclaredCriteriaAnswerFirst(): void {
    $root = $this->rootWithSpec("| ID | Statement | Verified By |\n|---|---|---|\n| AC-9 | from the table | SomeTest |\n");
    $store = new EvidenceStore($root);
    $store->declareCriterion('r1', 'plan', 'AC-1', 'every published rink is listed');
    $store->declareCriterion('r1', 'plan', 'AC-2', 'the page shows its coach');
    $store->verifyCriterion('r1', 'test', 'AC-1', 'RinkListTest::testEveryPublished');

    $counted = CriteriaVerification::resolve($root, 'r1', 'spec.md');
    $this->assertNotNull($counted);
    $this->assertSame('declared', $counted['source']);
    $this->assertSame(2, $counted['total']);
    $this->assertSame(['AC-1'], $counted['verified']);
    $this->assertSame(['AC-2'], $counted['unverified']);
    $this->assertSame([], $counted['manual']);
  }

  /**
   * A `manual` proof is a declaration, not a verdict, and its own bucket.
   *
   * The report must print manual and never passed: a human looked is a
   * different claim from a test proves, and one word compared is not
   * judgement.
   */
  public function testManualIsItsOwnAnswer(): void {
    $root = $this->rootWithSpec('');
    $store = new EvidenceStore($root);
    $store->declareCriterion('r1', 'plan', 'AC-1', 'the layout reads well on a phone');
    $store->verifyCriterion('r1', 'test', 'AC-1', 'manual — checked at 390px');

    $counted = CriteriaVerification::resolve($root, 'r1', 'spec.md');
    $this->assertNotNull($counted);
    $this->assertSame(['AC-1'], $counted['manual']);
    $this->assertSame([], $counted['verified']);
    $this->assertSame([], $counted['unverified']);
  }

  /**
   * The rows have no `unnamed` bucket, and that is deliberate.
   *
   * Deciding that a filled-in proof "points at nothing anyone can open" is a
   * regex's opinion about whether a string looks like a test. What a declared
   * proof NAMES is the test gate's question; whether it was declared is this
   * one's.
   */
  public function testTheDeclaredProofIsNeverJudgedOnItsSpelling(): void {
    $root = $this->rootWithSpec('');
    $store = new EvidenceStore($root);
    $store->declareCriterion('r1', 'plan', 'AC-1', 'x');
    $store->verifyCriterion('r1', 'test', 'AC-1', 'I looked at it and it was fine');

    $counted = CriteriaVerification::resolve($root, 'r1', 'spec.md');
    $this->assertNotNull($counted);
    $this->assertSame(['AC-1'], $counted['verified']);
    $this->assertSame([], $counted['unnamed'], 'no bucket for a proof the engine dislikes');
    $this->assertFalse($counted['column_missing'], 'rows cannot omit a column');
  }

  /**
   * With nothing declared, the table still answers.
   */
  public function testTheTableIsTheFallback(): void {
    $root = $this->rootWithSpec(
      "| ID | Statement | Verified By |\n|---|---|---|\n| AC-1 | listed | RinkTest::testIt |\n| AC-2 | shown | |\n"
    );

    $counted = CriteriaVerification::resolve($root, 'r1', 'spec.md');
    $this->assertNotNull($counted);
    $this->assertSame('parsed', $counted['source']);
    $this->assertSame(['AC-1'], $counted['verified']);
    $this->assertSame(['AC-2'], $counted['unverified']);
  }

  /**
   * Neither source carrying criteria is NULL, not zero verified.
   *
   * "Nobody promised anything" and "nothing is proven" are different facts
   * and the second must never stand in for the first.
   */
  public function testNoCriteriaAnywhereIsNotZeroVerified(): void {
    $root = $this->rootWithSpec('');

    $this->assertNull(CriteriaVerification::resolve($root, 'r1', 'spec.md'));
    $this->assertNull(CriteriaVerification::resolve($root, NULL, NULL));
  }

  /**
   * Another run's criteria are not this run's.
   */
  public function testAnotherRunsCriteriaAreNotCounted(): void {
    $root = $this->rootWithSpec('');
    (new EvidenceStore($root))->declareCriterion('r-earlier', 'plan', 'AC-1', 'x');

    $this->assertNull(CriteriaVerification::resolve($root, 'r1', 'spec.md'));
  }

  /**
   * A project root whose `spec.md` carries the given criteria section body.
   *
   * @param string $table
   *   The body under `## Acceptance criteria`.
   *
   * @return string
   *   The root.
   */
  private function rootWithSpec(string $table): string {
    $root = $this->makeRoot();
    file_put_contents(
      $root . '/spec.md',
      "# Spec\n\n## Tooling plan\n\n- hand\n\n## Acceptance criteria\n\n" . $table,
    );
    (new EvidenceStore($root))->upsertRun('r1', ['preset' => 'medium']);

    return $root;
  }

}
