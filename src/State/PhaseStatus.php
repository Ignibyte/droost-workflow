<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

/**
 * Where one phase of a run stands.
 *
 * Only phases the run actually configured carry a status. A phase dropped
 * from the pipeline is absent from the record rather than marked skipped: it
 * was never part of this run's plan, and saying "skipped" would imply
 * something was passed over.
 */
enum PhaseStatus: string {

  // Configured, not yet reached.
  case Pending = 'pending';

  // Currently executing.
  case Active = 'active';

  // Its exit gate was satisfied.
  case Passed = 'passed';

  // Its exit gate failed and the run stopped here.
  case Failed = 'failed';

  // Reached, but nothing to do.
  case Skipped = 'skipped';

}
