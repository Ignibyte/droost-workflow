<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Mode;

use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\ModeEngine;
use Droost\Workflow\Mode\PendingQuestion;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The run stops and ASKS rather than looping without limit.
 *
 * A gate that fails spends a retry and ends the phase by itself. The checks
 * that are not gates — declarations, contributed checks, spec conditions —
 * cost nothing to retry, which is right: the agent is meant to go away, change
 * something real and come back, and a legitimate correction cycle can be long.
 *
 * But nothing counted, so a phase could be re-entered at zero price for ever,
 * and the blocks come from the agent itself, which will keep trying. The engine
 * can see the count and nothing else; the person watching can tell a slow run
 * from a stuck one at a glance. So past a ceiling it stops and asks them.
 *
 * The whole mechanism — the ceiling, the question, the marker that makes an
 * answer buy a fresh budget — shipped with no test. This is it, and it runs
 * each case in BOTH modes, because "does this hold in interactive too" was an
 * open question nobody had answered by running it.
 */
final class BlockCeilingTest extends TestCase {

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-ceiling-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->root)) {
      exec('rm -rf ' . escapeshellarg($this->root));
    }
  }

  /**
   * Both run modes, so a case cannot hold in one and not the other.
   *
   * @return array<string, array{string}>
   *   The mode name.
   */
  public static function modes(): array {
    return ['agentic' => ['agentic'], 'interactive' => ['interactive']];
  }

  /**
   * A run in the given mode, and the engine that drives it.
   *
   * @param string $mode
   *   The mode name.
   *
   * @return array{\Droost\Workflow\Mode\ModeEngine, \Droost\Workflow\State\RunState}
   *   The engine and the state.
   */
  private function engineFor(string $mode): array {
    $config = WorkflowConfig::fromArray(['mode' => $mode, 'preset' => 'medium'], 'test');
    $state = RunState::begin('run-1', '2026-09-13T00:00:00+00:00', $config);
    $this->assertSame(
      $mode === 'agentic' ? Mode::Agentic : Mode::Interactive,
      $state->mode,
      'the fixture really is in the mode it says',
    );
    // `stuckOutcome()` runs no gate — it reads the store and decides. An
    // executor that would explode if called is the honest double: if this ever
    // starts running gates, the test says so rather than quietly measuring
    // something else.
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        throw new \LogicException('stuckOutcome() must not run a gate.');
      }

    };
    $engine = new ModeEngine(
      new GateRunner($executor, new NullSiteDriver()),
      new RunStateOnlySink(),
    );

    return [$engine, $state];
  }

  /**
   * Records one blocked non-gate check.
   *
   * @param \Droost\Workflow\Evidence\EvidenceStore $store
   *   The store.
   * @param int $times
   *   How many.
   */
  private function block(EvidenceStore $store, int $times): void {
    for ($i = 0; $i < $times; $i++) {
      $store->record('run-1', 'code', new CheckRecord(
        kind: 'check',
        name: 'declared_scope',
        state: CheckState::Blocked,
        fault: Fault::Agent,
        summary: 'an undeclared file was touched',
      ));
    }
  }

  /**
   * Below the ceiling the run is left alone.
   *
   * The ceiling is deliberately far above ten: the blocks come from the agent,
   * and a correction cycle that takes a dozen attempts is work, not a wedge.
   * Pausing early would interrupt exactly the runs that are going fine.
   */
  #[DataProvider('modes')]
  public function testBelowTheCeilingNothingIsAsked(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $store = new EvidenceStore($this->root);
    $store->upsertRun('run-1', ['preset' => 'medium']);
    $this->block($store, 12);

    $this->assertNull(
      $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00'),
      'a dozen corrections is a run doing its job',
    );
  }

  /**
   * At the ceiling it pauses and asks a question a human can answer.
   */
  #[DataProvider('modes')]
  public function testAtTheCeilingItStopsAndAsks(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $store = new EvidenceStore($this->root);
    $store->upsertRun('run-1', ['preset' => 'medium']);
    $this->block($store, 60);

    $outcome = $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00');

    $this->assertNotNull($outcome, sprintf('the ceiling holds in %s mode too', $mode));
    $this->assertSame(Outcome::Paused, $outcome->outcome);
    $this->assertNotNull($outcome->question, 'it asks rather than merely stopping');
    $this->assertStringContainsString('stuck', $outcome->question->question);
    $this->assertNotSame(
      [],
      $outcome->question->options,
      'and offers answers, so the human is not asked to compose one',
    );
  }

  /**
   * Answering buys another budget instead of another pause.
   *
   * This is the failure the marker exists to prevent, and it is worse than the
   * one the ceiling exists to prevent: counting from the start of the phase
   * meant "keep going" paused again on the very next invocation, and then for
   * ever. An unbounded failure loop at least keeps working; an unbounded PAUSE
   * loop asks a human the same question until they give up.
   */
  #[DataProvider('modes')]
  public function testAnsweringBuysAnotherBudget(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $store = new EvidenceStore($this->root);
    $store->upsertRun('run-1', ['preset' => 'medium']);
    $this->block($store, 60);

    $paused = $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00');
    $this->assertNotNull($paused);

    // The very next invocation, with nothing else changed.
    $this->assertNull(
      $engine->stuckOutcome($paused->state, Phase::Code, $this->root, NULL, '2026-09-13T02:00:00+00:00'),
      'the answer bought a fresh budget; asking again immediately is not asking',
    );

    // And the ceiling still holds on the NEXT sixty, so the budget is a budget
    // and not an amnesty.
    $this->block($store, 60);
    $this->assertNotNull(
      $engine->stuckOutcome($paused->state, Phase::Code, $this->root, NULL, '2026-09-13T03:00:00+00:00'),
      'a second wedge is asked about too',
    );
  }

  /**
   * A failing GATE is not what this counts.
   *
   * Gates have their own budget — `max_gate_retries` — and end the phase
   * themselves. Counting them here would pause a run that was already stopping,
   * and would hide the gate failure behind a question about something else.
   */
  #[DataProvider('modes')]
  public function testGateFailuresAreNotCountedHere(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $store = new EvidenceStore($this->root);
    $store->upsertRun('run-1', ['preset' => 'medium']);
    for ($i = 0; $i < 80; $i++) {
      $store->record('run-1', 'code', new CheckRecord(
        kind: 'gate',
        name: 'phpstan',
        state: CheckState::Blocked,
        fault: Fault::Agent,
        summary: '3 errors',
        exitCode: 1,
      ));
    }

    $this->assertNull(
      $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00'),
      'a gate spends its own retries and ends the phase without this',
    );
  }

  /**
   * The count is per phase, so one phase's wedge does not pause another.
   */
  #[DataProvider('modes')]
  public function testTheCountIsScopedToItsPhase(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $store = new EvidenceStore($this->root);
    $store->upsertRun('run-1', ['preset' => 'medium']);
    $this->block($store, 60);

    $this->assertNotNull(
      $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00'),
    );
    $this->assertNull(
      $engine->stuckOutcome($state, Phase::Test, $this->root, NULL, '2026-09-13T01:00:00+00:00'),
      'test has not been blocked once',
    );
  }

  /**
   * With no store there is no count, and the run is not held on one.
   *
   * The phase that could not write its evidence already says so, loudly. A
   * second failure here would only replace a clear message with a confusing
   * one.
   */
  #[DataProvider('modes')]
  public function testNoStoreMeansNoCeiling(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);

    $this->assertNull(
      $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00'),
    );
  }

  /**
   * Answering "stop here" stops the run. It used not to.
   *
   * The ceiling's whole product is the question, and the question offers two
   * answers. Nothing read the reply: a walk answered "stop here" and then
   * "banana", and both printed `answered — now at code` and carried on. A
   * question whose answer changes nothing is theatre, and it sits behind the
   * one wall that exists because the engine cannot tell a slow correction cycle
   * from a wedge — only the person watching can, and this is where they say so.
   */
  #[DataProvider('modes')]
  public function testAnsweringStopEndsTheRun(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $store = new EvidenceStore($this->root);
    $store->upsertRun('run-1', ['preset' => 'medium']);
    $this->block($store, 60);

    $paused = $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00');
    $this->assertNotNull($paused);

    $stopped = $engine->answer($paused->state, 'stop here — this is stuck and I will look at it', '2026-09-13T02:00:00+00:00');

    $this->assertSame(
      PhaseStatus::Failed,
      $stopped->phases['code'] ?? NULL,
      'the phase ends, rather than the answer being filed away',
    );
  }

  /**
   * But answering "keep going" does not.
   *
   * The other half matters as much: an answer that ends a run somebody meant to
   * continue is worse than one that is ignored.
   *
   * @param string $mode
   *   The run mode.
   */
  #[DataProvider('modes')]
  public function testAnsweringKeepGoingContinues(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $store = new EvidenceStore($this->root);
    $store->upsertRun('run-1', ['preset' => 'medium']);
    $this->block($store, 60);

    $paused = $engine->stuckOutcome($state, Phase::Code, $this->root, NULL, '2026-09-13T01:00:00+00:00');
    $this->assertNotNull($paused);

    foreach ([
      'keep going — the work is progressing and I expect it to clear',
      'banana',
      '',
    ] as $answer) {
      $carried = $engine->answer($paused->state, $answer, '2026-09-13T02:00:00+00:00');
      $this->assertNotSame(
        PhaseStatus::Failed,
        $carried->phases['code'] ?? NULL,
        sprintf('"%s" is not a stop', $answer),
      );
      $this->assertNull($carried->awaiting, 'and the question is answered either way');
    }
  }

  /**
   * And an ordinary conversation hold is never ended by its answer.
   *
   * Those questions ASK for consent to advance, so answering one is the act.
   * Reading "stop" out of a phase-advance reply would end runs that were
   * going fine.
   *
   * @param string $mode
   *   The run mode.
   */
  #[DataProvider('modes')]
  public function testConversationAnswersNeverEndTheRun(string $mode): void {
    [$engine, $state] = $this->engineFor($mode);
    $question = new PendingQuestion(
      Phase::Code,
      'Shall I advance?',
      'all gates passed',
      '2026-09-13T01:00:00+00:00',
    );
    $waiting = $state->awaiting($question->toArray());

    $answered = $engine->answer($waiting, 'stop here', '2026-09-13T02:00:00+00:00');

    $this->assertNotSame(
      PhaseStatus::Failed,
      $answered->phases['code'] ?? NULL,
    );
  }

}
