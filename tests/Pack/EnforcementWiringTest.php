<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\PackError;
use Droost\Workflow\Pack\PackManifest;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The enforcement layer's installation seams (0.3, W2/W5–W7).
 *
 * Three facts are pinned: settings.json is MERGED and never clobbered,
 * shared .claude directories are never claimed with a sentinel, and
 * droost/droost-workflow/ is gitignored by default. Each is the kind of choice
 * that only hurts a user when it regresses silently.
 */
final class EnforcementWiringTest extends WorkflowTestCase {

  /**
   * Init wires the guard into settings.json and is idempotent.
   */
  public function testInitWiresClaudeHooksIdempotently(): void {
    $root = $this->makeRoot();
    $materializer = new PackMaterializer();

    $first = $materializer->init($root);
    $this->assertContains('.claude/settings.json', $first->written);

    $raw = (string) file_get_contents($root . '/.claude/settings.json');
    $settings = json_decode($raw, TRUE);
    $this->assertIsArray($settings);
    // Every mode is wired on the guard, anchored to $CLAUDE_PROJECT_DIR so the
    // hook survives the agent's Bash tool moving its cwd out of the project
    // root (R27-F1). Asserted on the raw commands, not the escaped JSON blob.
    $guard = 'php "$CLAUDE_PROJECT_DIR/.claude/hooks/droost-workflow-guard.php"';
    $pre = self::preToolUseEntries($settings);
    $preCommands = array_column($pre, 'command');
    $this->assertContains($guard . ' pre-tool-use', $preCommands);
    $this->assertContains($guard . ' operator-commands', $preCommands);
    $this->assertSame($guard . ' stop', self::firstCommand($settings, 'Stop'));
    // The Bash guard is its own PreToolUse entry with its own matcher.
    $matchers = array_column($pre, 'matcher');
    $this->assertContains('Bash', $matchers);
    $this->assertContains('Edit|Write|MultiEdit|NotebookEdit', $matchers);

    $second = $materializer->init($root);
    $this->assertContains('.claude/settings.json', $second->kept);
    $this->assertSame(
      $raw,
      file_get_contents($root . '/.claude/settings.json'),
      'a re-run must not grow the file',
    );
  }

  /**
   * An install from before the Bash guard gains it on re-init (R23-F2).
   *
   * Presence is checked per COMMAND, not per event: a settings.json that
   * already carries the edit guard on PreToolUse must still receive the
   * operator-commands guard, and nothing else must change.
   */
  public function testExistingInstallGainsTheOperatorCommandsGuard(): void {
    $root = $this->makeRoot();
    mkdir($root . '/.claude', 0755, TRUE);
    // A pre-0.6.9 install: the edit and stop guards on the old relative path,
    // no Bash guard yet.
    $old = 'php .claude/hooks/droost-workflow-guard.php';
    file_put_contents($root . '/.claude/settings.json', json_encode([
      'hooks' => [
        'PreToolUse' => [[
          'matcher' => 'Edit|Write|MultiEdit|NotebookEdit',
          'hooks' => [['type' => 'command', 'command' => $old . ' pre-tool-use']],
        ],
        ],
        'Stop' => [['hooks' => [['type' => 'command', 'command' => $old . ' stop']]]],
      ],
    ]));

    $report = (new PackMaterializer())->init($root);
    $this->assertContains('.claude/settings.json', $report->written);

    $settings = json_decode((string) file_get_contents($root . '/.claude/settings.json'), TRUE);
    $this->assertIsArray($settings);
    // The Bash guard is added, and the pre-existing guards are upgraded to the
    // anchored path in place — two PreToolUse entries, one Stop, no duplicate
    // and no old relative path left behind (R23-F2 + R27-F1).
    $new = 'php "$CLAUDE_PROJECT_DIR/.claude/hooks/droost-workflow-guard.php"';
    $pre = self::preToolUseEntries($settings);
    $this->assertCount(2, $pre, 'the edit guard is upgraded once and the Bash guard added once');
    $commands = array_column($pre, 'command');
    $this->assertContains($new . ' pre-tool-use', $commands);
    $this->assertContains($new . ' operator-commands', $commands);
    $this->assertNotContains($old . ' pre-tool-use', $commands, 'the old relative path is gone, not left beside the new one');
    $hooks = $settings['hooks'] ?? NULL;
    $this->assertIsArray($hooks);
    $stop = $hooks['Stop'] ?? NULL;
    $this->assertIsArray($stop);
    $this->assertCount(1, $stop, 'the stop guard is not duplicated');
    $this->assertSame($new . ' stop', self::firstCommand($settings, 'Stop'), 'the stop guard is upgraded too');
  }

  /**
   * The PreToolUse entries of a decoded settings.json, typed for the analyser.
   *
   * @param array<mixed> $settings
   *   The decoded file.
   *
   * @return list<array{matcher: string, command: string}>
   *   One row per entry: its matcher and its first hook's command.
   */
  private static function preToolUseEntries(array $settings): array {
    $hooks = $settings['hooks'] ?? NULL;
    self::assertIsArray($hooks);
    $pre = $hooks['PreToolUse'] ?? NULL;
    self::assertIsArray($pre);
    $rows = [];
    foreach ($pre as $entry) {
      self::assertIsArray($entry);
      $list = $entry['hooks'] ?? NULL;
      self::assertIsArray($list);
      $first = $list[0] ?? NULL;
      self::assertIsArray($first);
      $command = $first['command'] ?? '';
      self::assertIsString($command);
      $matcher = $entry['matcher'] ?? '';
      self::assertIsString($matcher);
      $rows[] = ['matcher' => $matcher, 'command' => $command];
    }
    return $rows;
  }

