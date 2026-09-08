<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\SiteDriverInterface;

/**
 * A site driver that can judge a site gate against a baseline.
 *
 * The site-gate counterpart of BaselineAwareExecutorInterface: config_clean
 * partitions drift into what the snapshot recorded at adoption and what is
 * new. Same companion shape, same reason — every existing driver keeps
 * working untouched.
 */
interface BaselineAwareSiteDriverInterface extends SiteDriverInterface {

  /**
   * Runs a site gate, partitioning its findings into inherited and new.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Baseline\BaselineContext $context
   *   The baseline the run is held to.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict, failing only on NEW findings, with both counts recorded.
   */
  public function runWithBaseline(GateSettings $gate, string $projectRoot, BaselineContext $context): GateResult;

}
