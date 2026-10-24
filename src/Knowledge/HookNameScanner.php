<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

/**
 * Every hook the installation declares, from its `*.api.php` files.
 *
 * The graph extractor draws a procedural hook edge only for a name some
 * api.php declares (`function hook_<name>(`), so `mymodule_cron` is a hook
 * implementation and `mymodule_cron_helper` is not. A booted site asks its
 * module list where those files are; this walks the docroot for them.
 */
final class HookNameScanner {

  /**
   * Directories never descended into.
   */
  private const array SKIP = ['tests', 'node_modules', 'vendor', '.git', 'files', 'fixtures', 'assets', 'js', 'css'];

  /**
   * Extracts hook names from one api.php document.
   *
   * @param string $content
   *   The file's content.
   *
   * @return list<string>
   *   The names, without `hook_`, in file order.
   */
  public static function scan(string $content): array {
    if (preg_match_all('/^function hook_(\w+)\s*\(/m', $content, $matches) === FALSE) {
      return [];
    }
    return $matches[1];
  }

  /**
   * The hook names under a docroot.
   *
   * @param string $docroot
   *   The docroot, absolute.
   *
   * @return array<string, true>
   *   The names, as a set.
   */
  public static function hookNames(string $docroot): array {
    $names = [];
    foreach (['core', 'modules', 'profiles', 'themes'] as $top) {
      foreach (self::apiFiles(rtrim($docroot, '/') . '/' . $top, 0) as $file) {
        $content = @file_get_contents($file);
        foreach (is_string($content) ? self::scan($content) : [] as $name) {
          $names[$name] = TRUE;
        }
      }
    }
    return $names;
  }

  /**
   * The `*.api.php` files under a directory.
   *
   * @param string $dir
   *   The directory.
   * @param int $depth
   *   How deep this call is.
   *
   * @return list<string>
   *   The files.
   */
  private static function apiFiles(string $dir, int $depth): array {
    if ($depth > 10 || !is_dir($dir)) {
      return [];
    }
    $out = [];
    $entries = @scandir($dir);
    foreach ($entries === FALSE ? [] : $entries as $name) {
      if ($name === '.' || $name === '..') {
        continue;
      }
      $path = $dir . '/' . $name;
      if (is_dir($path) && !is_link($path)) {
        if (!in_array($name, self::SKIP, TRUE)) {
          array_push($out, ...self::apiFiles($path, $depth + 1));
        }
      }
      elseif (str_ends_with($name, '.api.php')) {
        $out[] = $path;
      }
    }
    return $out;
  }

}