  /**
   * The command on an event's first configured hook entry.
   *
   * @param array<mixed> $settings
   *   The decoded settings.json.
   * @param string $event
   *   The hook event name.
   *
   * @return string
   *   The first entry's first hook command.
   */
  private static function firstCommand(array $settings, string $event): string {
    $hooks = $settings['hooks'] ?? NULL;
    self::assertIsArray($hooks);
    $entries = $hooks[$event] ?? NULL;
    self::assertIsArray($entries);
    $first = $entries[0] ?? NULL;
    self::assertIsArray($first);
    $list = $first['hooks'] ?? NULL;
    self::assertIsArray($list);
    $hook = $list[0] ?? NULL;
    self::assertIsArray($hook);
    $command = $hook['command'] ?? NULL;
    self::assertIsString($command);
    return $command;
  }

  /**
   * A user's existing settings survive the merge; broken JSON is refused.
   */
  public function testUserSettingsAreMergedNeverClobbered(): void {
    $root = $this->makeRoot();
    mkdir($root . '/.claude', 0755, TRUE);
    file_put_contents($root . '/.claude/settings.json', json_encode([
      'permissions' => ['allow' => ['Bash(ls:*)']],
      'hooks' => [
        'Stop' => [
          ['hooks' => [['type' => 'command', 'command' => 'echo mine']]],
        ],
      ],
    ]));

    (new PackMaterializer())->init($root);

    $settings = json_decode(
      (string) file_get_contents($root . '/.claude/settings.json'),
      TRUE,
    );
    $this->assertIsArray($settings);
    $this->assertSame(['allow' => ['Bash(ls:*)']], $settings['permissions']);
    $hooks = $settings['hooks'];
    $this->assertIsArray($hooks);
    $stop = $hooks['Stop'] ?? NULL;
    $this->assertIsArray($stop);
    $commands = [];
    foreach ($stop as $entry) {
      if (is_array($entry) && is_array($entry['hooks'] ?? NULL) && is_array($entry['hooks'][0] ?? NULL)) {
        $commands[] = $entry['hooks'][0]['command'] ?? NULL;
      }
    }
    // The user's own Stop hook survives the merge, and the guard's is added in
    // the anchored form (R27-F1) — checked raw, since json_encode escapes it.
    $this->assertContains('echo mine', $commands);
    $this->assertContains('php "$CLAUDE_PROJECT_DIR/.claude/hooks/droost-workflow-guard.php" stop', $commands);

    $broken = $this->makeRoot();
    mkdir($broken . '/.claude', 0755, TRUE);
    file_put_contents($broken . '/.claude/settings.json', '{not json');
    $this->expectException(PackError::class);
    (new PackMaterializer())->init($broken);
  }

  /**
   * Shared .claude directories are populated but never claimed.
   */
  public function testSharedDirectoriesAreNeverSentinelled(): void {
    $root = $this->makeRoot();
    // A user's own command must neither block init nor be touched by it.
    mkdir($root . '/.claude/commands', 0755, TRUE);
    file_put_contents($root . '/.claude/commands/mine.md', "# Mine\n");

    (new PackMaterializer())->init($root);

    $this->assertFileExists($root . '/.claude/commands/droost/workflow/continue.md');
    $this->assertFileExists($root . '/.claude/hooks/droost-workflow-guard.php');
    $this->assertFileExists($root . '/.claude/agents/workflow-researcher.md');
    $this->assertSame("# Mine\n", file_get_contents($root . '/.claude/commands/mine.md'));

    foreach (PackManifest::SHARED_DIRS as $shared) {
      $this->assertFileDoesNotExist(
        $root . '/' . $shared . '/' . PackManifest::SENTINEL,
        $shared . ' is shared with the user and must never carry the sentinel',
      );
    }
    // The dedicated subdirectory keeps its sentinel — ownership did not
    // weaken, it just stopped over-reaching.
    $this->assertFileExists(
      $root . '/.claude/commands/droost/workflow/' . PackManifest::SENTINEL,
    );
  }

  /**
   * Run state is gitignored by default; an existing entry is respected.
   */
  public function testInitGitignoresRunState(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/.gitignore', "vendor/\n");

    $materializer = new PackMaterializer();
    $first = $materializer->init($root);
    $this->assertContains('.gitignore', $first->written);
    $contents = (string) file_get_contents($root . '/.gitignore');
    $this->assertStringContainsString("vendor/\n", $contents);
    $this->assertStringContainsString("droost/droost-workflow/\n", $contents);

    $second = $materializer->init($root);
    $this->assertContains('.gitignore', $second->kept);

    // A repo that already ignores it — in any spelling — is left alone.
    $spelled = $this->makeRoot();
    file_put_contents($spelled . '/.gitignore', "/droost/droost-workflow\n");
    $report = (new PackMaterializer())->init($spelled);
    $this->assertContains('.gitignore', $report->kept);
    $this->assertSame(
      "/droost/droost-workflow\n",
      file_get_contents($spelled . '/.gitignore'),
    );
  }

}
