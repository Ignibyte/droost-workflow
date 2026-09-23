<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\PackManifest;
use PHPUnit\Framework\TestCase;

/**
 * An agent is granted every droost tool its brief tells it to use.
 *
 * The researcher's brief said "with a booted site, ask it instead of
 * assuming" and named five droost tools, and its `tools:` line granted Read,
 * Grep, Glob and Bash. So it could call none of them, and P6 run 6's
 * researcher said so: its grounding came from `drush php:eval` and `curl`,
 * none of it reached the run's tool ledger, and the main agent made the
 * lookups again itself (F-75). The bug-fixer's brief had the same gap.
 */
final class PackAgentGrantTest extends TestCase {

  /**
   * Every droost tool an agent's brief names is in its grant.
   */
  public function testAgentsAreGrantedTheDroostToolsTheirBriefsName(): void {
    $files = glob(dirname(__DIR__, 2) . '/pack/agents/*.md') ?: [];
    $this->assertNotSame([], $files, 'the pack ships agents');
    $checked = 0;
    foreach ($files as $file) {
      $text = (string) file_get_contents($file);
      $this->assertSame(1, preg_match('/^tools:\s*(.+)$/m', $text, $grant), basename($file) . ' declares a grant');
      $granted = array_map('trim', explode(',', $grant[1]));
      preg_match_all('/`(droost_[a-z_]+)`/', $text, $named);
      foreach (array_unique($named[1]) as $tool) {
        if (!in_array($tool, PackManifest::CITABLE_TOOLS, TRUE)) {
          continue;
        }
        $checked++;
        $this->assertContains(
          'mcp__droost__' . $tool,
          $granted,
          sprintf('%s tells the agent to use %s and does not grant it', basename($file), $tool),
        );
      }
    }
    $this->assertGreaterThan(0, $checked, 'at least one brief names a droost tool, or this test checks nothing');
  }

}
