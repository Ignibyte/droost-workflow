<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * The spec's contract, as it stood when the plan phase ended.
 *
 * The spec is what a run is held to, and the agent writes it. That is the point
 * of the plan phase — and afterwards nothing stopped the agent editing it. A
 * grounding citation that satisfied the plan gate could be swapped for an
 * easier one before the code gate resolved it; a criterion naming a test could
 * become prose before complete counted it. Every phase re-read the file from
 * disk, so none of that left a trace.
 *
 * So plan exit records the spec, and later phases check it. What they check is
 * a PROJECTION, not the file, and not even the whole of each section — because
 * the workflow itself requires later phases to write here, and a freeze that
 * could not tell a mandated write from a cheat would deadlock every run:
 *
 *   * the code phase MUST add grounding rows tagged `code`, or
 *     `groundingMissing` refuses it;
 *   * the test phase MUST fill the `Verified By` column, or
 *     `criteriaUnverified` refuses complete;
 *   * complete MUST append `## Realized`, or `hasRealizedCapture` refuses it.
 *
 * A whole-section hash makes each of those simultaneously required and
 * forbidden. Both were reproduced end to end against a first cut of this class
 * that froze grounding and the criteria whole: plan advanced, and then there
 * was no legal move. So each section is frozen the way its own contract allows:
 *
 *   | Section              | Rule                                            |
 *   |----------------------|-------------------------------------------------|
 *   | `## Tooling plan`    | whole — no phase is told to edit it, and the     |
 *   |                      | seeker grades the diff against it, so it has to  |
 *   |                      | be the fixed reference                           |
 *   | `## Grounding`       | append-only — every row present at plan must     |
 *   |                      | still be there, unchanged; code may add its own  |
 *   | `## Acceptance…`     | append-only, and each row compared WITHOUT its   |
 *   |                      | `Verified By` cell, which test fills by mandate  |
 *
 * `## Realized` is absent entirely: it does not exist at plan and complete is
 * required to write it.
 *
 * What survives is the cheat each rule was aimed at — a criterion rewritten
 * into whatever was actually built, a citation swapped for one that resolves,
 * a planned tool quietly changed to hand-written — while every write the
 * workflow itself demands stays legal.
 */
final class SpecFreeze {

  /**
   * The tooling plan: frozen whole.
   */
  public const string TOOLING = '## Tooling plan';

  /**
   * Grounding: append-only, because the code phase must add rows.
   */
  public const string GROUNDING = '## Grounding';

  /**
   * Acceptance criteria: append-only, minus the column the test phase fills.
   */
  public const string CRITERIA = '## Acceptance criteria';

  /**
   * The column the test phase is required to fill, excluded from every row.
   */
  private const string VERIFIED_COLUMN = 'verified by';

  /**
   * Every section this class has an opinion about.
   */
  public const array FROZEN_SECTIONS = [self::TOOLING, self::GROUNDING, self::CRITERIA];

  /**
   * A fingerprint of the parts that may never change at all.
   *
   * Only the tooling plan contributes: the other two are append-only, and a
   * hash of something that may legitimately grow answers no question. Stored so
   * a reader has a short handle for the frozen contract, and so a run whose
   * recorded text is somehow lost can still detect the flat case.
   *
   * @param string $text
   *   The whole spec.
   *
   * @return string
   *   A hex digest, always — "this spec had no tooling plan" is a fact worth
   *   pinning, and an empty digest would make it look like a spec nobody froze.
   */
  public static function fingerprint(string $text): string {
    return hash('xxh128', self::TOOLING . "\n" . self::body($text, self::TOOLING));
  }

  /**
   * The frozen sections a spec has broken since the plan recorded it.
   *
   * This is the authoritative check. It needs the recorded TEXT rather than a
   * digest, because "every row that was there is still there, unchanged" is not
   * a question a hash can answer.
   *
   * @param string $text
   *   The spec as it stands now.
   * @param string $frozenText
   *   The spec as it was when the plan phase ended.
   *
   * @return list<string>
   *   The headings that broke their rule, each with what went missing appended.
   */
  public static function breaches(string $text, string $frozenText): array {
    $breaches = [];
    if (self::body($text, self::TOOLING) !== self::body($frozenText, self::TOOLING)) {
      $breaches[] = self::TOOLING;
    }
    foreach ([self::GROUNDING, self::CRITERIA] as $heading) {
      $lost = self::lostRows($text, $frozenText, $heading);
      if ($lost !== []) {
        $breaches[] = sprintf('%s (%d row(s) removed or rewritten)', $heading, count($lost));
      }
    }

    return $breaches;
  }

