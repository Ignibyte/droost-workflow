<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Mode;

/**
 * The sink for a surface with no way to reach a human directly.
 *
 * Does nothing, correctly. The question is already in run state by the time
 * any sink is called, so a CLI user finds it with `workflow:status` and a
 * later surface can add a real transport without changing anything here.
 *
 * The default, deliberately: a workflow that required a working message
 * transport before it would pause would be unusable on the surface that needs
 * pausing most.
 */
final class RunStateOnlySink implements QuestionSinkInterface {

  /**
   * {@inheritdoc}
   */
  public function emit(PendingQuestion $question): void {
    // Intentionally empty. See the class docblock: run state is the record,
    // and this surface has nowhere else to put it.
  }

}
