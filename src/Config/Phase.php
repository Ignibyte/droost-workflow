<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Config;

/**
 * The five phases of a workflow run.
 *
 * Solutions and design are folded into plan; there is no ticket phase. A run
 * may drop code, test or document, but plan and complete are endpoints: a run
 * that never planned has nothing to build against, and one that never
 * completed never presented its result.
 */
enum Phase: string {

  // Ground in the site, understand the request, produce the spec.
  case Plan = 'plan';

  // Implement, via droost and the Drupal APIs.
  case Code = 'code';

  // Run the configured gates. Artifacts are truth.
  case Test = 'test';

  // Capture what was built.
  case Document = 'document';

  // Present the diff and the gate report. Terminal.
  case Complete = 'complete';

  /**
   * The phases that cannot be dropped from a run.
   */
  public const REQUIRED = [self::Plan, self::Complete];

  /**
   * Every phase, in the only order a run may execute them.
   *
   * @return list<self>
   *   The canonical sequence.
   */
  public static function canonical(): array {
    return [
      self::Plan,
      self::Code,
      self::Test,
      self::Document,
      self::Complete,
    ];
  }

  /**
   * The phase names a config file may use.
   *
   * @return list<string>
   *   The backing values, in canonical order.
   */
  public static function names(): array {
    return array_map(
      static fn (self $p): string => $p->value,
      self::canonical(),
    );
  }

  /**
   * Whether a sequence is a subsequence of the canonical order.
   *
   * Phases may be dropped but never reordered: a run that tests before it
   * codes is not a fast run, it is a wrong one.
   *
   * @param list<self> $phases
   *   The candidate sequence.
   *
   * @return bool
   *   TRUE when every phase appears in canonical relative order.
   */
  public static function isSubsequence(array $phases): bool {
    $canonical = self::canonical();
    $at = 0;
    foreach ($phases as $phase) {
      $found = FALSE;
      while ($at < count($canonical)) {
        $candidate = $canonical[$at];
        $at++;
        if ($candidate === $phase) {
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        return FALSE;
      }
    }
    return TRUE;
  }

}
