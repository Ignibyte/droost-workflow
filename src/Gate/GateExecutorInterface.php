<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Config\GateSettings;

/**
 * Runs the gates that need only a checkout.
 *
 * One method, so a test can substitute a fake and drive the runner's logic
 * without a toolchain — and so the real implementation has exactly one place
 * where a subprocess is spawned.
 */
interface GateExecutorInterface {

  /**
   * Runs one gate.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $projectRoot
   *   The repository to run in.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   What happened. Implementations report a missing binary as
   *   ErrorToolMissing rather than throwing: a gate that cannot run is a
   *   result, not an exception.
   */
  public function execute(GateSettings $gate, string $projectRoot): GateResult;

}
