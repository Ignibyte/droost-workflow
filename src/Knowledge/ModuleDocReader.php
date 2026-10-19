<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Engine\Site\ScopeClassifier;
use Droost\Engine\Support\PathGuard;

/**
 * Locates, reads, and searches on-disk module documentation.
 *
 * Covers every discovered module — core (`core/modules`), contrib
 * (`modules/contrib`), and custom (`modules/custom`). A "doc" is a README,
 * any root `*.md`, a `*.api.php` API-doc file, a `docs/*.md` file, or — as a
 * baseline that even README-less core modules always have — the `.info.yml`
 * description. This gives an agent the real documentation for the exact module
 * versions installed, a local complement to a hosted documentation index.
 *
 * Drupal-free: the modules come from a module locator, so
 * `droost code:docs` reads the same docs with the site broken that
 * a booted site would.
 */
final class ModuleDocReader {

  /**
   * Maximum bytes read from a single doc file.
   */
  private const int MAX_FILE_BYTES = 16000;

  /**
   * Maximum doc files indexed per module.
   */
  private const int MAX_FILES_PER_MODULE = 12;

  /**
   * Candidate README filenames, in preference order.
   */
  private const array README_NAMES = ['README.md', 'README.txt', 'readme.md', 'readme.txt'];

  /**
   * Constructs a ModuleDocReader.
   *
   * @param string $appRoot
   *   The Drupal application root.
   * @param \Droost\Workflow\Knowledge\ModuleLocatorInterface $modules
   *   Where the modules are.
   * @param \Droost\Engine\Site\ScopeClassifier $scopes
   *   Who owns each module's code. A module the path alone could not place
   *   used to be filed as "other", which no scope filter but "all" reached.
   */
  public function __construct(
    protected string $appRoot,
    protected ModuleLocatorInterface $modules,
    protected ScopeClassifier $scopes,
  ) {}

  /**
   * Indexes documented modules, optionally filtered by scope.
   *
   * @param string $scope
   *   One of "core", "contrib", "custom", or "all".
   *
   * @return array<int, array{module: string, scope: string, path: string, description: string, files: array<int, string>}>
   *   The documented modules, sorted by scope then machine name.
   */
  public function index(string $scope = 'all'): array {
    $out = [];
    foreach ($this->modules->modules() as $name => $module) {
      $name = (string) $name;
      $relative = $module['path'];
      $module_scope = $this->scopes->classify($relative)->scope();
      if ($scope !== 'all' && $scope !== $module_scope) {
        continue;
      }
      $files = $this->docFiles($relative);
      $description = $this->description($module['info']);
      if ($files === [] && $description === '') {
        continue;
      }
      $out[] = [
        'module' => $name,
        'scope' => $module_scope,
        'path' => $relative,
        'description' => $description,
        'files' => $files,
      ];
    }
    usort($out, fn(array $a, array $b): int => [$a['scope'], $a['module']] <=> [$b['scope'], $b['module']]);
    return $out;
  }

  /**
   * Reads one documentation file for a module.
   *
   * @param string $module
   *   The module machine name.
   * @param string $file
   *   A specific doc file (from the module's indexed files); '' for the primary
   *   doc (README, else first file, else the info description).
   *
   * @return array{module: string, scope: string, path: string, file: string, content: string}|null
   *   The doc payload, or NULL if the module or requested file is missing.
   */
  public function read(string $module, string $file = ''): ?array {
    $list = $this->modules->modules();
    if (!isset($list[$module])) {
      return NULL;
    }
    $relative = $list[$module]['path'];
    $scope = $this->scopes->classify($relative)->scope();
    $files = $this->docFiles($relative);

    if ($file !== '') {
      $file = $this->sanitize($file);
      if (!in_array($file, $files, TRUE)) {
        return NULL;
      }
    }
    elseif ($files !== []) {
      $file = $files[0];
    }
    else {
      $description = $this->description($list[$module]['info']);
      if ($description === '') {
        return NULL;
      }
      return [
        'module' => $module,
        'scope' => $scope,
        'path' => $relative,
        'file' => '(info description)',
        'content' => $description,
      ];
    }

    // Defense-in-depth: even though docFiles() only lists globbed names, a
    // symlinked doc file (e.g. README.md -> ../../sites/default/settings.php)
    // would pass the name allowlist. PathGuard enforces realpath containment
    // against the module directory or the module's resolved home (composer
    // path repos and drupal.org CI materialize modules as per-file symlinks
    // into the real checkout).
    $module_dir = $this->appRoot . '/' . $relative;
    $resolved = PathGuard::resolve($module_dir, $file, $this->trustedBases($module, $module_dir));
    if ($resolved === NULL) {
      return NULL;
    }
    $content = $this->readCapped($resolved);
    return ['module' => $module, 'scope' => $scope, 'path' => $relative, 'file' => $file, 'content' => $content];
  }

