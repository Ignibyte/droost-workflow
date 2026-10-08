<?php

declare(strict_types=1);

namespace Droost\Workflow\Intake;

/**
 * Where an intake stands: opened, audited, approved or abandoned.
 *
 * Kept in the state directory as `intake.json`, which the guard protects run
 * or no run: what the human approved, and the audit the intake argued from,
 * are the record, and the agent writes neither.
 */
final class IntakeState {

  /**
   * The schema the file names.
   */
  public const SCHEMA = 'droost.intake/1';

  /**
   * Open: the agent is assessing, asking and planning.
   */
  public const OPEN = 'open';

  /**
   * Approved by the operator: the roadmap's rungs may run.
   */
  public const APPROVED = 'approved';

  /**
   * Abandoned by the operator.
   */
  public const ABANDONED = 'abandoned';

  /**
   * Constructs a state.
   *
   * @param string $id
   *   The intake's id: `intake-` and twelve hex digits.
   * @param string $status
   *   OPEN, APPROVED or ABANDONED.
   * @param string $request
   *   The human's request, verbatim.
   * @param string $source
   *   The source as named: a path or a URL.
   * @param string $openedAt
   *   When it opened, ISO-8601.
   * @param string|null $auditSha
   *   The sha256 of `audit.json` as `intake audit` wrote it, or NULL.
   * @param string|null $auditedAt
   *   When the audit ran.
   * @param string|null $closedAt
   *   When it was approved or abandoned.
   * @param array<string, string> $digest
   *   At approval, each file's sha256 by its name.
   * @param string|null $reason
   *   Why it was abandoned.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $status,
    public readonly string $request,
    public readonly string $source,
    public readonly string $openedAt,
    public readonly ?string $auditSha = NULL,
    public readonly ?string $auditedAt = NULL,
    public readonly ?string $closedAt = NULL,
    public readonly array $digest = [],
    public readonly ?string $reason = NULL,
  ) {}

  /**
   * Whether the intake is open.
   *
   * @return bool
   *   TRUE while it is.
   */
  public function isOpen(): bool {
    return $this->status === self::OPEN;
  }

  /**
   * The state with an audit recorded.
   *
   * @param string $sha
   *   The audit file's sha256.
   * @param string $at
   *   When.
   *
   * @return self
   *   The new state.
   */
  public function withAudit(string $sha, string $at): self {
    return new self($this->id, $this->status, $this->request, $this->source, $this->openedAt, $sha, $at, $this->closedAt, $this->digest, $this->reason);
  }

  /**
   * The state approved.
   *
   * @param array<string, string> $digest
   *   Each file's sha256 by its name.
   * @param string $at
   *   When.
   *
   * @return self
   *   The new state.
   */
  public function approved(array $digest, string $at): self {
    return new self($this->id, self::APPROVED, $this->request, $this->source, $this->openedAt, $this->auditSha, $this->auditedAt, $at, $digest, NULL);
  }

  /**
   * The state abandoned.
   *
   * @param string $reason
   *   Why.
   * @param string $at
   *   When.
   *
   * @return self
   *   The new state.
   */
  public function abandoned(string $reason, string $at): self {
    return new self($this->id, self::ABANDONED, $this->request, $this->source, $this->openedAt, $this->auditSha, $this->auditedAt, $at, $this->digest, $reason);
  }

  /**
   * The state as the file holds it.
   *
   * @return array<string, mixed>
   *   The document.
   */
  public function toArray(): array {
    return [
      'schema' => self::SCHEMA,
      'id' => $this->id,
      'status' => $this->status,
      'request' => $this->request,
      'source' => $this->source,
      'opened_at' => $this->openedAt,
      'audit_sha' => $this->auditSha,
      'audited_at' => $this->auditedAt,
      'closed_at' => $this->closedAt,
      'digest' => $this->digest,
      'reason' => $this->reason,
    ];
  }

  /**
   * A state from the file's document, or NULL when it is not one.
   *
   * @param mixed $data
   *   The decoded file.
   *
   * @return self|null
   *   The state.
   */
  public static function fromArray(mixed $data): ?self {
    if (!is_array($data) || ($data['schema'] ?? NULL) !== self::SCHEMA) {
      return NULL;
    }
    foreach (['id', 'status', 'request', 'source', 'opened_at'] as $key) {
      if (!is_string($data[$key] ?? NULL)) {
        return NULL;
      }
    }
    if (!in_array($data['status'], [self::OPEN, self::APPROVED, self::ABANDONED], TRUE)) {
      return NULL;
    }
    $text = static fn (string $key): ?string => is_string($data[$key] ?? NULL) ? $data[$key] : NULL;
    $digest = [];
    foreach (is_array($data['digest'] ?? NULL) ? $data['digest'] : [] as $name => $sha) {
      if (is_string($name) && is_string($sha)) {
        $digest[$name] = $sha;
      }
    }
    return new self(
      (string) $data['id'],
      (string) $data['status'],
      (string) $data['request'],
      (string) $data['source'],
      (string) $data['opened_at'],
      $text('audit_sha'),
      $text('audited_at'),
      $text('closed_at'),
      $digest,
      $text('reason'),
    );
  }

}
