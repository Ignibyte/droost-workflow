<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Seeker;

use Droost\Workflow\Seeker\SeekerError;
use Droost\Workflow\Seeker\SeekerLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The checkpoint trusts the parse, never the self-report.
 */
class SeekerLedgerTest extends TestCase {

  /**
   * The sentinel is a clean inspection.
   */
  public function testTheSentinelIsClean(): void {
    $ledger = SeekerLedger::parse(
      "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n",
    );

    $this->assertTrue($ledger->isClean());
    $this->assertSame([], $ledger->findings);
    $record = $ledger->toRecord('t');
    $this->assertSame('clean', $record['status']);
    $this->assertSame(0, $record['critical']);
  }

  /**
   * An open CRITICAL or open MEDIUM blocks; resolved, carried and LOW do not.
   */
  public function testTheSeverityProtocol(): void {
    $ledger = SeekerLedger::parse(<<<'MD'
      ## Seeker Inspection

      Inspector: independent

      | ID | Severity | Location | Finding | Status |
      |----|----------|----------|---------|--------|
      | F1 | CRITICAL | a.php:1  | bad     | resolved |
      | F2 | MEDIUM   | b.php:2  | drift   | carried: follow-up #9 |
      | F3 | LOW      | c.php:3  | naming  | open   |
      MD);

    $this->assertTrue(
      $ledger->isClean(),
      'resolved, carried and open-LOW rows do not block',
    );

    $dirty = SeekerLedger::parse(<<<'MD'
      ## Seeker Inspection

      Inspector: independent

      | ID | Severity | Location | Finding | Status |
      |----|----------|----------|---------|--------|
      | F1 | MEDIUM   | b.php:2  | drift   | open   |
      MD);

    $this->assertFalse($dirty->isClean(), 'an open MEDIUM blocks');
    $record = $dirty->toRecord('t');
    $this->assertSame('findings', $record['status']);
    $this->assertSame(1, $record['medium']);
  }

  /**
   * Observations are counted and never block.
   */
  public function testObservationsAreAdvisory(): void {
    $ledger = SeekerLedger::parse(<<<'MD'
      ## Seeker Inspection

      Inspector: independent

      (no findings)

      ### Out-of-scope observations (advisory)

      - legacy.php — pre-existing unsanitized output
      - old.module — weak legacy test
      MD);

    $this->assertTrue($ledger->isClean());
    $this->assertSame(2, $ledger->observations);
  }

  /**
   * A re-inspection appends a fresh section, and the newest verdict wins.
   */
  public function testTheLastSectionWins(): void {
    $ledger = SeekerLedger::parse(<<<'MD'
      ## Seeker Inspection

      Inspector: independent

      | ID | Severity | Location | Finding | Status |
      |----|----------|----------|---------|--------|
      | F1 | CRITICAL | a.php:1  | bad     | open   |

      ## Seeker Inspection

      Inspector: independent

      (no findings)
      MD);

    $this->assertTrue($ledger->isClean());
  }

  /**
   * Everything the parser must refuse rather than guess at.
   *
   * @param string $text
   *   The ledger text.
   * @param string $expected
   *   Text the message must contain.
   */
  #[DataProvider('malformedCases')]
  public function testMalformedLedgersAreRefused(
    string $text,
    string $expected,
  ): void {
    try {
      SeekerLedger::parse($text);
      $this->fail('Expected a SeekerError.');
    }
    catch (SeekerError $e) {
      $this->assertStringContainsString($expected, $e->getMessage());
    }
  }

  /**
   * The refusals.
   *
   * @return array<string, array{string, string}>
   *   Case name to text and expected message fragment.
   */
  public static function malformedCases(): array {
    return [
      'no section at all' => [
        "# Spec\n\nSome prose.\n",
        'no "## Seeker Inspection" section',
      ],
      'heading with neither rows nor sentinel' => [
        "## Seeker Inspection\n\nLooks fine to me!\n",
        'incomplete inspection',
      ],
      'sentinel AND rows' => [
        "## Seeker Inspection\n\n(no findings)\n\n"
        . "| ID | Severity | Location | Finding | Status |\n"
        . "|----|----|----|----|----|\n"
        . "| F1 | LOW | a:1 | x | open |\n",
        'contradiction',
      ],
      'severity outside the protocol' => [
        "## Seeker Inspection\n\n"
        . "Inspector: independent\n\n"
        . "| ID | Severity | Location | Finding | Status |\n"
        . "|----|----|----|----|----|\n"
        . "| F1 | BLOCKER | a:1 | x | open |\n",
        'severity "BLOCKER"',
      ],
      'empty status' => [
        "## Seeker Inspection\n\n"
        . "Inspector: independent\n\n"
        . "| ID | Severity | Location | Finding | Status |\n"
        . "|----|----|----|----|----|\n"
        . "| F1 | LOW | a:1 | x |  |\n",
        'empty status',
      ],
    ];
  }

