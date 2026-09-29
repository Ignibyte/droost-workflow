<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * The parity gate's reference, as one digest a run is held to.
 *
 * The reference is the source the site is judged against, kept in the
 * project, so a run that can rewrite it can make any page pass. The digest is
 * frozen with the gate's levers when the run begins, and the gate refuses to
 * judge against a reference that no longer has it. That catches every way of
 * changing the files (an editor, the shell, a program), where a wall around
 * the path catches only the ways it knows (F-144).
 */
final class ParityReference {

  /**
   * The digest of a reference that does not exist, or holds no file.
   */
  public const ABSENT = 'absent';

  /**
   * Where the references are kept when the `reference` lever names nowhere.
   */
  public const DEFAULT_DIR = 'droost/parity';

  /**
   * The reference directory a `reference` lever's value names.
   *
   * @param mixed $lever
   *   The lever's value.
   *
   * @return string
   *   The directory, relative to the project unless absolute.
   */
  public static function directory(mixed $lever): string {
    return is_string($lever) && $lever !== '' ? $lever : self::DEFAULT_DIR;
  }

  /**
   * The reference's digest: every file under it, by path and content.
   *
   * @param string $projectRoot
   *   The project root.
   * @param string $reference
   *   The reference directory, relative to the root or absolute.
   *
   * @return string
   *   A sha256 over the sorted (path, file sha256) pairs, or ABSENT.
   *
   * @phpstan-impure
   */
  public static function digest(string $projectRoot, string $reference): string {
    $dir = str_starts_with($reference, '/') ? $reference : rtrim($projectRoot, '/') . '/' . $reference;
    if (!is_dir($dir)) {
      return self::ABSENT;
    }
    $files = [];
    $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $path = $file->getPathname();
      $hash = @hash_file('sha256', $path);
      $files[substr($path, strlen(rtrim($dir, '/')) + 1)] = is_string($hash) ? $hash : 'unreadable';
    }
    if ($files === []) {
      return self::ABSENT;
    }
    ksort($files, SORT_STRING);

    return hash('sha256', (string) json_encode($files, JSON_UNESCAPED_SLASHES));
  }

}
