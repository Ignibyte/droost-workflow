<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

use Droost\Workflow\State\RunStateStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Where droost's SQLite stores live: the one place that answers it.
 *
 * The code index and the evidence store are files in the state directory by
 * default. A project may name other places in its configuration's `storage:`
 * block, which is how a Cockpit can hold them later:
 *
 * @code
 * storage:
 *   index: droost/droost-workflow/code-index.sqlite
 *   evidence: droost/droost-workflow/evidence.sqlite
 * @endcode
 *
 * A relative path is the project root's; an absolute one is kept. Anything
 * that reads or writes a store asks here, so the answer cannot differ
 * between a writer and a reader.
 */
final class StoragePaths {

  /**
   * The project's configuration file (ConfigLocator owns the rule).
   */
  public const string CONFIG_FILE = ConfigLocator::PATH;

  /**
   * The configuration file a project kept before droost/droost.yml.
   */
  public const string LEGACY_CONFIG_FILE = ConfigLocator::LEGACY;

  /**
   * The code index's file name, in the state directory.
   */
  public const string INDEX_FILE = 'code-index.sqlite';

  /**
   * The evidence store's file name, in the state directory.
   */
  public const string EVIDENCE_FILE = 'evidence.sqlite';

  /**
   * The configuration file a project holds, or NULL when it holds none.
   *
   * `droost/droost.yml` first; a root `droost.workflow.yml` is read until the
   * project runs `droost migrate`.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return string|null
   *   The file, absolute.
   */
  public static function configFile(string $projectRoot): ?string {
    // One rule for where the file is, shared with the workflow's loader and
    // the guard, so the knowledge commands never read a different file.
    $relative = ConfigLocator::found($projectRoot);
    return $relative === NULL ? NULL : rtrim($projectRoot, '/') . '/' . $relative;
  }

  /**
   * The project's configuration, parsed; an empty map when there is none.
   *
   * Never throws: a configuration that will not parse is read as none here,
   * and the workflow, which reads the same file, says what is wrong with it.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return array<string, mixed>
   *   The configuration.
   */
  public static function config(string $projectRoot): array {
    $file = self::configFile($projectRoot);
    if ($file === NULL) {
      return [];
    }
    try {
      $parsed = Yaml::parseFile($file);
    }
    catch (\Throwable) {
      return [];
    }
    if (!is_array($parsed)) {
      return [];
    }
    $out = [];
    foreach ($parsed as $key => $value) {
      $out[(string) $key] = $value;
    }
    return $out;
  }

  /**
   * The code index's file, absolute.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return string
   *   The path, whether or not the file exists.
   */
  public static function index(string $projectRoot): string {
    return self::resolve($projectRoot, 'index', self::INDEX_FILE);
  }

  /**
   * The evidence store's file, absolute.
   *
   * @param string $projectRoot
   *   The project root.
   *
   * @return string
   *   The path, whether or not the file exists.
   */
  public static function evidence(string $projectRoot): string {
    return self::resolve($projectRoot, 'evidence', self::EVIDENCE_FILE);
  }

  /**
   * One store's path: configured, or the state directory's.
   *
   * @param string $projectRoot
   *   The project root.
   * @param string $key
   *   The `storage:` key.
   * @param string $file
   *   The default file name.
   *
   * @return string
   *   The path, absolute.
   */
  private static function resolve(string $projectRoot, string $key, string $file): string {
    $root = rtrim($projectRoot, '/');
    $storage = self::config($root)['storage'] ?? NULL;
    $configured = is_array($storage) && is_string($storage[$key] ?? NULL) ? trim($storage[$key]) : '';
    if ($configured !== '') {
      return str_starts_with($configured, '/') ? $configured : $root . '/' . $configured;
    }
    return $root . '/' . RunStateStore::resolveStateDir($root) . '/' . $file;
  }

}
