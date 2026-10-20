<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Each proof says what the run changed after it was recorded (F-289).
 *
 * The seeker judged a stale proof with `git log -p`, and a run that commits
 * only at the end has no history to judge it by. `criteria` lists, under
 * each proof, the files this run changed later than it.
 */
final class CriteriaChangedAfterTest extends WorkflowTestCase {

  /**
   * A proof older than an edit names the file; a newer one names nothing.
   */
  public function testProofOlderThanAnEditNamesIt(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    file_put_contents($root . '/page.php', "<?php\n");
    exec(sprintf(
      'cd %s && git init -q . && git add -A && git -c user.name=droost-test -c user.email=test@example.invalid commit -q -m base 2>&1',
      escapeshellarg($root),
    ), $output, $exit);
    $this->assertSame(0, $exit, implode("\n", $output));

    $this->cli(['run'], $root, '2000-01-01T00:00:00+00:00');
    $this->cli(['declare-criterion', 'AC1', 'the page renders'], $root, '2000-01-01T00:00:00+00:00');
    $this->cli(['declare-criterion', 'AC2', 'the page still renders'], $root, '2000-01-01T00:00:00+00:00');
    $this->cli(['verify-criterion', 'AC1', 'PageTest::testIt'], $root, '2000-01-01T00:00:00+00:00');
    $this->cli(['verify-criterion', 'AC2', 'PageTest::testIt'], $root, '2100-01-01T00:00:00+00:00');
    file_put_contents($root . '/page.php', "<?php\necho 'changed';\n");

    [$code, $out] = $this->cli(['criteria'], $root, '2100-01-01T00:00:00+00:00');

    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $this->assertMatchesRegularExpression('/AC1 .*\n    changed after this proof: page\.php/', $out, 'the edit came after AC1\'s proof');
    $this->assertDoesNotMatchRegularExpression('/AC2 .*\n    changed after/', $out, 'and before AC2\'s');
  }

  /**
   * Runs the dispatcher on a given clock, both streams into one.
   *
   * @param list<string> $argv
   *   The verb and its arguments.
   * @param string $root
   *   The project.
   * @param string $now
   *   The clock's one reading.
   *
   * @return array{int, string}
   *   The exit code and the output.
   */
  private function cli(array $argv, string $root, string $now): array {
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher($sink, $sink, static fn (): string => $now, static fn (): string => 'run-criteria');

    return [$dispatcher->dispatch($argv, $root), implode("\n", $lines)];
  }

}
