<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Droost's site-write tools are held to the run, as the edit wall holds files.
 *
 * The droost module's tools change the live site (entities, configuration,
 * Views, modules) and run from the shell: `drush droost:tool <id>`. A whole
 * content model can be built that way without touching a file, so the edit
 * wall never saw it. The guard reads the tool's id from the command and holds
 * a write tool to the run: refused in plan, and refused with no run open
 * under require_run: hard. Reading tools stay free, as reading files does.
 */
final class GuardSiteToolTest extends WorkflowTestCase {

  /**
   * The guard script.
   */
  private const GUARD = __DIR__ . '/../../pack/hooks/droost-workflow-guard.php';

  /**
   * A write tool is refused in plan, through every door to drush.
   */
  public function testWriteToolsWaitForCode(): void {
    $root = $this->rootWithRun('plan');
    $commands = [
      "drush droost:tool droost_entity_create '{\"entity_type\":\"node\"}'",
      "ddev drush droost:tool droost_config_set '{\"name\":\"system.site\"}'",
      "ddev exec \"drush droost:tool droost_structure_create '{}'\"",
      "vendor/bin/drush tool:run droost_views_compose --input='{}'",
      'drush dtool droost_canvas_tree_set',
      "drush droost:tool droost_canvas_variant_tree_set '{\"variant\":\"site\"}'",
      "drush droost:tool droost_canvas_component_toggle '{\"enabled\":false}'",
    ];
    foreach ($commands as $command) {
      [$exit, , $stderr] = $this->bash($root, $command);
      $this->assertSame(2, $exit, $command);
      $this->assertStringContainsString('still in PLAN', $stderr, $command);
    }
  }

  /**
   * Reading tools are free in plan: looking is what plan is for.
   */
  public function testReadToolsAreFreeInPlan(): void {
    $root = $this->rootWithRun('plan');
    foreach (['drush droost:tool droost_entities', 'drush droost:tool droost_config_get', 'drush droost:tool'] as $command) {
      [$exit] = $this->bash($root, $command);
      $this->assertSame(0, $exit, $command);
    }
  }

  /**
   * In code, a write tool runs.
   */
  public function testWriteToolsRunInCode(): void {
    [$exit] = $this->bash($this->rootWithRun('code'), "drush droost:tool droost_entity_create '{}'");
    $this->assertSame(0, $exit);
  }

  /**
   * With no run open, a write tool is refused under require_run: hard.
   */
  public function testWriteToolsNeedAnOpenRun(): void {
    $root = $this->makeRoot();
    [$exit, , $stderr] = $this->bash($root, "drush droost:tool droost_entity_update '{}'");
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('no active run', $stderr);

    file_put_contents($root . '/droost.workflow.yml', "require_run: off\n");
    mkdir($root . '/droost', 0755, TRUE);
    file_put_contents($root . '/droost/droost.yml', "require_run: off\n");
    [$exit] = $this->bash($root, "drush droost:tool droost_entity_update '{}'");
    $this->assertSame(0, $exit, 'require_run: off lets it through');
  }

  /**
   * An operator's bypass lets a write tool run with no run open.
   */
  public function testBypassLetsWriteThrough(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/bypass.json', (string) json_encode([
      'reason' => 'a one-off content fix',
      'granted_at' => '2026-10-24T00:00:00+00:00',
    ]));
    [$exit] = $this->bash($root, "drush droost:tool droost_entity_update '{}'");
    $this->assertSame(0, $exit);
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
      'run_id' => 'run-site-tool',
      'current_phase' => $phase,
      'phases' => [$phase => 'active'],
      'enforcement' => 'hard',
      'mode' => 'interactive',
    ]));
    return $root;
  }

  /**
   * Runs the Bash guard on one command.
   *
   * @param string $root
   *   The project root.
   * @param string $command
   *   The command.
   *
   * @return array{0: int, 1: string, 2: string}
   *   The exit code, stdout and stderr.
   */
  private function bash(string $root, string $command): array {
    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $root;
    $process = proc_open(
      [PHP_BINARY, '-d', 'display_errors=stderr', self::GUARD, 'operator-commands'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
      $env,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode(['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]));
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
  }

}
