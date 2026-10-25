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
final class CliVcs implements VcsInterface, RefResolverInterface, AddedFilesInterface, LineCountsInterface, AncestryInterface, CommitsInterface {

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
  public function addedFiles(string $projectRoot, string $base): ?array {
    [$exit, $committed] = $this->git(
      $projectRoot,
      ['diff', '--name-only', '-z', '--diff-filter=A', '--end-of-options', $base],
    );
    if ($exit !== 0) {
      return NULL;
    }
    [$exitUntracked, $untracked] = $this->git($projectRoot, ['ls-files', '--others', '--exclude-standard', '-z']);
    if ($exitUntracked !== 0) {
      return NULL;
    }
    $files = [];
    foreach ([...explode("\0", $committed), ...explode("\0", $untracked)] as $path) {
      if ($path !== '') {
        $files[$path] = TRUE;
      }
    }
    return array_keys($files);
  }

  /**
   * {@inheritdoc}
   */
  public function resolveCommit(string $projectRoot, string $ref): ?string {
    // A ref that starts with a dash would be read as an option; git's own
    // --end-of-options says so as well, for the versions that know it.
    if ($ref === '' || str_starts_with($ref, '-')) {
      return NULL;
    }
    [$exit, $stdout] = $this->git(
      $projectRoot,
      ['rev-parse', '--verify', '--quiet', '--end-of-options', $ref . '^{commit}'],
    );
    $hash = trim($stdout);
    return $exit === 0 && preg_match('/^[0-9a-f]{40,64}$/', $hash) === 1 ? $hash : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function branch(string $projectRoot): ?string {
    [$exit, $stdout] = $this->git($projectRoot, ['rev-parse', '--abbrev-ref', 'HEAD']);
    $branch = trim($stdout);
    return $exit === 0 && $branch !== '' && $branch !== 'HEAD' ? $branch : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function descends(string $projectRoot, string $ancestor): ?bool {
    if ($ancestor === '' || str_starts_with($ancestor, '-')) {
      return NULL;
    }
    [$exit] = $this->git($projectRoot, ['merge-base', '--is-ancestor', '--end-of-options', $ancestor, 'HEAD']);
    return match ($exit) {
      0 => TRUE,
      1 => FALSE,
      default => NULL,
    };
  }

  /**
   * {@inheritdoc}
   */
  public function commitPaths(string $projectRoot, array $paths, string $message): array {
    // WHO COMMITS. Through `ddev droost` git runs inside the web container,
    // whose identity is DDEV's placeholder, not the developer's: the record
    // would be committed by "DDEV User <nobody@example.com>" (checked on a
    // DDEV 2026-10-25 container). A repository-local identity is read from
    // .git/config, which the container sees.
    [$identified, $ident] = $this->git($projectRoot, ['var', 'GIT_AUTHOR_IDENT']);
    $author = preg_match('/^(.*<[^>]*>)/', trim($ident), $match) === 1 ? $match[1] : '';
    if ($identified !== 0 || $author === '') {
      $why = 'git has no author identity here; set one for this repository (git config user.name, user.email) and commit again';
      return [NULL, $why];
    }
    if (str_contains($author, '<nobody@example.com>')) {
      $why = sprintf(
        'not committed: git here would commit as %s, DDEV\'s container placeholder, not you. '
        . 'Set this repository\'s own identity on the host (git config user.name "…" and user.email, '
        . 'no --global: the container reads .git/config), or pass GIT_AUTHOR_NAME/GIT_AUTHOR_EMAIL '
        . 'and GIT_COMMITTER_NAME/GIT_COMMITTER_EMAIL in, then run the commit again. The baseline is '
        . 'written; only the commit is missing (git commit -- %s from the host commits it).',
        $author,
        implode(' ', $paths),
      );
      return [NULL, $why];
    }
    [$added] = $this->git($projectRoot, ['add', '--', ...$paths]);
    if ($added !== 0) {
      return [NULL, 'git add failed on ' . implode(', ', $paths)];
    }
    [$unchanged] = $this->git($projectRoot, ['diff', '--cached', '--quiet', '--', ...$paths]);
    if ($unchanged === 0) {
      return [NULL, 'nothing to commit: ' . implode(', ', $paths) . ' already matches HEAD'];
    }
    // `--` with paths commits those paths alone (git's --only), so whatever
    // else the developer has staged stays staged and out of this commit.
    [$committed, $stdout] = $this->git($projectRoot, ['commit', '-q', '-m', $message, '--', ...$paths]);
    if ($committed !== 0) {
      return [NULL, 'git commit failed (exit ' . $committed . ')' . (trim($stdout) !== '' ? ': ' . trim($stdout) : '')];
    }
    $head = $this->head($projectRoot);
    return $head !== NULL ? [$head, 'committed as ' . $author] : [NULL, 'committed, but HEAD could not be read'];
  }

  /**
   * {@inheritdoc}
   */
  public function isRepository(string $projectRoot): bool {
    [$exit, $stdout] = $this->git($projectRoot, ['rev-parse', '--is-inside-work-tree']);
    return $exit === 0 && trim($stdout) === 'true';
  }

  /**
   * {@inheritdoc}
   */
  public function changedFiles(string $projectRoot, ?string $base): ?array {
    $files = [];
    // Committed since the base (when a base is known), plus everything the
    // working tree holds that the index or HEAD does not: modified, added,
    // untracked. `--porcelain` is the stable machine format.
    if ($base !== NULL) {
      // `-z` HERE TOO. The status half below was converted and this was not,
      // and this is the branch that actually runs: a base is known on every
      // run past the first phase. `git diff --name-only` QUOTES any path git
      // considers unusual — `core.quotePath` defaults on, so a single
      // non-ASCII byte is enough — and nothing downstream strips the quotes:
      //
      //   git diff --name-only HEAD~1
      //   "vendor/symfony/string/Tests/\303\274n\303\257code-fixture.php"
      //
      // The leading `"` breaks every prefix match, so a file inside an exempt
      // tree stops being exempt and an honest diff is reported as scope creep.
      // A committed path containing a newline is worse: splitting on `\R`
      // invents two paths out of one, neither of which exists.
      [$exit, $stdout] = $this->git($projectRoot, ['diff', '--name-only', '-z', $base]);
      // A failed diff (an unreachable base) is unknown: returning only the
      // working tree read as "no committed changes", and every ticket-scoped
      // gate lost them without a word (S4-13).
      if ($exit !== 0) {
        return NULL;
      }
      foreach (explode("\0", $stdout) as $line) {
        if ($line !== '') {
          $files[] = $line;
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
   * {@inheritdoc}
   */
  public function lineCounts(string $projectRoot, string $base): ?array {
    // `-z` for the same reason as changedFiles(): a quoted path matches
    // nothing. `--no-renames`, so a record is always `added\tremoved\tpath`
    // and a renamed file is counted under the path that exists now.
    [$exit, $stdout] = $this->git(
      $projectRoot,
      ['diff', '--numstat', '-z', '--no-renames', '--end-of-options', $base],
    );
    if ($exit !== 0) {
      return NULL;
    }
    $counts = [];
    foreach (explode("\0", $stdout) as $record) {
      $fields = explode("\t", $record, 3);
      if (count($fields) !== 3 || $fields[2] === '') {
        continue;
      }
      // A binary file is counted `-\t-`: changed, by no number of lines.
      $counts[$fields[2]] = [
        'added' => ctype_digit($fields[0]) ? (int) $fields[0] : NULL,
        'removed' => ctype_digit($fields[1]) ? (int) $fields[1] : NULL,
      ];
    }
    return $counts;
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
