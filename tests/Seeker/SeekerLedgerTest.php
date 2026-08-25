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
      "## Seeker Inspection\n\n(no findings)\n",
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

      | ID | Severity | Location | Finding | Status |
      |----|----------|----------|---------|--------|
      | F1 | CRITICAL | a.php:1  | bad     | open   |

      ## Seeker Inspection

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
        . "| ID | Severity | Location | Finding | Status |\n"
        . "|----|----|----|----|----|\n"
        . "| F1 | BLOCKER | a:1 | x | open |\n",
        'severity "BLOCKER"',
      ],
      'empty status' => [
        "## Seeker Inspection\n\n"
        . "| ID | Severity | Location | Finding | Status |\n"
        . "|----|----|----|----|----|\n"
        . "| F1 | LOW | a:1 | x |  |\n",
        'empty status',
      ],
    ];
  }

}
