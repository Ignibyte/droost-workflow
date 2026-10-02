<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\ClaudeMod;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The droost Claude Code mod reaches a project only by copy, on a yes (E2).
 *
 * Nothing here enables it: the operator runs Claude Code's own commands,
 * which write Claude Code's settings. A re-install refreshes an earlier yes.
 */
final class ClaudeModTest extends WorkflowTestCase {

  /**
   * The mod is copied whole, and a second copy is an update.
   */
  public function testTheModIsCopiedAndRefreshed(): void {
    $root = $this->makeRoot();
    $this->assertFalse(ClaudeMod::present($root));

    $this->assertSame('written', ClaudeMod::install($root));
    $this->assertTrue(ClaudeMod::present($root));
    $base = $root . '/' . ClaudeMod::DIRECTORY;
    $this->assertFileExists($base . '/droost-guard/.claude-plugin/plugin.json');
    $this->assertFileExists($base . '/droost-guard/hooks/hooks.json');
    $this->assertFileExists($base . '/droost-guard/hooks/register.js');
    $market = json_decode((string) file_get_contents($base . '/.claude-plugin/marketplace.json'), TRUE);
    $this->assertIsArray($market);
    $this->assertSame('droost', $market['name']);
    $this->assertIsArray($market['plugins']);
    $this->assertIsArray($market['plugins'][0]);
    $this->assertSame('droost-guard', $market['plugins'][0]['name']);
    $this->assertFileDoesNotExist($root . '/.claude/settings.json', 'nothing enables it');

    $this->assertSame('updated', ClaudeMod::install($root));
  }

  /**
   * The operator's commands and an organization's settings name the mod.
   */
  public function testTheOperatorIsHandedTheCommands(): void {
    $this->assertSame([
      'claude plugin marketplace add ./.claude/droost-plugins --scope project',
      'claude plugin install droost-guard@droost --scope project',
    ], ClaudeMod::hostCommands());
    $managed = json_decode(ClaudeMod::managedSettings('/opt/droost/claude-plugins'), TRUE);
    $this->assertIsArray($managed);
    $this->assertSame(['droost-guard@droost', 'sec-default@builtin'], $managed['prependPlugins']);
    $market = $managed['extraKnownMarketplaces'];
    $this->assertIsArray($market);
    $this->assertIsArray($market['droost']);
    $this->assertIsArray($market['droost']['source']);
    $this->assertSame('/opt/droost/claude-plugins', $market['droost']['source']['path']);
  }

  /**
   * The pack's mod is the one droost ships: its manifest and its hooks.
   */
  public function testThePackCarriesTheMod(): void {
    $plugin = json_decode((string) file_get_contents(ClaudeMod::source() . '/droost-guard/.claude-plugin/plugin.json'), TRUE);
    $this->assertIsArray($plugin);
    $this->assertSame('droost-guard', $plugin['name']);
    $register = (string) file_get_contents(ClaudeMod::source() . '/droost-guard/hooks/register.js');
    $this->assertStringContainsString("on('tool.call'", $register);
    $this->assertStringContainsString('export function refusal', $register);
  }

}
