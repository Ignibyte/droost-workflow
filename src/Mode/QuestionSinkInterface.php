<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Mode;

/**
 * Delivers a paused run's question to whoever can answer it.
 *
 * A notification, never the record. The pause itself is written to run state
 * before any sink is called, so a sink that fails, drops the message, or does
 * not exist costs the run its promptness and never its correctness.
 *
 * That distinction is not theoretical here. druplit — the surface this
 * workflow was designed alongside — relays a MANAGER's question to a human,
 * but consumes a WORKER's question as if it were a result, so a mailbox sink
 * written today would emit into a void. Owning the pause in state means the
 * run is still correct and resumable on a surface whose transport is not
 * ready.
 *
 * Implementations must be idempotent: a re-entered run re-emits its pending
 * question rather than tracking whether delivery already happened.
 */
interface QuestionSinkInterface {

  /**
   * Delivers a question.
   *
   * @param \Drupal\droost_workflow\Mode\PendingQuestion $question
   *   The question the run is waiting on.
   */
  public function emit(PendingQuestion $question): void;

}
