<?php

declare(strict_types=1);

namespace Droost\Workflow\Vcs;

/**
 * Version-control facts through the git binary.
 *
 * Every question is a short-lived process rooted at the project, run through
 * the same injected runner shape the gate executor uses, so a test can drive
 * this without a repository and the one place this package spawns git is
 * visible.
 */
final class CliVcs implements VcsInterface {

  /**
   * Constructs a CliVcs.
   *
   * @param callable(list<string>, string, int): array{int, string, string} $runner
   *   Runs argv in a directory with a timeout, returning exit code, stdout and
   *   stderr.
   * @param int $timeout
   *   Seconds before a git call is abandoned.
   */
  public function __construct(
    private readonly mixed $runner,
    private readonly int $timeout = 30,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function head(string $projectRoot): ?string {
    [$exit, $stdout] = $this->git($projectRoot, ['rev-parse', 'HEAD']);
    $hash = trim($stdout);
    return $exit === 0 && preg_match('/^[0-9a-f]{7,64}$/', $hash) === 1 ? $hash : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function changedFiles(string $projectRoot, ?string $base): array {
    $files = [];
    // Committed since the base (when a base is known), plus everything the
    // working tree holds that the index or HEAD does not: modified, added,
    // untracked. `--porcelain` is the stable machine format.
    if ($base !== NULL) {
      [$exit, $stdout] = $this->git($projectRoot, ['diff', '--name-only', $base]);
      if ($exit === 0) {
        foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
          if (trim($line) !== '') {
            $files[] = trim($line);
          }
        }
      }
    }
    [$exit, $stdout] = $this->git($projectRoot, ['status', '--porcelain', '--untracked-files=all']);
    if ($exit === 0) {
      foreach (preg_split('/\R/', $stdout) ?: [] as $line) {
        if (strlen($line) < 4) {
          continue;
        }
        $path = trim(substr($line, 3));
        // A rename prints "old -> new"; the new path is the one that exists.
        if (str_contains($path, ' -> ')) {
          $path = trim(substr($path, strrpos($path, ' -> ') + 4));
        }
        if ($path !== '') {
          $files[] = $path;
        }
      }
    }
    $files = array_values(array_unique($files));
    sort($files);
    return $files;
  }

  /**
   * Runs one git command rooted at the project.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $args
   *   The git arguments.
   *
   * @return array{int, string}
   *   The exit code and standard output; a runner that throws (no git on the
   *   PATH) reads as exit 127 with no output.
   */
  private function git(string $projectRoot, array $args): array {
    try {
      /** @var array{int, string, string} $outcome */
      $outcome = ($this->runner)(['git', '-C', rtrim($projectRoot, '/'), ...$args], rtrim($projectRoot, '/'), $this->timeout);
    }
    catch (\Throwable) {
      return [127, ''];
    }
    return [$outcome[0], $outcome[1]];
  }

}
