<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Intake\IntakeService;
use Droost\Workflow\Intake\IntakeStore;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The guard keeps the human's part of an intake the human's.
 *
 * It records each answer AskUserQuestion came back with while an intake is
 * open (`answers` mode, after the call), refuses the agent the files that
 * record holds, and refuses it the operator's intake verbs, run or no run.
 */
final class GuardIntakeTest extends WorkflowTestCase {

  /**
   * A project with an intake open.
   *
   * @return array{string, string}
   *   The project and the intake's id.
   */
  private function intake(): array {
    $root = $this->makeRootWithConfig("preset: custom\nmode: interactive\nseekers:\n  on: false\n");
    $state = (new IntakeService($root))->start('build me a site off this html', 'src');
    return [$root, $state->id];
  }

  /**
   * AskUserQuestion's finished call, as Claude Code hands it to the hook.
   *
   * @param array<string, string> $answers
   *   Each answer by its question.
   *
   * @return array<string, mixed>
   *   The payload.
   */
  private function asked(array $answers): array {
    $questions = array_map(static fn (string $q): array => [
      'question' => $q,
      'header' => 'Intake',
      'options' => [['label' => 'Yes'], ['label' => 'No']],
      'multiSelect' => FALSE,
    ], array_keys($answers));
    return [
      'tool_name' => 'AskUserQuestion',
      'tool_input' => ['questions' => $questions],
      'tool_response' => ['questions' => $questions, 'answers' => $answers, 'annotations' => []],
    ];
  }

  /**
   * Answers given while an intake is open are on record, as the human gave.
   */
  public function testAnswersAreRecordedWhileAnIntakeIsOpen(): void {
    [$root, $id] = $this->intake();
    $asked = $this->asked(['Who edits the events?' => 'Staff editors', 'Is the data real?' => 'No']);
    [$code] = $this->guard($root, 'answers', $asked);
    $this->assertSame(0, $code, 'recording never refuses');
    $this->assertSame([
      ['question' => 'Who edits the events?', 'answer' => 'Staff editors'],
      ['question' => 'Is the data real?', 'answer' => 'No'],
    ], (new IntakeStore($root))->answers($id));
  }

  /**
   * With no intake open, a question is conversation and nothing is kept.
   */
  public function testNothingIsKeptWithoutAnOpenIntake(): void {
    $root = $this->makeRoot();
    [$code] = $this->guard($root, 'answers', $this->asked(['Shall I commit?' => 'Yes']));
    $this->assertSame(0, $code);
    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/' . IntakeStore::ANSWERS_FILE);

    [$root, $id] = $this->intake();
    $service = new IntakeService($root);
    $state = $service->store()->load();
    $this->assertNotNull($state);
    $service->store()->save($state->abandoned('test', '2026-10-08T00:00:00+00:00'));
    $this->guard($root, 'answers', $this->asked(['Shall I commit?' => 'Yes']));
    $this->assertSame([], $service->store()->answers($id), 'a closed intake records nothing');
  }

  /**
   * The intake's record is not the agent's to write, by tool or by shell.
   *
   * There yet or not, run or no run.
   */
  public function testTheRecordIsRefusedToTheAgent(): void {
    [$root] = $this->intake();
    foreach ([IntakeStore::ANSWERS_FILE, IntakeStore::STATE_FILE] as $file) {
      [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => $root . '/droost/droost-workflow/' . $file, 'content' => '{}'],
      ]);
      $this->assertSame(2, $exit, 'Write ' . $file . ' must be refused');
      $this->assertStringContainsString('the intake\'s record', $stderr);
    }
    [$exit, , $stderr] = $this->guard($root, 'operator-commands', [
      'tool_input' => ['command' => 'echo \'{"intake":"x","question":"q","answer":"a"}\' >> droost/droost-workflow/intake-answers.jsonl'],
    ]);
    $this->assertSame(2, $exit, 'a shell append to the answers must be refused');
    $this->assertStringContainsString('an answer the human never gave', $stderr);

    // Before the file exists, too: written first by hand, it would be the
    // record the guard appends to.
    $fresh = $this->makeRoot();
    [$exit] = $this->guard($fresh, 'operator-commands', [
      'tool_input' => ['command' => 'printf x > droost/droost-workflow/intake-answers.jsonl'],
    ]);
    $this->assertSame(2, $exit);
  }

  /**
   * Approving, abandoning and answering are the operator's; reading is not.
   */
  public function testTheOperatorsIntakeVerbsAreRefusedFromTheAgentsShell(): void {
    [$root] = $this->intake();
    foreach ([
      'vendor/bin/droost-workflow intake approve',
      'droost-workflow intake abandon "changed my mind"',
      'vendor/bin/droost-workflow intake answer "Who edits?" "Me"',
      'ddev drush droost:workflow:intake approve',
      'drush droost:workflow:intake answer "Who edits?" "Me"',
      'ddev drush dwfin approve',
    ] as $command) {
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command . ' must be refused');
      $this->assertStringContainsString("OPERATOR's command", $stderr);
      $this->assertStringContainsString(str_contains($command, 'droost-workflow ') ? '`droost-workflow intake ' : '`drush droost:workflow:intake ', $stderr, $command);
      $this->assertStringNotContainsString('`! ', $stderr, $command . ': no `!` hand-over (F-256)');
    }
    foreach ([
      'vendor/bin/droost-workflow intake check',
      'vendor/bin/droost-workflow intake status',
      'ddev drush droost:workflow:intake audit --url=http://localhost:5173 --files=src',
      'cat droost/droost-workflow/intake-answers.jsonl',
    ] as $command) {
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(0, $exit, $command . ' is anyone\'s: ' . $stderr);
    }
  }

  /**
   * The pack wires the recorder after AskUserQuestion, and nowhere before.
   */
  public function testTheRecorderIsWiredAfterTheQuestion(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    $settings = json_decode((string) file_get_contents($root . '/.claude/settings.json'), TRUE);
    $this->assertIsArray($settings);
    $this->assertIsArray($settings['hooks']);
    $found = [];
    foreach ($settings['hooks'] as $event => $entries) {
      foreach (is_array($entries) ? $entries : [] as $entry) {
        if (!is_array($entry)) {
          continue;
        }
        foreach (is_array($entry['hooks'] ?? NULL) ? $entry['hooks'] : [] as $hook) {
          if (is_array($hook) && is_string($hook['command'] ?? NULL) && str_ends_with($hook['command'], ' answers')) {
            $found[] = [$event, $entry['matcher'] ?? NULL];
          }
        }
      }
    }
    $this->assertSame([['PostToolUse', 'AskUserQuestion']], $found);
  }

}
