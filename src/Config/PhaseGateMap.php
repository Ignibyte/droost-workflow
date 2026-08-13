<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Config;

/**
 * Which gates are due at which phase.
 *
 * Before this existed the runner executed the full resolved set at every
 * phase, so a run's PLAN phase ran phpunit, mutation testing and a browser
 * suite before a line of code existed. The design always said "the test phase
 * reads these levers"; this class is that sentence made executable.
 *
 * The map is engine-owned rather than a lever. The lever file decides WHETHER
 * a gate runs (`on`) and with what thresholds; WHEN it runs is a property of
 * what the phases mean — static analysis gates code that now exists, the
 * functional gates gate the phase whose job is verification, and complete
 * re-runs everything as the terminal safety net. A per-repo remap of that
 * would let a lever file move phpunit to the plan phase, which is not a
 * configuration, it is a contradiction.
 *
 * Complete re-running the full set is also what makes dropped phases safe: a
 * run configured without a test phase still meets every enabled gate once,
 * at the end, rather than never.
 */
final class PhaseGateMap {

  /**
   * The gates due at each phase, keyed by phase name.
   *
   * Every phase name appears, every gate name is drawn from
   * GateSettings::KNOWN_GATES, and complete carries the full vocabulary —
   * all three facts are pinned by tests, and the README renders this table
   * verbatim (ReadmeContractTest holds the two together).
   */
  public const DEFAULT = [
    'plan' => [],
    'code' => [
      'phpcs',
      'phpstan',
    ],
    'test' => [
      'phpunit',
      'mutation',
      'playwright',
      'coverage',
      'rendered_check',
    ],
    'document' => [],
    'complete' => [
      'phpcs',
      'phpstan',
      'phpunit',
      'mutation',
      'playwright',
      'coverage',
      'rendered_check',
    ],
  ];

  /**
   * The gates due at one phase.
   *
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase.
   *
   * @return list<string>
   *   The due gate names, in KNOWN_GATES order.
   */
  public static function gatesFor(Phase $phase): array {
    return self::DEFAULT[$phase->value];
  }

  /**
   * The map filtered to the phases a run actually executes.
   *
   * This is what RunState::begin() freezes into the run document, for the
   * same reason the resolved gate levers are frozen: a run is held to the map
   * it started under, so a future engine changing this class cannot change
   * what a half-finished run is measured against.
   *
   * @param list<string> $phaseNames
   *   The configured phase names, in execution order.
   *
   * @return array<string, list<string>>
   *   Phase name to its due gates, in the given order.
   */
  public static function forPhases(array $phaseNames): array {
    $due = [];
    foreach ($phaseNames as $name) {
      $due[$name] = self::DEFAULT[$name] ?? [];
    }
    return $due;
  }

}
