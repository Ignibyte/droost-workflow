<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Spec;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
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
   * A proof recorded as partly or failed is on record and proves nothing.
   *
   * F-357: droost read a proof's presence, never its result.
   */
  public function testProofThatDidNotHoldIsNotCounted(): void {
    $root = $this->rootWithSpec('');
    $store = new EvidenceStore($root);
    foreach (['AC-1', 'AC-2', 'AC-3'] as $ref) {
      $store->declareCriterion('r1', 'plan', $ref, 'criterion ' . $ref);
    }
    $store->verifyCriterion('r1', 'test', 'AC-1', 'contact.spec.ts AC-1 fails on the 390px view', NULL, 'failed');
    $store->verifyCriterion('r1', 'test', 'AC-2', 'two of three fields saved', NULL, 'partly');
    $store->verifyCriterion('r1', 'test', 'AC-3', 'contact.spec.ts AC-3');

    $counted = CriteriaVerification::resolve($root, 'r1', 'spec.md');
    $this->assertNotNull($counted);
    $this->assertSame(['AC-3'], $counted['verified']);
    $this->assertSame(['AC-1', 'AC-2'], $counted['unverified']);
    $this->assertSame(['failed', 'partly', 'proven'], array_column($store->specCriteria('r1'), 'outcome'));
  }

  /**
   * A proof naming a gate whose latest verdict failed proves nothing.
   *
   * UNI-A run 6's AC-10, word for word: its proof named parity, which was
   * blocked on every attempt, and it was counted among the verified (F-357).
   * When parity later passes, the same proof counts.
   */
  public function testProofNamingFailingGateIsNotCounted(): void {
    $root = $this->rootWithSpec('');
    $store = new EvidenceStore($root);
    $store->declareCriterion('r1', 'plan', 'AC-10', 'every view passes parity on the frame');
    $store->declareCriterion('r1', 'plan', 'AC-11', 'the form is tested');
    $store->verifyCriterion('r1', 'test', 'AC-10', 'droost/bin/canvas lint --custom: 45 of 45 pass. Parity: FAILS D5 on the frame at 1280px on all 7 routes');
    $store->verifyCriterion('r1', 'test', 'AC-11', 'r05-contact.spec.ts AC-11 (playwright)');
    $store->record('r1', 'test', new CheckRecord('gate', 'playwright', CheckState::Satisfied, Fault::None, 'playwright passed'));
    $store->record('r1', 'test', new CheckRecord('gate', 'parity', CheckState::Blocked, Fault::Agent, 'parity FAILED — 7 of 14 views'));

    $counted = CriteriaVerification::resolve($root, 'r1', 'spec.md');
    $this->assertNotNull($counted);
    $this->assertSame(['AC-11'], $counted['verified']);
    $this->assertSame(['AC-10'], $counted['unverified']);
    $this->assertSame(
      ['standing' => 'unverified', 'why' => 'its proof names parity, whose latest verdict in this run is blocked'],
      CriteriaVerification::standing('Parity: FAILS D5', NULL, $store->latestGateStates('r1')),
    );
    $this->assertSame('verified', CriteriaVerification::standing('the non-parity layout', NULL, ['parity' => 'blocked'])['standing'], 'a word, not a substring');
    $this->assertSame('unverified', CriteriaVerification::standing('component_lint: 45 pass', NULL, ['custom:component_lint' => 'unblocked'])['standing'], 'a custom gate by its own name');

    $store->record('r1', 'test', new CheckRecord('gate', 'parity', CheckState::Satisfied, Fault::None, 'parity passed — 14 of 14'));
    $counted = CriteriaVerification::resolve($root, 'r1', 'spec.md');
    $this->assertNotNull($counted);
    $this->assertSame(['AC-10', 'AC-11'], $counted['verified'], 'parity passing, the same proof counts');
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
      "# Spec\n\n## Notes\n\n- fixture\n\n## Acceptance criteria\n\n" . $table,
    );
    (new EvidenceStore($root))->upsertRun('r1', ['preset' => 'medium']);

    return $root;
  }

}
