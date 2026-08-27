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
   *
   * It names the REMEDY, not just the condition. A live eval run advanced its
   * test phase through this surface while the site was up and being driven by
   * a browser, and rendered_check came back "skipped-no-site" — true of the
   * surface, wildly misleading about the site. The agent only recovered
   * because it happened to notice at the next phase; had it used this surface
   * throughout, a configured gate would silently never have run. The reason
   * reaches the CLI's own output, run.json and droost's report, so it is the
   * one place worth spending words on.
   */
  public const REASON = 'no booted site (CLI surface) — a site-backed surface '
    . '(the drush command or the MCP run tool) runs this gate; re-run the '
    . 'phase there to include it';

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
