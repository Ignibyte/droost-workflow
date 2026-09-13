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
    // `-z`, because `--porcelain` QUOTES any path containing a space — literal
    // double quotes, passed straight through — and nothing downstream strips
    // them. The leading `"` then breaks every prefix match, so a file inside an
    // exempt tree stopped being exempt: a stock `composer require --dev
    // squizlabs/php_codesniffer` in a repo that commits vendor/ blocked the
    // code phase over
    // `"vendor/…/ClassFileName Spaces In Filename.inc"` — the exact wall
    // class the exemptions exist to remove, still standing. `core.quotePath`
    // does not fix it; that covers non-ASCII, not spaces.
    //
    // Under `-z` the records are NUL-separated and never quoted, and a RENAME
    // emits the new path then the old one as a second field — so the old path
    // is consumed rather than parsed as a record of its own.
    [$exit, $stdout] = $this->git(
      $projectRoot,
      ['status', '--porcelain', '-z', '--untracked-files=all'],
    );
    if ($exit === 0) {
      $records = explode("\0", $stdout);
      $count = count($records);
      for ($i = 0; $i < $count; $i++) {
        $line = $records[$i];
        if (strlen($line) < 4) {
          continue;
        }
        $status = substr($line, 0, 2);
        $path = substr($line, 3);
        // R and C carry their source as the NEXT field; the path above is the
        // one that exists now, which is the one a diff is about.
        if (str_contains($status, 'R') || str_contains($status, 'C')) {
          $i++;
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
