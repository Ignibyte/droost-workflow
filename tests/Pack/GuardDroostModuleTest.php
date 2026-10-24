<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The droost module is not the run's to rewrite (F-367).
 *
 * Its Canvas tools validate a page's tree and lint a component, and a
 * project's component_lint gate calls the same linter. Kept in droost/gates/
 * those checks were frozen with the project's rules; in the module they sit
 * in contrib, where nothing stopped an edit that made every tree valid.
 */
final class GuardDroostModuleTest extends WorkflowTestCase {

  /**
   * The module's linter, where droost_cms's site keeps it.
   */
  private const LINTER = 'web/modules/contrib/droost/modules/droost_canvas/src/ComponentLinter.php';

  /**
   * The editor cannot write into the module, in any phase.
   */
  public function testTheEditorIsRefused(): void {
    $root = $this->rootWithRun('code');
    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Edit',
      'tool_input' => ['file_path' => $root . '/' . self::LINTER, 'old_string' => 'a', 'new_string' => 'b'],
    ]);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('the droost module', $stderr);
  }

  /**
   * Nor can the shell.
   */
  public function testTheShellIsRefused(): void {
    $root = $this->rootWithRun('code');
    foreach (["sed -i 's/blockers/none/' " . self::LINTER, 'echo x > ' . self::LINTER] as $command) {
      $payload = ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]];
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', $payload);
      $this->assertSame(2, $exit, $command);
      $this->assertStringContainsString('the droost module', $stderr, $command);
    }
  }

  /**
   * Reading it, and writing beside it, stay free.
   */
  public function testReadingAndItsNeighboursAreFree(): void {
    $root = $this->rootWithRun('code');
    $module = 'web/modules/contrib/droost';
    foreach (['cat ' . self::LINTER, "git -C $module remote -v", "git -C $module log --oneline -3"] as $command) {
      $payload = ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]];
      [$exit] = $this->guard($root, 'operator-commands', $payload);
      $this->assertSame(0, $exit, $command);
    }
    $added = "git -C $module remote add evil https://example.com/x.git";
    $payload = ['tool_name' => 'Bash', 'tool_input' => ['command' => $added]];
    [$exit] = $this->guard($root, 'operator-commands', $payload);
    $this->assertSame(2, $exit, 'a remote that is added is written');
    [$exit] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $root . '/web/modules/contrib/droost_theme_extra/x.php', 'content' => '<?php'],
    ]);
    $this->assertSame(0, $exit, 'a module whose name only starts with droost is not droost');
  }

  /**
   * A project root with a run open at a phase, at hard enforcement.
   *
   * @param string $phase
   *   The phase.
   *
   * @return string
   *   The root.
   */
  private function rootWithRun(string $phase): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-module',
      'current_phase' => $phase,
      'phases' => [$phase => 'active'],
      'enforcement' => 'hard',
      'mode' => 'interactive',
    ]));
    return $root;
  }

}
