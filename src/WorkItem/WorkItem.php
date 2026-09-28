<?php

declare(strict_types=1);

namespace Droost\Workflow\WorkItem;

/**
 * One ticket, as a source read it.
 *
 * The same shape whichever source answered: a markdown file in the repo in
 * solo mode, or the cockpit's queue. `toArray()` is the JSON that `status`,
 * the `ticket` verb and the run event log carry.
 */
final class WorkItem {

  /**
   * The states a ticket moves through, in order.
   *
   * Machine names (`[a-z0-9_]`), because a Drupal Workflow state id is one,
   * so the file, the API and the cockpit speak one vocabulary.
   */
  public const STATES = ['backlog', 'ready', 'in_progress', 'review', 'done'];

  /**
   * Constructs a work item.
   *
   * @param string $id
   *   The ticket's id, e.g. `TICKET-186`.
   * @param int $number
   *   Its number.
   * @param string $title
   *   Its title.
   * @param string $status
   *   One of STATES.
   * @param string|null $type
   *   What kind of work it is (feature, bug, …), when the ticket says.
   * @param string $body
   *   The markdown after the frontmatter.
   * @param array<string, mixed> $extra
   *   Every other frontmatter key, in the order the file holds them.
   * @param string|null $path
   *   Where the ticket lives, project-relative (the markdown source only).
   * @param string $source
   *   Which source answered, e.g. `markdown`.
   */
  public function __construct(
    public readonly string $id,
    public readonly int $number,
    public readonly string $title,
    public readonly string $status,
    public readonly ?string $type,
    public readonly string $body,
    public readonly array $extra = [],
    public readonly ?string $path = NULL,
    public readonly string $source = 'markdown',
  ) {}

  /**
   * The ticket as JSON data.
   *
   * @return array<string, mixed>
   *   The ticket.
   */
  public function toArray(): array {
    return [
      'id' => $this->id,
      'source' => $this->source,
      'number' => $this->number,
      'title' => $this->title,
      'status' => $this->status,
      'type' => $this->type,
      'path' => $this->path,
      'extra' => $this->extra,
      'body' => $this->body,
    ];
  }

  /**
   * What a run records of the ticket it is bound to: no body, no extra keys.
   *
   * @return array{id: string, source: string, number: int, title: string, status: string, type: string|null, path: string|null}
   *   The binding.
   */
  public function binding(): array {
    return [
      'id' => $this->id,
      'source' => $this->source,
      'number' => $this->number,
      'title' => $this->title,
      'status' => $this->status,
      'type' => $this->type,
      'path' => $this->path,
    ];
  }

}
