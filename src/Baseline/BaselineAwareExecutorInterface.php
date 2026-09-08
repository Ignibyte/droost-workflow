<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;

/**
 * An executor that can judge a gate against a baseline.
 *
 * A companion to GateExecutorInterface rather than a change to it, so every
 * executor that only knows the whole-tree verdict — the test fakes included —
 * keeps working untouched. The runner hands the context to an executor that
 * declares it can use one, and calls the plain method otherwise.
 */
interface BaselineAwareExecutorInterface extends GateExecutorInterface {

  /**
   * Runs a gate, partitioning its findings into inherited and new.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $projectRoot
   *   The repository to run in.
   * @param \Droost\Workflow\Baseline\BaselineContext $context
   *   The baseline the run is held to, and what the run changed.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict, failing only on NEW findings, with both counts recorded.
   */
  public function executeWithBaseline(GateSettings $gate, string $projectRoot, BaselineContext $context): GateResult;

}