  /**
   * Whether a spec still carries the contract the plan recorded.
   *
   * @param string $text
   *   The spec as it stands now.
   * @param string|null $frozenText
   *   The spec as it was frozen, or NULL when nothing was recorded.
   *
   * @return bool
   *   TRUE when intact, and when there is nothing to check against: a run that
   *   predates the freeze is not retroactively in breach.
   */
  public static function intact(string $text, ?string $frozenText): bool {
    return $frozenText === NULL || $frozenText === '' || self::breaches($text, $frozenText) === [];
  }

  /**
   * Rows the frozen spec had that this one no longer carries as they were.
   *
   * Set difference, not position: a row that moved has not changed, and
   * refusing over a reordered table would be a false accusation. A row compared
   * here has been canonicalised — whitespace collapsed, and for the criteria
   * the `Verified By` cell dropped — so re-padding a table is not drift either.
   *
   * @param string $text
   *   The spec now.
   * @param string $frozenText
   *   The spec as frozen.
   * @param string $heading
   *   The section.
   *
   * @return list<string>
   *   The rows that went missing.
   */
  private static function lostRows(string $text, string $frozenText, string $heading): array {
    $now = self::rows($text, $heading);
    $then = self::rows($frozenText, $heading);

    return array_values(array_diff($then, $now));
  }

  /**
   * A section's table rows, canonicalised for comparison.
   *
   * @param string $text
   *   The spec.
   * @param string $heading
   *   The section.
   *
   * @return list<string>
   *   One canonical string per data row.
   */
  private static function rows(string $text, string $heading): array {
    $body = self::body($text, $heading);
    if ($body === '') {
      return [];
    }
    $lines = preg_split('/\R/', $body) ?: [];
    $verified = NULL;
    $rows = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] !== '|') {
        continue;
      }
      $cells = self::cells($line);
      if ($cells === [] || preg_match('/^[\s\-:|]+$/', $line) === 1) {
        continue;
      }
      if ($verified === NULL && $heading === self::CRITERIA) {
        $header = array_map(static fn (string $c): string => strtolower(trim($c)), $cells);
        $found = array_search(self::VERIFIED_COLUMN, $header, TRUE);
        if ($found !== FALSE) {
          // The header row itself is not a promise; it names the columns.
          $verified = (int) $found;
          continue;
        }
      }
      if ($verified !== NULL) {
        unset($cells[$verified]);
      }
      $rows[] = implode('|', array_map(
        static fn (string $cell): string => (string) preg_replace('/\s+/', ' ', trim($cell)),
        $cells,
      ));
    }

    return $rows;
  }

  /**
   * One table row's cells.
   *
   * @param string $row
   *   The row.
   *
   * @return list<string>
   *   The cells.
   */
  private static function cells(string $row): array {
    $row = trim($row);
    $row = preg_replace('/^\|/', '', $row) ?? $row;
    $row = preg_replace('/\|$/', '', $row) ?? $row;

    return array_map('trim', explode('|', $row));
  }

  /**
   * One section's body, with trailing whitespace normalised away.
   *
   * An editor that trims on save has not changed the contract, and treating
   * that as tampering would train everyone to ignore the alarm.
   *
   * @param string $text
   *   The spec.
   * @param string $heading
   *   The section.
   *
   * @return string
   *   The body, or '' when the section is absent.
   */
  private static function body(string $text, string $heading): string {
    $pattern = '/^' . preg_quote($heading, '/') . '\b[^\n]*\n(.*?)(?=^#{1,2} |\z)/msi';
    if (preg_match($pattern, $text, $match) !== 1) {
      return '';
    }
    $lines = preg_split('/\R/', $match[1]) ?: [];

    return trim(implode("\n", array_map('rtrim', $lines)));
  }

}
