<?php

declare(strict_types=1);

namespace Droost\Workflow\Seeker;

/**
 * One seeker inspection's parsed ledger.
 *
 * The seeker is the adversarial reviewer dispatched between code and test:
 * gates verify rules, the seeker verifies judgment — the defects a green gate
 * hides. Its verdict arrives as a markdown section written into the spec, and
 * THIS parser is what the checkpoint trusts: the recorded counts come from
 * parsing the ledger text, never from the agent's summary of it, because a
 * checkpoint that believes a self-report is a checkpoint in name only.
 *
 * The format is exact on purpose (the invoking skill copies it verbatim):
 *
 *   ## Seeker Inspection
 *
 *   | ID | Severity | Location | Finding | Status |
 *   |----|----------|----------|---------|--------|
 *   | F1 | CRITICAL | path:42  | ...     | open   |
 *
 * or, when the six lenses found nothing in scope, the literal sentinel
 * `(no findings)` — required even when observations follow, because a heading
 * with neither rows nor sentinel is an INCOMPLETE inspection and blocks.
 *
 * Severity protocol: an open CRITICAL or open MEDIUM blocks; a resolved or
 * carried row does not (carrying a MEDIUM forward with a reason is a recorded
 * owner decision); LOW never blocks. Out-of-scope observations are advisory
 * bullets under their own subheading — counted, never blocking.
 */
final class SeekerLedger {

  /**
   * The heading a ledger section must open with.
   */
  public const HEADING = '## Seeker Inspection';

  /**
   * The literal a clean inspection must state.
   */
  public const SENTINEL = '(no findings)';

  /**
   * The severities a finding row may carry.
   */
  private const SEVERITIES = ['CRITICAL', 'MEDIUM', 'LOW'];

  /**
   * Constructs a SeekerLedger.
   *
   * @param bool $sentinel
   *   Whether the section states the no-findings sentinel.
   * @param list<array{id: string, severity: string, location: string, finding: string, status: string}> $findings
   *   The finding rows, in ledger order.
   * @param int $observations
   *   How many out-of-scope observation bullets the section carries.
   */
  private function __construct(
    public readonly bool $sentinel,
    public readonly array $findings,
    public readonly int $observations,
  ) {}

  /**
   * Parses a ledger out of the text that carries it.
   *
   * @param string $text
   *   The text — typically the spec file's content, or the section alone.
   *
   * @return self
   *   The parsed ledger.
   *
   * @throws \Droost\Workflow\Seeker\SeekerError
   *   When no section exists, or the section is incomplete or contradictory.
   */
  public static function parse(string $text): self {
    $lines = preg_split('/\R/', $text) ?: [];

    $start = NULL;
    foreach ($lines as $i => $line) {
      if (str_starts_with(trim($line), self::HEADING)) {
        $start = $i + 1;
      }
    }
    if ($start === NULL) {
      throw SeekerError::malformed('no "## Seeker Inspection" section found');
    }

    // The LAST section wins (found above by not breaking): a re-inspection
    // after fixes appends a fresh section, and the checkpoint must read the
    // newest verdict, not the first.
    $sentinel = FALSE;
    $findings = [];
    $observations = 0;
    $inObservations = FALSE;
    for ($i = $start, $n = count($lines); $i < $n; $i++) {
      $line = trim($lines[$i]);
      if (str_starts_with($line, '## ') && !str_starts_with($line, '### ')) {
        break;
      }
      if (str_starts_with($line, '###')) {
        $inObservations = str_contains(strtolower($line), 'observation');
        continue;
      }
      if ($inObservations) {
        if (str_starts_with($line, '- ')) {
          $observations++;
        }
        continue;
      }
      if ($line === self::SENTINEL) {
        $sentinel = TRUE;
        continue;
      }
      if (str_starts_with($line, '|')) {
        $row = self::row($line);
        if ($row !== NULL) {
          $findings[] = $row;
        }
      }
    }

    if ($sentinel && $findings !== []) {
      throw SeekerError::malformed(
        'the section states "(no findings)" AND carries finding rows — '
        . 'a contradiction the checkpoint refuses to pick a side of',
      );
    }
    if (!$sentinel && $findings === []) {
      throw SeekerError::malformed(
        'the section carries neither finding rows nor the sentinel — '
        . 'an incomplete inspection',
      );
    }

    return new self($sentinel, $findings, $observations);
  }

  /**
   * The findings that block the run.
   *
   * @return list<array{id: string, severity: string, location: string, finding: string, status: string}>
   *   Open CRITICAL and open MEDIUM rows.
   */
  public function blocking(): array {
    return array_values(array_filter(
      $this->findings,
      static fn (array $f): bool =>
        strtolower($f['status']) === 'open' && $f['severity'] !== 'LOW',
    ));
  }

  /**
   * Whether this inspection lets the run advance.
   *
   * @return bool
   *   TRUE for the sentinel, or for findings all resolved/carried/LOW.
   */
  public function isClean(): bool {
    return $this->sentinel || $this->blocking() === [];
  }

  /**
   * This ledger as the record run state freezes.
   *
   * @param string $reportedAt
   *   When, as a caller-supplied ISO-8601 string.
   *
   * @return array{status: string, critical: int, medium: int, low: int, observations: int, reported_at: string}
   *   The record.
   */
  public function toRecord(string $reportedAt): array {
    $counts = ['CRITICAL' => 0, 'MEDIUM' => 0, 'LOW' => 0];
    foreach ($this->findings as $finding) {
      $counts[$finding['severity']]++;
    }
    return [
      'status' => $this->isClean() ? 'clean' : 'findings',
      'critical' => $counts['CRITICAL'],
      'medium' => $counts['MEDIUM'],
      'low' => $counts['LOW'],
      'observations' => $this->observations,
      'reported_at' => $reportedAt,
    ];
  }

  /**
   * One table line as a finding row, or NULL for header and separator lines.
   *
   * @param string $line
   *   The trimmed line, starting with "|".
   *
   * @return array{id: string, severity: string, location: string, finding: string, status: string}|null
   *   The row.
   *
   * @throws \Droost\Workflow\Seeker\SeekerError
   *   When a data row carries a severity outside the protocol.
   */
  private static function row(string $line): ?array {
    $cells = array_map(trim(...), explode('|', trim($line, '|')));
    if (count($cells) !== 5) {
      return NULL;
    }
    [$id, $severity, $location, $finding, $status] = $cells;
    if (strcasecmp($id, 'ID') === 0) {
      return NULL;
    }
    if (preg_match('/^[-: ]+$/', $id) === 1) {
      return NULL;
    }
    $severity = strtoupper($severity);
    if (!in_array($severity, self::SEVERITIES, TRUE)) {
      throw SeekerError::malformed(sprintf(
        'row "%s" carries severity "%s" (the protocol: %s)',
        $id,
        $severity,
        implode(', ', self::SEVERITIES),
      ));
    }
    if ($status === '') {
      throw SeekerError::malformed(sprintf(
        'row "%s" has an empty status — open, resolved, or carried: <reason>',
        $id,
      ));
    }
    return [
      'id' => $id,
      'severity' => $severity,
      'location' => $location,
      'finding' => $finding,
      'status' => $status,
    ];
  }

}
