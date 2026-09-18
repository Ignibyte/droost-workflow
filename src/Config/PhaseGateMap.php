<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * Which gates are due at which phase.
 *
 * Before this existed the runner executed the full resolved set at every
 * phase, so a run's PLAN phase ran phpunit, mutation testing and a browser
 * suite before a line of code existed. The design always said "the test phase
 * reads these levers"; this class is that sentence made executable.
 *
 * A gate appearing at more than one phase is not duplication. Each phase
 * writes source, and the gate reads whatever is on disk when it runs, so the
 * second run of phpcs measures the files the second phase produced. The
 * question "did this phase's own output meet the standard" has no other
 * mechanical answer.
 *
 * The map is engine-owned rather than a lever. The lever file decides WHETHER
 * a gate runs (`on`) and with what thresholds; WHEN it runs is a property of
 * what the phases mean — static analysis gates source that now exists, in
 * both phases that write source, the functional gates gate the phase whose
 * job is verification, and complete re-runs everything as the terminal safety
 * net. A per-repo remap of that
 * would let a lever file move phpunit to the plan phase, which is not a
 * configuration, it is a contradiction.
 *
 * Complete re-running the full set is also what makes dropped phases safe: a
 * run configured without a test phase still meets every enabled gate once,
 * at the end, rather than never.
 *
 * wiki_fresh runs at complete only. Since 0.4 the documentation work IS the
 * first half of complete — the wiki is built there and then the freshness
 * check has something true to verify; checking it any earlier would gate a
 * phase on documentation that phase had not yet produced.
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
      'eslint',
      'stylelint',
      'prettier',
      'config_clean',
      // The code phase is where grounding citations are first resolvable:
      // plan WROTE the rows, code has just put the thing it grounded against
      // on disk. Resolving here means a citation to a symbol that does not
      // exist fails while the phase that invented it is still open.
      'grounding_check',
    ],
    'test' => [
      // The test phase writes source, so the gates that read source are due
      // again — over the tests it just wrote. P2-KCH-2 is the worked example:
      // a run passed phpcs at code, then wrote a test method in lowerCamel
      // that phpcs forbids, and nothing looked at it again until complete,
      // where a failure costs the most to act on. A test held to a lower
      // standard than the code it tests is not a test the next run can read.
      //
      // phpcs and phpstan precede phpunit in KNOWN_GATES order, which is the
      // order gates execute: the suite's shape is checked before its result
      // is believed. eslint and prettier are here for the same reason, for
      // the browser specs. stylelint is not — the phase writes no
      // stylesheets, and a gate that can only ever report nothing-to-analyse
      // is noise in every report that carries it.
      'phpcs',
      'phpstan',
      'eslint',
      'prettier',
      'phpunit',
      'mutation',
      'playwright',
      'coverage',
      'rendered_check',
      'config_clean',
    ],
    'complete' => [
      'phpcs',
      'phpstan',
      'eslint',
      'stylelint',
      'prettier',
      'phpunit',
      'mutation',
      'playwright',
      'coverage',
      'rendered_check',
      'config_clean',
      'grounding_check',
      'wiki_fresh',
    ],
  ];

  /**
   * The gates due at one phase.
   *
   * @param \Droost\Workflow\Config\Phase $phase
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
