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
        return [0, "web/modules/custom/a/a.module\nweb/themes/custom/t/t.css\n", ''];
      }
      return [0, " M web/modules/custom/a/a.module\n?? web/modules/custom/b/b.info.yml\nR  old.txt -> new.txt\n", ''];
    });

    $files = $vcs->changedFiles('/repo', 'abc123');

    $this->assertSame([
      'new.txt',
      'web/modules/custom/a/a.module',
      'web/modules/custom/b/b.info.yml',
      'web/themes/custom/t/t.css',
    ], $files, 'sorted, deduplicated, renames by their new name');
    $this->assertSame(['git', '-C', '/repo', 'diff', '--name-only', 'abc123'], $seen[0]);
    $this->assertSame(['git', '-C', '/repo', 'status', '--porcelain', '--untracked-files=all'], $seen[1]);
  }

  /**
   * With no base commit only the working tree is consulted.
   */
  public function testNoBaseMeansWorkingTreeOnly(): void {
    $calls = 0;
    $vcs = new CliVcs(static function (array $argv) use (&$calls): array {
      $calls++;
      return [0, "?? x.php\n", ''];
    });
    $this->assertSame(['x.php'], $vcs->changedFiles('/repo', NULL));
    $this->assertSame(1, $calls, 'no diff call without a base');
  }

}
