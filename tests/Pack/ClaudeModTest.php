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
    $this->assertSame(ClaudeMod::nameFor($root), $market['name'], 'named for the project (F-255)');
    $this->assertIsArray($market['plugins']);
    $this->assertIsArray($market['plugins'][0]);
    $this->assertSame('droost-guard', $market['plugins'][0]['name']);
    $this->assertFileDoesNotExist($root . '/.claude/settings.json', 'nothing enables it');

    $this->assertSame('updated', ClaudeMod::install($root));
  }

  /**
   * Each project's copy is a marketplace of its own name (F-255).
   *
   * Claude Code keeps marketplaces by name for the whole user, so one name
   * for every project let the last project to add its copy re-point every
   * other project's mod at its own. On ddev the install runs where every
   * root is /var/www/html, so the name comes from the ddev project.
   */
  public function testEachProjectNamesItsOwnMarketplace(): void {
    $was = getenv('DDEV_PROJECT');
    try {
      putenv('DDEV_PROJECT=Corner Shop');
      $a = $this->makeRoot();
      ClaudeMod::install($a);
      $this->assertSame('droost-corner-shop', ClaudeMod::marketplace($a));
      $this->assertSame('droost-guard@droost-corner-shop', ClaudeMod::plugin($a));
      $this->assertSame([
        'claude plugin marketplace add ./.claude/droost-plugins --scope project',
        'claude plugin install droost-guard@droost-corner-shop --scope project',
      ], ClaudeMod::hostCommands($a));

      putenv('DDEV_PROJECT=bookshop');
      $b = $this->makeRoot();
      ClaudeMod::install($b);
      $this->assertSame('droost-bookshop', ClaudeMod::marketplace($b), 'another project, another name');
      ClaudeMod::install($a);
      $this->assertSame('droost-corner-shop', ClaudeMod::marketplace($a), 'a copy keeps the name it was given');

      // A copy made before F-255 carries the shared name, and is renamed.
      $file = $b . '/' . ClaudeMod::DIRECTORY . '/.claude-plugin/marketplace.json';
      $doc = json_decode((string) file_get_contents($file), TRUE);
      $this->assertIsArray($doc);
      $doc['name'] = ClaudeMod::SHARED;
      file_put_contents($file, json_encode($doc));
      ClaudeMod::install($b);
      $this->assertSame('droost-bookshop', ClaudeMod::marketplace($b));

      putenv('DDEV_PROJECT');
      $this->assertSame('droost-' . strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', basename((string) realpath($a)))), ClaudeMod::nameFor($a), 'off ddev, the directory');
    }
    finally {
      putenv($was === FALSE ? 'DDEV_PROJECT' : 'DDEV_PROJECT=' . $was);
    }
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
