<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\Cli\ArgvDispatcher;

/**
 * Finds the project a `droost` command is run in.
 *
 * The package lives in its own vendor directory (`tools/droost/vendor/`), so
 * the autoloader's location says nothing about the project. The host says it
 * when it can (`CLAUDE_PROJECT_DIR`, the same answer the guard reads);
 * otherwise the nearest directory up from where the command was run that
 * holds droost's configuration or state, stopping at a repository's root.
 */
final class ProjectLocator {

  /**
   * What marks a directory as a droost project, nearest first.
   */
  private const array MARKERS = [
    'droost/droost.yml',
    'droost/droost-workflow',
    'droost.workflow.yml',
    '.droost-workflow',
  ];

  /**
   * The project root for a working directory.
   *
   * @param string $cwd
   *   The working directory.
   * @param string|false $host
   *   The host's project directory (`CLAUDE_PROJECT_DIR`), FALSE when unset.
   *
   * @return string
   *   The project root, absolute; the working directory when nothing marks
   *   one above it.
   */
  public static function locate(string $cwd, string|false $host = FALSE): string {
    if (is_string($host) && $host !== '' && is_dir($host)) {
      $real = realpath($host);
      return $real === FALSE ? rtrim($host, '/') : $real;
    }
    $start = realpath($cwd);
    $start = $start === FALSE ? rtrim($cwd, '/') : $start;
    // THE WORKFLOW'S RULE FIRST (F-506): a run's record wins outright, then
    // the nearest lever file, and a repository's top is as far as it goes.
    // This walk took the nearest state DIRECTORY instead, so a stray one
    // (the droost module made one beside a site nested in a larger project)
    // was the project for `droost code:*` while `droost workflow:*` stayed
    // where the lever file is. The walk below is the fallback, for a
    // project with a state directory and no lever file or repository.
    $ruled = ArgvDispatcher::projectAbove($start);
    if ($ruled !== $start) {
      return $ruled;
    }
    $dir = $start;
    for ($depth = 0; $depth < 32; $depth++) {
      foreach (self::MARKERS as $marker) {
        if (file_exists($dir . '/' . $marker)) {
          return $dir;
        }
      }
      // A repository's root is as far as a project reaches.
      if (file_exists($dir . '/.git')) {
        return $dir;
      }
      $parent = dirname($dir);
      if ($parent === $dir) {
        break;
      }
      $dir = $parent;
    }
    return $start;
  }

}
