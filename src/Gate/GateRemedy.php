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
   * A gate reached a site driver that cannot run it.
   *
   * There is nothing to install here, which is exactly what the default
   * "install %s" remedy would have told the reader to go and do.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return string
   *   The remedy.
   */
  public static function wrongDriver(string $gate): string {
    return sprintf(
      'The %1$s gate is on, and this site has no driver that implements it — '
      . 'a configuration gap rather than a missing package, so there is '
      . 'nothing for `composer require` to fetch. Either the module that '
      . 'provides %1$s is not enabled on this site, or the gate is switched '
      . 'on in droost.workflow.yml for a project whose driver cannot run it. '
      . 'Both are the OPERATOR\'s call: enable the provider, or set '
      . '`gates.%1$s.on: false`.',
      $gate,
    );
  }

  /**
   * The drush binary a rendered check needs is not there, or will not run.
   *
   * @param string $relative
   *   Where the driver looked, relative to the project root.
   *
   * @return string
   *   The remedy.
   */
  public static function drush(string $relative): string {
    return sprintf(
      'rendered_check asks a Drupal site to render its own routes, so it '
      . 'needs a drush it can execute. It looked for `%s` under the project '
      . 'root and could not run it. Ask the OPERATOR to install drush '
      . '(`composer require --dev drush/drush`), or — if drush lives '
      . 'elsewhere here — to point the gate at it; `ls -l %s` says which of '
      . 'the two it is. If this project has no site to render, '
      . '`gates.rendered_check.on: false` in droost.workflow.yml.',
      $relative,
      $relative,
    );
  }

  /**
   * What makes a gate that examined nothing examine something.
   *
   * TWO THINGS WERE WRONG WITH THE ONE SENTENCE THIS REPLACES, and both were
   * wrong in the direction that costs an operator a run.
   *
   * It named `gates.<gate>.paths` whatever the gate was. Only phpcs, phpstan,
   * eslint, stylelint and prettier have that lever; `WorkType::mustMeasure()`
   * also names phpunit, config_clean and rendered_check, which do not. Writing
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
      'config_clean' => 'config_clean has no levers at all: it asks a Drupal '
        . 'site whether its exported configuration still matches the database. '
        . 'It measured nothing because there was no site to ask. Run the phase '
        . 'where the site is, or — if this project exports no configuration — '
        . 'ask the OPERATOR for `gates.config_clean.on: false`.',
      'rendered_check' => 'rendered_check has no `paths` lever; it renders the '
        . 'routes `gates.rendered_check.routes` names, and it needs a site to '
        . 'render them on. Name the routes in droost.workflow.yml and run the '
        . 'phase where the site is.',
      default => sprintf(
        'Point %1$s at the code: set `gates.%1$s.paths` in droost.workflow.yml, '
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

    return $specific . ' This takes effect NOW, not next run: `on` is frozen '
      . 'at begin, but the tuning levers are re-read from the file at gate '
      . 'time and the gate\'s summary says so when they have moved. No `reset '
      . '--force` is needed — and if nothing changes, check the file still '
      . 'parses, because an unreadable droost.workflow.yml leaves the frozen '
      . 'levers in force without comment.';
  }

}
