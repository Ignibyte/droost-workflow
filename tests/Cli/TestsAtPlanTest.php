<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\State\TestsChoice;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * Plan asks the human about tests (`tests.ask`, owner 2026-10-30).
 *
 * No unit tests and no saved browser spec unless the human says yes at plan.
 * In a human-led run the answer is theirs: taken from the dialog the guard
 * recorded, or typed in the operator's terminal, never from the agent's
 * words. An autonomous run takes the rule's default, neither.
 */
final class TestsAtPlanTest extends WorkflowTestCase {

  /**
   * The lever, read; anything but `ask` refused.
   */
  public function testTheLeverIsRead(): void {
    $root = $this->makeRootWithConfig("preset: custom\ntests:\n  ask: true\n");
    $this->assertTrue(WorkflowConfig::load($root)->askTests);
    $levers = $this->facade()->status($root)['levers'] ?? NULL;
    $this->assertIsArray($levers);
    $this->assertSame(['ask' => TRUE], $levers['tests'] ?? NULL, 'status says so before a run');
    $this->assertFalse(WorkflowConfig::load($this->makeRootWithConfig("preset: custom\n"))->askTests);

    try {
      WorkflowConfig::load($this->makeRootWithConfig("preset: medium\ntests:\n  ask: true\n"));
      $this->fail('tests.ask at medium would disarm a mandatory gate');
    }
    catch (ConfigError $e) {
      $this->assertStringContainsString('tests.ask needs `preset: custom`', $e->getMessage());
    }

    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('unknown tests option(s) unit (known: ask)');
    WorkflowConfig::load($this->makeRootWithConfig("preset: custom\ntests:\n  unit: true\n"));
  }

  /**
   * Plan's hold carries the two questions; the agent cannot answer them.
   *
   * Refused with no dialog, and with a dialog that asked only plan's own
   * question; taken when the human answered all three.
   */
  public function testTheHumanAnswersInTheDialog(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\ntests:\n  ask: true\n");
    $this->wireQuestionWall($root);
    $paused = $this->facade()->run($root);
    $this->assertSame(Outcome::Paused, $paused->outcome);
    $awaiting = $paused->state->awaiting ?? [];
    $this->assertSame('plan', $awaiting['phase'] ?? NULL);
    $this->assertSame(TestsChoice::questions(), $awaiting['also'] ?? NULL, 'the test questions ride with plan\'s');

    [$code, $err] = $this->cli(['answer', 'Looks right — start building'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'no dialog: refused');
    $this->assertStringContainsString(TestsChoice::UNIT_QUESTION, $err, 'the refusal names the test questions');
    $this->assertStringContainsString(TestsChoice::SPEC_QUESTION, $err);

    $question = $awaiting['question'] ?? NULL;
    $this->assertIsString($question);
    $this->guard($root, 'answers', [
      'tool_name' => 'AskUserQuestion',
      'tool_response' => ['answers' => [$question => 'Looks right — start building']],
    ]);
    [$code] = $this->cli(['answer', 'Looks right — start building'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'plan\'s question alone: the test questions unasked');

    $this->guard($root, 'answers', [
      'tool_name' => 'AskUserQuestion',
      'tool_response' => [
        'answers' => [
          $question => 'Looks right — start building',
          TestsChoice::UNIT_QUESTION => 'No unit tests',
          TestsChoice::SPEC_QUESTION => 'Yes — save it as a Playwright spec',
        ],
      ],
    ]);
    [$code, $out] = $this->cli(['answer', 'whatever the agent says'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $this->assertStringContainsString('tests (dialog): no unit tests; the browser check saved as a Playwright spec', $out);

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertSame('code', $state->currentPhase?->value);
    $this->assertFalse($state->tests['unit'] ?? NULL);
    $this->assertTrue($state->tests['spec'] ?? NULL);
    $this->assertSame('dialog', $state->tests['by'] ?? NULL);
    $this->assertFalse($state->resolvedGates['phpunit']['on'] ?? NULL, 'no unit tests: phpunit off');
    $this->assertTrue($state->resolvedGates['playwright']['on'] ?? NULL, 'a saved spec: playwright on');
    $this->assertTrue($state->resolvedGates['playwright']['required'] ?? NULL, 'and required');
  }

  /**
   * The operator's terminal says the choices as flags; the agent may not.
   */
  public function testTheTerminalMayUseFlags(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\ntests:\n  ask: true\n");
    $this->facade()->run($root);

    $this->expectException(\InvalidArgumentException::class);
    try {
      $this->facade()->answer($root, 'go', 'agent', ['unit' => TRUE]);
    }
    finally {
      $answered = $this->facade()->answer($root, 'Looks right — start building', 'terminal', [
        'unit' => TRUE,
        'spec' => FALSE,
      ]);
      $this->assertSame('terminal', $answered->state->tests['by'] ?? NULL);
      $this->assertTrue($answered->state->resolvedGates['phpunit']['on'] ?? NULL, 'unit tests: phpunit on');
      $this->assertTrue($answered->state->resolvedGates['phpunit']['in_diff'] ?? NULL, 'and a class needs its test');
      $this->assertFalse($answered->state->resolvedGates['playwright']['on'] ?? NULL, 'no saved spec: the suite gate off');
    }
  }

  /**
   * An autonomous run is asked nothing and writes neither.
   */
  public function testAutonomousRunTakesTheDefault(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\ntests:\n  ask: true\n");
    $outcome = $this->facade()->run($root);

    $this->assertSame(RunState::LEAD_AUTONOMOUS, $outcome->state->lead);
    $this->assertSame('code', $outcome->state->currentPhase?->value, 'plan did not hold');
    $tests = $outcome->state->tests;
    $this->assertSame([FALSE, FALSE, 'default'], [$tests['unit'] ?? NULL, $tests['spec'] ?? NULL, $tests['by'] ?? NULL]);
    $this->assertFalse($outcome->state->resolvedGates['phpunit']['on'] ?? NULL);
  }

  /**
   * Without the lever nothing changes: plan's hold is the agent's to answer.
   */
  public function testWithoutTheLeverPlanIsUnchanged(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\n");
    $paused = $this->facade()->run($root);
    $this->assertArrayNotHasKey('also', $paused->state->awaiting ?? []);

    [$code, $out] = $this->cli(['answer', 'build it'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $this->assertSame([], (new RunStateStore($root))->load()?->tests);
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
    $dispatcher = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-30T00:00:00+00:00', static fn (): string => 'run-tests');

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

    return new WorkflowFacade($executor, new RunStateOnlySink(), static fn (): string => '2026-10-30T00:00:00+00:00', static fn (): string => 'run-tests');
  }

}
