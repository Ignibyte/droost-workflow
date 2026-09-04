<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Mode;

use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\ModeEngine;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\PendingQuestion;
use Droost\Workflow\Mode\QuestionSinkInterface;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;

/**
 * Agentic, interactive, and the mid-run swap.
 *
 * Several tests still write the SUPERSEDED lever names on purpose — a run
 * begun from `mode: automated` has to keep behaving, so the alias is
 * exercised by the same tests that cover the behaviour.
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
   *
   * Deliberately run at PLAN, which the phase map leaves gateless: pair mode
   * pauses even when a phase ran zero gates, because the pause is about the
   * human deciding to continue, not about what the gates said — and the
   * cheapest moment to redirect a run is before any code exists.
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
    // The question carries what the gates said, so the answer can be
    // informed — here, honestly, that nothing was due.
    $this->assertSame(
      'plan: no gates configured',
      $outcome->question->gateSummary,
    );
    $this->assertSame(['emit'], $sink->order);
  }

  /**
   * REQ-006: re-entering a paused run re-presents, and re-runs nothing.
   *
   * Run at CODE, a phase where gates genuinely execute, so "re-runs nothing"
   * is proven against a non-zero first count rather than vacuously.
   */
  public function testReEnteringWhileAwaitingIsIdempotent(): void {
    $sink = $this->recordingSink();
    $executor = $this->countingExecutor();
    $engine = new ModeEngine(
      new GateRunner($executor, new NullSiteDriver()),
      $sink,
    );

    $first = $engine->runPhase(
      $this->begin(['mode' => 'pair', 'seekers' => ['on' => FALSE]]),
      Phase::Code,
      '/tmp',
      self::NOW,
    );
    $ranOnce = $executor->count;
    $this->assertGreaterThan(0, $ranOnce, 'code must actually run gates');

    $second = $engine->runPhase(
      $first->state,
      Phase::Code,
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

    $state = $engine->swap($state, Mode::Agentic, self::NOW);
    $outcome = $engine->runPhase($state, Phase::Plan, '/tmp', self::NOW);

    $this->assertSame(Mode::Agentic, $engine->effectiveMode($state));
    $this->assertSame(Outcome::Advanced, $outcome->outcome);
    $this->assertSame([], $sink->emitted);
    // The configured mode is remembered, not rewritten.
    $this->assertSame(Mode::Interactive, $state->mode);
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

    $swapped = $engine->swap($paused, Mode::Agentic, self::NOW);

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
  public function testSwapToInteractiveIsRefused(): void {
    $engine = $this->engine($this->recordingSink());

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only a swap to agentic');
    $engine->swap($this->begin([]), Mode::Interactive, self::NOW);
  }

  /**
   * REQ-005: the override outranks the file, and is re-read every time.
   */
  public function testEffectiveModeIsOverrideThenFile(): void {
    $engine = $this->engine($this->recordingSink());
    $paired = $this->begin(['mode' => 'pair']);

    $this->assertSame(Mode::Interactive, $engine->effectiveMode($paired));
    $this->assertSame(
      Mode::Agentic,
      $engine->effectiveMode($paired->withModeOverride(Mode::Agentic)),
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

    // Code, not plan: the failure has to come from a gate that actually ran.
    $outcome = $engine->runPhase(
      $this->begin(['mode' => 'pair']),
      Phase::Code,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Failed, $outcome->outcome);
    $this->assertNull($outcome->state->awaiting);
    $this->assertSame([], $sink->emitted);
  }

  /**
   * REQ-004: a blocking gate spends retry budget across invocations.
   *
   * A max_gate_retries of 2 means one attempt plus two retries. The third
   * blocking invocation finds the budget spent and marks the phase failed —
   * terminally — without counting another attempt.
   */
  public function testRetryBudgetIsCountedAcrossInvocations(): void {
    $engine = new ModeEngine(
      new GateRunner($this->failingExecutor(), new NullSiteDriver()),
      $this->recordingSink(),
    );
    // Advanced to code first, as the facade always has by the time it calls
    // runPhase — the run's current phase IS the phase being worked.
    $state = $this->begin([
      // The custom preset keeps the front-end lint trio off, so this test
      // stays about the counting mechanism over the static pair, not the
      // gate vocabulary.
      'preset' => 'custom',
      'max_gate_retries' => 2,
      'seekers' => ['on' => FALSE],
    ])->advanceTo(Phase::Code);

    $first = $engine->runPhase($state, Phase::Code, '/tmp', self::NOW);
    $this->assertSame(Outcome::Failed, $first->outcome);
    $this->assertSame(
      ['phpcs' => 1, 'phpstan' => 1],
      $first->state->feedbackAttempts,
    );
    $this->assertFalse($first->exhausted());

    $second = $engine->runPhase($first->state, Phase::Code, '/tmp', self::NOW);
    $this->assertSame(Outcome::Failed, $second->outcome);
    $this->assertSame(
      ['phpcs' => 2, 'phpstan' => 2],
      $second->state->feedbackAttempts,
    );
    $this->assertFalse($second->exhausted());

    $third = $engine->runPhase($second->state, Phase::Code, '/tmp', self::NOW);
    $this->assertSame(Outcome::Failed, $third->outcome);
    // The budget was already spent, so no further attempt is counted.
    $this->assertSame(
      ['phpcs' => 2, 'phpstan' => 2],
      $third->state->feedbackAttempts,
    );
    // And the phase is now terminally failed.
    $this->assertSame(
      PhaseStatus::Failed,
      $third->state->statusOf(Phase::Code),
    );
    $this->assertTrue($third->exhausted());
  }

  /**
   * A budget of zero means one attempt and no retry.
   */
  public function testZeroBudgetFailsTerminallyOnTheFirstFailure(): void {
    $engine = new ModeEngine(
      new GateRunner($this->failingExecutor(), new NullSiteDriver()),
      $this->recordingSink(),
    );

    $outcome = $engine->runPhase(
      $this->begin(['max_gate_retries' => 0])->advanceTo(Phase::Code),
      Phase::Code,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Failed, $outcome->outcome);
    $this->assertSame([], $outcome->state->feedbackAttempts);
    $this->assertSame(
      PhaseStatus::Failed,
      $outcome->state->statusOf(Phase::Code),
    );
    $this->assertTrue($outcome->exhausted());
  }

  /**
   * A missing tool spends the same budget a failure does.
   *
   * ErrorToolMissing blocks advance, and a missing binary re-invoked
   * forever is the worst infinite loop of all — installing the tool
   * between invocations is a legitimate retry, so it is bounded like one.
   */
  public function testMissingToolConsumesRetryBudget(): void {
    $missingPhpcs = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        return $gate->name === 'phpcs'
          ? GateResult::toolMissing('phpcs', 'phpcs')
          : new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
    $engine = new ModeEngine(
      new GateRunner($missingPhpcs, new NullSiteDriver()),
      $this->recordingSink(),
    );

    $outcome = $engine->runPhase(
      $this->begin(['max_gate_retries' => 2])->advanceTo(Phase::Code),
      Phase::Code,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Failed, $outcome->outcome);
    $this->assertSame(['phpcs' => 1], $outcome->state->feedbackAttempts);
  }

  /**
   * A fixed gate passes on the next invocation, keeping its history.
   *
   * The attempts already spent are the run's record, not a penalty — they
   * survive the pass, and only blocking gates ever consumed budget.
   */
  public function testFixedGateAdvancesAndKeepsItsHistory(): void {
    $flaky = new class() implements GateExecutorInterface {

      /**
       * Whether the first invocation has already happened.
       */
      public bool $fixed = FALSE;

      /**
       * {@inheritdoc}
       */
      public function execute(
        GateSettings $gate,
        string $projectRoot,
      ): GateResult {
        if ($gate->name === 'phpcs' && !$this->fixed) {
          return new GateResult('phpcs', GateStatus::Failed, 1, 1, 'nope');
        }
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
    $engine = new ModeEngine(
      new GateRunner($flaky, new NullSiteDriver()),
      $this->recordingSink(),
    );
    $state = $this->begin([
      'max_gate_retries' => 2,
      'seekers' => ['on' => FALSE],
    ])->advanceTo(Phase::Code);

    $failed = $engine->runPhase($state, Phase::Code, '/tmp', self::NOW);
    $this->assertSame(
      ['phpcs' => 1],
      $failed->state->feedbackAttempts,
      'only the blocking gate spends budget',
    );

    $flaky->fixed = TRUE;
    $passed = $engine->runPhase($failed->state, Phase::Code, '/tmp', self::NOW);

    $this->assertSame(Outcome::Advanced, $passed->outcome);
    $this->assertSame(
      ['phpcs' => 1],
      $passed->state->feedbackAttempts,
      'the spent attempts are the record of the journey, not a penalty',
    );
  }

  /**
   * The terminal phase completes rather than advancing.
   */
  public function testTheTerminalPhaseCompletes(): void {
    $outcome = $this->engine($this->recordingSink())->runPhase(
      $this->begin([
        'mode' => 'automated',
        'seekers' => ['on' => FALSE],
      ]),
      Phase::Complete,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Completed, $outcome->outcome);
  }

  /**
   * The seeker checkpoint holds a green code phase until a clean ledger.
   *
   * Gates verify rules; the seeker verifies judgment. The checkpoint fires
   * AFTER the machines pass (inspection is of code that already satisfies
   * them), holds the run in place without spending retry budget, releases
   * on a recorded clean inspection — and holds again at complete, the other
   * boundary the pattern names. A findings record holds exactly like no
   * record: fixing is followed by re-inspection, never by advancing.
   */
  public function testTheSeekerCheckpointHoldsCodeAndComplete(): void {
    $engine = $this->engine($this->recordingSink());
    $state = $this->begin(['mode' => 'automated'])->advanceTo(Phase::Code);

    $held = $engine->runPhase($state, Phase::Code, '/tmp', self::NOW);
    $this->assertSame(Outcome::InspectionDue, $held->outcome);
    $this->assertSame(Phase::Code, $held->state->currentPhase);
    $this->assertSame(
      [],
      $held->state->feedbackAttempts,
      'the checkpoint is not a failing gate — it spends no budget',
    );

    $dirty = $held->state->withSeekerReport([
      'status' => 'findings',
      'critical' => 1,
      'medium' => 0,
      'low' => 0,
      'observations' => 0,
      'reported_at' => self::NOW,
    ]);
    $stillHeld = $engine->runPhase($dirty, Phase::Code, '/tmp', self::NOW);
    $this->assertSame(Outcome::InspectionDue, $stillHeld->outcome);

    $clean = $stillHeld->state->withSeekerReport([
      'status' => 'clean',
      'critical' => 0,
      'medium' => 0,
      'low' => 0,
      'observations' => 2,
      'reported_at' => self::NOW,
    ]);
    $released = $engine->runPhase($clean, Phase::Code, '/tmp', self::NOW);
    $this->assertSame(Outcome::Advanced, $released->outcome);

    // The other boundary: complete demands the clean record too.
    $atComplete = $released->state
      ->advanceTo(Phase::Test)
      ->advanceTo(Phase::Complete);
    $completed = $engine->runPhase(
      $atComplete,
      Phase::Complete,
      '/tmp',
      self::NOW,
    );
    $this->assertSame(Outcome::Completed, $completed->outcome);
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
   * Interactive holds with a conversation, not a yes/no.
   *
   * The point of the mode. A hold whose only answer is "yes" is a form, so
   * the question has to arrive with what the phase produced, what the human
   * needs in order to answer, and the answers worth offering.
   */
  public function testInteractiveHoldsWithConversation(): void {
    $sink = $this->recordingSink();
    $engine = $this->engine($sink);

    $outcome = $engine->runPhase(
      $this->begin(['mode' => 'interactive']),
      Phase::Plan,
      '/tmp',
      self::NOW,
    );

    $this->assertSame(Outcome::Paused, $outcome->outcome);
    $question = $outcome->question;
    $this->assertInstanceOf(PendingQuestion::class, $question);
    $this->assertNotSame('', $question->headline);
    $this->assertNotSame([], $question->detail);
    $this->assertNotSame([], $question->options);
    // Not the old wording, and specific to the boundary it is holding at.
    $this->assertStringNotContainsString(
      'Continue to the next phase?',
      $question->question,
    );
    $this->assertStringContainsString('spec', $question->question);
  }

  /**
   * Each phase asks its own question.
   *
   * What a phase hands over differs, so what is worth asking differs. If
   * every boundary asked the same thing, the mode would be a form again.
   */
  public function testEachPhaseAsksSomethingDifferent(): void {
    $engine = $this->engine($this->recordingSink());
    $asked = [];

    foreach (Phase::cases() as $phase) {
      // Seekers off: the inspection checkpoint deliberately precedes the
      // hold at code and complete, and this test is about the hold.
      $outcome = $engine->runPhase(
        $this->begin(['mode' => 'interactive', 'seekers' => ['on' => FALSE]]),
        $phase,
        '/tmp',
        self::NOW,
      );
      $question = $outcome->question;
      $this->assertInstanceOf(PendingQuestion::class, $question);
      $this->assertSame($phase, $question->phase);
      $asked[] = $question->question;
      $this->assertNotSame([], $question->options);
    }

    $this->assertCount(count(Phase::cases()), array_unique($asked));
  }

  /**
   * The conversation is in run state before the sink is told.
   *
   * Same ordering guarantee the original pause had, now with more to lose:
   * the options are what a structured-question surface renders, so a pause
   * delivered without them recorded would degrade to a free-text prompt.
   */
  public function testTheConversationIsPersistedNotJustEmitted(): void {
    $sink = $this->recordingSink();
    $engine = $this->engine($sink);

    $outcome = $engine->runPhase(
      $this->begin(['mode' => 'interactive', 'seekers' => ['on' => FALSE]]),
      Phase::Code,
      '/tmp',
      self::NOW,
    );

    $awaiting = $outcome->state->awaiting;
    $this->assertIsArray($awaiting);
    $this->assertArrayHasKey('options', $awaiting);
    $this->assertNotSame([], $awaiting['options']);
    $this->assertArrayHasKey('headline', $awaiting);

    $stored = PendingQuestion::fromArray($awaiting);
    $this->assertNotNull($stored);
    $this->assertSame($sink->emitted[0]->options, $stored->options);
  }

  /**
   * A lever file that still says `pair` gets the conversation.
   *
   * End to end through the real config loader, because this is the promise
   * the alias makes: a site provisioned before the rename keeps working, and
   * gets the better behaviour rather than the old one.
   */
  public function testTheLegacyPairLeverStillHolds(): void {
    $engine = $this->engine($this->recordingSink());

    $state = $this->begin(['mode' => 'pair']);
    $this->assertSame(Mode::Interactive, $state->mode);

    $outcome = $engine->runPhase($state, Phase::Plan, '/tmp', self::NOW);

    $this->assertSame(Outcome::Paused, $outcome->outcome);
    $question = $outcome->question;
    $this->assertInstanceOf(PendingQuestion::class, $question);
    $this->assertNotSame([], $question->options);
  }

  /**
   * A lever file that still says `automated` runs straight through.
   */
  public function testTheLegacyAutomatedLeverStillRunsThrough(): void {
    $sink = $this->recordingSink();
    $state = $this->begin(['mode' => 'automated']);
    $this->assertSame(Mode::Agentic, $state->mode);

    $outcome = $this->engine($sink)
      ->runPhase($state, Phase::Plan, '/tmp', self::NOW);

    $this->assertSame(Outcome::Advanced, $outcome->outcome);
    $this->assertSame([], $sink->emitted);
  }

  /**
   * An engine with a passing executor and no site.
   *
   * @param \Droost\Workflow\Mode\QuestionSinkInterface $sink
   *   The sink to use.
   *
   * @return \Droost\Workflow\Mode\ModeEngine
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
   * @return object{emitted: list<\Droost\Workflow\Mode\PendingQuestion>}&\Droost\Workflow\Mode\QuestionSinkInterface
   *   The double.
   */
  private function recordingSink(): object {
    return new class() implements QuestionSinkInterface {

      /**
       * Questions this sink was given, in order.
       *
       * @var list<\Droost\Workflow\Mode\PendingQuestion>
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
   * An executor that fails everything it is asked to run.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The double.
   */
  private function failingExecutor(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

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
  }

  /**
   * An executor that passes everything and counts its calls.
   *
   * @return object{count: int}&\Droost\Workflow\Gate\GateExecutorInterface
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
   * @return \Droost\Workflow\State\RunState
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
