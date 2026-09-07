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
   * A phase became active — the start of one cycle step.
   *
   * Fires exactly once per phase entered: for the first phase right after
   * onRunStart(), and for each later phase as the run advances into it (paired
   * with the onPhaseEnd() of the phase left). A phase that fails and retries
   * stays active and does NOT begin again — begin marks entering the phase, not
   * each attempt within it.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run, with $phase now active.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that just became active.
   */
  public function onPhaseBegin(RunState $state, Phase $phase): void;

  /**
   * A phase completed — the end of one cycle step.
   *
   * Fires exactly once per phase, only when the phase is actually LEFT: on a
   * real advance to the next phase (paired with that phase's onPhaseBegin()),
   * and for the final phase when the run completes (just before
   * onRunComplete()). It does NOT fire on a failure, pause, or seeker hold —
   * those leave the phase active, so it has not ended; end waits for the phase
   * to pass. Same "only on real progress" contract as onPhaseChange().
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run, after leaving $phase.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that just completed.
   */
  public function onPhaseEnd(RunState $state, Phase $phase): void;

  /**
   * The run advanced from one phase to the next.
   *
   * Fires between the left phase's onPhaseEnd() and the entered phase's
   * onPhaseBegin(). Kept alongside the begin/end pair (not replaced by it) so
   * existing consumers that key on the transition itself are unaffected.
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
