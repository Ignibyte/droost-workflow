<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

/**
 * The four things that can happen when a phase is worked.
 */
enum Outcome: string {

  // The phase passed its gates and the run moved on.
  case Advanced = 'advanced';

  // Pair mode: the run is waiting for an answer before it will continue.
  case Paused = 'paused';

  // A gate blocked, and the retry budget is spent or the failure is terminal.
  case Failed = 'failed';

  // The terminal phase passed. There is nothing after this.
  case Completed = 'completed';

}
