<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The editor makes no new file in droost's state directory (F-345).
 *
 * A new file of another name there stays open to the shell, on purpose: an
 * agent hands a script to the container through it, copying it in, running
 * it and removing it in one command. A file the Write tool makes there can
 * never be run or removed afterwards, because the next command finds it
 * already there and the guard protects every file already there. KCH-A run 2
 * wrote `r01-config.php` with Write, was refused running it, and left it for
 * the operator to remove.
 */
final class GuardStateScratchTest extends WorkflowTestCase {

  /**
   * The editor is refused a new scratch file, and told where it goes.
   */
  public function testEditorIsRefusedNewScratchFile(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $dir = $root . '/droost/droost-workflow';
    @mkdir($dir, 0755, TRUE);

    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $dir . '/r01-config.php', 'content' => "<?php\n"],
    ]);
    $this->assertSame(2, $exit, 'the Write tool makes no new file in the state directory');
    $this->assertStringContainsString('cat > /tmp/', $stderr, 'and says where a scratch file goes');
    $this->assertFileDoesNotExist($dir . '/r01-config.php');

    [$exit] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $dir . '/spec.md', 'content' => "# spec\n"],
    ]);
    $this->assertSame(0, $exit, 'the spec is the agent\'s to write');
  }

  /**
   * The shell's one-command hand-off stays open.
   */
  public function testShellHandOffStaysOpen(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    @mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    $command = 'cp /tmp/x.php droost/droost-workflow/x.php && ddev drush php:script droost/droost-workflow/x.php; rm droost/droost-workflow/x.php';
    $call = ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]];
    [$exit, , $stderr] = $this->guard($root, 'operator-commands', $call);
    $this->assertSame(0, $exit, $stderr);
  }

}
