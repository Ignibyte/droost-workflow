<?php

declare(strict_types=1);

namespace Droost\Workflow\Event;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\State\RunState;

/**
 * Observes a run's lifecycle transitions.
 *
 * The framework-free counterpart to a Drupal hook: the engine emits neutral
 * lifecycle callbacks here, and a surface (the Drupal bridge, a test) turns
 * them into whatever it needs — a Drupal hook, a Symfony event, a log line, a
 * Jira write. The engine itself stays portable and knows nothing of any of it.
 *
 * Like {@see \Droost\Workflow\Mode\QuestionSinkInterface}, a listener is a
 * NOTIFICATION, never the record. Every transition is written to run state and
 * persisted BEFORE any listener is called, and the facade isolates each call,
 * so a listener that throws, blocks, or does not exist costs the run its
 * promptness and never its correctness. A broken Jira token cannot fail a run.
 *
 * Implementers should extend {@see NullWorkflowListener} and override only the
 * transitions they care about, so an event added later cannot break them.
 */
interface WorkflowListenerInterface {

  /**
   * A run has just begun: its first phase is active and state is persisted.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run as begun.
   */
  public function onRunStart(RunState $state): void;

  /**
   * The run advanced from one phase to the next.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run after advancing.
   * @param \Droost\Workflow\Config\Phase $from
   *   The phase just left.
   * @param \Droost\Workflow\Config\Phase $to
   *   The phase now active.
   */
  public function onPhaseChange(RunState $state, Phase $from, Phase $to): void;

  /**
   * The run reached its terminal state — currentPhase is now NULL.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The finished run.
   */
  public function onRunComplete(RunState $state): void;

}
