<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * A gate's tool never reads the stdin of the process that runs it (F-84).
 *
 * `drush mcp:server` speaks to its client over stdin, and it runs every gate.
 * The children used to inherit that stdin: infection with no config asked its
 * setup questions on it, and stylelint and prettier handed no files read
 * their code from it. Each blocked until the gate's timeout, and anything the
 * client sent meanwhile went to the tool.
 *
 * So this runs the shape itself: a parent whose stdin is a pipe held open,
 * sometimes carrying a line a client might have sent, calls
 * `CliProcess::run()` on a child that reads stdin to the end.
 */
final class CliProcessStdinTest extends TestCase {

  /**
   * A child that reads stdin sees its end at once, not the parent's pipe.
   */
  public function testChildReadsAnEmptyClosedStdin(): void {
    [$result, $elapsed] = $this->runUnderOpenStdin('');
    $this->assertSame([0, '0'], [$result[0], $result[1]], 'the child read nothing and exited');
    $this->assertLessThan(5.0, $elapsed, 'the child did not wait on the open pipe until its timeout');
  }

  /**
   * What the parent's client sends is not the tool's to read.
   */
  public function testChildNeverSeesTheParentsInput(): void {
    [$result] = $this->runUnderOpenStdin("{\"jsonrpc\":\"2.0\",\"method\":\"notifications/cancelled\"}\n");
    $this->assertSame([0, '0'], [$result[0], $result[1]], 'the line on the parent\'s stdin stayed the parent\'s');
  }

  /**
   * Runs CliProcess::run() in a parent whose stdin stays open.
   *
   * @param string $sent
   *   What the stand-in client writes to the parent's stdin, which is then
   *   left open, as a session's pipe is.
   *
   * @return array{array{int, string, string}, float}
   *   The run's result as the parent saw it, and the seconds it took.
   */
  private function runUnderOpenStdin(string $sent): array {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $reader = 'echo strlen((string) stream_get_contents(STDIN));';
    $parent = sprintf(
      'require %s; echo json_encode(\Droost\Workflow\Cli\CliProcess::run([PHP_BINARY, "-r", %s], sys_get_temp_dir(), 10));',
      var_export($autoload, TRUE),
      var_export($reader, TRUE),
    );
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-r', $parent], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $this->assertIsResource($process);
    if ($sent !== '') {
      fwrite($pipes[0], $sent);
      fflush($pipes[0]);
    }
    $started = microtime(TRUE);
    $stdout = (string) stream_get_contents($pipes[1]);
    $elapsed = microtime(TRUE) - $started;
    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $result = json_decode($stdout, TRUE);
    $this->assertIsArray($result, 'the parent reported a result: ' . $stdout);
    /** @var array{int, string, string} $result */
    return [$result, $elapsed];
  }

}
