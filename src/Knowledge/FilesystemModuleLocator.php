<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Symfony\Component\Yaml\Yaml;

/**
 * The modules on disk under a docroot, found by their info files.
 *
 * What Drupal's extension discovery finds, read without booting it: every
 * `*.info.yml` of `type: module` under core, the profiles, `modules/` and
 * `sites/*`, skipping test modules and what no extension lives in
 * (`node_modules`, `vendor`, a site's `files`). The first module of a name
 * wins, core before the rest.
 */
final class FilesystemModuleLocator implements ModuleLocatorInterface {

  /**
   * Where modules live, relative to the docroot, in the order they are read.
   */
  private const array BASES = ['core/modules', 'core/profiles', 'profiles', 'modules', 'sites'];

  /**
   * Directory names never walked into.
   */
  private const array SKIP = ['tests', 'node_modules', 'vendor', 'files', '.git'];

  /**
   * The modules, once walked.
   *
   * @var array<string, array{path: string, info: array<array-key, mixed>}>|null
   */
  private ?array $modules = NULL;

  /**
   * Constructs a FilesystemModuleLocator.
   *
   * @param string $docroot
   *   Drupal's docroot, absolute.
   */
  public function __construct(private readonly string $docroot) {}

  /**
   * {@inheritdoc}
   */
  public function modules(): array {
    if ($this->modules !== NULL) {
      return $this->modules;
    }
    $root = rtrim($this->docroot, '/');
    $this->modules = [];
    foreach (self::BASES as $base) {
      if (!is_dir($root . '/' . $base)) {
        continue;
      }
      foreach ($this->infoFiles($root . '/' . $base) as $file) {
        $name = basename($file, '.info.yml');
        if (isset($this->modules[$name])) {
          continue;
        }
        $info = $this->info($file);
        if (($info['type'] ?? NULL) !== 'module') {
          continue;
        }
        $this->modules[$name] = [
          'path' => ltrim(substr(dirname($file), strlen($root)), '/'),
          'info' => $info,
        ];
      }
    }
    ksort($this->modules);
    return $this->modules;
  }

  /**
   * The info files under a directory, sorted.
   *
   * @param string $dir
   *   The directory, absolute.
   *
   * @return list<string>
   *   The files.
   */
  private function infoFiles(string $dir): array {
    // Symlinks are followed, as Drupal's discovery follows them (a module
    // linked in from a checkout is a module); a directory reached twice is
    // walked once, so a link that loops ends.
    $seen = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
        static function (\SplFileInfo $file) use (&$seen): bool {
          if (!$file->isDir()) {
            return TRUE;
          }
          $real = $file->getRealPath();
          if (in_array($file->getFilename(), self::SKIP, TRUE) || $real === FALSE || isset($seen[$real])) {
            return FALSE;
          }
          $seen[$real] = TRUE;
          return TRUE;
        },
      ),
    );
    $files = [];
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo && $file->isFile() && str_ends_with($file->getFilename(), '.info.yml')) {
        $files[] = $file->getPathname();
      }
    }
    sort($files);
    return $files;
  }

  /**
   * An info file's contents, or nothing when it does not parse.
   *
   * @param string $file
   *   The file.
   *
   * @return array<array-key, mixed>
   *   The parsed info.
   */
  private function info(string $file): array {
    try {
      $parsed = Yaml::parseFile($file);
    }
    catch (\Throwable) {
      return [];
    }
    return is_array($parsed) ? $parsed : [];
  }

}
