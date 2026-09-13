<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\SpecFreeze;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The contract is fixed at plan exit; the document stays writable.
 *
 * Both halves matter equally. A freeze that forbade appending would break the
 * `## Realized` capture and the seeker's ledgers, which are REQUIRED to land in
 * this same file — and a freeze that let a criterion be rewritten would leave
 * the agent writing the contract it is graded against, with an edit button in
 * between.
 */
#[CoversClass(SpecFreeze::class)]
final class SpecFreezeTest extends TestCase {

  /**
   * A spec carrying all three frozen sections.
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

    | ID | Criterion | Verified By |
    |---|---|---|
    | AC1 | the page renders | RinkTest::testItRenders |
    MD;
  }

  /**
   * Appending the run's own history leaves the contract intact.
   *
   * The complete phase REQUIRES a `## Realized` section here, and the seeker
   * appends an inspection ledger every round. Freezing the whole document would
   * make doing what the workflow demands look like tampering.
   */
  public function testAppendingRealizedAndSeekerLedgersIsLegal(): void {
    $frozen = SpecFreeze::fingerprint($this->spec());
    $grown = $this->spec() . "\n\n## Realized\n\nBuilt the bundle.\n\n"
      . "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n";

    $this->assertTrue(SpecFreeze::intact($grown, $frozen));
  }

  /**
   * Trailing whitespace is not tampering.
   *
   * An editor that trims on save must not raise an alarm, or everyone learns
   * to ignore the alarm.
   */
  public function testTrailingWhitespaceIsNoBreach(): void {
    $frozen = SpecFreeze::fingerprint($this->spec());
    // At the END of lines only — replacing ' |' everywhere would pad the
    // interior of every table cell, which IS a content change.
    $retrimmed = preg_replace('/$/m', '   ', $this->spec());
    $this->assertIsString($retrimmed);

    $this->assertNotSame($this->spec(), $retrimmed, 'the fixture really did change');
    $this->assertTrue(SpecFreeze::intact($retrimmed, $frozen));
  }

  /**
   * Reordering sections is not tampering either.
   */
  public function testReorderingSectionsIsNoBreach(): void {
    $frozen = SpecFreeze::fingerprint($this->spec());
    $parts = preg_split('/(?=^## )/m', $this->spec());
    $this->assertIsArray($parts);
    // Each part must end with a newline before it can be moved: the heredoc's
    // last section has none, and putting it first would weld its final line to
    // the next heading — a corrupted fixture, not a reordered one.
    $parts = array_map(static fn (string $part): string => rtrim($part, "\n") . "\n\n", $parts);
    $reordered = $parts[0] . $parts[3] . $parts[1] . $parts[2];

    $this->assertTrue(
      SpecFreeze::intact($reordered, $frozen),
      'a cut-and-pasted heading has not changed what the run promised',
    );
  }

  /**
   * Rewriting a criterion after the plan is a breach, and it is named.
   */
  public function testRewritingCriterionIsCaughtAndNamed(): void {
    $tampered = str_replace('RinkTest::testItRenders', 'I tested it by hand', $this->spec());

    $this->assertFalse(SpecFreeze::intact($tampered, SpecFreeze::fingerprint($this->spec())));
    $this->assertSame(
      ['## Acceptance criteria'],
      SpecFreeze::changedSections($tampered, $this->spec()),
    );
  }

  /**
   * Rewriting a grounding citation is a breach, and it is named.
   *
   * The concrete hole: a citation that satisfied the plan gate could be swapped
   * for an easier one before the code gate resolved it.
   */
  public function testRewritingCitationIsCaughtAndNamed(): void {
    $tampered = str_replace('`Drupal\node\Entity\NodeType`', '`none: anything`', $this->spec());

    $this->assertFalse(SpecFreeze::intact($tampered, SpecFreeze::fingerprint($this->spec())));
    $this->assertSame(['## Grounding'], SpecFreeze::changedSections($tampered, $this->spec()));
  }

  /**
   * Swapping a planned tool for a hand-written row is a breach.
   */
  public function testRewritingTheToolingPlanIsCaught(): void {
    $tampered = str_replace('`droost_structure_create`', 'hand-written', $this->spec());

    $this->assertSame(['## Tooling plan'], SpecFreeze::changedSections($tampered, $this->spec()));
  }

  /**
   * A run that froze nothing is not retroactively in breach.
   *
   * Runs begun before this existed, and runs whose evidence store could not be
   * written, must keep working.
   */
  public function testNothingFrozenMeansNothingToBreach(): void {
    $this->assertTrue(SpecFreeze::intact($this->spec(), NULL));
    $this->assertTrue(SpecFreeze::intact($this->spec(), ''));
  }

  /**
   * A spec with no contract sections still fingerprints.
   *
   * "This spec carried no contract" is a fact worth pinning; an empty digest
   * would make it indistinguishable from a spec nobody froze.
   */
  public function testSpecWithNoSectionsStillFingerprints(): void {
    $bare = SpecFreeze::fingerprint("# just a title\n\nsome prose\n");

    $this->assertNotSame('', $bare);
    $this->assertNotSame(SpecFreeze::fingerprint($this->spec()), $bare);
  }

}