  /**
   * Searches module documentation for a query string.
   *
   * @param string $query
   *   The case-insensitive search term.
   * @param string $scope
   *   One of "core", "contrib", "custom", or "all".
   * @param int $maxResults
   *   Maximum number of result rows to return.
   * @param int $perFile
   *   Maximum matching lines per file.
   *
   * @return array<int, array{module: string, scope: string, path: string, file: string, matches: array<int, string>}>
   *   Result rows, each a module + file with snippet lines.
   */
  public function search(string $query, string $scope, int $maxResults, int $perFile): array {
    $needle = mb_strtolower($query);
    $results = [];
    foreach ($this->index($scope) as $entry) {
      if (count($results) >= $maxResults) {
        break;
      }
      if ($entry['description'] !== '' && str_contains(mb_strtolower($entry['description']), $needle)) {
        $results[] = [
          'module' => $entry['module'],
          'scope' => $entry['scope'],
          'path' => $entry['path'],
          'file' => '(info description)',
          'matches' => [mb_substr($entry['description'], 0, 240)],
        ];
      }
      foreach ($entry['files'] as $file) {
        if (count($results) >= $maxResults) {
          break;
        }
        $module_dir = $this->appRoot . '/' . $entry['path'];
        $resolved = PathGuard::resolve($module_dir, $file, $this->trustedBases($entry['module'], $module_dir));
        if ($resolved === NULL) {
          continue;
        }
        $matches = $this->grep($resolved, $needle, $perFile);
        if ($matches !== []) {
          $results[] = [
            'module' => $entry['module'],
            'scope' => $entry['scope'],
            'path' => $entry['path'],
            'file' => $file,
            'matches' => $matches,
          ];
        }
      }
    }
    return $results;
  }

  /**
   * Lists a module's doc files, relative to the module directory.
   *
   * @param string $relative
   *   The module path relative to the app root.
   *
   * @return array<int, string>
   *   The doc file names (README, *.md, *.api.php, docs/*.md), capped.
   */
  private function docFiles(string $relative): array {
    $base = $this->appRoot . '/' . $relative;
    $found = $this->firstReadme($base);
    foreach ($this->globNames($base . '/*.md') as $name) {
      if (stripos($name, 'readme') === FALSE) {
        $found[] = $name;
      }
    }
    $found = array_merge($found, $this->globNames($base . '/*.api.php'));
    foreach ($this->globNames($base . '/docs/*.md') as $name) {
      $found[] = 'docs/' . $name;
    }
    return array_slice(array_values(array_unique($found)), 0, self::MAX_FILES_PER_MODULE);
  }

  /**
   * Returns a single-element list with the first README found, or [].
   *
   * @param string $base
   *   The absolute module directory.
   *
   * @return array<int, string>
   *   The README filename in a list, or an empty list.
   */
  private function firstReadme(string $base): array {
    foreach (self::README_NAMES as $candidate) {
      if (is_file($base . '/' . $candidate)) {
        return [$candidate];
      }
    }
    return [];
  }

  /**
   * Globs a pattern and returns the matched base filenames.
   *
   * @param string $pattern
   *   The glob pattern.
   *
   * @return array<int, string>
   *   The matched base filenames.
   */
  private function globNames(string $pattern): array {
    return array_map(basename(...), glob($pattern) ?: []);
  }

  /**
   * Returns matching, trimmed lines from a file.
   *
   * @param string $path
   *   Absolute file path.
   * @param string $needle
   *   The lower-cased search term.
   * @param int $limit
   *   Maximum matches.
   *
   * @return array<int, string>
   *   The matching lines, capped at 240 characters each.
   */
  private function grep(string $path, string $needle, int $limit): array {
    $matches = [];
    foreach (preg_split('/\r?\n/', $this->readCapped($path)) ?: [] as $line) {
      if (count($matches) >= $limit) {
        break;
      }
      $line = trim($line);
      if ($line !== '' && str_contains(mb_strtolower($line), $needle)) {
        $matches[] = mb_substr($line, 0, 240);
      }
    }
    return $matches;
  }

  /**
   * Reads a file, capped at the maximum byte length.
   *
   * @param string $path
   *   Absolute file path.
   *
   * @return string
   *   The (possibly truncated) file content.
   */
  private function readCapped(string $path): string {
    $content = (string) file_get_contents($path, FALSE, NULL, 0, self::MAX_FILE_BYTES + 1);
    if (strlen($content) > self::MAX_FILE_BYTES) {
      // mb_strcut (not substr) so the cut never splits a multibyte character
      // into invalid bytes that would corrupt the JSON response.
      $content = mb_strcut($content, 0, self::MAX_FILE_BYTES) . "\n…(truncated)";
    }
    return $content;
  }

  /**
   * Extracts a trimmed string description from a module info array.
   *
   * @param mixed $info
   *   The module info array (or anything).
   *
   * @return string
   *   The description, or ''.
   */
  private function description(mixed $info): string {
    if (is_array($info) && isset($info['description']) && is_scalar($info['description'])) {
      return trim((string) $info['description']);
    }
    return '';
  }

  /**
   * Normalizes a requested file path and blocks traversal.
   *
   * @param string $file
   *   The requested file.
   *
   * @return string
   *   The normalized relative path, or '' if it is unsafe.
   */
  private function sanitize(string $file): string {
    $file = ltrim(str_replace('\\', '/', $file), '/');
    return str_contains($file, '..') ? '' : $file;
  }

  /**
   * Extra containment bases: where the module's own files really live.
   *
   * Anchored on the module's .info.yml — when the module directory is built
   * from per-file symlinks (composer path repos, drupal.org CI), the info
   * file's resolved parent IS the module's true home, and sibling doc files
   * resolving there are the module's own. Foreign symlink targets (another
   * module, sites/) resolve elsewhere and stay rejected.
   *
   * @param string $module
   *   The module machine name.
   * @param string $module_dir
   *   The absolute (unresolved) module directory.
   *
   * @return array<int, string>
   *   Zero or one resolved base directories.
   */
  private function trustedBases(string $module, string $module_dir): array {
    $info = realpath($module_dir . '/' . $module . '.info.yml');
    return $info === FALSE ? [] : [dirname($info)];
  }

}
