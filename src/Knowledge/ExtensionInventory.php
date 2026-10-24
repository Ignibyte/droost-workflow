<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Symfony\Component\Yaml\Yaml;

/**
 * Every module, theme and profile the project carries, read from files.
 *
 * What is installed and at which version comes from composer.lock; what an
 * extension is, needs and provides from its own `*.info.yml`,
 * `*.routing.yml`, `*.services.yml` and `*.permissions.yml`; what is enabled
 * from the exported `core.extension.yml`, which is only as fresh as the last
 * export and says so. Core's own modules are counted, not paged: they are
 * Drupal's, and the agent knows them.
 *
 * The pages are generated, never written into the wiki's directory: the
 * wiki is what people wrote, and this is what the files say today.
 */
final class ExtensionInventory {

  /**
   * The directory the inventory's pages appear under, in the wiki.
   */
  public const string PREFIX = 'inventory/';

  /**
   * Directory names never descended into.
   */
  private const array SKIP = ['tests', 'node_modules', 'vendor', '.git', 'files', 'fixtures'];

  /**
   * The extensions, keyed by machine name, once read.
   *
   * @var array<string, array{machine: string, name: string, type: string, scope: string, path: string, description: string, package: string, version: string, composer_package: string, dependencies: list<string>, routes: array<string, string>, services: list<string>, permissions: list<string>, enabled: bool|null}>|null
   */
  private ?array $extensions = NULL;

  /**
   * The exported extension list, once read: NULL when there is none.
   *
   * @var array{modules: array<string, true>, themes: array<string, true>, file: string, exported: string}|false|null
   */
  private array|false|null $exported = NULL;

  /**
   * How many of core's own modules and themes there are.
   */
  private int $coreCount = 0;

  /**
   * Constructs an ExtensionInventory.
   *
   * @param \Droost\Workflow\Knowledge\KnowledgeSettings $settings
   *   Where the project, its docroot and its exported config are.
   */
  public function __construct(private readonly KnowledgeSettings $settings) {}

  /**
   * The extensions, by machine name, custom first, then contrib, then other.
   *
   * @return array<string, array{machine: string, name: string, type: string, scope: string, path: string, description: string, package: string, version: string, composer_package: string, dependencies: list<string>, routes: array<string, string>, services: list<string>, permissions: list<string>, enabled: bool|null}>
   *   The extensions.
   */
  public function extensions(): array {
    if ($this->extensions !== NULL) {
      return $this->extensions;
    }
    $docroot = rtrim($this->settings->docroot, '/');
    $lock = $this->lockedPackages();
    $exported = $this->exported();
    $found = [];
    foreach (['modules', 'themes', 'profiles'] as $top) {
      foreach ($this->infoFiles($docroot . '/' . $top, 0) as $info) {
        $entry = $this->read($info, $docroot, $lock, $exported);
        if ($entry !== NULL && !isset($found[$entry['machine']])) {
          $found[$entry['machine']] = $entry;
        }
      }
    }
    $this->coreCount = count($this->infoFiles($docroot . '/core/modules', 0))
      + count($this->infoFiles($docroot . '/core/themes', 0));
    $order = ['custom' => 0, 'contrib' => 1, 'other' => 2];
    uasort($found, static function (array $a, array $b) use ($order): int {
      return [$order[$a['scope']] ?? 3, $a['machine']] <=> [$order[$b['scope']] ?? 3, $b['machine']];
    });
    return $this->extensions = $found;
  }

  /**
   * The exported extension list, or FALSE when nothing was exported.
   *
   * @return array{modules: array<string, true>, themes: array<string, true>, file: string, exported: string}|false
   *   What core.extension.yml enables, its file, and when it was written.
   */
  public function exported(): array|false {
    if ($this->exported !== NULL) {
      return $this->exported;
    }
    $dir = $this->settings->configSyncDir();
    if ($dir === NULL) {
      return $this->exported = FALSE;
    }
    $file = $dir . '/core.extension.yml';
    try {
      $parsed = Yaml::parseFile($file);
    }
    catch (\Throwable) {
      return $this->exported = FALSE;
    }
    $modules = [];
    $themes = [];
    if (is_array($parsed)) {
      foreach (is_array($parsed['module'] ?? NULL) ? $parsed['module'] : [] as $name => $weight) {
        $modules[(string) $name] = TRUE;
      }
      foreach (is_array($parsed['theme'] ?? NULL) ? $parsed['theme'] : [] as $name => $weight) {
        $themes[(string) $name] = TRUE;
      }
    }
    $mtime = @filemtime($file);
    return $this->exported = [
      'modules' => $modules,
      'themes' => $themes,
      'file' => $file,
      'exported' => $mtime === FALSE ? '' : date('c', $mtime),
    ];
  }

