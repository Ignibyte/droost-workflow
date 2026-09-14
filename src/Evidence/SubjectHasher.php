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
   * How much of a very large file's head and tail is read into the digest.
   *
   * Size alone was not enough: a same-length edit vanished. Two 64KB reads
   * bound the cost regardless of the file's size, and the only edit that still
   * escapes is a same-length change confined strictly to the middle of a
   * multi-megabyte file — which is a narrower hole than "any edit at all", and
   * is stated rather than left to be discovered.
   */
  private const int ENDS_BYTES = 65_536;

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
        foreach (self::walk($absolute, $root) as $relative => $found) {
          $files[$path . '/' . $relative] = $found;
        }
        // The directories the walk refused to descend into are part of what
        // this path set contains, and pretending otherwise made them invisible:
        // a reviewer rewrote a file under a `vendor/` INSIDE an explicitly
        // declared path to `system($_GET['c'])` and the fingerprint did not
        // move. Their shape — names, entry counts, total bytes — is cheap and
        // catches an addition, a removal or a size change. A same-size in-place
        // rewrite inside a skipped tree still escapes; hashing every dependency
        // on every gate is the cost this skip exists to avoid, and the trade is
        // named here rather than discovered later.
        foreach (self::skippedShape($absolute) as $shape) {
          $files['skipped:' . $path . '/' . $shape] = NULL;
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
      if ($absolute === NULL) {
        // A skipped directory's shape, already encoded in the key.
        hash_update($digest, $relative . "\0");
        continue;
      }
      $size = @filesize($absolute);
      hash_update($digest, $relative . "\0");
      if ($size !== FALSE && $size > self::STREAM_ABOVE) {
        // Size ALONE was the fingerprint here, so editing a large file in place
        // without changing its length left the digest identical — a reviewer
        // flipped the first byte of a 2.2MB file and `stillGreen()` said yes.
        // The ends plus the size cost two reads of 64KB however large the file
        // is, and catch every edit that is not a same-length change confined to
        // the middle.
        hash_update($digest, 'size:' . $size . "\0");
        $handle = @fopen($absolute, 'rb');
        if ($handle !== FALSE) {
          hash_update($digest, (string) fread($handle, self::ENDS_BYTES));
          if (fseek($handle, -self::ENDS_BYTES, SEEK_END) === 0) {
            hash_update($digest, (string) fread($handle, self::ENDS_BYTES));
          }
          fclose($handle);
        }
        hash_update($digest, "\0");
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
   * The shape of the directories the walk refused to descend into.
   *
   * Not their contents — that is the cost this skip exists to avoid — but their
   * name, how many entries they hold and how many bytes those come to. Enough
   * that adding, removing or resizing anything inside one moves the digest, and
   * cheap enough to run on every gate.
   *
   * @param string $directory
   *   The absolute directory being walked.
   *
   * @return list<string>
   *   One descriptor per skipped directory.
   */
  private static function skippedShape(string $directory): array {
    $shapes = [];
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $entry) {
      if (!$entry instanceof \SplFileInfo || !$entry->isDir()) {
        continue;
      }
      if (!in_array($entry->getFilename(), self::SKIP_DIRS, TRUE)) {
        continue;
      }
      $count = 0;
      $bytes = 0;
      foreach (new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($entry->getPathname(), \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
      ) as $inside) {
        if ($inside instanceof \SplFileInfo && $inside->isFile()) {
          $count++;
          $bytes += $inside->getSize();
        }
      }
      $shapes[] = sprintf('%s|%d|%d', $entry->getFilename(), $count, $bytes);
    }
    sort($shapes);

    return $shapes;
  }

  /**
   * Every readable file under a directory, project-relative to it.
   *
   * @param string $directory
   *   The absolute directory.
   * @param string $root
   *   The project root, so a symlink can be judged by whether it lands inside
   *   it. Empty means "do not follow any link" — the fingerprint then records
   *   every link by shape, which is the honest answer when there is no root to
   *   measure against.
   *
   * @return array<string, string|null>
   *   Relative path to absolute path; NULL for a descriptor whose whole value
   *   is encoded in its key.
   */
  private static function walk(string $directory, string $root = ''): array {
    $found = [];
    $links = [];
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
      if (!$file instanceof \SplFileInfo) {
        continue;
      }
      // A SYMLINKED DIRECTORY CONTRIBUTED NOTHING AT ALL. The walk does not
      // descend into one (RecursiveDirectoryIterator does not follow links by
      // default) and `skippedShape()` only shapes the directories it NAMES —
      // so a whole subtree reached through a link was invisible: not its
      // content, not its shape, not even its existence.
      //
      // A reviewer pointed a gate's `paths` lever at `src`, where
      // `src/Payments` linked to `../lib`, recorded a green, then rewrote an
      // authorisation check to `return TRUE;` and watched `stillGreen()` say
      // yes over a byte-identical fingerprint. That is precisely the failure
      // the whole mechanism exists to prevent, and it was not stated anywhere
      // — unlike the >2MB middle-edit hole, which is documented.
      //
      // Links are recorded by WHERE THEY POINT and what is on the other side:
      // the target's own shape, which moves when anything in it is added,
      // removed or resized. Following it and hashing the contents would be
      // better still, and is refused for the same reason `insideRoot()`
      // refuses `paths: ../outside` — a fingerprint must not depend on bytes
      // outside the project, or it can never expire.
      if ($file->isLink()) {
        $relative = substr($file->getPathname(), $prefix);
        $target = (string) @readlink($file->getPathname());
        $real = @realpath($file->getPathname());
        // INSIDE THE PROJECT, FOLLOW IT. A link to another part of this
        // repository points at bytes the run can change, so those bytes belong
        // in the fingerprint exactly as any other file's do — and then a
        // same-size rewrite behind the link moves the digest too.
        //
        // Outside the project, record the shape and stop, for the same reason
        // `insideRoot()` refuses `paths: ../outside`: a fingerprint that
        // depends on bytes nobody here can change never expires, which defeats
        // the mechanism rather than extending it.
        if ($real !== FALSE && $root !== '' && self::insideRoot($root, $real) && is_dir($real)) {
          foreach (self::walk($real, $root) as $inner => $path) {
            $found[$relative . '/' . $inner] = $path;
          }
          $links[] = sprintf('link:%s->%s|followed', $relative, $target);
          continue;
        }
        $links[] = sprintf(
          'link:%s->%s|%s',
          $relative,
          $target,
          $real === FALSE ? 'broken' : self::treeShape($real),
        );
        continue;
      }
      if (!$file->isFile()) {
        continue;
      }
      $found[substr($file->getPathname(), $prefix)] = $file->getPathname();
    }
    sort($links);
    foreach ($links as $link) {
      $found['skipped:' . $link] = NULL;
    }

    return $found;
  }

  /**
   * How many files a tree holds and how many bytes they come to.
   *
   * The same cheap descriptor `skippedShape()` uses, for the same reason: an
   * addition, a removal or a size change moves it, and hashing an arbitrary
   * tree on every gate is the cost this avoids. A same-size in-place rewrite
   * behind a link still escapes, which is the trade — stated here rather than
   * left to be discovered, which is how the hole above came to exist.
   *
   * @param string $directory
   *   The resolved target.
   *
   * @return string
   *   Entry count and total bytes, or a word when it cannot be read.
   */
  private static function treeShape(string $directory): string {
    if (is_file($directory)) {
      return 'file|' . (int) @filesize($directory);
    }
    if (!is_dir($directory)) {
      return 'missing';
    }
    $count = 0;
    $bytes = 0;
    try {
      $inside = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
      );
      foreach ($inside as $entry) {
        if ($entry instanceof \SplFileInfo && $entry->isFile()) {
          $count++;
          $bytes += (int) $entry->getSize();
        }
      }
    }
    catch (\UnexpectedValueException) {
      return 'unreadable';
    }

    return sprintf('%d|%d', $count, $bytes);
  }

}
