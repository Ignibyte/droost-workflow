<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Config\GateSettings;

/**
 * The driver for a run with no booted site.
 *
 * Every site gate becomes a reported skip carrying its reason. This is the
 * CLI surface's entire relationship with site-dependent checks, and it is
 * deliberately a real object rather than a null check scattered through the
 * runner: the reason a gate did not run belongs in the report, and something
 * has to be responsible for putting it there.
 */
final class NullSiteDriver implements SiteDriverInterface {

  /**
   * The reason recorded against every gate this driver cannot run.
   */
  public const REASON = 'no booted site (CLI surface)';

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(): array {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function run(GateSettings $gate, string $projectRoot): GateResult {
    return GateResult::skippedNoSite($gate->name, self::REASON);
  }

}
