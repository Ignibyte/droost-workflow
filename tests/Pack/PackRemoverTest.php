<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\AgentsBlock;
use Droost\Workflow\Pack\PackManifest;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Pack\PackRemover;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Install then uninstall leaves the project as it was.
 *
 * There was no uninstall at all until 2026-09-15, and the gap was not a
 * missing convenience: `drush droost:uninstall` removed droost's skills and
 * its MCP entry and left three guard hooks wired in settings.json, running a
 * guard that reads a lever file for a module no longer installed. The only way
 * out was to hand-edit JSON — which the guard refuses.
 */
final class PackRemoverTest extends WorkflowTestCase {

  /**
   * A full round trip: nothing of the pack's is left behind.
   */
  public function testInstallThenUninstallLeavesNoPackFiles(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    foreach (PackManifest::FILES as $destination) {
      $this->assertFileExists($root . '/' . $destination, $destination . ' was installed');
    }

    $report = (new PackRemover())->uninstall($root);

    foreach (PackManifest::FILES as $destination) {
      $this->assertFileDoesNotExist($root . '/' . $destination, $destination . ' is gone');
    }
    foreach (PackManifest::ownedDirectories() as $relative) {
      $this->assertDirectoryDoesNotExist($root . '/' . $relative, $relative . ' is gone');
    }
    $this->assertNotEmpty($report->removed);
  }

  /**
   * THE HOOKS STOP FIRING — the reason this class exists.
   */
  public function testTheGuardHooksLeaveSettingsJson(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    $settings = $root . '/.claude/settings.json';
    $this->assertStringContainsString(
      'droost-guard',
      (string) file_get_contents($settings),
      'the guard was wired',
    );

    (new PackRemover())->uninstall($root);
    $this->assertFileDoesNotExist(
      $settings,
      'settings.json held nothing but our hooks, so it goes with them',
    );
  }

  /**
   * A user's own hooks survive; only ours are taken out.
   *
   * The settings.json file is the user's. Removing it wholesale would be the
   * mirror of the bug this fixes — losing configuration nobody asked us to
   * touch — so the removal is keyed the same way the wiring is: by the
   * guard's filename, independent of the path it was installed with.
   */
  public function testAnotherToolsHooksAreNotTouched(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    $settings = $root . '/.claude/settings.json';
    $decoded = json_decode((string) file_get_contents($settings), TRUE);
    $this->assertIsArray($decoded);
    $this->assertIsArray($decoded['hooks']);
    $this->assertIsArray($decoded['hooks']['PreToolUse']);
    $decoded['hooks']['PreToolUse'][] = [
      'matcher' => 'Bash',
      'hooks' => [['type' => 'command', 'command' => 'php my-own-linter.php']],
    ];
    $decoded['permissions'] = ['allow' => ['Bash(ls:*)']];
    file_put_contents($settings, (string) json_encode($decoded, JSON_PRETTY_PRINT));

    (new PackRemover())->uninstall($root);

    $this->assertFileExists($settings, 'the file stays: it carries the user\'s own settings');
    $body = (string) file_get_contents($settings);
    $this->assertStringNotContainsString('droost-guard', $body, 'ours are gone');
    $this->assertStringContainsString('my-own-linter.php', $body, 'theirs is not');
    $this->assertStringContainsString('Bash(ls:*)', $body, 'and neither is anything else');
  }

  /**
   * The user's empty objects stay objects (F-483).
   *
   * Decoded as arrays and written back, `"env": {}` became `"env": []`: a
   * different type, in a file the harness validates. Both init and
   * uninstall rewrote it that way.
   */
  public function testEmptyObjectsInSettingsStayObjects(): void {
    $root = $this->makeRoot();
    mkdir($root . '/.claude');
    $settings = $root . '/.claude/settings.json';
    file_put_contents($settings, "{\n  \"env\": {},\n  \"permissions\": {\"allow\": [], \"extra\": {}},\n  \"model\": \"x\"\n}\n");

    (new PackMaterializer())->init($root);
    $after = (string) file_get_contents($settings);
    $this->assertStringContainsString('droost-guard', $after, 'our hooks went in');
    $this->assertMatchesRegularExpression('/"env": \{\}/', $after, 'init kept the empty object');
    $this->assertMatchesRegularExpression('/"extra": \{\}/', $after);
    $this->assertMatchesRegularExpression('/"allow": \[\]/', $after, 'and an empty list is still a list');

    (new PackRemover())->uninstall($root);
    $after = (string) file_get_contents($settings);
    $this->assertStringNotContainsString('droost-guard', $after);
    $this->assertMatchesRegularExpression('/"env": \{\}/', $after, 'uninstall kept it too');
    $this->assertMatchesRegularExpression('/"allow": \[\]/', $after);
  }

