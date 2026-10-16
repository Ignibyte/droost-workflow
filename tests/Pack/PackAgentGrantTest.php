<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\PackManifest;
use PHPUnit\Framework\TestCase;

/**
 * An agent can reach every droost tool its brief tells it to use.
 *
 * The researcher's brief once named five droost tools while its `tools:` line
 * granted none of them, so it could call none, and its grounding never reached
 * the run's tool ledger (F-75). droost is reached by command now
 * (`vendor/bin/droost <id> '<json>'` for the code, `drush droost:tool <id>
 * '<json>'` for the live site), so an agent whose brief names a droost tool
 * must hold Bash and say which command. A subagent is never granted a droost
 * MCP tool: droost's server is optional (`--no-mcp`), and a command is there
 * on every host.
 */
final class PackAgentGrantTest extends TestCase {

  /**
   * Every agent whose brief names a droost tool can run drush.
   */
  public function testAgentsThatNameDroostToolsCanRunThem(): void {
    $files = glob(dirname(__DIR__, 2) . '/pack/agents/*.md') ?: [];
    $this->assertNotSame([], $files, 'the pack ships agents');
    $checked = 0;
    foreach ($files as $file) {
      $text = (string) file_get_contents($file);
      $this->assertSame(1, preg_match('/^tools:\s*(.+)$/m', $text, $grant), basename($file) . ' declares a grant');
      $granted = array_map('trim', explode(',', $grant[1]));
      foreach ($granted as $tool) {
        $this->assertStringStartsNotWith('mcp__droost__', $tool, sprintf('%s is granted %s: the pack\'s subagents reach droost by command, which every host has, never through a server the project may not wire', basename($file), $tool));
      }
      preg_match_all('/`(droost_[a-z_]+)`/', $text, $named);
      $names = array_values(array_intersect(array_unique($named[1]), PackManifest::CITABLE_TOOLS));
      if ($names === []) {
        continue;
      }
      $checked++;
      $this->assertContains(
        'Bash',
        $granted,
        sprintf('%s tells the agent to use %s, which runs by command, and does not grant Bash', basename($file), implode(', ', $names)),
      );
      $this->assertMatchesRegularExpression(
        '#vendor/bin/droost\b|droost:tool#',
        $text,
        sprintf('%s names droost tools and never says how to call them', basename($file)),
      );
    }
    $this->assertGreaterThan(0, $checked, 'at least one brief names a droost tool, or this test checks nothing');
  }

}
