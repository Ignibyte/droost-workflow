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
   * Three more verbs carry a human's decision (F-459, F-460, F-461).
   *
   * Taking the guard out, rewriting the baseline of inherited debt, and
   * moving a run to agentic each loosen what holds the agent, and each ran
   * from a shell with no terminal and no yes: the usage and docs/guard.md
   * said every operator command refuses there. They take the same two
   * proofs bypass takes, a terminal or the dialog's approval, so the person
   * can say yes without leaving the session. What only reads, or tightens,
   * asks for nothing.
   */
  public function testVerbsThatLoosenNeedTheSameProof(): void {
    $root = $this->wiredRoot();
    foreach ([['uninstall'], ['baseline', '--refresh'], ['baseline'], ['swap', 'agentic']] as $argv) {
      $command = OperatorApproval::command($argv);
      [$code, $out] = $this->cli($argv, $root);
      $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, $command . ': no terminal, no approval');
      $this->assertStringContainsString(OperatorApproval::question($command), $out, $command);
    }
    $this->assertFileExists($root . '/.claude/settings.json', 'the refused uninstall removed nothing');

    foreach ([['baseline', '--status'], ['baseline', '--measure'], ['swap', 'interactive']] as $argv) {
      [, $out] = $this->cli($argv, $root);
      $this->assertStringNotContainsString("operator's command", $out, implode(' ', $argv) . ' asks for nothing');
    }

    foreach ([['swap', 'agentic'], ['baseline', '--refresh'], ['uninstall']] as $argv) {
      $command = OperatorApproval::command($argv);
      $this->approve($root, $command);
      [, $out] = $this->cli($argv, $root);
      $this->assertStringNotContainsString("operator's command", $out, $command . ': approved, it is let past the check');
    }
  }

  /**
   * An option a verb does not take stops it; it is not ignored (F-488).
   *
   * `baseline --staus` wrote a baseline, and `uninstall --dry-run`, `reset
   * --force --dry-run` and `effort low --dry-run` each did for real what the
   * person asked to rehearse. On a verb that changes state an unknown
   * option is a misuse, named, and nothing is done.
   */
  public function testUnknownOptionOnVerbThatChangesStateIsRefused(): void {
    $root = $this->wiredRoot();
    $cases = [
      [['baseline', '--staus'], '--staus'],
      [['uninstall', '--dry-run'], '--dry-run'],
      [['reset', '--force', '--dry-run'], '--dry-run'],
      [['effort', 'low', '--dry-run'], '--dry-run'],
      [['bypass', 'a reason', '--dry-run'], '--dry-run'],
    ];
    foreach ($cases as [$argv, $option]) {
      [$code, $out] = $this->cli($argv, $root);
      $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, implode(' ', $argv));
      $this->assertStringContainsString('does not take ' . $option, $out, implode(' ', $argv));
      $this->assertStringContainsString('nothing was done', $out, implode(' ', $argv));
    }
    $this->assertFileExists($root . '/.claude/settings.json');
    $this->assertDirectoryDoesNotExist($root . '/droost/baseline');

    // The options each does take are still taken.
    foreach ([['baseline', '--status'], ['effort', 'low', '--preview'], ['bypass', '--off']] as $argv) {
      [, $out] = $this->cli($argv, $root);
      $this->assertStringNotContainsString('does not take', $out, implode(' ', $argv));
    }
  }

  /**
   * A ticket directory that cannot be read stops the ticket verbs only (F-487).
   *
   * Every command built the project's ticket source before doing anything,
   * so a `docs/` that is a symlink (a ticket directory is never reached
   * through one) made `status`, `effort` and `reset` exit 2 over tickets
   * none of them reads. The verbs that use tickets still say what is wrong.
   */
  public function testUnreadableTicketsStopOnlyTheTicketVerbs(): void {
    $root = $this->wiredRoot("preset: custom\nwork_item:\n  provider: markdown\n  markdown: { dir: docs/tickets }\n");
    $elsewhere = $this->makeRoot();
    mkdir($elsewhere . '/tickets');
    symlink($elsewhere, $root . '/docs');

    foreach ([['status'], ['effort'], ['baseline', '--status']] as $argv) {
      [$code, $out] = $this->cli($argv, $root);
      $this->assertSame(ArgvDispatcher::EXIT_OK, $code, implode(' ', $argv) . ': ' . $out);
    }
    foreach ([['ticket', 'list'], ['run', '--ticket=T-1']] as $argv) {
      [$code, $out] = $this->cli($argv, $root);
      $this->assertNotSame(ArgvDispatcher::EXIT_OK, $code, implode(' ', $argv));
      $this->assertStringContainsString('symlink', $out, implode(' ', $argv));
    }
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
    // The diary says WHY it was let through: an operator's verb the agent
    // ran read `allow, rule: null`, as `ls` does (F-482).
    $this->assertSame('operator-approved', $this->lastGuardRule($root));

    // A baseline write is gated by the CLI as bypass is (F-460), so the CLI
    // spends the approval and the guard only reads it. The guard used to
    // spend it, when nothing behind it asked.
    $baseline = 'ddev droost workflow:baseline --refresh --grow --reason="phpstan 2.3"';
    $argv = ['baseline', '--refresh', '--grow', '--reason=phpstan 2.3'];
    $this->assertSame('droost workflow:baseline --refresh --grow --reason=phpstan 2.3', OperatorApproval::command($argv));
    $this->approve($root, 'droost workflow:baseline --refresh --grow --reason=phpstan 2.3');
    [$exit] = $this->guard($root, 'operator-commands', $bash($baseline));
    $this->assertSame(0, $exit, 'approved baseline write');
    [, $out] = $this->cli($argv, $root);
    $this->assertStringNotContainsString("operator's command", $out, 'the CLI takes the approval the guard left');
    [$code] = $this->cli($argv, $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, 'and spent it: a second write needs a second yes');
    [$exit] = $this->guard($root, 'operator-commands', $bash($baseline));
    $this->assertSame(2, $exit, 'which the guard sees too');
  }

  /**
   * A question that quotes an argument approves the command the shell ran.
   *
   * F-399 (2026-11-02): an agent asked "Approve running this operator
   * command? droost workflow:gate-waive prettier "Formatted …"" and the human
   * approved it, but the shell had removed the quotes from what ran, so the
   * guard refused the approved command and the human was asked twice.
   */
  public function testQuotesInTheQuestionDoNotDecide(): void {
    $root = $this->wiredRoot();
    $bash = static fn (string $command): array => ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]];
    $run = 'ddev droost workflow:bypass "TICKET-3: the module READMEs, didn\'t change"';

    $this->approve($root, 'droost workflow:bypass "TICKET-3: the module READMEs, didn\'t change"');
    [$exit, , $stderr] = $this->guard($root, 'operator-commands', $bash($run));
    $this->assertSame(0, $exit, $stderr);
    [$code, $out] = $this->cli(['bypass', "TICKET-3: the module READMEs, didn't change"], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);

    // Quotes are ignored, words are not: another reason is another command.
    $this->approve($root, 'droost workflow:bypass "TICKET-4: one reason"');
    [$code] = $this->cli(['bypass', 'TICKET-4: another reason'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
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
    $this->assertSame('operator-approved', $this->lastGuardRule($root));
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
   * The rule on the guard's latest diary row.
   *
   * @param string $root
   *   The project root.
   *
   * @return string|null
   *   The rule, or NULL when the row names none or there is no row.
   */
  private function lastGuardRule(string $root): ?string {
    $rows = @file($this->stateDir($root) . '/guard-calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $last = json_decode((string) end($rows), TRUE);

    return is_array($last) && is_string($last['rule'] ?? NULL) ? $last['rule'] : NULL;
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
