<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

/**
 * A ticket could not be read, found, bound or moved.
 *
 * Every message names what to do next, because the reader is an agent or an
 * operator at a command line, and the CLI prints it as it stands (exit 2).
 */
final class WorkItemError extends \RuntimeException {

  /**
   * A ticket was asked for and no source is configured.
   *
   * @return self
   *   The error.
   */
  public static function noSource(): self {
    return new self(
      'no work-item source is configured: tickets need `work_item.provider: '
      . 'markdown` in droost.workflow.yml (and, optionally, `work_item.markdown: '
      . '{ dir: <dir>, prefix: <PREFIX> }`), or `provider: droost_cockpit` with '
      . '`work_item.cockpit: { url_env: <VARIABLE>, token_env: <VARIABLE> }`. '
      . 'Any other provider is metadata only.'
    );
  }

  /**
   * No ticket has the id.
   *
   * @param string $id
   *   The id asked for.
   * @param string $where
   *   Where the source looked.
   *
   * @return self
   *   The error.
   */
  public static function notFound(string $id, string $where): self {
    return new self(sprintf('no ticket %s in %s: `droost-workflow ticket list` shows the tickets there.', $id, $where));
  }

  /**
   * A ticket file does not hold what a ticket must.
   *
   * @param string $file
   *   The file.
   * @param string $why
   *   What is wrong with it.
   *
   * @return self
   *   The error.
   */
  public static function unreadable(string $file, string $why): self {
    return new self(sprintf('%s is not a ticket this source can read: %s.', $file, $why));
  }

  /**
   * A state that is not one of the five.
   *
   * @param string $state
   *   The state given.
   *
   * @return self
   *   The error.
   */
  public static function unknownState(string $state): self {
    return new self(sprintf(
      'unknown ticket state "%s": use one of %s.',
      $state,
      implode(', ', WorkItem::STATES),
    ));
  }

  /**
   * A path the source must not write through.
   *
   * @param string $path
   *   The path.
   * @param string $why
   *   Why it is refused.
   *
   * @return self
   *   The error.
   */
  public static function refusedPath(string $path, string $why): self {
    return new self(sprintf('refusing %s: %s.', $path, $why));
  }

  /**
   * A run in progress is bound to another ticket, or to none.
   *
   * @param string $asked
   *   The ticket asked for.
   * @param string|null $bound
   *   The ticket the run is bound to, if any.
   * @param string $runId
   *   The run.
   *
   * @return self
   *   The error.
   */
  public static function alreadyStarted(string $asked, ?string $bound, string $runId): self {
    return new self(sprintf(
      '%s cannot be bound to %s: one run, one ticket, bound when the run begins, and this run %s. '
      . 'Finish it, or `droost-workflow reset --force`, then `run --ticket=%s`.',
      $asked,
      $runId,
      $bound === NULL ? 'began with none' : 'is bound to ' . $bound,
      $asked,
    ));
  }

  /**
   * The cockpit could not answer for a ticket, and nothing cached stands in.
   *
   * @param string $what
   *   The ticket or request.
   * @param string $why
   *   The one-line reason, which names the cockpit's origin and never its
   *   token.
   *
   * @return self
   *   The error.
   */
  public static function cockpitUnreachable(string $what, string $why): self {
    return new self(sprintf('the cockpit could not be asked for %s: %s.', $what, $why));
  }

  /**
   * A move asked of cockpit mode, where the cockpit moves tickets.
   *
   * @return self
   *   The error.
   */
  public static function moveInTheCockpit(): self {
    return new self('move tickets in the cockpit: in cockpit mode its queue owns their state, and it reads a run\'s progress from the run\'s events.');
  }

  /**
   * The source cannot perform an operation.
   *
   * @param string $what
   *   The operation.
   *
   * @return self
   *   The error.
   */
  public static function unsupported(string $what): self {
    return new self(sprintf('this work-item source cannot %s.', $what));
  }

}
