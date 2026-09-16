<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Driver;

use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;

/**
 * An open run whose spec carries a `## Routes` section, for driver tests.
 *
 * The drivers read the run's spec through the state store, so a test that
 * wants the spec consulted needs a real run.json — begun the way the facade
 * begins one, not hand-written JSON that drifts from the state's shape.
 */
final class RunWithSpec {

  /**
   * Begins a run at `$root` governed by a spec whose routes body is `$routes`.
   *
   * @param string $root
   *   The project root (must exist).
   * @param string $routes
   *   The body of the `## Routes` section.
   */
  public static function open(string $root, string $routes): void {
    $dir = $root . '/droost/droost-workflow';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }
    file_put_contents($root . '/droost.workflow.yml', "preset: custom\nseekers: { on: false }\n");
    file_put_contents(
      $dir . '/spec-routes.md',
      "# Spec\n\n## Tooling plan\n\n- hand-written (fixture)\n\n## Routes\n\n" . $routes . "\n",
    );
    $config = WorkflowConfig::load($root);
    $state = RunState::begin('run-routes', '2026-09-16T12:00:00+00:00', $config, NULL, NULL)
      ->withSpecPath('droost/droost-workflow/spec-routes.md');
    (new RunStateStore($root))->save($state);
  }

  /**
   * Removes what open() wrote.
   *
   * @param string $root
   *   The project root.
   */
  public static function close(string $root): void {
    $dir = $root . '/droost/droost-workflow';
    foreach (glob($dir . '/*') ?: [] as $file) {
      is_dir($file) ? self::rmdir($file) : @unlink($file);
    }
    @rmdir($dir);
    @rmdir($root . '/droost');
    @unlink($root . '/droost.workflow.yml');
  }

  /**
   * Removes a directory tree.
   */
  private static function rmdir(string $dir): void {
    foreach (glob($dir . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
      if (basename($file) === '.' || basename($file) === '..') {
        continue;
      }
      is_dir($file) ? self::rmdir($file) : @unlink($file);
    }
    @rmdir($dir);
  }

}
