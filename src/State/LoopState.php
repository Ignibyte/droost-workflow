<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

/**
 * The fast flow's loop: returns to code, their budget, and the follow-ups.
 *
 * The owner, 2026-09-29: a failure at test goes back to code, and one that is
 * out of the ticket's scope, or still there when the budget is spent, becomes
 * a follow-up ticket. Frozen at begin like every other lever, so a run is
 * measured against the budget it started under, and kept whole in run.json,
 * so the record shows how many loops a ticket took and why.
 *
 * A return is `failed` when test's gates failed, and spends the budget.
 * It is `moved` when the files code's gates measured changed after they
 * passed. That spends nothing: the agent's own edit caused it, code's gates
 * are what measures the edit, and the next return is the agent's to cause.
 */
final class LoopState {

  /**
   * The ways a loop's spent budget can end.
   */
  public const WHEN_SPENT = ['follow-up', 'fail'];

  /**
   * Where follow-up tickets can be written.
   */
  public const FOLLOW_UPS = ['auto', 'markdown', 'cockpit', 'none'];

  /**
   * Constructs the loop record.
   *
   * @param string $flow
   *   `fast` or `strict`, frozen at begin. Only the fast flow loops.
   * @param int $maxLoops
   *   How many failure returns the run may make.
   * @param string $whenSpent
   *   Either `follow-up`, to write the still-failing gates up and move on,
   *   or `fail`, to end the phase (at max, or with nowhere to write them).
   * @param string $followUps
   *   Where follow-ups go: `auto`, `markdown`, `cockpit` or `none`.
   * @param list<array{from: string, to: string, reason: string, at: string, gates: array<string, string>}> $returns
   *   Every return, oldest first, with the summaries of what caused it.
   * @param array<string, string> $subjects
   *   The fingerprint of what a phase's gates last measured, by phase.
   * @param list<array<string, string|null>> $followUpsFiled
   *   Every follow-up written: phase, gate, summary, id, source, path, note.
   * @param array<string, list<string>> $deferred
   *   The gates whose failures a deferred phase left as follow-ups, by phase.
   */
  public function __construct(
    public readonly string $flow = 'strict',
    public readonly int $maxLoops = 0,
    public readonly string $whenSpent = 'fail',
    public readonly string $followUps = 'none',
    public readonly array $returns = [],
    public readonly array $subjects = [],
    public readonly array $followUpsFiled = [],
    public readonly array $deferred = [],
  ) {}

  /**
   * Whether this run returns to code on a test failure.
   *
   * @return bool
   *   TRUE in the fast flow.
   */
  public function loops(): bool {
    return $this->flow === 'fast';
  }

  /**
   * How many returns spent the budget.
   *
   * @return int
   *   The count of `failed` returns.
   */
  public function spent(): int {
    return count(array_filter(
      $this->returns,
      static fn (array $return): bool => $return['reason'] === 'failed',
    ));
  }

  /**
   * How many failure returns are left.
   *
   * @return int
   *   Zero or more.
   */
  public function remaining(): int {
    return max(0, $this->maxLoops - $this->spent());
  }

  /**
   * The loop with one more return recorded.
   *
   * @param string $from
   *   The phase the run left.
   * @param string $to
   *   The phase it returned to.
   * @param string $reason
   *   Either `failed` or `moved`.
   * @param string $at
   *   When, ISO-8601.
   * @param array<string, string> $gates
   *   What caused it: each failing gate's summary, or each moved subject.
   *
   * @return self
   *   A new instance.
   */
  public function returned(string $from, string $to, string $reason, string $at, array $gates): self {
    $returns = $this->returns;
    $returns[] = ['from' => $from, 'to' => $to, 'reason' => $reason, 'at' => $at, 'gates' => $gates];

    return $this->copy(returns: $returns);
  }

  /**
   * The loop with a phase's measured subject recorded.
   *
   * @param string $phase
   *   The phase whose gates just ran.
   * @param string|null $fingerprint
   *   What they measured, or NULL when it could not be taken.
   *
   * @return self
   *   A new instance.
   */
  public function withSubject(string $phase, ?string $fingerprint): self {
    $subjects = $this->subjects;
    if ($fingerprint === NULL) {
      unset($subjects[$phase]);
    }
    else {
      $subjects[$phase] = $fingerprint;
    }

    return $this->copy(subjects: $subjects);
  }

  /**
   * The loop with a phase deferred and its follow-ups recorded.
   *
   * @param string $phase
   *   The phase left with failures.
   * @param list<array<string, string|null>> $filed
   *   The follow-ups written for it.
   *
   * @return self
   *   A new instance.
   */
  public function deferring(string $phase, array $filed): self {
    $deferred = $this->deferred;
    $gates = array_values(array_unique(array_map(
      static fn (array $record): string => (string) ($record['gate'] ?? ''),
      $filed,
    )));
    $deferred[$phase] = array_values(array_unique(array_merge($deferred[$phase] ?? [], $gates)));

    return $this->copy(followUpsFiled: array_merge($this->followUpsFiled, $filed), deferred: $deferred);
  }

