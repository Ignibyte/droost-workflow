<?php

declare(strict_types=1);

namespace Droost\Workflow\Event;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\State\RunState;

/**
 * A listener that does nothing, correctly.
 *
 * The default on every surface with nowhere to send lifecycle events, and the
 * base implementers extend so that an event added to
 * {@see WorkflowListenerInterface} later never breaks them. Mirrors
 * {@see \Droost\Workflow\Mode\RunStateOnlySink}.
 *
 * Not final: it exists to be extended.
 */
class NullWorkflowListener implements WorkflowListenerInterface {

  /**
   * {@inheritdoc}
   */
  public function onRunStart(RunState $state): void {
    // Intentionally empty.
  }

  /**
   * {@inheritdoc}
   */
  public function onPhaseChange(RunState $state, Phase $from, Phase $to): void {
    // Intentionally empty.
  }

  /**
   * {@inheritdoc}
   */
  public function onRunComplete(RunState $state): void {
    // Intentionally empty.
  }

}
