<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The `events` verb prints the log as it holds it, after a cursor.
 *
 * And the binary itself, run against a log it cannot write, advances the run
 * and says so once on stderr.
 */
final class EventsVerbTest extends WorkflowTestCase {

  /**
   * Raw lines after --after, nothing for an empty log, torn lines skipped.
   */
  public function testEventsPrintsTheLogAfterCursor(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    // The state directory exists: the base case writes the spec into it.
    $dir = (new RunStateStore($root))->directory();

    [$code, $out] = $this->dispatch(['events'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code);
    $this->assertSame('', $out, 'an empty log prints nothing');

    $log = new RunEventLog($dir);
    for ($i = 1; $i <= 5; $i++) {
      $log->append('run-a', NULL, 'phase.began', ['phase' => 'p' . $i]);
    }
    file_put_contents($log->path(), '{"torn":', FILE_APPEND);
    $written = file($log->path(), FILE_IGNORE_NEW_LINES) ?: [];

    [$code, $out] = $this->dispatch(['events', '--after=3'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_OK, $code);
    $this->assertSame([$written[3], $written[4]], explode("\n", $out), 'the lines as the log holds them, the torn one skipped');

    [, $all] = $this->dispatch(['events'], $root);
    $this->assertCount(5, explode("\n", $all));

    [$code, $out] = $this->dispatch(['events', '--after=three'], $root);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('--after needs a sequence number', $out);
  }

  /**
   * A log that cannot be written: the run advances, stderr says so once.
   */
  public function testUnwritableLogNeverStopsTheRun(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  on: false\n");
    $dir = (new RunStateStore($root))->directory();
    mkdir($dir . '/' . RunEventLog::FILENAME, 0755, TRUE);

    $process = proc_open(
      [PHP_BINARY, dirname(__DIR__, 2) . '/bin/droost-workflow', 'run', '--project=' . $root],
      [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
    );
    $this->assertIsResource($process);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    proc_close($process);

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state, 'the run began: ' . $stdout . $stderr);
    $this->assertNotSame('plan', $state->currentPhase?->value, 'and advanced past plan');
    $this->assertSame(1, substr_count($stderr, 'the run-event log'), 'reported once, though several events went unwritten: ' . $stderr);
  }

  /**
   * Runs the dispatcher, capturing both streams.
   *
   * @param list<string> $argv
   *   The arguments.
   * @param string $cwd
   *   The working directory.
   *
   * @return array{int, string}
   *   The exit code and everything printed.
   */
  private function dispatch(array $argv, string $cwd): array {
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher(
      $sink,
      $sink,
      static fn (): string => '2026-09-28T00:00:00+00:00',
      static fn (): string => 'run-events',
    );

    return [$dispatcher->dispatch([...$argv, '--project=' . $cwd], $cwd), implode("\n", $lines)];
  }

}
