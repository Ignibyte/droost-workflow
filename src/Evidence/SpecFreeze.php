<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * The spec, as it stood when the plan phase ended.
 *
 * The spec is the contract a run is held to, and until now the agent could edit
 * it after passing the checks that read it. Every later phase re-read the file
 * from disk, so a grounding table that satisfied the plan gate could be
 * rewritten before the code gate looked, and nothing would know.
 *
 * So the plan phase ends by freezing it: the text is copied into the evidence
 * store and fingerprinted, and every contract afterwards verifies the
 * fingerprint before trusting the file. The file stays on disk and stays the
 * working document — the `## Realized` capture and the seeker's inspection
 * ledgers are APPENDED to it by design, and that must keep working. What is
 * frozen is the part the phase gates read.
 *
 * Hence sections rather than the whole file. Hashing the file would make every
 * legitimate append look like tampering; hashing the contract sections catches
 * an edit to the criteria, the tooling plan or the grounding table while
 * leaving the run free to write its own history underneath them.
 */
final class SpecFreeze {

  /**
   * The sections a run is held to, and may not rewrite after the plan.
   *
   * `## Realized` is deliberately absent: complete is REQUIRED to add it, and
   * the seeker appends its ledgers to the same file. Freezing those would
   * freeze the run's ability to record what it did.
   */
  public const array FROZEN_SECTIONS = [
    '## Tooling plan',
    '## Grounding',
    '## Acceptance criteria',
  ];

  /**
   * The fingerprint of a spec's contract sections.
   *
   * @param string $text
   *   The whole spec.
   *
   * @return string
   *   A hex digest. Always returns one, even for a spec carrying none of the
   *   sections — "this spec had no contract" is itself a fact worth pinning,
   *   and an empty digest would make every such spec look identical to a
   *   missing one.
   */
  public static function fingerprint(string $text): string {
    return hash('xxh128', self::contract($text));
  }

  /**
   * Whether a spec still carries the contract it was frozen with.
   *
   * @param string $text
   *   The spec as it stands now.
   * @param string|null $frozen
   *   The fingerprint taken at plan exit, or NULL when nothing was frozen.
   *
   * @return bool
   *   TRUE when the contract is unchanged, or when there is nothing to check
   *   against. A run that predates the freeze is not retroactively in breach.
   */
  public static function intact(string $text, ?string $frozen): bool {
    return $frozen === NULL || $frozen === '' || hash_equals($frozen, self::fingerprint($text));
  }

  /**
   * Which frozen sections differ from the ones recorded at plan exit.
   *
   * Reported per section so the refusal can name what moved rather than saying
   * only that something did.
   *
   * @param string $text
   *   The spec as it stands now.
   * @param string $frozenText
   *   The spec as it was frozen.
   *
   * @return list<string>
   *   The headings whose content changed.
   */
  public static function changedSections(string $text, string $frozenText): array {
    $now = self::sections($text);
    $then = self::sections($frozenText);
    $changed = [];
    foreach (self::FROZEN_SECTIONS as $heading) {
      if (($now[$heading] ?? '') !== ($then[$heading] ?? '')) {
        $changed[] = $heading;
      }
    }

    return $changed;
  }

  /**
   * The contract sections, concatenated in a fixed order.
   *
   * Fixed order because a spec that merely REORDERS its sections has not
   * changed its contract, and a fingerprint that moved because somebody cut and
   * pasted a heading would be a false accusation of tampering.
   *
   * @param string $text
   *   The spec.
   *
   * @return string
   *   The canonical contract text.
   */
  private static function contract(string $text): string {
    $sections = self::sections($text);
    $parts = [];
    foreach (self::FROZEN_SECTIONS as $heading) {
      $parts[] = $heading . "\n" . ($sections[$heading] ?? '');
    }

    return implode("\n\0\n", $parts);
  }

  /**
   * Each frozen section's body, keyed by heading.
   *
   * Whitespace at the ends of lines is normalised away: an editor that strips
   * trailing spaces on save has not changed the contract, and treating that as
   * tampering would train everyone to ignore the alarm.
   *
   * @param string $text
   *   The spec.
   *
   * @return array<string, string>
   *   Heading to body.
   */
  private static function sections(string $text): array {
    $found = [];
    foreach (self::FROZEN_SECTIONS as $heading) {
      $pattern = '/^' . preg_quote($heading, '/') . '\b[^\n]*\n(.*?)(?=^#{1,2} |\z)/msi';
      if (preg_match($pattern, $text, $match) !== 1) {
        continue;
      }
      $lines = preg_split('/\R/', $match[1]) ?: [];
      $body = implode("\n", array_map('rtrim', $lines));
      $found[$heading] = trim($body);
    }

    return $found;
  }

}
