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
   * Columns a LATER gate adjudicates, excluded from the frozen comparison.
   *
   * Both are the same situation and the same reasoning. A column that no
   * plan-phase gate reads is not part of the promise the plan made — and a
   * column a later gate judges is one a later phase may legitimately have to
   * correct, because the gate's own refusal tells it to.
   *
   * `Verified By` the test phase is REQUIRED to fill. `Evidence` is resolved by
   * grounding_check at code, and its refusal reads "Cite a class this site
   * actually has ... or `none: <query>`" — an instruction to edit the cell. The
   * plan gate checks only that answers are non-blank and all three tiers
   * appear; it never looks at a citation. So freezing that cell protected
   * nothing anybody had checked, and forbade the one remedy the failure named.
   *
   * What stays frozen in a grounding row is the claim: which tier was searched,
   * what was asked, and what came back. Swapping THOSE after the fact is the
   * cheat. The citation is re-resolved against the site on every later phase,
   * so it is guarded by adjudication rather than by immutability — which is
   * the stronger of the two anyway.
   */
  private const array ADJUDICATED_COLUMNS = [
    self::CRITERIA => 'verified by',
    self::GROUNDING => 'evidence',
  ];

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
    $lines = array_values(array_filter(
      array_map('trim', preg_split('/\R/', $body) ?: []),
      static fn (string $line): bool => $line !== '' && $line[0] === '|',
    ));
    // The header is the row BEFORE the separator, whatever cells it holds.
    //
    // It used to be recognised by containing a `verified by` cell, which made
    // the whole comparison depend on a column that may not exist yet. A plan
    // whose criteria table had no `Verified By` was refused at complete for
    // lacking it — and adding it, which the engine demands, was then refused as
    // tampering, because the header stopped being read as a data row and the
    // "row" it had been counted as vanished. Two mutually exclusive refusals,
    // and no legal move between them.
    $separator = NULL;
    foreach ($lines as $index => $line) {
      if (preg_match('/^[\s\-:|]+$/', $line) === 1) {
        $separator = $index;
        break;
      }
    }
    $verified = NULL;
    $adjudicated = self::ADJUDICATED_COLUMNS[$heading] ?? NULL;
    if ($separator !== NULL && $separator > 0 && $adjudicated !== NULL) {
      $header = array_map(
        static fn (string $cell): string => strtolower(trim($cell)),
        self::cells($lines[$separator - 1]),
      );
      $found = array_search($adjudicated, $header, TRUE);
      $verified = $found === FALSE ? NULL : (int) $found;
    }
    $rows = [];
    foreach ($lines as $index => $line) {
      // Skip the separator and the header that precedes it: neither is a
      // promise, and which columns a table names is not part of the contract.
      if ($index === $separator || ($separator !== NULL && $index === $separator - 1)) {
        continue;
      }
      if (preg_match('/^[\s\-:|]+$/', $line) === 1) {
        continue;
      }
      $cells = self::cells($line);
      if ($cells === []) {
        continue;
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
