<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Mode;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Mode\PendingQuestion;
use Droost\Workflow\Support\TypedArray;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The question a paused run carries, including the shape it used to have.
 *
 * A paused run is persisted. That means a question written by the previous
 * version of this class is sitting in real run records right now, and a run
 * that cannot read its own pending question is a run nobody can answer.
 */
class PendingQuestionTest extends WorkflowTestCase {

  /**
   * Everything the question carries survives a round trip through state.
   */
  public function testTheConversationRoundTrips(): void {
    $question = new PendingQuestion(
      Phase::Plan,
      'Is the spec what you want built?',
      'plan: 0 gates',
      '2026-08-27T10:00:00+00:00',
      'The spec is written.',
      ['Changing it now costs nothing.', 'Next: code builds only this.'],
      ['Looks right', 'Change the spec first'],
    );

    $back = PendingQuestion::fromArray($question->toArray());

    $this->assertNotNull($back);
    $this->assertSame(Phase::Plan, $back->phase);
    $this->assertSame($question->question, $back->question);
    $this->assertSame($question->gateSummary, $back->gateSummary);
    $this->assertSame($question->askedAt, $back->askedAt);
    $this->assertSame('The spec is written.', $back->headline);
    $this->assertSame($question->detail, $back->detail);
    $this->assertSame($question->options, $back->options);
  }

  /**
   * A question stored before the conversation fields existed still reads.
   *
   * This is the compatibility that matters: the four original keys and
   * nothing else, exactly as the old class wrote them.
   */
  public function testTheOldFourKeyShapeStillReads(): void {
    $stored = [
      'phase' => 'code',
      'question' => 'The code phase passed its gates. Continue to the next phase?',
      'gate_summary' => 'code: 2 passed',
      'asked_at' => '2026-08-01T12:00:00+00:00',
    ];

    $back = PendingQuestion::fromArray($stored);

    $this->assertNotNull($back);
    $this->assertSame(Phase::Code, $back->phase);
    $this->assertSame('code: 2 passed', $back->gateSummary);
    // Absent, not invented: an old question had no conversation to restore.
    $this->assertSame('', $back->headline);
    $this->assertSame([], $back->detail);
    $this->assertSame([], $back->options);
  }

  /**
   * An unusable stored shape yields NULL rather than an exception.
   *
   * Losing the question is bad; losing access to the whole run because of it
   * would be worse.
   */
  public function testAnUnreadablePhaseYieldsNull(): void {
    $this->assertNull(PendingQuestion::fromArray(['phase' => 'nonsense']));
    $this->assertNull(PendingQuestion::fromArray([]));
  }

  /**
   * The serialized shape is what run state actually stores.
   *
   * Asserted through TypedArray because that is how the reader gets at it,
   * so a key renamed here would fail here rather than at read time.
   */
  public function testTheSerializedKeysAreStable(): void {
    $question = new PendingQuestion(
      Phase::Test,
      'Anything you want covered?',
      'test: 1 passed',
      '2026-08-27T10:00:00+00:00',
      'The test phase passed.',
      ['A gate that could not run is reported as such.'],
      ['Accept and complete the run'],
    );

    $node = TypedArray::serialized($question->toArray());

    $this->assertSame('test', $node->string('phase'));
    $this->assertSame('The test phase passed.', $node->string('headline'));
    $this->assertSame(
      ['A gate that could not run is reported as such.'],
      $node->stringList('detail'),
    );
    $this->assertSame(
      ['Accept and complete the run'],
      $node->stringList('options'),
    );
  }

}
