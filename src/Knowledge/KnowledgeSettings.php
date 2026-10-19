<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\Config\StoragePaths;
use Symfony\Component\Yaml\Yaml;

/**
 * What the knowledge commands are configured with, read from files only.
 *
 * The project's configuration (`droost/droost.yml`, or a root
 * `droost.workflow.yml` until `droost migrate` moves it) names the wiki's
 * directory and the embeddings endpoint. A site that kept those as Drupal
 * configuration still has them in its exported config, which is read when
 * the project's own file says nothing; drush is never asked.
 */
final class KnowledgeSettings {

  /**
   * The wiki's directory when nothing names another.
   */
  public const string DEFAULT_WIKI = 'droost/wiki';

  /**
   * Where exported config is looked for when settings.php names nothing.
   */
  private const array SYNC_CANDIDATES = ['config/sync', 'config/default', 'config/default/sync', 'sync'];

  /**
   * Constructs a KnowledgeSettings.
   *
   * @param string $projectRoot
   *   The project root, absolute.
   * @param string $docroot
   *   The docroot, absolute.
   * @param array<string, mixed> $config
   *   The project's configuration, parsed.
   */
  public function __construct(
    public readonly string $projectRoot,
    public readonly string $docroot,
    private readonly array $config,
  ) {}

  /**
   * The settings a project root holds.
   *
   * @param string $projectRoot
   *   The project root, absolute.
   *
   * @return self
   *   The settings.
   */
  public static function fromProjectRoot(string $projectRoot): self {
    $root = rtrim($projectRoot, '/');
    return new self($root, CitationResolver::docroot($root), StoragePaths::config($root));
  }

  /**
   * The wiki's directory, absolute.
   *
   * `wiki.path` in the project's configuration; else the `path` a site kept
   * in its exported `droost.wiki.settings.yml`; else droost/wiki. Relative
   * to the project root unless absolute.
   *
   * @return string
   *   The directory.
   */
  public function wikiDir(): string {
    $wiki = $this->config['wiki'] ?? NULL;
    $path = is_array($wiki) && is_string($wiki['path'] ?? NULL) ? trim($wiki['path']) : '';
    if ($path === '') {
      $path = $this->exportedValue('droost.wiki.settings', 'path');
    }
    if ($path === '') {
      $path = self::DEFAULT_WIKI;
    }
    return str_starts_with($path, '/') ? $path : $this->projectRoot . '/' . $path;
  }

  /**
   * The embeddings endpoint the project names, if any.
   *
   * @return array<string, string>
   *   `endpoint`, `model`, and whatever else `search.embeddings` holds as
   *   strings; empty when semantic search is not configured.
   */
  public function embeddings(): array {
    $search = $this->config['search'] ?? NULL;
    $embeddings = is_array($search) ? ($search['embeddings'] ?? NULL) : NULL;
    if (!is_array($embeddings)) {
      return [];
    }
    $out = [];
    foreach ($embeddings as $key => $value) {
      if (is_scalar($value)) {
        $out[(string) $key] = (string) $value;
      }
    }
    return $out;
  }

  /**
   * The directory the site exports its configuration to, if one is found.
   *
   * The `config_sync_directory` settings.php names, when it exists, else the
   * usual places. It is the configuration as last exported, which
   * is what a reader with no site can know.
   *
   * @return string|null
   *   The directory, absolute, holding core.extension.yml; NULL when none.
   */
  public function configSyncDir(): ?string {
    $candidates = [];
    foreach (['settings.php', 'settings.local.php', 'settings.ddev.php'] as $name) {
      $named = self::syncFromSettings($this->docroot . '/sites/default/' . $name, $this->docroot);
      if ($named !== NULL) {
        $candidates[] = $named;
      }
    }
    foreach (self::SYNC_CANDIDATES as $relative) {
      $candidates[] = $this->projectRoot . '/' . $relative;
    }
    foreach ($candidates as $dir) {
      if (is_file($dir . '/core.extension.yml')) {
        $real = realpath($dir);
        return $real === FALSE ? $dir : $real;
      }
    }
    return NULL;
  }

  /**
   * One value of one exported configuration object, '' when absent.
   *
   * @param string $name
   *   The configuration object's name.
   * @param string $key
   *   The top-level key.
   *
   * @return string
   *   The value.
   */
  public function exportedValue(string $name, string $key): string {
    $dir = $this->configSyncDir();
    if ($dir === NULL || !is_file($dir . '/' . $name . '.yml')) {
      return '';
    }
    try {
      $parsed = Yaml::parseFile($dir . '/' . $name . '.yml');
    }
    catch (\Throwable) {
      return '';
    }
    $value = is_array($parsed) ? ($parsed[$key] ?? NULL) : NULL;
    return is_string($value) ? trim($value) : '';
  }

  /**
   * The sync directory a settings file assigns, resolved; NULL when none.
   *
   * Reads the assignment's last quoted string: `'../config/sync'`, or the
   * tail of `$app_root . '/../config/sync'`. Relative paths are the
   * docroot's, as Drupal resolves them.
   *
   * @param string $file
   *   The settings file.
   * @param string $docroot
   *   The docroot.
   *
   * @return string|null
   *   The directory.
   */
  public static function syncFromSettings(string $file, string $docroot): ?string {
    $content = @file_get_contents($file);
    if (!is_string($content)) {
      return NULL;
    }
    $found = NULL;
    foreach (preg_split('/\R/', $content) ?: [] as $line) {
      $line = trim($line);
      if (str_starts_with($line, '#') || str_starts_with($line, '//') || str_starts_with($line, '*')) {
        continue;
      }
      if (!str_contains($line, 'config_sync_directory') || !str_contains($line, '=')) {
        continue;
      }
      if (preg_match_all('/[\'"]([^\'"]*)[\'"]/', substr($line, (int) strpos($line, '=')), $m) > 0) {
        $value = (string) end($m[1]);
        if ($value === '') {
          continue;
        }
        $concatenated = preg_match('/(\$app_root|DRUPAL_ROOT|__DIR__)\s*\./', $line) === 1;
        if (str_contains($line, '__DIR__')) {
          $found = dirname($file) . '/' . ltrim($value, '/');
        }
        elseif ($concatenated || !str_starts_with($value, '/')) {
          $found = rtrim($docroot, '/') . '/' . ltrim($value, '/');
        }
        else {
          $found = $value;
        }
      }
    }
    return $found;
  }

}
