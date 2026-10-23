<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\SpecFreeze;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The contract is fixed at plan exit, in the way each section can bear.
 *
 * Both halves matter, and the first cut of this class got the second one badly
 * wrong: it froze grounding and the criteria whole, which made every run
 * impossible, because the workflow REQUIRES the code phase to add grounding
 * rows and the test phase to fill `Verified By`. Add them and the freeze
 * refused; omit them and the phase gate refused. There was no legal move.
 *
 * Every rule below therefore has a test for what it must ALLOW as well as one
 * for what it must catch. See also SpecFreezeIntegrationTest, which drives a
 * whole run through the facade — the class of test whose absence let a
 * deadlock ship under a green suite.
 */
#[CoversClass(SpecFreeze::class)]
final class SpecFreezeTest extends TestCase {

  /**
   * A spec as the plan phase leaves it.
   *
   * @return string
   *   The markdown.
   */
  private function spec(): string {
    return <<<'MD'
    # HH-3 — rink detail

    ## Routes

    none — fixture

    ## Grounding

    | Phase | Tier | Asked | Found | Evidence |
    |---|---|---|---|---|
    | plan | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |

    ## Acceptance criteria

    | ID | Criterion | Check | Verified By |
    |---|---|---|---|
    | AC1 | the page renders | curl /rinks | |
    MD;
  }

  /**
   * The code phase adding its grounding rows is legal.
   *
   * Not merely legal — expected: the `grounding` observation says when the
   * code phase added no rows tagged `code`. Freezing grounding whole made
   * those rows simultaneously asked for and forbidden.
   */
  public function testCodeMayAddItsGroundingRows(): void {
    $grown = str_replace(
      '| plan | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |',
      "| plan | core | how is a bundle made? | NodeType | `Drupal\\node\\Entity\\NodeType` |\n"
      . '| code | custom | anything named rink? | nothing | `none: rink` |',
      $this->spec(),
    );

    $this->assertSame([], SpecFreeze::breaches($grown, $this->spec()));
  }

  /**
   * The test phase filling `Verified By` is legal.
   *
   * Also expected: the `criteria_verified` observation names an empty cell at
   * complete. Freezing the criteria row whole made the fill forbidden.
   */
  public function testTestMayFillTheVerifiedByColumn(): void {
    $filled = str_replace(
      '| AC1 | the page renders | curl /rinks | |',
      '| AC1 | the page renders | curl /rinks | RinkTest::testItRenders |',
      $this->spec(),
    );

    $this->assertSame([], SpecFreeze::breaches($filled, $this->spec()));
  }

  /**
   * A criterion with an escaped pipe may have its Verified By filled (F-359).
   *
   * UNI-A run 9's AC-6 names two page titles, `Home Phone \| United
   * Communications`, and the freeze split the escaped pipe as a cell
   * boundary: filling the cell the test phase is told to fill read as the
   * criterion rewritten. Its wording changed is still caught.
   */
  public function testAnEscapedPipeIsPartOfItsCell(): void {
    $row = '| AC1 | the tab reads "Home Phone \| United Communications" | test | |';
    $frozen = str_replace('| AC1 | the page renders | curl /rinks | |', $row, $this->spec());
    $filled = str_replace($row, '| AC1 | the tab reads "Home Phone \| United Communications" | test | r07.spec.ts › AC-6 |', $frozen);
    $this->assertSame([], SpecFreeze::breaches($filled, $frozen));

    $rewritten = str_replace('Home Phone', 'Phone', $filled);
    $this->assertNotSame([], SpecFreeze::breaches($rewritten, $frozen), 'its wording is still frozen');
  }

  /**
   * A pipe inside a code span is part of its cell (F-365).
   */
  public function testPipeInCodeSpanIsPartOfItsCell(): void {
    $row = '| AC1 | the id is an `int|string` | test | |';
    $frozen = str_replace('| AC1 | the page renders | curl /rinks | |', $row, $this->spec());
    $filled = str_replace($row, '| AC1 | the id is an `int|string` | test | r07.spec.ts › AC-6 |', $frozen);
    $this->assertSame([], SpecFreeze::breaches($filled, $frozen));
  }

  /**
   * Appending the run's own history is legal.
   */
  public function testAppendingRealizedAndSeekerLedgersIsLegal(): void {
    $grown = $this->spec() . "\n\n## Realized\n\nBuilt the bundle.\n\n"
      . "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n";

    $this->assertSame([], SpecFreeze::breaches($grown, $this->spec()));
  }

  /**
   * Re-padding a table is not drift.
   */
  public function testWhitespaceChangesAreNoBreach(): void {
    $repadded = str_replace('| AC1 | the page renders |', '|  AC1  |  the page renders  |', $this->spec());

    $this->assertNotSame($this->spec(), $repadded);
    $this->assertSame([], SpecFreeze::breaches($repadded, $this->spec()));
  }

  /**
   * Rewriting a criterion into what was actually built is caught.
   *
   * The cheat every one of these rules exists for: an agent that cannot satisfy
   * AC1 quietly turns AC1 into something it did satisfy.
   */
  public function testRewritingCriterionIsCaught(): void {
    $tampered = str_replace('the page renders', 'the module exists', $this->spec());

    $breaches = SpecFreeze::breaches($tampered, $this->spec());
    $this->assertCount(1, $breaches);
    // NAMED, as frozen. "1 row(s) removed or rewritten" sent a reviewer round
    // a three-attempt loop before they found which cell had moved.
    $this->assertStringStartsWith(
      '## Acceptance criteria (1 row(s) removed or rewritten; frozen as "AC1|the page renders',
      $breaches[0],
    );
  }

