<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Mode;

use Drupal\Tests\droost_workflow\Unit\WorkflowTestCase;
use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\WorkflowConfig;
use Drupal\droost_workflow\Gate\GateExecutorInterface;
use Drupal\droost_workflow\Gate\GateResult;
use Drupal\droost_workflow\Gate\GateRunner;
use Drupal\droost_workflow\Gate\GateStatus;
use Drupal\droost_workflow\Gate\NullSiteDriver;
use Drupal\droost_workflow\Mode\ModeEngine;
use Drupal\droost_workflow\Mode\Outcome;
use Drupal\droost_workflow\Mode\PendingQuestion;
use Drupal\droost_workflow\Mode\QuestionSinkInterface;
use Drupal\droost_workflow\State\RunState;
use Drupal\droost_workflow\State\RunStateStore;

/**
 * Automated, pair, and the mid-run swap.
 */
class ModeEngineTest extends WorkflowTestCase {

  /**
   * The moment every test uses, so nothing depends on a real clock.
   */
  private const NOW = '2026-07-27T10:00:00+00:00';

  /**
   * REQ-002: automated works straight through, asking nobody.
   */
  public function testAutomatedNeverPausesNorEmits(): void {
    $sink = $this->recordingSink();
    $engine = $this->engine($sink);

    $outcome = $engine->runPhase(
      $this->begin(['mode' => 'automated']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Advanced, $outcome->outcome);
    $this->assertNull($outcome->state->awaiting);
    $this->assertSame([], $sink->emitted);
  }

  /**
   * REQ-001: pair pauses, and the pause is in state before the sink hears.
   *
   * The ordering is the design. A crash between deciding to pause and
   * delivering the question must leave a visibly-waiting run, not one that
   * silently continued.
   */
  public function testPairPausesWithStateWrittenBeforeTheSink(): void {
    $order = [];
    $sink = new class($order) implements QuestionSinkInterface {

      /**
       * Constructs the sink.
       *
       * @param list<string> $order
       *   Shared event log.
       */
      public function __construct(public array &$order) {}

      /**
       * {@inheritdoc}
       */
      public function emit(PendingQuestion $question): void {
        $this->order[] = 'emit';
      }

    };

    $engine = $this->engine($sink);
    $outcome = $engine->runPhase(
      $this->begin(['mode' => 'pair']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    );

    $this->assertTrue($outcome->isPaused());
    $this->assertNotNull($outcome->state->awaiting);
    $this->assertNotNull($outcome->question);
    $this->assertSame(Phase::Plan, $outcome->question->phase);
    // The question carries what the gates said, so the answer can be informed.
    $this->assertStringContainsString('plan:', $outcome->question->gateSummary);
    $this->assertSame(['emit'], $sink->order);
  }

  /**
   * REQ-006: re-entering a paused run re-presents, and re-runs nothing.
   */
  public function testReEnteringWhileAwaitingIsIdempotent(): void {
    $sink = $this->recordingSink();
    $executor = $this->countingExecutor();
    $engine = new ModeEngine(
      new GateRunner($executor, new NullSiteDriver()),
      $sink,
    );

    $first = $engine->runPhase(
      $this->begin(['mode' => 'pair']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    );
    $ranOnce = $executor->count;

    $second = $engine->runPhase(
      $first->state,
      Phase::Plan,
      '/tmp',
      self::NOW,
    );

    $this->assertTrue($second->isPaused());
    $this->assertSame($ranOnce, $executor->count, 'gates ran a second time');
    $this->assertSame($first->state->awaiting, $second->state->awaiting);
    $this->assertCount(2, $sink->emitted, 'the question is re-presented');
  }

  /**
   * REQ-003: an answer records the exchange, clears the pause, and resumes.
   */
  public function testAnswerAppendsHistoryClearsAwaitingAndResumes(): void {
    $engine = $this->engine($this->recordingSink());
    $paused = $engine->runPhase(
      $this->begin(['mode' => 'pair']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    )->state;

    $answered = $engine->answer($paused, 'yes, continue', self::NOW);

    $this->assertNull($answered->awaiting);
    $this->assertCount(1, $answered->qaHistory);
    $entry = $answered->qaHistory[0];
    $this->assertIsArray($entry);
    $this->assertSame('yes, continue', $entry['answer']);
    $this->assertNotNull($entry['asked']);
  }

  /**
   * Answering a question nobody asked is refused.
   *
   * It would otherwise append a decision to the record that no human made.
   */
  public function testAnsweringWhenNotPausedIsRefused(): void {
    $engine = $this->engine($this->recordingSink());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('not waiting for an answer');
    $engine->answer($this->begin([]), 'sure', self::NOW);
  }

  /**
   * REQ-004: a swap stops future pauses.
   */
  public function testSwapStopsFuturePauses(): void {
    $sink = $this->recordingSink();
    $engine = $this->engine($sink);
    $state = $this->begin(['mode' => 'pair']);

    $state = $engine->swap($state, Mode::Automated, self::NOW);
    $outcome = $engine->runPhase($state, Phase::Plan, '/tmp', self::NOW);

    $this->assertSame(Mode::Automated, $engine->effectiveMode($state));
    $this->assertSame(Outcome::Advanced, $outcome->outcome);
    $this->assertSame([], $sink->emitted);
    // The configured mode is remembered, not rewritten.
    $this->assertSame(Mode::Pair, $state->mode);
  }

  /**
   * REQ-004: a swap also releases a pause that is already outstanding.
   *
   * A swap whose purpose is "finish unattended" that still needed the
   * outstanding question answered first would not do its job.
   */
  public function testSwapReleasesTheCurrentPause(): void {
    $engine = $this->engine($this->recordingSink());
    $paused = $engine->runPhase(
      $this->begin(['mode' => 'pair']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    )->state;
    $this->assertNotNull($paused->awaiting);

    $swapped = $engine->swap($paused, Mode::Automated, self::NOW);

    $this->assertNull($swapped->awaiting);
    // The question that was bypassed is still a fact about the run.
    $this->assertCount(1, $swapped->qaHistory);
    $entry = $swapped->qaHistory[0];
    $this->assertIsArray($entry);
    $this->assertNull($entry['answer']);
    $this->assertArrayHasKey('released_at', $entry);
  }

  /**
   * Only pair to automated is supported.
   */
  public function testSwapToPairIsRefused(): void {
    $engine = $this->engine($this->recordingSink());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only a swap to automated');
    $engine->swap($this->begin([]), Mode::Pair, self::NOW);
  }

  /**
   * REQ-005: the override outranks the file, and is re-read every time.
   */
  public function testEffectiveModeIsOverrideThenFile(): void {
    $engine = $this->engine($this->recordingSink());
    $paired = $this->begin(['mode' => 'pair']);

    $this->assertSame(Mode::Pair, $engine->effectiveMode($paired));
    $this->assertSame(
      Mode::Automated,
      $engine->effectiveMode($paired->withModeOverride(Mode::Automated)),
    );
  }

  /**
   * A pause survives being written to disk and read back with its question.
   */
  public function testPauseSurvivesReload(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    $engine = $this->engine($this->recordingSink());

    $paused = $engine->runPhase(
      $this->begin(['mode' => 'pair']),
      Phase::Plan,
      $root,
      self::NOW,
    )->state;
    $store->save($paused);

    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $question = $engine->pendingQuestion($reloaded);
    $this->assertNotNull($question);
    $this->assertSame(Phase::Plan, $question->phase);
    $this->assertSame(self::NOW, $question->askedAt);
  }

  /**
   * A blocked phase fails rather than pausing, even in pair mode.
   *
   * Pair mode asks whether to continue past a phase that PASSED. A phase that
   * failed has nothing to ask about.
   */
  public function testFailedPhaseFailsRatherThanPausing(): void {
    $failing = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        return new GateResult($gate->name, GateStatus::Failed, 1, 1, 'nope');
      }

    };
    $sink = $this->recordingSink();
    $engine = new ModeEngine(
      new GateRunner($failing, new NullSiteDriver()),
      $sink,
    );

    $outcome = $engine->runPhase(
      $this->begin(['mode' => 'pair']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Failed, $outcome->outcome);
    $this->assertNull($outcome->state->awaiting);
    $this->assertSame([], $sink->emitted);
  }

  /**
   * The terminal phase completes rather than advancing.
   */
  public function testTheTerminalPhaseCompletes(): void {
    $outcome = $this->engine($this->recordingSink())->runPhase(
      $this->begin(['mode' => 'automated']),
      Phase::Complete,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Completed, $outcome->outcome);
  }

  /**
   * Every phase's gate report is recorded into the run.
   */
  public function testTheGateReportIsRecorded(): void {
    $outcome = $this->engine($this->recordingSink())->runPhase(
      $this->begin(['mode' => 'automated']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    );

    $this->assertArrayHasKey('plan', $outcome->state->gateResults);
  }

  /**
   * An engine with a passing executor and no site.
   *
   * @param \Drupal\droost_workflow\Mode\QuestionSinkInterface $sink
   *   The sink to use.
   *
   * @return \Drupal\droost_workflow\Mode\ModeEngine
   *   The engine.
   */
  private function engine(QuestionSinkInterface $sink): ModeEngine {
    return new ModeEngine(
      new GateRunner($this->countingExecutor(), new NullSiteDriver()),
      $sink,
    );
  }

  /**
   * A sink that remembers what it was given.
   *
   * @return object{emitted: list<\Drupal\droost_workflow\Mode\PendingQuestion>}&\Drupal\droost_workflow\Mode\QuestionSinkInterface
   *   The double.
   */
  private function recordingSink(): object {
    return new class() implements QuestionSinkInterface {

      /**
       * Questions this sink was given, in order.
       *
       * @var list<\Drupal\droost_workflow\Mode\PendingQuestion>
       */
      public array $emitted = [];

      /**
       * {@inheritdoc}
       */
      public function emit(PendingQuestion $question): void {
        $this->emitted[] = $question;
      }

    };
  }

  /**
   * An executor that passes everything and counts its calls.
   *
   * @return object{count: int}&\Drupal\droost_workflow\Gate\GateExecutorInterface
   *   The double.
   */
  private function countingExecutor(): object {
    return new class() implements GateExecutorInterface {

      /**
       * How many gates this executor has been asked to run.
       */
      public int $count = 0;

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        $this->count++;
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
  }

  /**
   * Begins a run from a lever document.
   *
   * @param array<array-key, mixed> $raw
   *   The document.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The run.
   */
  private function begin(array $raw): RunState {
    return RunState::begin(
      'run-1',
      '2026-07-27T09:00:00+00:00',
      WorkflowConfig::fromArray($raw, 'test'),
    );
  }

}
