<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Vcs;

use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\CliVcs;

/**
 * Version-control facts through a fake git.
 */
final class CliVcsTest extends WorkflowTestCase {

  /**
   * The head commit is the trimmed rev-parse output, when it looks like one.
   */
  public function testHeadParsesRevParse(): void {
    $vcs = new CliVcs(static fn (array $argv): array => [0, "0123456789abcdef0123456789abcdef01234567\n", '']);
    $this->assertSame('0123456789abcdef0123456789abcdef01234567', $vcs->head('/repo'));

    $notARepo = new CliVcs(static fn (array $argv): array => [128, '', 'fatal: not a git repository']);
    $this->assertNull($notARepo->head('/repo'));

    $noGit = new CliVcs(static function (array $argv): array {
      throw new \RuntimeException('git: command not found');
    });
    $this->assertNull($noGit->head('/repo'), 'a missing git binary is "no repository", not a crash');
    $this->assertSame([], $noGit->changedFiles('/repo', 'abc'));
  }

  /**
   * Changed files merge the diff against the base with the working tree.
   */
  public function testChangedFilesMergeDiffAndStatus(): void {
    $seen = [];
    $vcs = new CliVcs(static function (array $argv) use (&$seen): array {
      $seen[] = $argv;
      if (in_array('diff', $argv, TRUE)) {
        // NUL-separated here too. `--name-only` without `-z` QUOTES any path
        // git considers unusual — `core.quotePath` defaults on, so one
        // non-ASCII byte is enough — and the leading `"` then breaks every
        // prefix match downstream, so a file inside an exempt tree stops
        // being exempt. The status half was converted and this was not, and
        // this is the branch that runs on every phase past the first.
        return [0, "web/modules/custom/a/a.module\0web/themes/custom/t/t.css\0", ''];
      }
      // NUL-separated, which is what `-z` returns — and a rename emits its new
      // path then its old one as a second field, rather than "old -> new".
      return [
        0,
        " M web/modules/custom/a/a.module\0"
        . "?? web/modules/custom/b/b.info.yml\0"
        . "R  new.txt\0old.txt\0",
        '',
      ];
    });

    $files = $vcs->changedFiles('/repo', 'abc123');

    $this->assertSame([
      'new.txt',
      'web/modules/custom/a/a.module',
      'web/modules/custom/b/b.info.yml',
      'web/themes/custom/t/t.css',
    ], $files, 'sorted, deduplicated, renames by their new name');
    $this->assertSame(
      ['git', '-C', '/repo', 'diff', '--name-only', '-z', 'abc123'],
      $seen[0],
      '-z here too: --name-only quotes any path git considers unusual, and the
      leading quote then breaks every prefix match downstream',
    );
    $this->assertSame(
      ['git', '-C', '/repo', 'status', '--porcelain', '-z', '--untracked-files=all'],
      $seen[1],
      '-z, because --porcelain quotes any path containing a space and nothing '
      . 'downstream strips the quotes',
    );
  }

  /**
   * With no base commit only the working tree is consulted.
   */
  public function testNoBaseMeansWorkingTreeOnly(): void {
    $calls = 0;
    $vcs = new CliVcs(static function (array $argv) use (&$calls): array {
      $calls++;
      return [0, "?? x.php\0", ''];
    });
    $this->assertSame(['x.php'], $vcs->changedFiles('/repo', NULL));
    $this->assertSame(1, $calls, 'no diff call without a base');
  }

  /**
   * A path containing a space arrives unquoted, and a rename yields one file.
   *
   * `git status --porcelain` QUOTES any path with a space — literal double
   * quotes, passed through — and nothing downstream stripped them. The leading
   * `"` then broke every prefix match, so a file inside an EXEMPT tree stopped
   * being exempt: a stock `composer require --dev squizlabs/php_codesniffer` in
   * a repo that commits vendor/ blocked the code phase over
   * `"vendor/…/ClassFileName Spaces In FilenameUnitTest.inc"`. The exact wall
   * the exemptions exist to remove, still standing.
   *
   * `-z` never quotes. Its cost is that a RENAME emits the old path as a second
   * NUL field, which must be consumed rather than read as another file —
   * otherwise a rename invents a change to a file that no longer exists.
   */
  public function testPathsWithSpacesAndRenamesArePlain(): void {
    $vcs = new CliVcs(static fn (array $argv, string $dir, int $timeout): array => [
      0,
      // Two ordinary files, one with a space, and a rename (new then old).
      "A  src/plain.php\0"
      . "?? src/Has Spaces.php\0"
      . "R  src/renamed.php\0src/was.php\0",
      '',
    ]);

    $files = $vcs->changedFiles('/tmp/whatever', NULL);

    $this->assertSame(
      ['src/Has Spaces.php', 'src/plain.php', 'src/renamed.php'],
      $files,
    );
    $this->assertNotContains('src/was.php', $files, 'a rename is not two files');
    foreach ($files as $file) {
      $this->assertStringNotContainsString('"', $file, 'and nothing arrives quoted');
    }
  }

  /**
   * A path git would quote comes back as the path, not as its spelling.
   *
   * `git diff --name-only HEAD~1` on a real repository:
   *
   *     "vendor/symfony/string/Tests/\303\274n\303\257code-fixture.php"
   *
   * The quotes and the octal escapes are git's rendering, not the name. Passed
   * through, the leading `"` breaks every prefix match — so a file inside a
   * tree the scope audit exempts reads as undeclared creep, with an agent
   * fault and no waiver, on a file nobody chose to write. A committed path
   * containing a NEWLINE is worse still: splitting on `\R` invents two paths
   * out of one, neither of which exists.
   *
   * `-z` is git's answer to both, and asserting it is asserted through the
   * ARGV as well as the output, because a fixture that returns NUL-separated
   * text would pass whatever the command asked for.
   */
  public function testTheDiffIsAskedForNulSeparated(): void {
    $seen = [];
    $vcs = new CliVcs(static function (array $argv) use (&$seen): array {
      $seen[] = $argv;
      if (in_array('diff', $argv, TRUE)) {
        return [0, "vendor/x/\u{fc}n\u{ef}code.php\0src/A.php\0", ''];
      }

      return [0, '', ''];
    });

    $files = $vcs->changedFiles('/repo', 'abc123');

    $diff = [];
    foreach ($seen as $argv) {
      if (in_array('diff', $argv, TRUE)) {
        $diff = $argv;
      }
    }
    $this->assertContains('-z', $diff, 'the diff is asked for NUL-separated');
    $this->assertSame(
      ['src/A.php', "vendor/x/\u{fc}n\u{ef}code.php"],
      $files,
      'and the name comes back as the name, with no quotes around it',
    );
  }

}
