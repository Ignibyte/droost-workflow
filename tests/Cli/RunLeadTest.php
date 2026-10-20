<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunOutcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * Who may finish a run: human-led or autonomous (owner, 2026-10-20).
 *
 * A live run's four check-ins, complete's included, were all answered by the
 * agent's own session (F-335). In a human-led run the agent may answer plan's,
 * code's and test's holds, and the complete hold is a human's, from the
 * operator's terminal. An autonomous run is the agent's to finish.
 */
final class RunLeadTest extends WorkflowTestCase {

  /**
   * The lead follows the mode unless the lever names one.
   */
  public function testLeadFollowsTheModeUnlessNamed(): void {
    $interactive = $this->makeRootWithConfig("preset: custom\nmode: interactive\n");
    $this->facade()->run($interactive);
    $this->assertSame(RunState::LEAD_HUMAN, $this->leadOf($interactive));

    $agentic = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $this->facade()->run($agentic);
    $this->assertSame(RunState::LEAD_AUTONOMOUS, $this->leadOf($agentic));

    $named = $this->makeRootWithConfig("preset: custom\nmode: agentic\nlead: human-led\n");
    $this->facade()->run($named);
    $this->assertSame(RunState::LEAD_HUMAN, $this->leadOf($named));
  }

  /**
   * A human-led run holds at complete even in agentic mode, for a human.
   */
  public function testHumanLedRunIsFinishedFromTheTerminal(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nlead: human-led\nseekers:\n  on: false\n");

    $outcome = $this->toEnd($root);
    $this->assertSame(Outcome::Paused, $outcome->outcome, 'complete holds in a human-led run');
    $this->assertSame('complete', $outcome->state->awaiting['phase'] ?? NULL);

    [$code, $out] = $this->cli(['answer', 'finish it'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'no terminal: the agent cannot finish it');
    $this->assertStringContainsString('human-led', $out);
    $this->assertNotNull((new RunStateStore($root))->load()?->awaiting, 'still waiting, nothing recorded');

    $done = $this->facade()->answer($root, 'finish it', 'terminal');
    $this->assertSame(Outcome::Completed, $done->outcome);
  }

  /**
   * In a human-led run the agent still answers plan's hold.
   */
  public function testAgentAnswersTheEarlierHolds(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\n");
    $this->assertSame(Outcome::Paused, $this->facade()->run($root)->outcome);

    [$code, $out] = $this->cli(['answer', 'build it'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
  }

  /**
   * An autonomous run is the agent's to finish.
   */
  public function testAutonomousRunIsTheAgentsToFinish(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nlead: autonomous\nseekers:\n  on: false\n");
    $this->facade()->run($root);
    for ($i = 0; $i < 12; $i++) {
      $state = (new RunStateStore($root))->load();
      if ($state === NULL || $state->currentPhase === NULL) {
        break;
      }
      if ($state->awaiting !== NULL) {
        [$code, $out] = $this->cli(['answer', 'go on'], $root);
        $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
        continue;
      }
      $this->facade()->run($root);
    }
    $this->assertNull((new RunStateStore($root))->load()?->currentPhase, 'the agent finished it');
  }

  /**
   * Making a run autonomous is the operator's; making it human-led is anyone's.
   */
  public function testOnlyTheTerminalMakesRunAutonomous(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\n");

    [$code, $out] = $this->cli(['run', '--lead=autonomous'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'no terminal: not autonomous');
    $this->assertNull((new RunStateStore($root))->load(), 'and no run began');

    $this->facade()->run($root);
    [$code] = $this->cli(['lead', 'autonomous'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertSame(RunState::LEAD_HUMAN, $this->leadOf($root));

    $agentic = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $this->facade()->run($agentic);
    [$code, $out] = $this->cli(['lead', 'human-led'], $agentic);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $this->assertSame(RunState::LEAD_HUMAN, $this->leadOf($agentic));
  }

  /**
   * The open run's lead, read back from disk.
   *
   * @param string $root
   *   The project.
   *
   * @return string|null
   *   The lead, or NULL with no run.
   */
  private function leadOf(string $root): ?string {
    return (new RunStateStore($root))->load()?->lead;
  }

  /**
   * Drives a run until it holds or ends.
   *
   * @param string $root
   *   The project.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
   *   The last outcome.
   */
  private function toEnd(string $root): RunOutcome {
    $outcome = $this->facade()->run($root);
    for ($i = 0; $i < 6 && $outcome->outcome === Outcome::Advanced; $i++) {
      $outcome = $this->facade()->run($root);
    }
    return $outcome;
  }

  /**
   * Runs the dispatcher with no terminal, both streams into one.
   *
   * @param list<string> $argv
   *   The verb and its arguments.
   * @param string $root
   *   The project.
   *
   * @return array{int, string}
   *   The exit code and the output.
   */
  private function cli(array $argv, string $root): array {
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-20T00:00:00+00:00', static fn (): string => 'run-lead');

    return [$dispatcher->dispatch($argv, $root), implode("\n", $lines)];
  }

  /**
   * A facade whose gates all pass.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };

    return new WorkflowFacade($executor, new RunStateOnlySink(), static fn (): string => '2026-10-20T00:00:00+00:00', static fn (): string => 'run-lead');
  }

}
