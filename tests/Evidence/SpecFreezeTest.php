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

    ## Tooling plan

    | # | Construct | Surface |
    |---|---|---|
    | 1 | rink bundle | `droost_structure_create` |

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
   * Not merely legal — REQUIRED: `groundingMissing` refuses the code phase
   * without rows tagged `code`. Freezing grounding whole made those rows
   * simultaneously mandatory and forbidden.
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
   * Also required: `criteriaUnverified` refuses to gate complete while a cell
   * is empty. Freezing the criteria row whole made the fill forbidden.
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

    $this->assertSame(
      ['## Acceptance criteria (1 row(s) removed or rewritten)'],
      SpecFreeze::breaches($tampered, $this->spec()),
    );
  }

  /**
   * Deleting a criterion is caught.
   */
  public function testDeletingCriterionIsCaught(): void {
    // No trailing newline to strip: the heredoc's last line IS this row.
    $tampered = str_replace('| AC1 | the page renders | curl /rinks | |', '', $this->spec());

    $this->assertNotSame($this->spec(), $tampered, 'the fixture really did delete it');
    $this->assertSame(
      ['## Acceptance criteria (1 row(s) removed or rewritten)'],
      SpecFreeze::breaches($tampered, $this->spec()),
    );
  }

  /**
   * Swapping a plan citation for an easier one is caught.
   *
   * The hole SpecFreeze exists to close: a citation that satisfied the plan
   * gate, rewritten before the code gate resolved it.
   */
  public function testRewritingPlanCitationIsCaught(): void {
    $tampered = str_replace('`Drupal\node\Entity\NodeType`', '`none: anything`', $this->spec());

    $this->assertSame(
      ['## Grounding (1 row(s) removed or rewritten)'],
      SpecFreeze::breaches($tampered, $this->spec()),
    );
  }

  /**
   * The tooling plan is frozen whole, because no phase is told to edit it.
   */
  public function testRewritingTheToolingPlanIsCaught(): void {
    $tampered = str_replace('`droost_structure_create`', 'hand-written', $this->spec());

    $this->assertSame(['## Tooling plan'], SpecFreeze::breaches($tampered, $this->spec()));
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
   * A run that recorded nothing is not retroactively in breach.
   */
  public function testNothingRecordedMeansNothingToBreach(): void {
    $this->assertTrue(SpecFreeze::intact($this->spec(), NULL));
    $this->assertTrue(SpecFreeze::intact($this->spec(), ''));
  }

  /**
   * The fingerprint tracks the tooling plan, and only that.
   *
   * The other two sections may legitimately grow, and a digest of something
   * that may grow answers no question anybody asked.
   */
  public function testTheFingerprintTracksTheToolingPlanOnly(): void {
    $before = SpecFreeze::fingerprint($this->spec());
    $moreGrounding = str_replace(
      '| plan | core | how is a bundle made? | NodeType | `Drupal\node\Entity\NodeType` |',
      "| plan | core | how is a bundle made? | NodeType | `Drupal\\node\\Entity\\NodeType` |\n"
      . '| code | custom | anything? | nothing | `none: rink` |',
      $this->spec(),
    );

    $this->assertSame($before, SpecFreeze::fingerprint($moreGrounding));
    $this->assertNotSame(
      $before,
      SpecFreeze::fingerprint(str_replace('`droost_structure_create`', 'hand-written', $this->spec())),
    );
  }

}
