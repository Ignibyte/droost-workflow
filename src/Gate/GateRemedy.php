<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * What to do about a gate that could not run, or ran over nothing.
 *
 * One home, because the last three rounds of review kept finding the same
 * shape of defect underneath the surface ones: a rule implemented in more than
 * one place drifts, and a remedy is a rule about what an operator should type.
 * Each sentence here has more than one caller.
 *
 * THE STANDARD THESE HAVE TO MEET is that what they tell somebody to type
 * works. `measuredNothing()` exists because the old text did not: it named
 * `gates.<gate>.paths` for whichever gate measured nothing, and four of the
 * gates `WorkType::mustMeasure()` can name have no `paths` lever at all.
 * Writing that key does not fail quietly — `WorkflowConfig` refuses the whole
 * file, so following the remedy ends with every droost-workflow command
 * printing `gate "phpunit" has no option "paths"` and nothing running.
 */
final class GateRemedy {

  /**
   * The gates that actually take a `paths` lever.
   *
   * Asserted against `GateSettings::optionNames()` by the test, so this cannot
   * drift away from what the config reader accepts.
   */
  private const HAS_PATHS = ['phpcs', 'phpstan', 'eslint', 'stylelint', 'prettier'];

  /**
   * What each of those tools calls its own configuration.
   *
   * Named because "give the tool its own config file" is not actionable
   * advice to somebody who has not used the tool before, and this remedy is
   * printed for an OPERATOR, who may not be the person who chose it.
   */
  private const CONFIG_FILE = [
    'phpcs' => 'phpcs.xml',
    'phpstan' => 'phpstan.neon',
    'eslint' => 'eslint.config.js',
    'stylelint' => '.stylelintrc.json',
    'prettier' => '.prettierrc',
  ];

  /**
   * What makes a gate that examined nothing examine something.
   *
   * TWO THINGS WERE WRONG WITH THE ONE SENTENCE THIS REPLACES, and both were
   * wrong in the direction that costs an operator a run.
   *
   * It named `gates.<gate>.paths` whatever the gate was. Only phpcs, phpstan,
   * eslint, stylelint and prettier have that lever; `WorkType::mustMeasure()`
   * also names phpunit, which does not. Writing
   * the key anyway does not fail quietly — `WorkflowConfig::load()` refuses
   * the whole file, so an operator who does as they are told ends up with
   * every droost-workflow command printing `gate "phpunit" has no option
   * "paths"` and a project that cannot run its own workflow.
   *
   * And it closed with "Levers are frozen per run, so an operator editing them
   * now is editing the next run — ask them to clear this one with `reset
   * --force` after." That is false about the exact lever it had just named:
   * `on` is the frozen half, and `GateRunner::liveTuning()` re-reads the
   * TUNING options — `paths` among them — from droost.workflow.yml at gate
   * time, announcing the change in the gate's own summary. So the remedy told
   * an operator to throw away a run that one edit and one re-run would have
   * finished.
   *
   * @param string $gate
   *   The gate that measured nothing.
   *
   * @return string
   *   What to do about it.
   */
  public static function measuredNothing(string $gate): string {
    $specific = match ($gate) {
      'phpunit' => 'phpunit has no `paths` lever — what it runs is the '
        . '`<testsuites>` in phpunit.xml. It discovered no tests, so either '
        . 'the suite names a directory that holds none (`vendor/bin/phpunit '
        . '--list-tests` says what it can see), or there are no tests yet — '
        . 'and writing them is the work, not a setting.',
      default => sprintf(
        'Point %1$s at the code: set `gates.%1$s.paths` in droost/droost.yml, '
        . 'or give %1$s its own %2$s — a tool\'s own config names its own '
        . 'paths and outranks the lever. Then run the phase again.',
        $gate,
        self::CONFIG_FILE[$gate] ?? 'config file',
      ),
    };

    if (!in_array($gate, self::HAS_PATHS, TRUE)) {
      return $specific . ' Do not add a `paths` key to this gate: the config '
        . 'reader rejects options a gate does not have, and it rejects the '
        . 'whole file when it finds one — every droost-workflow command then '
        . 'stops working until the key is removed.';
    }

    return $specific . ' Or, with `scope: ticket`, the run may simply have '
      . 'changed none of its files since the base commit: work committed '
      . 'before the run opened (a closed PR re-run) is read once its files are '
      . 'declared by name with `declare-changes --files=…`, and the paths lever '
      . 'needs no change. This takes effect NOW, not next run: `on` is frozen '
      . 'at begin, but the tuning levers are re-read from the file at gate '
      . 'time and the gate\'s summary says so when they have moved. No `reset '
      . '--force` is needed — and if nothing changes, check the file still '
      . 'parses, because an unreadable droost/droost.yml leaves the frozen '
      . 'levers in force without comment.';
  }

}