  /**
   * The follow-up already written for a gate at a phase, if any.
   *
   * @param string $phase
   *   The phase.
   * @param string $gate
   *   The gate.
   *
   * @return array<string, string|null>|null
   *   The record, or NULL.
   */
  public function filedFor(string $phase, string $gate): ?array {
    foreach ($this->followUpsFiled as $record) {
      if (($record['phase'] ?? NULL) === $phase && ($record['gate'] ?? NULL) === $gate) {
        return $record;
      }
    }

    return NULL;
  }

  /**
   * The loop as run.json keeps it.
   *
   * @return array<string, mixed>
   *   The document.
   */
  public function toArray(): array {
    return [
      'flow' => $this->flow,
      'max_loops' => $this->maxLoops,
      'when_spent' => $this->whenSpent,
      'follow_ups' => $this->followUps,
      'spent' => $this->spent(),
      'remaining' => $this->remaining(),
      'returns' => $this->returns,
      'subjects' => $this->subjects,
      'follow_ups_filed' => $this->followUpsFiled,
      'deferred' => $this->deferred,
    ];
  }

  /**
   * The loop as the run envelope shows it: without the fingerprints.
   *
   * @return array<string, mixed>
   *   The budget, the returns and the follow-ups.
   */
  public function envelope(): array {
    $document = $this->toArray();
    unset($document['subjects']);

    return $document;
  }

  /**
   * The loop from run.json, or the strict default for a record without one.
   *
   * A run begun before 0.11 has no loop, and it never looped: that is what
   * the default says.
   *
   * @param array<array-key, mixed>|null $data
   *   The stored document.
   *
   * @return self
   *   The loop.
   */
  public static function fromArray(?array $data): self {
    if ($data === NULL) {
      return new self();
    }
    $flow = in_array($data['flow'] ?? NULL, ['fast', 'strict'], TRUE) ? $data['flow'] : 'strict';
    $whenSpent = in_array($data['when_spent'] ?? NULL, self::WHEN_SPENT, TRUE) ? $data['when_spent'] : 'fail';
    $followUps = in_array($data['follow_ups'] ?? NULL, self::FOLLOW_UPS, TRUE) ? $data['follow_ups'] : 'none';
    $returns = [];
    foreach (is_array($data['returns'] ?? NULL) ? $data['returns'] : [] as $return) {
      if (!is_array($return)) {
        continue;
      }
      $gates = [];
      foreach (is_array($return['gates'] ?? NULL) ? $return['gates'] : [] as $gate => $summary) {
        $gates[(string) $gate] = is_scalar($summary) ? (string) $summary : '';
      }
      $returns[] = [
        'from' => self::text($return, 'from'),
        'to' => self::text($return, 'to'),
        'reason' => self::text($return, 'reason') === 'moved' ? 'moved' : 'failed',
        'at' => self::text($return, 'at'),
        'gates' => $gates,
      ];
    }
    $subjects = [];
    foreach (is_array($data['subjects'] ?? NULL) ? $data['subjects'] : [] as $phase => $fingerprint) {
      if (is_string($fingerprint)) {
        $subjects[(string) $phase] = $fingerprint;
      }
    }
    $filed = [];
    foreach (is_array($data['follow_ups_filed'] ?? NULL) ? $data['follow_ups_filed'] : [] as $record) {
      if (!is_array($record)) {
        continue;
      }
      $row = [];
      foreach ($record as $key => $value) {
        $row[(string) $key] = is_scalar($value) ? (string) $value : NULL;
      }
      $filed[] = $row;
    }
    $deferred = [];
    foreach (is_array($data['deferred'] ?? NULL) ? $data['deferred'] : [] as $phase => $gates) {
      if (is_array($gates)) {
        $deferred[(string) $phase] = array_values(array_map('strval', array_filter($gates, 'is_string')));
      }
    }

    return new self(
      $flow,
      max(0, is_int($data['max_loops'] ?? NULL) ? $data['max_loops'] : 0),
      $whenSpent,
      $followUps,
      $returns,
      $subjects,
      $filed,
      $deferred,
    );
  }

  /**
   * A copy with some fields replaced.
   *
   * @param list<array{from: string, to: string, reason: string, at: string, gates: array<string, string>}>|null $returns
   *   The returns, or NULL to keep them.
   * @param array<string, string>|null $subjects
   *   The subjects, or NULL to keep them.
   * @param list<array<string, string|null>>|null $followUpsFiled
   *   The follow-ups, or NULL to keep them.
   * @param array<string, list<string>>|null $deferred
   *   The deferred gates, or NULL to keep them.
   *
   * @return self
   *   A new instance.
   */
  private function copy(
    ?array $returns = NULL,
    ?array $subjects = NULL,
    ?array $followUpsFiled = NULL,
    ?array $deferred = NULL,
  ): self {
    return new self(
      $this->flow,
      $this->maxLoops,
      $this->whenSpent,
      $this->followUps,
      $returns ?? $this->returns,
      $subjects ?? $this->subjects,
      $followUpsFiled ?? $this->followUpsFiled,
      $deferred ?? $this->deferred,
    );
  }

  /**
   * One string field of a stored row.
   *
   * @param array<array-key, mixed> $row
   *   The row.
   * @param string $key
   *   The field.
   *
   * @return string
   *   Its value, or '' when it is not a string.
   */
  private static function text(array $row, string $key): string {
    return is_string($row[$key] ?? NULL) ? $row[$key] : '';
  }

}
