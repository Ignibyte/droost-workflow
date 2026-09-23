<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * The files a scaffold wrote, and which of them nobody has touched since.
 *
 * A generated test tests what its generator knew, which is not the code the
 * run wrote. droost's hook blueprint writes a test for every hook class it
 * scaffolds, a reflection check that the #[Hook] attribute is there, so a
 * demand for "a phpunit test alongside src/" was met the moment a hook was
 * scaffolded, and could not fail after (F-65). The kernel-test blueprint's
 * skeleton would do the same.
 *
 * So the scaffold says what it wrote. The site half appends one JSON line to
 * `scaffolded.jsonl`, beside the tool-call ledger, for each file a blueprint
 * creates: `{"path", "hash", "blueprint", "run", "at"}`, with the path
 * relative to the project root and the hash `sha256:` of the bytes it wrote.
 * A file whose content still hashes to that is the scaffold's, not the
 * agent's. One edit and it is the agent's.
 *
 * Read-only, and absent is empty: a site whose scaffold writes no record, or
 * a project with no scaffold at all, has nothing discounted, which is exactly
 * how the audit behaved before this record existed.
 */
final class ScaffoldRecord {

  /**
   * The record's file name, in the run's state directory.
   */
  public const FILE = 'scaffolded.jsonl';

  /**
   * The recorded files whose content is still what the scaffold wrote.
   *
   * @param string $projectRoot
   *   The repository root the recorded paths are relative to.
   * @param string $stateDir
   *   The run's state directory, which holds the record.
   * @param string|null $runId
   *   The open run. Rows made under another run are skipped, as the
   *   tool-call ledger's are; rows made under no run are kept, since a
   *   scaffold can run before a run opens.
   *
   * @return list<string>
   *   Project-relative paths, sorted.
   */
  public static function untouched(string $projectRoot, string $stateDir, ?string $runId): array {
    $path = rtrim($stateDir, '/') . '/' . self::FILE;
    if (!is_file($path)) {
      return [];
    }
    // The latest row for a path wins: re-running a blueprint over a file it
    // already wrote records what is there now.
    $hashes = [];
    foreach (preg_split('/\R/', (string) @file_get_contents($path)) ?: [] as $line) {
      if (trim($line) === '') {
        continue;
      }
      $row = json_decode($line, TRUE);
      if (!is_array($row)) {
        continue;
      }
      $file = $row['path'] ?? NULL;
      $hash = $row['hash'] ?? NULL;
      $run = $row['run'] ?? NULL;
      if (!is_string($file) || $file === '' || !is_string($hash) || !str_starts_with($hash, 'sha256:')) {
        continue;
      }
      if ($runId !== NULL && is_string($run) && $run !== $runId) {
        continue;
      }
      $hashes[self::normalise($file)] = substr($hash, 7);
    }
    $untouched = [];
    $root = rtrim($projectRoot, '/');
    foreach ($hashes as $file => $hash) {
      if (str_contains('/' . $file . '/', '/../')) {
        continue;
      }
      $absolute = $root . '/' . $file;
      if (is_file($absolute) && hash_file('sha256', $absolute) === $hash) {
        $untouched[] = $file;
      }
    }
    sort($untouched);
    return $untouched;
  }

  /**
   * A project-relative path without a leading "./" or "/".
   *
   * @param string $file
   *   The path.
   *
   * @return string
   *   The normalised path.
   */
  public static function normalise(string $file): string {
    $file = str_replace('\\', '/', $file);
    while (str_starts_with($file, './')) {
      $file = substr($file, 2);
    }
    return ltrim($file, '/');
  }

}