  /**
   * The inventory's pages, by wiki path.
   *
   * @return array<string, string>
   *   `inventory/index.md` and one `inventory/<machine>.md` per extension.
   */
  public function pages(): array {
    $pages = [self::PREFIX . 'index.md' => $this->indexPage()];
    foreach ($this->extensions() as $machine => $entry) {
      $pages[self::PREFIX . $machine . '.md'] = self::renderPage($entry, $this->exported());
    }
    return $pages;
  }

  /**
   * One page, by its wiki path; NULL when there is no such page.
   *
   * @param string $path
   *   The path: `inventory/<machine>.md`, `inventory/<machine>`, or
   *   `inventory`.
   *
   * @return string|null
   *   The page's markdown.
   */
  public function page(string $path): ?string {
    $path = trim($path, '/');
    if ($path === 'inventory' || $path === 'inventory/index' || $path === 'inventory/index.md') {
      return $this->indexPage();
    }
    if (!str_starts_with($path, self::PREFIX)) {
      return NULL;
    }
    $machine = substr($path, strlen(self::PREFIX));
    $machine = str_ends_with($machine, '.md') ? substr($machine, 0, -3) : $machine;
    $entry = $this->extensions()[$machine] ?? NULL;
    return $entry === NULL ? NULL : self::renderPage($entry, $this->exported());
  }

  /**
   * The inventory's index page.
   *
   * @return string
   *   The markdown.
   */
  public function indexPage(): string {
    $exported = $this->exported();
    $lines = ['# Installed modules and themes', ''];
    $lines[] = $exported === FALSE
      ? 'Enabled: unknown. No exported `core.extension.yml` was found, so this lists what is installed, not what is on.'
      : sprintf('Enabled: as of the last configuration export, %s (`%s`). A module enabled or removed since is not reflected until the next export.', $exported['exported'], $this->relative($exported['file']));
    $lines[] = '';
    $extensions = $this->extensions();
    foreach (['custom' => 'Custom', 'contrib' => 'Contrib', 'other' => 'Other'] as $scope => $label) {
      $rows = array_filter($extensions, static fn (array $e): bool => $e['scope'] === $scope);
      if ($rows === []) {
        continue;
      }
      $lines[] = sprintf('## %s (%d)', $label, count($rows));
      $lines[] = '';
      foreach ($rows as $entry) {
        $lines[] = sprintf(
          '- [%s](%s.md) %s%s%s: %s',
          $entry['machine'],
          $entry['machine'],
          $entry['type'],
          $entry['version'] !== '' ? ' ' . $entry['version'] : '',
          self::enabledLabel($entry['enabled']),
          $entry['description'] !== '' ? $entry['description'] : $entry['name'],
        );
      }
      $lines[] = '';
    }
    $lines[] = sprintf('Core: %d modules and themes of its own, not paged here.', $this->coreCount);
    return implode("\n", $lines) . "\n";
  }

