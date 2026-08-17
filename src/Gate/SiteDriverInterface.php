<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Config\GateSettings;

/**
 * Runs the gates that need a booted Drupal site.
 *
 * The seam between the two execution surfaces. The CLI gets NullSiteDriver
 * and every site gate becomes a reported skip; the live surface gets a driver
 * that can actually render a page. Neither surface decides what a skip
 * means — that is the runner's job, identically for both.
 */
interface SiteDriverInterface {

  /**
   * Whether a booted site is available.
   *
   * @return bool
   *   TRUE when site gates can run.
   */
  public function available(): bool;

  /**
   * The gates this driver knows how to run.
   *
   * A gate that is site-dependent but absent from this list is one the driver
   * cannot run even with a site — reported as tool-missing rather than
   * silently ignored.
   *
   * @return list<string>
   *   Gate names.
   */
  public function supports(): array;

  /**
   * Runs one site-dependent gate.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $projectRoot
   *   The repository the run belongs to.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   What happened.
   */
  public function run(GateSettings $gate, string $projectRoot): GateResult;

}
