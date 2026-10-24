<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use PHPUnit\Framework\TestCase;

/**
 * An agent can run every droost command its brief tells it to use.
 *
 * The researcher's brief once named five droost tools while its `tools:` line
 * granted none of them, so it could call none, and its grounding never reached
 * the run's tool ledger (F-75). droost is one command, `droost` (2026-10-19),
 * so an agent whose brief names a droost command must hold Bash. A subagent is
 * never granted a droost MCP tool: droost ships no MCP server.
 */
final class PackAgentGrantTest extends TestCase {

  /**
   * Every agent whose brief names a droost command can run it.
   */
  public function testAgentsThatNameDroostCommandsCanRunThem(): void {
    $files = glob(dirname(__DIR__, 2) . '/pack/agents/*.md') ?: [];
    $this->assertNotSame([], $files, 'the pack ships agents');
    $checked = 0;
    foreach ($files as $file) {
      $text = (string) file_get_contents($file);
      $this->assertSame(1, preg_match('/^tools:\s*(.+)$/m', $text, $grant), basename($file) . ' declares a grant');
      $granted = array_map('trim', explode(',', $grant[1]));
      foreach ($granted as $tool) {
        $this->assertStringStartsNotWith('mcp__droost__', $tool, sprintf('%s is granted %s: droost ships no MCP server, and its command is there on every host', basename($file), $tool));
      }
      if (preg_match('/`droost (?:code|search|workflow):[a-z-]+|`droost brief`/', $text) !== 1) {
        continue;
      }
      $checked++;
      $this->assertContains(
        'Bash',
        $granted,
        sprintf('%s tells the agent to run droost, a command, and does not grant Bash', basename($file)),
      );
    }
    $this->assertGreaterThan(0, $checked, 'at least one brief names a droost command, or this test checks nothing');
  }

}
