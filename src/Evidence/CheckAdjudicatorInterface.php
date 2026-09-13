<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * Answers the questions a shell command cannot ask.
 *
 * A GATE is a command whose exit code decides. That covers phpcs and snyk, and
 * covers nothing a site's own process cares about: does this run carry a ticket
 * id, did the transition happen, were the testing notes written back. Those
 * have PHP answers and no subprocess.
 *
 * This is the seam, and it lives here — in the Drupal-free package — for one
 * reason: the engine must be able to ASK without being able to answer. The
 * implementation is a booted Drupal's plugin catalog; this side knows only that
 * something can be asked and that what comes back is a `CheckRecord` with the
 * same invariants as any other.
 *
 * The trust boundary is not a gate's, and the distance is the point. droost
 * runs a gate and reads its exit code, so the agent's opinion never enters. A
 * check is adjudicated by the module that knows — droost cannot tell whether a
 * Jira transition happened, and only the module talking to Jira can. What makes
 * that safe is that a module is installed by the OPERATOR, deliberately, before
 * the run, and an agent cannot write one mid-run: a new plugin is custom code,
 * and the `require_run` wall guards exactly that path.
 */
interface CheckAdjudicatorInterface {

  /**
   * Every contributed verdict for a phase.
   *
   * Called once per phase, after that phase's gates have run and before it may
   * advance — so a check sees the same world the gates just measured, and a
   * blocked one stops the phase exactly as a failed gate does.
   *
   * An implementation must never throw: a phase that dies on a contributed
   * module's bug wedges the run over somebody else's mistake. Record the
   * failure as a blocked check with an environment fault instead, which stops
   * the phase without blaming the agent for it.
   *
   * @param string $projectRoot
   *   The repository under the run.
   * @param string $phase
   *   The phase that just ran its gates.
   * @param string $runId
   *   The run.
   *
   * @return list<\Droost\Workflow\Evidence\CheckRecord>
   *   The verdicts, each attributed to the module that made it. Empty when
   *   nothing applies, which is the honest answer and not a pass.
   */
  public function adjudicate(string $projectRoot, string $phase, string $runId): array;

}
