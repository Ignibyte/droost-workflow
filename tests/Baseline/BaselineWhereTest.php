<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Baseline;

use Droost\Workflow\Baseline\BaselineError;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Cli\CliProcess;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\CliVcs;
use Droost\Workflow\WorkflowFacade;

/**
 * A baseline says what it measured, and can commit itself (F-390, F-391).
 *
 * An adopter refreshed while a ticket branch that deletes files was checked
 * out, and the record took that branch's deletions as debt paid off; it had
 * to be thrown away. And committing the record is the operator's, so they
 * typed a git command after every refresh.
 */
final class BaselineWhereTest extends WorkflowTestCase {

  /**
   * The levers: every gate the writer measures is off, so it records none.
   */
  private const LEVERS = "preset: custom\nmode: agentic\ngates:\n  phpcs: { on: false }\n  phpstan: { on: false }\n"
    . "  phpunit: { on: false }\n  playwright: { on: false }\n  parity: { on: false }\n  house_rules: { on: false }\n";

  /**
   * The write names the branch and commit it measured, and records both.
   */
  public function testTheWriteNamesWhatItMeasured(): void {
    $root = $this->repository();
    $head = $this->git($root, 'rev-parse HEAD');

    $written = $this->facade()->baselineWrite($root);

    $this->assertSame(['branch' => 'main', 'commit' => $head, 'replaces' => NULL], $written['measured']);
    $this->assertSame('main', BaselineStore::load($root)?->manifest->generatedBranch);
  }

  /**
   * A refresh on a ticket branch is refused, naming both branches.
   *
   * The ticket branch was cut after the baseline was committed, so it
   * descends from the record's commit: only the branch tells the two apart.
   */
  public function testRefreshOnTicketBranchIsRefused(): void {
    $root = $this->repository();
    $facade = $this->facade();
    $facade->baselineWrite($root);
    $this->git($root, 'add -A');
    $this->git($root, 'commit -q -m baseline');
    $this->git($root, 'checkout -q -b TICKET-2416');
    $this->git($root, 'rm -q a.txt');
    $this->git($root, 'commit -q -m "the ticket deletes a file"');

    try {
      $facade->baselineWrite($root, TRUE);
      $this->fail('a refresh on the ticket branch was written');
    }
    catch (BaselineError $e) {
      $this->assertStringContainsString('TICKET-2416', $e->getMessage());
      $this->assertStringContainsString('recorded on main', $e->getMessage());
      $this->assertStringContainsString('--from-here', $e->getMessage());
    }

    $written = $facade->baselineWrite($root, TRUE, fromHere: TRUE);
    $this->assertSame('TICKET-2416', $this->at($written, 'measured', 'branch'), 'the operator said this tree is the one');
    $this->assertSame('main', $this->at($written, 'measured', 'replaces', 'branch'));
  }

  /**
   * Detached, off the record's line of history, a refresh is refused too.
   */
  public function testDetachedOffTheLineIsRefused(): void {
    $root = $this->repository();
    $first = $this->git($root, 'rev-parse HEAD');
    $this->commitFile($root, 'b.txt', 'main moves on');
    $facade = $this->facade();
    $facade->baselineWrite($root);
    $this->git($root, 'add -A');
    $this->git($root, 'commit -q -m baseline');
    // A commit beside the record's, carrying the record: not its descendant.
    $this->git($root, 'checkout -q --detach ' . $first);
    $this->git($root, 'checkout -q main -- ' . BaselineStore::DIR);
    $this->git($root, 'commit -q -m "the record, on another line"');

    $this->expectException(BaselineError::class);
    $this->expectExceptionMessage('a detached HEAD');
    $facade->baselineWrite($root, TRUE);
  }

  /**
   * A refresh further down the record's line is measured, naming the old one.
   */
  public function testRefreshDownTheLineIsMeasured(): void {
    $root = $this->repository();
    $facade = $this->facade();
    $facade->baselineWrite($root);
    $recorded = BaselineStore::load($root)?->manifest->generatedCommit;
    $this->assertNotNull($recorded);
    $this->git($root, 'add -A');
    $this->git($root, 'commit -q -m baseline');
    $this->commitFile($root, 'c.txt', 'later work');

    $written = $facade->baselineWrite($root, TRUE);

    $this->assertSame(['branch' => 'main', 'commit' => $recorded], $this->at($written, 'measured', 'replaces'));
  }

