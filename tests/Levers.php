<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

/**
 * Where a test writes a project's lever file: `droost/droost.yml`.
 */
final class Levers {

  /**
   * The lever file's path under a project root, its directory created.
   *
   * @param string $root
   *   The project root.
   *
   * @return string
   *   The absolute path of `droost/droost.yml`.
   */
  public static function path(string $root): string {
    $dir = rtrim($root, '/') . '/droost';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }

    return $dir . '/droost.yml';
  }

}
