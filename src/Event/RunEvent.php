<?php

declare(strict_types=1);

namespace Droost\Workflow\Event;

/**
 * One line of the run-event log: something the engine persisted.
 *
 * The shape `schema/run-event.v1.json` describes. Versioned by `schema` and
 * additive only within a version: a consumer ignores a type or a payload key
 * it does not know, and renaming or removing one is `droost.run-event/2`.
 * `event_id` is a consumer's dedup key (delivery is at least once); `seq` is a
 * relay's cursor, never an identity.
 */
final class RunEvent {

  /**
   * The schema every event of this version names.
   */
  public const SCHEMA = 'droost.run-event/1';

  /**
   * The event types this version writes.
   */
  public const TYPES = [
    'run.started',
    'phase.began',
    'phase.attempted',
    'phase.ended',
    'question.asked',
    'question.answered',
    'run.completed',
    'run.reset',
    'phase.returned',
    'follow_up.filed',
    'intake.opened',
    'intake.audited',
    'intake.answered',
    'intake.approved',
    'intake.abandoned',
  ];

  /**
   * Constructs an event.
   *
   * @param string $eventId
   *   The id: `evt-` and sixteen hex digits, unique.
   * @param int $seq
   *   Its place in the log, from 1, strictly increasing.
   * @param string $runId
   *   The run it belongs to.
   * @param string|null $workItemId
   *   The ticket the run is bound to, or NULL.
   * @param string $at
   *   When, as ISO-8601.
   * @param string $type
   *   One of TYPES.
   * @param array<string, mixed> $payload
   *   What the type carries.
   * @param string $schema
   *   The schema it was written under.
   */
  public function __construct(
    public readonly string $eventId,
    public readonly int $seq,
    public readonly string $runId,
    public readonly ?string $workItemId,
    public readonly string $at,
    public readonly string $type,
    public readonly array $payload,
    public readonly string $schema = self::SCHEMA,
  ) {}

  /**
   * The event as the line it is written as, decoded.
   *
   * @return array<string, mixed>
   *   The event.
   */
  public function toArray(): array {
    return [
      'schema' => $this->schema,
      'event_id' => $this->eventId,
      'seq' => $this->seq,
      'run_id' => $this->runId,
      'work_item_id' => $this->workItemId,
      'at' => $this->at,
      'type' => $this->type,
      'payload' => $this->payload,
    ];
  }

  /**
   * An event read back from a line, or NULL when the line is not one.
   *
   * @param mixed $data
   *   A decoded line.
   *
   * @return self|null
   *   The event.
   */
  public static function fromArray(mixed $data): ?self {
    if (!is_array($data)
      || !is_string($data['schema'] ?? NULL)
      || !is_string($data['event_id'] ?? NULL)
      || !is_int($data['seq'] ?? NULL)
      || !is_string($data['run_id'] ?? NULL)
      || !(is_string($data['work_item_id'] ?? NULL) || ($data['work_item_id'] ?? NULL) === NULL)
      || !is_string($data['at'] ?? NULL)
      || !is_string($data['type'] ?? NULL)
      || !is_array($data['payload'] ?? NULL)) {
      return NULL;
    }
    $payload = [];
    foreach ($data['payload'] as $key => $value) {
      $payload[(string) $key] = $value;
    }

    return new self(
      $data['event_id'],
      $data['seq'],
      $data['run_id'],
      $data['work_item_id'] ?? NULL,
      $data['at'],
      $data['type'],
      $payload,
      $data['schema'],
    );
  }

}
