<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * Where a project's levers live: `droost/droost.yml`, once in one place.
 *
 * Droost left Drupal (2026-10-19) and its configuration moved into the
 * project's visible `droost/` folder, beside the wiki, the baseline and the
 * run state already there. The root `droost.workflow.yml` is still read for
 * one release, and every surface that reports levers says so in a notice that
 * names the new file, so a project is never moved silently and never left on
 * the old name without being told.
 *
 * Everything that reads, writes or protects the lever file asks this class,
 * and the guard (a standalone hook with no autoloader) carries the same rule
 * inlined, held to it by `PackGuardParityTest`.
 */
final class ConfigLocator {

  /**
   * The lever file, relative to the project root.
   */
  public const PATH = 'droost/droost.yml';

  /**
   * The lever file's name before droost left Drupal, read for one release.
   */
  public const LEGACY = 'droost.workflow.yml';

  /**
   * The text added to the AGENTS block, relative to the project root.
   */
  public const AGENTS = 'droost/agents.md';

  /**
   * The directories of the project's own gate, hook and wall files.
   */
  public const RULE_DIRS = ['droost/gates', 'droost/hooks', 'droost/walls'];

  /**
   * The lever file in force, relative to the root, or NULL when there is none.
   *
   * `droost/droost.yml` wins when both exist: the new file is the one a
   * project moved to, and the old one left beside it is history.
   *
   * @param string $root
   *   The project root.
   *
   * @return string|null
   *   `droost/droost.yml`, `droost.workflow.yml`, or NULL.
   */
  public static function found(string $root): ?string {
    foreach ([self::PATH, self::LEGACY] as $relative) {
      $path = rtrim($root, '/') . '/' . $relative;
      // is_link() as well: a dangling link IS something there, and the loader
      // refuses it rather than falling back to the built-in levers.
      if (is_link($path) || file_exists($path)) {
        return $relative;
      }
    }

    return NULL;
  }

  /**
   * The lever file to read or write, relative to the root.
   *
   * @param string $root
   *   The project root.
   *
   * @return string
   *   The file in force, or the new location when there is none yet.
   */
  public static function relative(string $root): string {
    return self::found($root) ?? self::PATH;
  }

  /**
   * The lever file to read or write, as an absolute path.
   *
   * @param string $root
   *   The project root.
   *
   * @return string
   *   The absolute path.
   */
  public static function absolute(string $root): string {
    return rtrim($root, '/') . '/' . self::relative($root);
  }

  /**
   * Whether the project still keeps its levers at the old root name.
   *
   * @param string $root
   *   The project root.
   *
   * @return bool
   *   TRUE when the legacy file is the one in force.
   */
  public static function isLegacy(string $root): bool {
    return self::found($root) === self::LEGACY;
  }

  /**
   * The notice every lever report carries while the legacy file is in force.
   *
   * @return string
   *   The notice.
   */
  public static function legacyNotice(): string {
    return sprintf(
      '%s at the project root is the old place for the levers: droost now '
      . 'reads %s. This release still reads the old file; the next does not. '
      . 'Move it with `droost migrate` (or `git mv %1$s %2$s`).',
      self::LEGACY,
      self::PATH,
    );
  }

  /**
   * Whether a project-relative path is a lever file, new or old.
   *
   * @param string $relative
   *   The normalised project-relative path.
   *
   * @return bool
   *   TRUE for `droost/droost.yml` or the root `droost.workflow.yml`.
   */
  public static function isLeverPath(string $relative): bool {
    return $relative === self::PATH || $relative === self::LEGACY;
  }

  /**
   * Whether a project-relative path is one of the run's frozen rules.
   *
   * The lever file, the AGENTS text and every file under the gate, hook and
   * wall directories: what a run is held to, and what the guard refuses to
   * let the agent rewrite while one is under way.
   *
   * @param string $relative
   *   The normalised project-relative path.
   *
   * @return bool
   *   TRUE when the path is part of the rules.
   */
  public static function isRulePath(string $relative): bool {
    if (self::isLeverPath($relative) || $relative === self::AGENTS) {
      return TRUE;
    }
    foreach (self::RULE_DIRS as $dir) {
      if ($relative === $dir || str_starts_with($relative, $dir . '/')) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