  /**
   * The section's own self-review label is read back into the record.
   *
   * The pack's contract when a subagent cannot be dispatched is that the
   * inspection still HAPPENS and that the section says it was not
   * independent. Nothing read that label, so a run that reviewed itself
   * produced a record identical to an independently-cleared one — and three
   * consecutive live rounds took exactly that path.
   */
  public function testSelfReviewLabelIsRead(): void {
    $labelled = SeekerLedger::parse(
      "## Seeker Inspection\n\n"
      . "Inspector: self-reviewed\n\n"
      . "(no findings)\n\n"
      . "This session does not spawn subagents, so the six lenses were "
      . "applied in-session instead and this inspection is **self-reviewed**, "
      . "recorded so the substitution is visible.\n",
    );
    $this->assertTrue($labelled->selfReviewed);
    $this->assertTrue($labelled->toRecord('t1')['self_reviewed']);

    // Silence is not a confession: an unlabelled inspection is independent,
    // because the contract asks a SELF-review to declare itself.
    $plain = SeekerLedger::parse("## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n");
    $this->assertFalse($plain->selfReviewed);
    $this->assertFalse($plain->toRecord('t1')['self_reviewed']);
  }

  /**
   * The label is read wherever the agent puts it, including a table row.
   */
  public function testSelfReviewLabelIsReadFromAnywhereInTheSection(): void {
    $inRow = SeekerLedger::parse(
      "## Seeker Inspection\n\n"
      . "Inspector: self-reviewed\n\n"
      . "Self-reviewed: no subagent was available.\n\n"
      . "| ID | Severity | Location | Finding | Status |\n"
      . "|----|----|----|----|----|\n"
      . "| F1 | LOW | a:1 | x | open |\n",
    );
    $this->assertTrue($inRow->selfReviewed);
    $this->assertCount(1, $inRow->findings);
  }

  /**
   * A live round's own words count as the disclosure.
   *
   * Verbatim from the T01 eval ledger. The agent disclosed the substitution
   * more fully than the contract asks — naming the agent, what it did
   * instead, and why it recorded the fact — and never wrote the canonical
   * token. An exact-token match read that as an independent inspection,
   * which is the failure this whole field exists to prevent, so the real
   * wording is pinned rather than an invented paraphrase.
   */
  public function testRealSelfReviewDisclosureIsRecognised(): void {
    $ledger = SeekerLedger::parse(
      "## Seeker Inspection\n\n"
      . "Inspector: self-reviewed\n\n"
      . "Inspection performed in-session over the run's full diff against\n"
      . "the spec. This session is configured not to spawn subagents, so the\n"
      . "`workflow-seeker` agent was not dispatched and the six lenses were\n"
      . "applied directly instead. Recorded here so the substitution is\n"
      . "visible rather than implied.\n\n"
      . "(no findings)\n",
    );
    $this->assertTrue(
      $ledger->selfReviewed,
      'an honest disclosure in the agent\'s own words is still a disclosure',
    );
  }

  /**
   * The third live phrasing: "not to dispatch agents", "in-session".
   *
   * Verbatim from the T09 eval ledger — the third distinct wording in three
   * rounds, and the second the phrase list missed on first contact. Every
   * addition is pinned by the real ledger that exposed it.
   */
  public function testThirdLiveDisclosurePhrasingIsRecognised(): void {
    $ledger = SeekerLedger::parse(
      "## Seeker Inspection\n\n"
      . "Inspector: self-reviewed\n\n"
      . "Inspection performed in-session over the run's full diff (8 modified\n"
      . "files, 4 untracked), not by the `workflow-seeker` subagent: this\n"
      . "session is configured not to dispatch agents unless asked.\n\n"
      . "(no findings)\n",
    );
    $this->assertTrue($ledger->selfReviewed);
  }

  /**
   * The section must say who inspected — an omission is a refusal.
   *
   * The T08 live round: a thorough, silent self-review produced a record
   * identical to an independently-cleared one. The parser can only read
   * what is written, so the declaration is now REQUIRED — an omission
   * becomes a refusal that teaches the format, and a false claim becomes
   * an explicit lie in an auditable record instead of silence.
   */
  public function testAnUndeclaredInspectorIsRefused(): void {
    $this->expectException(SeekerError::class);
    $this->expectExceptionMessageMatches('/does not say who inspected/');
    SeekerLedger::parse("## Seeker Inspection\n\n(no findings)\n");
  }

  /**
   * Claiming independence beside a self-review disclosure is a contradiction.
   */
  public function testIndependenceClaimBesideSelfDisclosureIsRefused(): void {
    $this->expectException(SeekerError::class);
    $this->expectExceptionMessageMatches('/contradiction/');
    SeekerLedger::parse(
      "## Seeker Inspection\n\n"
      . "Inspector: independent\n\n"
      . "Inspection performed in-session; the workflow-seeker agent was not "
      . "dispatched.\n\n"
      . "(no findings)\n",
    );
  }

  /**
   * An unknown inspector value is refused by name.
   */
  public function testAnUnknownInspectorValueIsRefused(): void {
    $this->expectException(SeekerError::class);
    $this->expectExceptionMessageMatches('/Inspector is "my colleague"/');
    SeekerLedger::parse(
      "## Seeker Inspection\n\nInspector: my colleague\n\n(no findings)\n",
    );
  }

}
