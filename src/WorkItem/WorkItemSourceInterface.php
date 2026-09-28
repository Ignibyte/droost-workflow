<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

/**
 * Where a run's tickets come from, and where their state is kept.
 *
 * In solo mode the tickets are markdown files in the repo
 * (MarkdownWorkItemSource); a cockpit's queue implements the same contract.
 * The engine moves a bound ticket only twice: to `in_progress` when the run
 * begins and to `review` when it completes. `done` is a human's move.
 */
interface WorkItemSourceInterface {

  /**
   * One ticket.
   *
   * @param string $id
   *   Its id.
   *
   * @return \Droost\Workflow\WorkItem\WorkItem|null
   *   The ticket, or NULL when there is none with the id.
   *
   * @throws \Droost\Workflow\WorkItem\WorkItemError
   *   When the ticket exists and cannot be read.
   */
  public function get(string $id): ?WorkItem;

  /**
   * Every ticket, or those in one state, in number order.
   *
   * @param string|null $status
   *   One of WorkItem::STATES, or NULL for all.
   *
   * @return list<\Droost\Workflow\WorkItem\WorkItem>
   *   The tickets.
   *
   * @throws \Droost\Workflow\WorkItem\WorkItemError
   *   When the state is unknown, or a ticket cannot be read.
   */
  public function list(?string $status = NULL): array;

  /**
   * Files a new ticket, in `backlog`.
   *
   * @param string $title
   *   Its title.
   * @param string $type
   *   What kind of work it is.
   *
   * @return \Droost\Workflow\WorkItem\WorkItem
   *   The ticket as written.
   *
   * @throws \Droost\Workflow\WorkItem\WorkItemError
   *   When it cannot be written.
   */
  public function create(string $title, string $type): WorkItem;

  /**
   * Moves a ticket to another state.
   *
   * @param string $id
   *   The ticket.
   * @param string $to
   *   One of WorkItem::STATES.
   * @param string $reason
   *   Why, for a source that keeps one.
   *
   * @return \Droost\Workflow\WorkItem\WorkItem
   *   The ticket as it now stands.
   *
   * @throws \Droost\Workflow\WorkItem\WorkItemError
   *   When the ticket is unknown, the state is unknown, the write is refused,
   *   or the source cannot transition (`unsupported`).
   */
  public function transition(string $id, string $to, string $reason): WorkItem;

}