  /**
   * Deleting a criterion is caught.
   */
  public function testDeletingCriterionIsCaught(): void {
    // No trailing newline to strip: the heredoc's last line IS this row.
    $tampered = str_replace('| AC1 | the page renders | curl /rinks | |', '', $this->spec());

    $this->assertNotSame($this->spec(), $tampered, 'the fixture really did delete it');
    $breaches = SpecFreeze::breaches($tampered, $this->spec());
    $this->assertCount(1, $breaches);
    $this->assertStringStartsWith(
      '## Acceptance criteria (1 row(s) removed or rewritten; frozen as "AC1|the page renders',
      $breaches[0],
    );
  }

  /**
   * A citation may be corrected, because the gate that judges it says to.
   *
   * The deadlock this closes: grounding rows freeze at plan, but nothing at
   * plan looks at a citation — the plan gate checks only that answers are
   * non-blank and all three tiers appear. grounding_check first RESOLVES them
   * at code, and its refusal reads "Cite a class this site actually has ... or
   * `none: <query>`", which is an instruction to edit a frozen cell. Freezing
   * the Evidence column therefore protected something nobody had checked and
   * forbade the only remedy the failure named.
   */
  public function testCitationMayBeCorrectedAfterTheGateRefusesIt(): void {
    $plan = "## Routes

none — fixture

## Grounding\n\n| Phase | Tier | Asked | Found | Evidence |\n"
      . "|---|---|---|---|---|\n| plan | core | how? | NodeType | `Drupal\\Made\\Up` |\n";
    $fixed = str_replace('`Drupal\Made\Up`', '`Drupal\node\Entity\NodeType`', $plan);

    $this->assertSame([], SpecFreeze::breaches($fixed, $plan));
  }

  /**
   * The Evidence column may be ADDED, which the gate also demands.
   *
   * A table with no such column fails grounding_check for citing nothing; the
   * plan gate never required it, so a spec can be frozen without one.
   */
  public function testTheEvidenceColumnMayBeAdded(): void {
    $plan = "## Routes

none — fixture

## Grounding\n\n| Phase | Tier | Asked | Found |\n|---|---|---|---|\n"
      . "| plan | core | how? | NodeType |\n";
    $withColumn = "## Routes

none — fixture

## Grounding\n\n| Phase | Tier | Asked | Found | Evidence |\n"
      . "|---|---|---|---|---|\n| plan | core | how? | NodeType | `Drupal\\node\\Entity\\NodeType` |\n";

    $this->assertSame([], SpecFreeze::breaches($withColumn, $plan));
  }

  /**
   * Rewriting what was ASKED or FOUND is still caught.
   *
   * The citation is guarded by adjudication — it is re-resolved against the
   * site on every later phase — but the claim about what was looked up and
   * what came back is guarded by the freeze, because nothing re-runs a
   * sentence.
   */
  public function testRewritingWhatWasAskedIsStillCaught(): void {
    $plan = "## Routes

none — fixture

## Grounding\n\n| Phase | Tier | Asked | Found | Evidence |\n"
      . "|---|---|---|---|---|\n| plan | core | how is a bundle made? | NodeType | `Drupal\\node\\Entity\\NodeType` |\n";
    $cheat = str_replace('how is a bundle made? | NodeType', 'anything at all? | nothing', $plan);

    $this->assertNotSame([], SpecFreeze::breaches($cheat, $plan));
  }

  /**
   * Reordering rows is not tampering: a row that moved has not changed.
   */
  public function testReorderingRowsIsNoBreach(): void {
    $two = str_replace(
      '| plan | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |',
      "| plan | core | a | b | `x` |\n| plan | custom | c | d | `none: y` |",
      $this->spec(),
    );
    $swapped = str_replace(
      "| plan | core | a | b | `x` |\n| plan | custom | c | d | `none: y` |",
      "| plan | custom | c | d | `none: y` |\n| plan | core | a | b | `x` |",
      $two,
    );

    $this->assertNotSame($two, $swapped, 'the fixture really did reorder');
    $this->assertSame([], SpecFreeze::breaches($swapped, $two));
  }

  /**
   * The fingerprint is a handle for the frozen sections as the plan ended.
   *
   * A section outside the freeze changes nothing; a frozen one does.
   */
  public function testTheFingerprintCoversTheFrozenSections(): void {
    $before = SpecFreeze::fingerprint($this->spec());

    $this->assertSame($before, SpecFreeze::fingerprint($this->spec() . "\n## Realized\n\nbuilt\n"));
    $this->assertNotSame(
      $before,
      SpecFreeze::fingerprint(str_replace('how is a bundle made?', 'what is a bundle?', $this->spec())),
    );
  }

  /**
   * Routes are append-only: a planned route may not vanish before test.
   *
   * F-15's cheapest dodge would be naming the page at plan and deleting the
   * line at code. Adding a page code discovered is legal; losing one is not.
   */
  public function testRoutesAreAppendOnly(): void {
    $frozen = "# S\n\n## Routes\n\n- /camps — listing\n- /camps/summer — detail\n";
    $added = str_replace("- /camps/summer — detail\n", "- /camps/summer — detail\n- /camps/winter — found at code\n", $frozen);
    $this->assertSame([], SpecFreeze::breaches($added, $frozen), 'a route added at code is not a breach');

    $rebulleted = str_replace("- /camps", "* /camps", $frozen);
    $this->assertSame([], SpecFreeze::breaches($rebulleted, $frozen), 'the list marker is not the promise');

    $lost = str_replace("- /camps/summer — detail\n", '', $frozen);
    $breaches = SpecFreeze::breaches($lost, $frozen);
    $this->assertCount(1, $breaches);
    $this->assertStringStartsWith('## Routes (1 route(s) removed or rewritten; frozen as "/camps/summer — detail")', $breaches[0]);
  }

}
