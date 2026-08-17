<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Support\TypedArray;

/**
 * A question a paused run is waiting on.
 *
 * Carries the gate summary as well as the question, because "may I continue?"
 * is not answerable without knowing what just happened. A human asked to
 * approve a phase whose gate results they cannot see is being asked to rubber
 * stamp it.
 */
final class PendingQuestion {

  /**
   * Constructs a PendingQuestion.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase whose gate the run paused at.
   * @param string $question
   *   What is being asked.
   * @param string $gateSummary
   *   What the phase's gates reported, so the answer can be informed.
   * @param string $askedAt
   *   When it was asked, as a caller-supplied ISO-8601 string.
   */
  public function __construct(
    public readonly Phase $phase,
    public readonly string $question,
    public readonly string $gateSummary,
    public readonly string $askedAt,
  ) {}

  /**
   * This question as the data stored in run state.
   *
   * @return array<string, string>
   *   The serialized question.
   */
  public function toArray(): array {
    return [
      'phase' => $this->phase->value,
      'question' => $this->question,
      'gate_summary' => $this->gateSummary,
      'asked_at' => $this->askedAt,
    ];
  }

  /**
   * Rebuilds a question from run state.
   *
   * @param array<array-key, mixed> $raw
   *   The stored question.
   *
   * @return self|null
   *   The question, or NULL when the stored shape is unusable. NULL rather
   *   than an exception because a run whose pending question cannot be read
   *   should still be inspectable — losing the question is bad, losing access
   *   to the whole run because of it would be worse.
   */
  public static function fromArray(array $raw): ?self {
    $node = TypedArray::serialized($raw);
    $phase = Phase::tryFrom($node->optionalString('phase', '') ?? '');
    if ($phase === NULL) {
      return NULL;
    }
    return new self(
      $phase,
      $node->optionalString('question', '') ?? '',
      $node->optionalString('gate_summary', '') ?? '',
      $node->optionalString('asked_at', '') ?? '',
    );
  }

}