  /**
   * A hook the user put in droost's group is the user's (F-484).
   *
   * The group was found by any hook in it being ours, then replaced or
   * removed whole, so a second hook a person added under the same matcher
   * went with it on the next init or on uninstall.
   */
  public function testUsersHookInOurGroupSurvives(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    $settings = $root . '/.claude/settings.json';
    $decoded = json_decode((string) file_get_contents($settings), TRUE);
    $this->assertIsArray($decoded);
    $this->assertIsArray($decoded['hooks']);
    $groups = $decoded['hooks']['PreToolUse'];
    $this->assertIsArray($groups);
    $this->assertNotSame([], $groups);
    $mine = ['type' => 'command', 'command' => 'php my-own-linter.php'];
    $edited = [];
    foreach ($groups as $group) {
      $this->assertIsArray($group);
      $this->assertIsArray($group['hooks']);
      $ours = $group['hooks'][0];
      $this->assertIsArray($ours);
      $this->assertIsString($ours['command']);
      // Theirs beside ours, and ours made stale so init has to rewrite it.
      $ours['command'] = str_replace('php "', 'php  "', $ours['command']);
      $group['hooks'] = [$ours, $mine];
      $edited[] = $group;
    }
    $decoded['hooks']['PreToolUse'] = $edited;
    file_put_contents($settings, (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    (new PackMaterializer())->init($root);
    $body = (string) file_get_contents($settings);
    $this->assertStringNotContainsString('php  "', $body, 'ours was brought up to date');
    $this->assertSame(count($edited), substr_count($body, 'my-own-linter.php'), 'init kept theirs in every group');

    (new PackRemover())->uninstall($root);
    $this->assertFileExists($settings);
    $body = (string) file_get_contents($settings);
    $this->assertStringNotContainsString('droost-guard', $body, 'ours are gone');
    $this->assertSame(count($edited), substr_count($body, 'my-own-linter.php'), 'uninstall kept theirs too');
  }

  /**
   * The lever file and the run records are KEPT, and the report names them.
   *
   * An uninstaller that deletes the record of past runs is a data-loss bug
   * wearing a tidy-up's clothes. The lever file is version-controlled intent
   * somebody wrote; the state directory holds the evidence store.
   */
  public function testTheLeverFileAndTheRunRecordsSurvive(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    if (!is_dir($root . '/droost/droost-workflow')) {
      mkdir($root . '/droost/droost-workflow', 0777, TRUE);
    }
    file_put_contents($root . '/droost/droost-workflow/run.json', '{"current_phase":null}');

    $report = (new PackRemover())->uninstall($root);

    $this->assertFileExists($root . '/' . PackManifest::CONFIG_FILE, 'the lever file is the user\'s');
    $this->assertFileExists($root . '/droost/droost-workflow/run.json', 'the record survives');
    // But pack.lock is ours, and goes: a project with no pack should not
    // carry a file describing the pack it used to have.
    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/pack.lock');
    $this->assertContains(PackManifest::CONFIG_FILE, $report->left);
    $this->assertContains('droost/droost-workflow', $report->left);
    $this->assertStringContainsString('left in place, deliberately', $report->summary());
  }

  /**
   * A directory with our name but no sentinel is a human's, and stays.
   *
   * The same ownership test `init` applies before it will write. Two rules
   * for "is this ours" is how an uninstaller deletes somebody's work.
   */
  public function testTheDirectoryWeDoNotOwnIsKept(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    // Strip the sentinel: now it looks like a directory a human made.
    unlink($root . '/.claude/templates/' . PackManifest::SENTINEL);

    $report = (new PackRemover())->uninstall($root);

    $this->assertDirectoryExists($root . '/.claude/templates');
    $this->assertContains('.claude/templates', $report->kept);
    $this->assertStringContainsString('not this package\'s to remove', $report->summary());
  }

  /**
   * Our AGENTS.md block goes; a project's own instructions do not.
   */
  public function testTheAgentsBlockIsRemovedAndTheRestOfTheFileKept(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/' . AgentsBlock::FILE, "# House rules\n\nRun the linter.\n");
    (new PackMaterializer())->init($root);
    $this->assertStringContainsString(
      AgentsBlock::BEGIN,
      (string) file_get_contents($root . '/' . AgentsBlock::FILE),
    );

    (new PackRemover())->uninstall($root);

    $body = (string) file_get_contents($root . '/' . AgentsBlock::FILE);
    $this->assertStringNotContainsString(AgentsBlock::BEGIN, $body);
    $this->assertStringContainsString('Run the linter.', $body);
  }

  /**
   * An AGENTS.md that existed only for the block goes with it.
   */
  public function testAnAgentsFileWeCreatedIsRemovedEntirely(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    $this->assertFileExists($root . '/' . AgentsBlock::FILE, 'init created it');

    (new PackRemover())->uninstall($root);

    $this->assertFileDoesNotExist(
      $root . '/' . AgentsBlock::FILE,
      'the file held nothing but our block, so it goes too',
    );
  }

  /**
   * Running it twice is not an error.
   */
  public function testUninstallIsIdempotent(): void {
    $root = $this->makeRoot();
    (new PackMaterializer())->init($root);
    (new PackRemover())->uninstall($root);

    $second = (new PackRemover())->uninstall($root);
    $this->assertSame([], $second->removed);
    $this->assertStringContainsString('nothing to do', $second->summary());
  }

}
