<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * The project's own rule files a run is held to, and their hashes.
 *
 * `droost/droost.yml` points gates, hooks and walls at files, and
 * `droost/agents.md` is the text the AGENTS block carries. A run records
 * each one's sha256 when it begins, so a gate whose file changed under the
 * run says so at its verdict rather than judging by a rule the run never
 * agreed to — and the guard refuses an edit to any of them while a run is
 * under way (the guard's own rule-path check; probe-rule-freeze holds it).
 */
final class RuleFiles {

  /**
   * Every rule file the levers name, with its sha256 now.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The resolved levers.
   *
   * @return array<string, string|null>
   *   Project-relative path => sha256, or NULL for a file that is not there
   *   (named by the levers and missing is a fact worth freezing too).
   */
  public static function hash(string $root, WorkflowConfig $config): array {
    $paths = [];
    foreach ($config->gates as $gate) {
      $run = $gate->option('run');
      if (is_string($run) && $run !== '') {
        $paths[] = $run;
      }
    }
    foreach ($config->hooks as $file) {
      $paths[] = $file;
    }
    foreach ($config->walls as $wall) {
      $paths[] = $wall['run'];
    }
    // A panel tab only draws, but what it draws is what a person reads about
    // the run: frozen with the rest, so a run cannot rewrite its own tab.
    foreach ($config->panelTabs as $tab) {
      $paths[] = $tab['run'];
    }
    $paths[] = ConfigLocator::AGENTS;

    $hashes = [];
    foreach (array_unique($paths) as $path) {
      $hashes[$path] = self::sha($root, $path);
    }
    ksort($hashes);
    return $hashes;
  }

  /**
   * One file's sha256, or NULL when it is not a readable regular file.
   *
   * @param string $root
   *   The project root.
   * @param string $path
   *   The project-relative path.
   *
   * @return string|null
   *   The hash, or NULL.
   */
  public static function sha(string $root, string $path): ?string {
    $file = rtrim($root, '/') . '/' . $path;
    if (!is_file($file) || !is_readable($file)) {
      return NULL;
    }
    $hash = @hash_file('sha256', $file);
    return is_string($hash) ? $hash : NULL;
  }

  /**
   * Whether a rule file is not what it was when the run began.
   *
   * @param string $root
   *   The project root.
   * @param array<string, string|null> $frozen
   *   The run's frozen hashes.
   * @param string $path
   *   The project-relative path.
   *
   * @return bool
   *   TRUE when the run froze the file and it differs now (appeared,
   *   vanished or changed); FALSE when unchanged or never frozen.
   */
  public static function changed(string $root, array $frozen, string $path): bool {
    if (!array_key_exists($path, $frozen)) {
      return FALSE;
    }
    return self::sha($root, $path) !== $frozen[$path];
  }

}