  /**
   * Committing commits droost/baseline/ and leaves everything else as it was.
   */
  public function testCommitCommitsTheBaselineAlone(): void {
    $root = $this->repository();
    file_put_contents($root . '/staged.txt', "the developer's own work\n");
    $this->git($root, 'add staged.txt');

    $written = $this->facade()->baselineWrite($root, commit: TRUE);

    $commit = $this->at($written, 'commit', 'commit');
    $this->assertIsString($commit, (string) json_encode($written['commit'] ?? NULL));
    $this->assertSame($commit, $this->git($root, 'rev-parse HEAD'));
    $files = explode("\n", $this->git($root, 'show --name-only --format= HEAD'));
    $this->assertNotSame([], $files);
    foreach ($files as $file) {
      $this->assertStringStartsWith(BaselineStore::DIR . '/', $file);
    }
    $this->assertStringContainsString('droost baseline: recorded', $this->git($root, 'log -1 --format=%s'));
    $this->assertSame('committed as t <t@example.invalid>', $this->at($written, 'commit', 'why'));
    $this->assertSame('staged.txt', $this->git($root, 'diff --cached --name-only'), 'still staged, not committed');
  }

  /**
   * DDEV's placeholder identity commits nothing, and says how to fix it.
   *
   * Through `ddev droost` git runs in the web container, where it would
   * commit as "DDEV User <nobody@example.com>" rather than the developer.
   */
  public function testThePlaceholderIdentityCommitsNothing(): void {
    $root = $this->repository();
    $this->git($root, 'config user.name "DDEV User"');
    $this->git($root, 'config user.email nobody@example.com');
    $before = $this->git($root, 'rev-parse HEAD');

    $written = $this->facade()->baselineWrite($root, commit: TRUE);

    $this->assertNull($this->at($written, 'commit', 'commit'));
    $why = $this->at($written, 'commit', 'why');
    $this->assertIsString($why);
    $this->assertStringContainsString('DDEV', $why);
    $this->assertStringContainsString('git config user.name', $why);
    $this->assertSame($before, $this->git($root, 'rev-parse HEAD'), 'nothing was committed');
    $this->assertTrue(BaselineStore::exists($root), 'the baseline itself is written');
  }

  /**
   * Committing paths that match HEAD commits nothing and says so.
   */
  public function testNothingToCommitIsSaid(): void {
    $root = $this->repository();
    [$commit, $why] = (new CliVcs(CliProcess::run(...)))->commitPaths($root, ['a.txt'], 'nothing');
    $this->assertNull($commit);
    $this->assertStringContainsString('nothing to commit', $why);
  }

  /**
   * A value inside nested arrays, each step asserted to be one.
   *
   * @param mixed $value
   *   The outer value.
   * @param string ...$keys
   *   The path.
   *
   * @return mixed
   *   What is there.
   */
  private function at(mixed $value, string ...$keys): mixed {
    foreach ($keys as $key) {
      $this->assertIsArray($value, 'at ' . $key);
      $this->assertArrayHasKey($key, $value);
      $value = $value[$key];
    }
    return $value;
  }

  /**
   * A git repository on main with one commit and the levers.
   *
   * @return string
   *   The root.
   */
  private function repository(): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost', 0755, TRUE);
    file_put_contents($root . '/droost/droost.yml', self::LEVERS);
    file_put_contents($root . '/a.txt', "start\n");
    $this->git($root, 'init -q -b main .');
    $this->git($root, 'config user.name t');
    $this->git($root, 'config user.email t@example.invalid');
    $this->git($root, 'add -A');
    $this->git($root, 'commit -q -m start');
    return $root;
  }

  /**
   * Commits one file.
   *
   * @param string $root
   *   The repository.
   * @param string $file
   *   The file name.
   * @param string $text
   *   Its contents.
   */
  private function commitFile(string $root, string $file, string $text): void {
    file_put_contents($root . '/' . $file, $text . "\n");
    $this->git($root, 'add ' . escapeshellarg($file));
    $this->git($root, 'commit -q -m ' . escapeshellarg($file));
  }

  /**
   * Runs git in the repository.
   *
   * @param string $root
   *   The repository.
   * @param string $args
   *   The arguments, as a shell would read them.
   *
   * @return string
   *   Standard output, trimmed.
   */
  private function git(string $root, string $args): string {
    exec(sprintf('git -C %s %s 2>&1', escapeshellarg($root), $args), $out, $exit);
    $this->assertSame(0, $exit, 'git ' . $args . ': ' . implode("\n", $out));
    return trim(implode("\n", $out));
  }

  /**
   * A facade that measures with the shell and reads git.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    return new WorkflowFacade(
      new ShellGateExecutor(
        static fn (array $argv, string $cwd, int $timeout): array => CliProcess::run($argv, $cwd, $timeout),
        static fn (): int => 0,
      ),
      new RunStateOnlySink(),
      static fn (): string => '2026-10-25T00:00:00+00:00',
      static fn (): string => 'run-baseline-where',
    );
  }

}
