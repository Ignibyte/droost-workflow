<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * What to do about a site gate that could not run.
 *
 * Two sentences, each needed by more than one caller — `GateRunner` when no
 * driver claims the gate, both site drivers when one is handed a gate it does
 * not implement, and the fresh-process driver for its drush binary. Written
 * once because the last three rounds of review kept finding the same shape of
 * defect underneath the surface ones: a rule implemented in more than one
 * place drifts, and a remedy is a rule about what an operator should type.
 */
final class SiteGateRemedy {

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

}
