<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

/**
 * The droost Claude Code mod, copied into a project only on a yes.
 *
 * Owner, 2026-10-01: the install must make sure it is all right to add the
 * mod. A mod runs inside Claude Code with the user's permissions, so nothing
 * here runs it or enables it. This copies the mod's marketplace into
 * `.claude/droost-plugins/`, and the operator turns it on in their own
 * terminal with Claude Code's commands, which write Claude Code's settings
 * themselves (`hostCommands()`). On a ddev site the installer runs in the
 * container, where Claude Code is not and the host's path is unknown, so
 * the operator's commands are also the only way the path comes out right.
 *
 * Copied, never linked: the guard protects the directory, and a project
 * that re-installs gets the pack's current mod, reported as updated.
 */
final class ClaudeMod {

  /**
   * Where the mod's marketplace lands in a project.
   */
  public const DIRECTORY = '.claude/droost-plugins';

  /**
   * The marketplace name the pack ships, and an organization's.
   *
   * One name for every project was F-255: Claude Code keeps marketplaces by
   * name for the whole user, so the last project to add its copy re-pointed
   * every other project's mod at its own. A project's copy is named for the
   * project (`marketplace()`); this name stays for managed settings, where an
   * organization keeps one marketplace for everyone.
   */
  public const SHARED = 'droost';

  /**
   * The plugin's install id under the shared name.
   *
   * The mod's name, `@`, the marketplace's. A project's own is `plugin()`.
   */
  public const PLUGIN = 'droost-guard@' . self::SHARED;

  /**
   * The pack's copy of the marketplace.
   *
   * @param string|null $packageRoot
   *   The package root; this package's when NULL.
   *
   * @return string
   *   The absolute directory.
   */
  public static function source(?string $packageRoot = NULL): string {
    // Beside the pack, not in it: pack/ is what init materializes into every
    // project, and the mod reaches a project only on a yes.
    return ($packageRoot ?? dirname(__DIR__, 2)) . '/claude-plugins';
  }

  /**
   * The marketplace name a project's copy carries (F-255).
   *
   * The name its copy already has, when it has one of its own; otherwise one
   * made for it (`nameFor()`).
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return string
   *   `droost-<project>`.
   */
  public static function marketplace(string $projectRoot): string {
    $raw = @file_get_contents(rtrim($projectRoot, '/') . '/' . self::DIRECTORY . '/.claude-plugin/marketplace.json');
    $doc = is_string($raw) ? json_decode($raw, TRUE) : NULL;
    $name = is_array($doc) && is_string($doc['name'] ?? NULL) ? $doc['name'] : '';
    return $name !== '' && $name !== self::SHARED ? $name : self::nameFor($projectRoot);
  }

  /**
   * The marketplace name made for a project.
   *
   * By the ddev project's name when there is one: on ddev the install runs
   * in the container, where every project's root is `/var/www/html`, and
   * ddev keeps project names unique. Off ddev, by the project's directory.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return string
   *   `droost-` and a slug of lowercase letters, digits and hyphens.
   */
  public static function nameFor(string $projectRoot): string {
    $ddev = getenv('DDEV_PROJECT');
    $base = is_string($ddev) && $ddev !== '' ? $ddev : basename((string) (realpath($projectRoot) ?: $projectRoot));
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($base)), '-');
    return self::SHARED . '-' . ($slug !== '' ? $slug : 'project');
  }

  /**
   * A project's install id for the mod.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return string
   *   `droost-guard@droost-<project>`.
   */
  public static function plugin(string $projectRoot): string {
    return 'droost-guard@' . self::marketplace($projectRoot);
  }

  /**
   * Whether a project already carries the mod: an earlier yes.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return bool
   *   TRUE when its marketplace file is there.
   */
  public static function present(string $projectRoot): bool {
    return is_file(rtrim($projectRoot, '/') . '/' . self::DIRECTORY . '/.claude-plugin/marketplace.json');
  }

  /**
   * Copies the mod's marketplace into a project.
   *
   * @param string $projectRoot
   *   The project root.
   * @param string|null $packageRoot
   *   The package root; this package's when NULL.
   *
   * @return string
   *   `written` for a first copy, `updated` when an earlier yes is refreshed.
   *
   * @throws \RuntimeException
   *   When the pack carries no mod or a file cannot be written.
   */
  public static function install(string $projectRoot, ?string $packageRoot = NULL): string {
    $from = self::source($packageRoot);
    if (!is_dir($from)) {
      throw new \RuntimeException(sprintf('This droost/workflow carries no Claude Code mod at %s', $from));
    }
    $was = self::present($projectRoot);
    // Read before the copy overwrites it with the pack's shared name.
    $name = self::marketplace($projectRoot);
    $to = rtrim($projectRoot, '/') . '/' . self::DIRECTORY;
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $relative = substr($file->getPathname(), strlen($from) + 1);
      $target = $to . '/' . $relative;
      if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, TRUE) && !is_dir(dirname($target))) {
        throw new \RuntimeException(sprintf('Could not create %s', dirname($target)));
      }
      if (!copy($file->getPathname(), $target)) {
        throw new \RuntimeException(sprintf('Could not write %s', $target));
      }
    }
    $file = $to . '/.claude-plugin/marketplace.json';
    $doc = json_decode((string) file_get_contents($file), TRUE);
    if (!is_array($doc)) {
      throw new \RuntimeException(sprintf('The mod\'s marketplace file %s is not JSON', $file));
    }
    $doc['name'] = $name;
    if (file_put_contents($file, json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === FALSE) {
      throw new \RuntimeException(sprintf('Could not write %s', $file));
    }
    return $was ? 'updated' : 'written';
  }

  /**
   * The commands the operator runs, on the host, to turn the mod on.
   *
   * @param string|null $projectRoot
   *   The project, whose own marketplace name the install names; NULL names
   *   the shared one, as before F-255.
   *
   * @return list<string>
   *   Add the project's marketplace, then install the mod into the project.
   */
  public static function hostCommands(?string $projectRoot = NULL): array {
    return [
      'claude plugin marketplace add ./' . self::DIRECTORY . ' --scope project',
      'claude plugin install ' . ($projectRoot === NULL ? self::PLUGIN : self::plugin($projectRoot)) . ' --scope project',
    ];
  }

  /**
   * The managed settings that run the mod ahead of every user's mod.
   *
   * A repository cannot set these; an organization can (`prependPlugins`
   * reads managed settings only). Printed, never written.
   *
   * @param string $absoluteDirectory
   *   Where the organization keeps the marketplace, by absolute path.
   *
   * @return string
   *   The JSON to merge into managed-settings.json.
   */
  public static function managedSettings(string $absoluteDirectory): string {
    return (string) json_encode([
      'extraKnownMarketplaces' => [
        self::SHARED => ['source' => ['source' => 'directory', 'path' => $absoluteDirectory]],
      ],
      'enabledPlugins' => [self::PLUGIN => TRUE],
      'prependPlugins' => [self::PLUGIN, 'sec-default@builtin'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

}