  /**
   * One extension's page.
   *
   * @param array{machine: string, name: string, type: string, scope: string, path: string, description: string, package: string, version: string, composer_package: string, dependencies: list<string>, routes: array<string, string>, services: list<string>, permissions: list<string>, enabled: bool|null} $entry
   *   The extension.
   * @param array{modules: array<string, true>, themes: array<string, true>, file: string, exported: string}|false $exported
   *   The exported extension list.
   *
   * @return string
   *   The markdown.
   */
  public static function renderPage(array $entry, array|false $exported): string {
    $lines = [sprintf('# %s (%s)', $entry['name'], $entry['machine']), ''];
    if ($entry['description'] !== '') {
      $lines[] = $entry['description'];
      $lines[] = '';
    }
    $lines[] = sprintf('- Type: %s, %s', $entry['type'], $entry['scope']);
    $lines[] = sprintf('- Where: `%s`', $entry['path']);
    if ($entry['version'] !== '') {
      $lines[] = sprintf('- Version: %s%s', $entry['version'], $entry['composer_package'] !== '' ? sprintf(' (`%s`, composer.lock)', $entry['composer_package']) : '');
    }
    if ($entry['package'] !== '') {
      $lines[] = sprintf('- Package: %s', $entry['package']);
    }
    $lines[] = sprintf(
      '- Enabled: %s',
      $entry['enabled'] === NULL
        ? 'unknown (no exported core.extension.yml)'
        : sprintf('%s, as of the export of %s', $entry['enabled'] ? 'yes' : 'no', $exported === FALSE ? '?' : $exported['exported']),
    );
    if ($entry['dependencies'] !== []) {
      $lines[] = '- Needs: ' . implode(', ', $entry['dependencies']);
    }
    if ($entry['routes'] !== []) {
      $lines[] = '';
      $lines[] = sprintf('## Routes (%d)', count($entry['routes']));
      $lines[] = '';
      foreach (array_slice($entry['routes'], 0, 50, TRUE) as $name => $path) {
        $lines[] = sprintf('- `%s` %s', $name, $path);
      }
    }
    if ($entry['services'] !== []) {
      $lines[] = '';
      $lines[] = sprintf('## Services (%d)', count($entry['services']));
      $lines[] = '';
      foreach (array_slice($entry['services'], 0, 50) as $id) {
        $lines[] = sprintf('- `%s`', $id);
      }
    }
    if ($entry['permissions'] !== []) {
      $lines[] = '';
      $lines[] = sprintf('## Permissions (%d)', count($entry['permissions']));
      $lines[] = '';
      foreach (array_slice($entry['permissions'], 0, 50) as $name) {
        $lines[] = sprintf('- %s', $name);
      }
    }
    return implode("\n", $lines) . "\n";
  }

  /**
   * The words for an extension's state.
   *
   * @param bool|null $enabled
   *   The state.
   *
   * @return string
   *   The label, with its leading separator.
   */
  private static function enabledLabel(?bool $enabled): string {
    return match ($enabled) {
      TRUE => ', enabled',
      FALSE => ', not enabled',
      NULL => '',
    };
  }

