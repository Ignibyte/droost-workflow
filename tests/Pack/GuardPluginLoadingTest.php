<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The agent's own routes to loading a Claude Code plugin are refused (E3).
 *
 * A mod runs inside Claude Code with the user's permissions, and a user's
 * mod runs before the project's PreToolUse hook: its `tool.check` can approve
 * what this guard refused. So installing one, pointing a session at one, or
 * writing one where Claude Code loads it from is the operator's act, run or
 * no run. Reading what is installed is not.
 */
final class GuardPluginLoadingTest extends WorkflowTestCase {

  /**
   * Every shell route to loading a plugin is refused.
   */
  public function testShellRoutesToLoadingPluginsAreRefused(): void {
    $root = $this->makeRoot();
    $cases = [
      'install' => 'claude plugin install droost-guard@droost --scope project',
      'install short' => 'claude plugin i some-mod@market',
      'enable' => 'claude plugin enable some-mod@market',
      'marketplace add' => 'claude plugin marketplace add ./mods --scope project',
      'plugin dir' => 'claude --plugin-dir ./my-mod -p "hi"',
      'plugin dir equals' => 'claude --plugin-dir=./my-mod',
      'plugin url' => 'claude --plugin-url https://example.com/mod.zip',
      'env prefix' => 'CLAUDE_CODE_PLUGIN_DIRS=/tmp/mods claude -p "hi"',
      'export' => 'export CLAUDE_CODE_PLUGIN_DIRS=/tmp/mods',
      'wrapped' => 'timeout 5 claude plugin install x@y',
      'absolute claude' => '/usr/local/bin/claude plugin install x@y',
    ];
    foreach ($cases as $label => $command) {
      [$code, , $err] = $this->shell($root, $command);
      $this->assertSame(2, $code, $label . ' is refused');
      $this->assertStringContainsString('OPERATOR', $err, $label);
    }
  }

  /**
   * Reading what is installed, and prose about it, is not refused.
   */
  public function testReadingWhatIsInstalledIsNot(): void {
    $root = $this->makeRoot();
    $cases = [
      'claude plugin list',
      'claude plugin validate ./my-mod',
      'claude plugin details droost-guard',
      'claude --version',
      'git commit -m "ask the operator to run claude plugin install x@y"',
      'grep -rn "claude plugin install" docs',
      'echo CLAUDE_CODE_PLUGIN_DIRS',
    ];
    foreach ($cases as $command) {
      $this->assertSame(0, $this->shell($root, $command)[0], $command);
    }
  }

  /**
   * Where Claude Code loads plugins from is the operator's, by any writer.
   */
  public function testWritingWherePluginsLoadFromIsRefused(): void {
    $root = $this->makeRoot();
    $home = $this->makeRoot();
    $paths = [
      $home . '/.claude/dev-mods/session-1/hooks/register.js',
      $home . '/.claude/plugins/cache/x/plugin.json',
      $home . '/.claude.json',
      $root . '/.claude/droost-plugins/droost-guard/hooks/register.js',
    ];
    foreach ($paths as $path) {
      [$code, , $err] = $this->guard($root, 'pre-tool-use', [
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => $path, 'content' => 'x'],
      ]);
      $this->assertSame(2, $code, $path);
      $this->assertStringContainsString('loads plugins and mods from', $err, $path);
      $this->assertSame(2, $this->shell($root, 'echo x > ' . escapeshellarg($path))[0], 'by shell: ' . $path);
    }
  }

  /**
   * Runs one shell command through the guard's operator-command mode.
   *
   * @param string $root
   *   The project root.
   * @param string $command
   *   The command line.
   *
   * @return array{0: int, 1: string, 2: string}
   *   Exit code, stdout, stderr.
   */
  private function shell(string $root, string $command): array {
    return $this->guard($root, 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => $command],
    ]);
  }

}
