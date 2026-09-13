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
 *
 * It carries three more things so interactive mode can hold a CONVERSATION
 * rather than present a form: a headline naming what the phase actually
 * produced, detail lines saying what the human needs to know to answer, and
 * the options worth offering. The options exist because a host with a
 * structured-question surface can render them as choices; a host without one
 * prints them and takes a sentence. Every one of them is optional, so a
 * question stored before these fields existed still reads.
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
   * @param string $headline
   *   One line naming what the phase produced, so the question opens with
   *   the state of the work rather than with the request.
   * @param list<string> $detail
   *   What the human needs in order to answer — what was found, what is
   *   recommended, what the next phase will do. Empty is legitimate: not
   *   every hold has something to say beyond its gates.
   * @param list<string> $options
   *   The answers worth offering, most-likely first. A structured-question
   *   surface renders them as choices; a plain prompt prints them. Empty
   *   means the question is open-ended.
   * @param string $kind
   *   What kind of question this is, which decides whether the ANSWER does
   *   anything beyond being recorded.
   *
   *   For a conversation hold, answering IS the act: the human's consent to
   *   advance is the whole point, so recording it and carrying on is right.
   *   The stuck question is different — it offers "stop here — this is stuck
   *   and I will look at it", and that was inert. `answer()` recorded the text
   *   and the run advanced; "stop here" and "banana" produced the same result,
   *   which makes the question theatre and the wall it sits behind pointless.
   */
  public function __construct(
    public readonly Phase $phase,
    public readonly string $question,
    public readonly string $gateSummary,
    public readonly string $askedAt,
    public readonly string $headline = '',
    public readonly array $detail = [],
    public readonly array $options = [],
    public readonly string $kind = self::KIND_CONVERSATION,
  ) {}

  /**
   * An ordinary phase hold: answering is consent to carry on.
   */
  public const string KIND_CONVERSATION = 'conversation';

  /**
   * The block ceiling: one of the answers ENDS the run.
   */
  public const string KIND_STUCK = 'stuck';

  /**
   * This question as the data stored in run state.
   *
   * @return array<string, string|list<string>>
   *   The serialized question.
   */
  public function toArray(): array {
    return [
      'phase' => $this->phase->value,
      'question' => $this->question,
      'gate_summary' => $this->gateSummary,
      'asked_at' => $this->askedAt,
      'headline' => $this->headline,
      'detail' => $this->detail,
      'options' => $this->options,
      'kind' => $this->kind,
    ];
  }

  /**
   * Whether an answer means "stop — this run is not going to get there".
   *
   * Matched on the leading word rather than on the whole option, because a
   * human answering a prompt types "stop", a structured surface sends the
   * option back verbatim, and neither should have to match the other exactly.
   * Anything else is "keep going", which is the safe reading: a run that ends
   * because somebody phrased their answer unusually is worse than one that
   * carries on and asks again at the next ceiling.
   *
   * @param string $answer
   *   What the human said.
   *
   * @return bool
   *   TRUE when this question is one that can be stopped, and was.
   */
  public function answerEndsTheRun(string $answer): bool {
    if ($this->kind !== self::KIND_STUCK) {
      return FALSE;
    }
    $said = strtolower(trim($answer));

    return $said === 'stop'
      || str_starts_with($said, 'stop ')
      || str_starts_with($said, 'stop,')
      || str_starts_with($said, 'stop-')
      || str_starts_with($said, 'stop here')
      || str_starts_with($said, 'abandon');
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
      $node->optionalString('headline', '') ?? '',
      $node->optionalStringList('detail', []),
      $node->optionalStringList('options', []),
      $node->optionalString('kind', self::KIND_CONVERSATION) ?: self::KIND_CONVERSATION,
    );
  }

}
