<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * A fingerprint of what a check examined.
 *
 * This is the mechanism that turns "the record says phpstan passed" into
 * "phpstan passed, and it passed about the code as it stands right now". Store
 * a verdict alone and you have a sticker; store it beside a fingerprint of its
 * subject and the verdict expires by itself the moment a file moves.
 *
 * It matters because nothing noticed before. A green recorded at 03:00 was
 * still a green at 03:05 after three files changed, and the only thing that
 * would have caught it was a human re-running the tool — which is the
 * out-of-band measurement the whole record exists to replace.
 *
 * Content, never mtime. mtime is wrong twice over here: `cp -Rp` restamps
 * whole trees when the eval harness collects a bundle (which once made three
 * bundles grade against the wrong spec), and a checkout that restores a file to
 * its previous content gets a new mtime while being, correctly, still green.
 */
final class SubjectHasher {

  /**
   * Files above this size are fingerprinted by size and path, not content.
   *
   * A gate's path set can include a committed vendored bundle or a fixture
   * dump. Hashing megabytes on every gate to detect a change that a size delta
   * would also catch is a cost with no reader.
   */
  private const int STREAM_ABOVE = 2_097_152;

  /**
   * Directory names never descended into.
   */
  private const array SKIP_DIRS = ['.git', 'node_modules', 'vendor', '.ddev', '.idea'];

  /**
   * The fingerprint of a set of paths, or NULL when there is nothing to hash.
   *
   * NULL is a real answer and is stored as such: a gate with no resolvable
   * subject (one that talks to a site, or runs a whole suite by config) has no
   * fingerprint, and pretending otherwise would make its green look
   * self-expiring when it is not. `EvidenceStore::stillGreen()` refuses a NULL
   * hash for the same reason.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param list<string> $paths
   *   Project-relative files or directories, as a gate's `paths` lever names
   *   them.
   *
   * @return string|null
   *   A hex digest, or NULL when no path resolved to anything readable.
   */
  public static function hash(string $projectRoot, array $paths): ?string {
    $root = rtrim($projectRoot, '/');
    $files = [];
    foreach ($paths as $path) {
      $path = trim($path);
      if ($path === '') {
        continue;
      }
      $absolute = $root . '/' . ltrim($path, '/');
      // A path that climbs out of the project is refused, not hashed. `paths:
      // ../outside` fingerprinted content nobody in this repository can change,
      // so the hash held across every edit and `stillGreen()` reported a stale
      // verdict as current — which defeats the one mechanism that makes a green
      // expire. Caught before anything called it, which is the only good time.
      if (!self::insideRoot($root, $absolute)) {
        continue;
      }
      if (is_file($absolute)) {
        $files[$path] = $absolute;
        continue;
      }
      if (is_dir($absolute)) {
        foreach (self::walk($absolute) as $relative => $found) {
          $files[$path . '/' . $relative] = $found;
        }
      }
    }
    if ($files === []) {
      return NULL;
    }
    // Sorted by the project-relative name so the digest does not depend on
    // filesystem iteration order, which differs between macOS and the
    // container the same gate runs in.
    ksort($files);
    $digest = hash_init('xxh128');
    foreach ($files as $relative => $absolute) {
      $size = @filesize($absolute);
      hash_update($digest, $relative . "\0");
      if ($size !== FALSE && $size > self::STREAM_ABOVE) {
        hash_update($digest, 'size:' . $size . "\0");
        continue;
      }
      $content = @file_get_contents($absolute);
      hash_update($digest, $content === FALSE ? 'unreadable' : hash('xxh128', $content));
      hash_update($digest, "\0");
    }

    return hash_final($digest);
  }

  /**
   * The comma-separated `paths` lever as a list.
   *
   * @param mixed $paths
   *   The lever's value, which is a string by schema but arrives from YAML.
   *
   * @return list<string>
   *   The paths.
   */
  public static function fromLever(mixed $paths): array {
    if (is_array($paths)) {
      $strings = array_map(
        static fn (mixed $path): string => is_scalar($path) ? (string) $path : '',
        $paths,
      );

      return array_values(array_filter($strings, static fn (string $p): bool => trim($p) !== ''));
    }
    if (!is_string($paths) || trim($paths) === '') {
      return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $paths)), static fn (string $p): bool => $p !== ''));
  }

  /**
   * Whether a path resolves inside the project.
   *
   * Resolved with realpath, so `src/../../outside` and a symlink pointing out
   * of the tree are both caught — the textual form of a path says nothing
   * about where it lands. A path that does not exist yet is judged on its
   * lexical form instead, because realpath returns FALSE for it and refusing
   * every not-yet-created path would make a gate's fingerprint depend on
   * whether its subject had been written.
   *
   * @param string $root
   *   The project root.
   * @param string $candidate
   *   The path to test.
   *
   * @return bool
   *   TRUE when it is inside the project.
   */
  private static function insideRoot(string $root, string $candidate): bool {
    $realRoot = realpath($root);
    if ($realRoot === FALSE) {
      return FALSE;
    }
    $real = realpath($candidate);
    if ($real !== FALSE) {
      return $real === $realRoot || str_starts_with($real, $realRoot . '/');
    }
    // Not on disk: judge the lexical path, collapsing "." and ".." by hand.
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $candidate)) as $segment) {
      if ($segment === '' || $segment === '.') {
        continue;
      }
      if ($segment === '..') {
        if (array_pop($parts) === NULL) {
          return FALSE;
        }
        continue;
      }
      $parts[] = $segment;
    }
    $lexical = '/' . implode('/', $parts);

    return $lexical === $realRoot || str_starts_with($lexical, $realRoot . '/');
  }

  /**
   * Every readable file under a directory, project-relative to it.
   *
   * @param string $directory
   *   The absolute directory.
   *
   * @return array<string, string>
   *   Relative path to absolute path.
   */
  private static function walk(string $directory): array {
    $found = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        static function (\SplFileInfo $file): bool {
          return !$file->isDir() || !in_array($file->getFilename(), self::SKIP_DIRS, TRUE);
        },
      ),
      \RecursiveIteratorIterator::LEAVES_ONLY,
    );
    $prefix = strlen(rtrim($directory, '/')) + 1;
    foreach ($iterator as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $found[substr($file->getPathname(), $prefix)] = $file->getPathname();
    }

    return $found;
  }

}