  /**
   * Reads one extension.
   *
   * @param string $info
   *   Its `*.info.yml`, absolute.
   * @param string $docroot
   *   The docroot.
   * @param array<string, string> $lock
   *   The versions composer.lock records, by package name.
   * @param array{modules: array<string, true>, themes: array<string, true>, file: string, exported: string}|false $exported
   *   The exported extension list.
   *
   * @return array{machine: string, name: string, type: string, scope: string, path: string, description: string, package: string, version: string, composer_package: string, dependencies: list<string>, routes: array<string, string>, services: list<string>, permissions: list<string>, enabled: bool|null}|null
   *   The extension, or NULL when the file is not one.
   */
  private function read(string $info, string $docroot, array $lock, array|false $exported): ?array {
    $data = self::yaml($info);
    if ($data === NULL) {
      return NULL;
    }
    $machine = basename($info, '.info.yml');
    $dir = dirname($info);
    $relative = ltrim(substr($dir, strlen($docroot)), '/');
    $type = is_string($data['type'] ?? NULL) ? $data['type'] : 'module';
    $scope = str_contains('/' . $relative . '/', '/custom/') ? 'custom' : (str_contains('/' . $relative . '/', '/contrib/') ? 'contrib' : 'other');

    $composerPackage = '';
    $version = is_string($data['version'] ?? NULL) ? $data['version'] : '';
    if (preg_match('#^(?:modules|themes|profiles)/contrib/([^/]+)#', $relative, $m) === 1 && isset($lock['drupal/' . $m[1]])) {
      $composerPackage = 'drupal/' . $m[1];
      $version = $lock[$composerPackage];
    }

    $dependencies = [];
    foreach (is_array($data['dependencies'] ?? NULL) ? $data['dependencies'] : [] as $dependency) {
      if (is_string($dependency)) {
        $dependencies[] = $dependency;
      }
    }
    if (is_string($data['base theme'] ?? NULL)) {
      $dependencies[] = 'base theme ' . $data['base theme'];
    }

    $routes = [];
    foreach (self::yaml($dir . '/' . $machine . '.routing.yml') ?? [] as $name => $route) {
      if (is_array($route) && is_string($route['path'] ?? NULL)) {
        $routes[(string) $name] = $route['path'];
      }
    }
    $services = [];
    $declared = self::yaml($dir . '/' . $machine . '.services.yml');
    foreach (is_array($declared['services'] ?? NULL) ? $declared['services'] : [] as $id => $service) {
      if (!str_starts_with((string) $id, '_')) {
        $services[] = (string) $id;
      }
    }
    $permissions = [];
    foreach (self::yaml($dir . '/' . $machine . '.permissions.yml') ?? [] as $name => $permission) {
      if ($name !== 'permission_callbacks') {
        $permissions[] = (string) $name;
      }
    }

    $enabled = NULL;
    if ($exported !== FALSE) {
      $enabled = $type === 'theme' ? isset($exported['themes'][$machine]) : isset($exported['modules'][$machine]);
    }

    return [
      'machine' => $machine,
      'name' => is_string($data['name'] ?? NULL) ? $data['name'] : $machine,
      'type' => $type,
      'scope' => $scope,
      'path' => $this->relative($dir),
      'description' => is_string($data['description'] ?? NULL) ? trim($data['description']) : '',
      'package' => is_string($data['package'] ?? NULL) ? $data['package'] : '',
      'version' => $version,
      'composer_package' => $composerPackage,
      'dependencies' => $dependencies,
      'routes' => $routes,
      'services' => $services,
      'permissions' => $permissions,
      'enabled' => $enabled,
    ];
  }

  /**
   * Every `*.info.yml` under a directory, outside tests and vendored trees.
   *
   * @param string $dir
   *   The directory.
   * @param int $depth
   *   How deep this call is.
   *
   * @return list<string>
   *   The files, absolute, sorted.
   */
  private function infoFiles(string $dir, int $depth): array {
    if ($depth > 8 || !is_dir($dir)) {
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
          array_push($out, ...$this->infoFiles($path, $depth + 1));
        }
      }
      elseif (str_ends_with($name, '.info.yml')) {
        $out[] = $path;
      }
    }
    sort($out);
    return $out;
  }

  /**
   * The versions composer.lock records, by package name.
   *
   * @return array<string, string>
   *   The versions.
   */
  private function lockedPackages(): array {
    $raw = @file_get_contents($this->settings->projectRoot . '/composer.lock');
    $decoded = is_string($raw) ? json_decode($raw, TRUE) : NULL;
    $out = [];
    if (!is_array($decoded)) {
      return $out;
    }
    foreach (['packages', 'packages-dev'] as $key) {
      foreach (is_array($decoded[$key] ?? NULL) ? $decoded[$key] : [] as $package) {
        if (is_array($package) && is_string($package['name'] ?? NULL) && is_string($package['version'] ?? NULL)) {
          $out[$package['name']] = $package['version'];
        }
      }
    }
    return $out;
  }

  /**
   * A YAML file as a map; NULL when absent or not one.
   *
   * @param string $file
   *   The file.
   *
   * @return array<array-key, mixed>|null
   *   The map.
   */
  private static function yaml(string $file): ?array {
    if (!is_file($file)) {
      return NULL;
    }
    try {
      $parsed = Yaml::parseFile($file);
    }
    catch (\Throwable) {
      return NULL;
    }
    return is_array($parsed) ? $parsed : NULL;
  }

  /**
   * A path relative to the project root.
   *
   * @param string $path
   *   The path, absolute.
   *
   * @return string
   *   The relative path.
   */
  private function relative(string $path): string {
    $root = rtrim($this->settings->projectRoot, '/') . '/';
    return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
  }

}
