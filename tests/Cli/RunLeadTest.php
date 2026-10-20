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
    $this->assertStringContainsString('Claude Code\'s `!`', $out, 'and that `!` is no terminal either');
    $this->assertNotNull((new RunStateStore($root))->load()?->awaiting, 'still waiting, nothing recorded');

    $done = $this->facade()->answer($root, 'finish it', 'terminal');
    $this->assertSame(Outcome::Completed, $done->outcome);
  }

  /**
   * The human's answer to the agent's question finishes a human-led run.
   *
   * Owner, 2026-10-20: approval comes through the session, not a terminal.
   * The guard records the dialog's RESULT (never its input) while the run
   * waits at complete; the agent's `answer` then takes the human's words,
   * and is refused when there are none, or when the question wall is not
   * wired (an answer in the record could then be the agent's).
   */
  public function testHumanAnswerInTheSessionFinishesIt(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nlead: human-led\nseekers:\n  on: false\n");
    $this->assertSame(Outcome::Paused, $this->toEnd($root)->outcome);
    $dir = (new RunStateStore($root))->directory();

    // The agent fills the dialog's answers itself: refused before it opens.
    $this->wireQuestionWall($root);
    [$exit, , $stderr] = $this->guard($root, 'question', [
      'tool_name' => 'AskUserQuestion',
      'tool_input' => ['questions' => [['question' => 'Finish?']], 'answers' => ['Finish?' => 'finish it']],
    ]);
    $this->assertSame(2, $exit, 'a pre-answered question is the agent answering itself');
    $this->assertStringContainsString('already filled in', $stderr);
    $this->assertFileDoesNotExist($dir . '/hold-answers.jsonl');

    // Nor can it write the human's answer itself, by any door.
    $forges = [
      'echo x >> droost/droost-workflow/hold-answers.jsonl',
      'cd droost/droost-workflow && printf x > hold-answers.jsonl',
    ];
    foreach ($forges as $forge) {
      [$exit] = $this->guard($root, 'operator-commands', ['tool_name' => 'Bash', 'tool_input' => ['command' => $forge]]);
      $this->assertSame(2, $exit, $forge);
    }
    $write = ['file_path' => $dir . '/hold-answers.jsonl', 'content' => 'x'];
    [$exit] = $this->guard($root, 'pre-tool-use', ['tool_name' => 'Write', 'tool_input' => $write]);
    $this->assertSame(2, $exit, 'the Write tool');
    $this->assertFileDoesNotExist($dir . '/hold-answers.jsonl');

    // No human answer yet: the agent's answer is refused.
    [$code] = $this->cli(['answer', 'finish it'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);

    // A yes to some other question is no approval to finish.
    $this->guard($root, 'answers', [
      'tool_name' => 'AskUserQuestion',
      'tool_input' => ['questions' => [['question' => 'Shall I run lint?']]],
      'tool_response' => ['answers' => ['Shall I run lint?' => 'yes']],
    ]);
    $this->assertFileDoesNotExist($dir . '/hold-answers.jsonl', 'only the hold\'s own question is the hold\'s answer');

    // The human answers the hold's own question; the guard records it.
    $question = (new RunStateStore($root))->load()?->awaiting['question'] ?? NULL;
    $this->assertIsString($question);
    $this->guard($root, 'answers', [
      'tool_name' => 'AskUserQuestion',
      'tool_input' => ['questions' => [['question' => $question]]],
      'tool_response' => ['answers' => [$question => 'Finish it']],
    ]);
    $this->assertFileExists($dir . '/hold-answers.jsonl');

    [$code, $out] = $this->cli(['answer', 'whatever the agent says'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    // The run finished, and went to history with the exchange.
    $archived = glob($dir . '/history/*.json') ?: [];
    $this->assertCount(1, $archived, 'the run completed and was archived');
    $record = json_decode((string) file_get_contents($archived[0]), TRUE);
    $this->assertIsArray($record);
    $history = is_array($record['qa_history'] ?? NULL) ? $record['qa_history'] : [];
    $last = end($history);
    $this->assertIsArray($last);
    $this->assertSame('Finish it', $last['answer'] ?? NULL, 'the human\'s words, not the agent\'s');
    $this->assertSame('question', $last['via'] ?? NULL);
  }

  /**
   * Without the question wall wired, a recorded answer is not taken.
   */
  public function testUnwiredQuestionWallTakesNoAnswer(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nlead: human-led\nseekers:\n  on: false\n");
    $this->toEnd($root);
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    file_put_contents($state->awaiting === NULL ? '/dev/null' : (new RunStateStore($root))->directory() . '/hold-answers.jsonl', (string) json_encode([
      'run' => $state->runId,
      'phase' => 'complete',
      'asked_at' => $state->awaiting['asked_at'] ?? '',
      'answers' => ['Finish?' => 'Finish it'],
    ]) . "\n");

    [$code] = $this->cli(['answer', 'finish it'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'no question wall, no answer taken from the record');
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
   * Wires the guard's question wall and answer recorder, as init does.
   *
   * @param string $root
   *   The project.
   */
  private function wireQuestionWall(string $root): void {
    @mkdir($root . '/.claude', 0755, TRUE);
    $entry = static fn (string $mode): array => [
      'matcher' => 'AskUserQuestion',
      'hooks' => [
        [
          'type' => 'command',
          'command' => 'php "$CLAUDE_PROJECT_DIR/bin/droost-guard" ' . $mode . ' --harness=claude',
        ],
      ],
    ];
    $hooks = ['PreToolUse' => [$entry('question')], 'PostToolUse' => [$entry('answers')]];
    file_put_contents($root . '/.claude/settings.json', (string) json_encode(['hooks' => $hooks]));
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
