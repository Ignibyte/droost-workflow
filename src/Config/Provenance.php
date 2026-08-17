<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * Where a resolved configuration came from.
 *
 * Reported rather than inferred: a run driven by built-in defaults and a run
 * driven by a committed lever file are different situations, and a report that
 * cannot tell them apart invites the reader to assume the wrong one.
 */
enum Provenance: string {

  // Read from a droost.workflow.yml at the project root.
  case File = 'file';

  // No lever file exists; the built-in factory defaults are in force.
  case BuiltIn = 'built-in';

}
