<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\State\OperatorApproval;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * An operator step a human approved in the session (owner, 2026-10-30).
 *
 * The agent runs the operator command; with no terminal and no approval it is
 * refused with the question to ask. The human answers it in AskUserQuestion,
 * the guard records the dialog's result, and the same command then runs once.
 * Never on the agent's word: a different command, a decline, a stale or spent
 * approval, or a project whose question hooks are not wired take nothing.
 */
final class OperatorApprovalTest extends WorkflowTestCase {

  /**
   * The refusal names the question; the dialog's yes runs the command once.
   */
  public function testApprovedInTheDialogRunsOnce(): void {
    $root = $this->wiredRoot();
    $command = OperatorApproval::command(['bypass', 'TICKET-1: module READMEs']);
    $this->assertSame('droost workflow:bypass TICKET-1: module READMEs', $command);

    [$code, $out] = $this->cli(['bypass', 'TICKET-1: module READMEs'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'no terminal, no approval');
    $this->assertStringContainsString(OperatorApproval::question($command), $out, 'the refusal names the question, word for word');

    $this->approve($root, $command);
    [$code, $out] = $this->cli(['bypass', 'TICKET-1: module READMEs'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $grant = json_decode((string) file_get_contents($this->stateDir($root) . '/bypass.json'), TRUE);
    $this->assertIsArray($grant);
    $this->assertSame('dialog', $grant['granted_by'] ?? NULL, 'the record says a dialog approved it');

    [$code] = $this->cli(['bypass', 'TICKET-1: module READMEs'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'one approval, one use');
  }

  /**
   * Another command, a decline, a stale approval or unwired hooks take nothing.
   */
  public function testOnlyThatCommandFreshAndApproved(): void {
    $root = $this->wiredRoot();
    $this->approve($root, OperatorApproval::command(['bypass', 'one reason']));
    [$code] = $this->cli(['bypass', 'another reason'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'a different reason is a different command');

    $this->approve($root, OperatorApproval::command(['bypass', 'declined']), "Don't run it");
    [$code] = $this->cli(['bypass', 'declined'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'a decline approves nothing');

    $file = $this->stateDir($root) . '/' . OperatorApproval::FILE;
    file_put_contents($file, (string) json_encode([
      'id' => 'old',
      'command' => OperatorApproval::command(['bypass', 'stale']),
      'approved' => TRUE,
      'at' => time() - OperatorApproval::TTL - 1,
    ]) . "\n", FILE_APPEND);
    [$code] = $this->cli(['bypass', 'stale'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'an approval older than its time has lapsed');

    $unwired = $this->makeRootWithConfig("preset: custom\n");
    @mkdir($this->stateDir($unwired), 0777, TRUE);
    file_put_contents($this->stateDir($unwired) . '/' . OperatorApproval::FILE, (string) json_encode([
      'id' => 'x',
      'command' => OperatorApproval::command(['bypass', 'unwired']),
      'approved' => TRUE,
      'at' => time(),
    ]) . "\n");
    [$code] = $this->cli(['bypass', 'unwired'], $unwired);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'without the question wall a row could be the agent\'s');
  }

  /**
   * The guard lets an approved command through and refuses the rest.
   *
   * The shell's quoting is gone from what it compares, as from the CLI's
   * argv, so the command the human approved is the one both see.
   */
  public function testGuardLetsTheApprovedCommandThrough(): void {
    $root = $this->wiredRoot();
    $bash = static fn (string $command): array => ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]];
    $run = 'ddev droost workflow:bypass "TICKET-2: the READMEs"';

    [$exit, , $stderr] = $this->guard($root, 'operator-commands', $bash($run));
    $this->assertSame(2, $exit);
    $this->assertStringContainsString(OperatorApproval::question('droost workflow:bypass TICKET-2: the READMEs'), $stderr);

    $this->approve($root, 'droost workflow:bypass TICKET-2: the READMEs');
    [$exit] = $this->guard($root, 'operator-commands', $bash($run));
    $this->assertSame(0, $exit, 'approved: the guard lets it through, and the CLI spends it');

    // A baseline write is not gated by the CLI, so the guard spends it.
    $baseline = 'ddev droost workflow:baseline --refresh --grow --reason="phpstan 2.3"';
    $this->approve($root, 'droost workflow:baseline --refresh --grow --reason=phpstan 2.3');
    [$exit] = $this->guard($root, 'operator-commands', $bash($baseline));
    $this->assertSame(0, $exit, 'approved baseline write');
    [$exit] = $this->guard($root, 'operator-commands', $bash($baseline));
    $this->assertSame(2, $exit, 'the guard spent it: a second write needs a second yes');
  }

  /**
   * A project's operator-only script asks the same way and spends it itself.
   */
  public function testOperatorOnlyScriptByApproval(): void {
    $root = $this->wiredRoot("preset: custom\noperator_only:\n  - droost/bin/arm\n");
    @mkdir($root . '/droost/bin', 0777, TRUE);
    file_put_contents($root . '/droost/bin/arm', "#!/bin/sh\n");
    $bash = ['tool_name' => 'Bash', 'tool_input' => ['command' => 'bash droost/bin/arm on']];

    [$exit, , $stderr] = $this->guard($root, 'operator-commands', $bash);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString(OperatorApproval::question('droost/bin/arm on'), $stderr);

    $this->approve($root, 'droost/bin/arm on');
    [$exit] = $this->guard($root, 'operator-commands', $bash);
    $this->assertSame(0, $exit, 'approved: the script runs');
    [$code] = $this->cli(['approval', '--consume', '--', 'droost/bin/arm', 'on'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, 'and the script spends it');
    [$code] = $this->cli(['approval', '--consume', '--', 'droost/bin/arm', 'on'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_RUN_FAILED, $code, 'once');
  }

  /**
   * The approvals are the guard's to write, never the agent's.
   */
  public function testTheAgentCannotWriteAnApproval(): void {
    $root = $this->wiredRoot();
    $file = $this->stateDir($root) . '/' . OperatorApproval::FILE;
    [$exit] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $file, 'content' => 'x'],
    ]);
    $this->assertSame(2, $exit, 'the Write tool');
    [$exit] = $this->guard($root, 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => 'echo x >> droost/droost-workflow/' . OperatorApproval::FILE],
    ]);
    $this->assertSame(2, $exit, 'a shell redirection');
    $question = OperatorApproval::question('droost workflow:bypass x');
    [$exit] = $this->guard($root, 'question', [
      'tool_name' => 'AskUserQuestion',
      'tool_input' => ['questions' => [['question' => $question]], 'answers' => [$question => 'Approve']],
    ]);
    $this->assertSame(2, $exit, 'a question that arrives answered');
  }

  /**
   * The guard's mirror of the question matches the library's.
   */
  public function testGuardMirrorsTheQuestion(): void {
    $guard = (string) file_get_contents(dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php');
    $this->assertStringContainsString("return '" . OperatorApproval::QUESTION . "';", $guard);
    $this->assertStringContainsString('$row[\'at\'] >= $now - ' . OperatorApproval::TTL, $guard);
  }

  /**
   * Records the human's answer as the guard does, from the dialog's result.
   *
   * @param string $root
   *   The project.
   * @param string $command
   *   The command asked about.
   * @param string $answer
   *   Their answer.
   */
  private function approve(string $root, string $command, string $answer = OperatorApproval::APPROVE): void {
    $question = OperatorApproval::question($command);
    [$exit] = $this->guard($root, 'answers', [
      'tool_name' => 'AskUserQuestion',
      'tool_input' => ['questions' => [['question' => $question]]],
      'tool_response' => ['answers' => [$question => $answer]],
    ]);
    $this->assertSame(0, $exit);
  }

  /**
   * A project whose guard question and answer hooks are wired.
   *
   * @param string $yaml
   *   The lever file.
   *
   * @return string
   *   The root.
   */
  private function wiredRoot(string $yaml = "preset: custom\n"): string {
    $root = $this->makeRootWithConfig($yaml);
    @mkdir($root . '/.claude', 0755, TRUE);
    $entry = static fn (string $mode): array => [
      'matcher' => 'AskUserQuestion',
      'hooks' => [
        ['type' => 'command', 'command' => 'php "$CLAUDE_PROJECT_DIR/bin/droost-guard" ' . $mode . ' --harness=claude'],
      ],
    ];
    $hooks = ['PreToolUse' => [$entry('question')], 'PostToolUse' => [$entry('answers')]];
    file_put_contents($root . '/.claude/settings.json', (string) json_encode(['hooks' => $hooks]));
    @mkdir($this->stateDir($root), 0777, TRUE);
    return $root;
  }

  /**
   * The project's state directory.
   *
   * @param string $root
   *   The project.
   *
   * @return string
   *   The absolute path.
   */
  private function stateDir(string $root): string {
    return $root . '/' . RunStateStore::resolveStateDir($root);
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
    $dispatcher = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-30T00:00:00+00:00', static fn (): string => 'run-approval');

    return [$dispatcher->dispatch($argv, $root), implode("\n", $lines)];
  }

}
