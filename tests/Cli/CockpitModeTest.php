<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkItem\CockpitStubCase;
use Droost\Workflow\WorkItem\CockpitEventRelay;
use Droost\Workflow\WorkItem\CockpitHttp;

/**
 * Cockpit mode through the standalone binary, against the contract's stub.
 *
 * The binary, not the dispatcher in-process, because the environment
 * variables the lever file names and the one stderr line are the binary's.
 */
final class CockpitModeTest extends CockpitStubCase {

  /**
   * Tickets, a bound run, and its events at the cockpit as they happen.
   */
  public function testTicketsAndBoundRunThroughTheCockpit(): void {
    [$base, $statePath] = $this->stub([
      'items' => ['TICKET-12' => self::item('TICKET-12')],
      'next_number' => 13,
    ]);
    $root = $this->project();
    $env = $this->env($base);

    [$code, $out] = $this->binary($root, $env, 'ticket', 'list');
    $this->assertSame(0, $code, $out);
    $listed = json_decode($out, TRUE);
    $this->assertIsArray($listed);
    $this->assertIsArray($listed['tickets'] ?? NULL);
    $this->assertSame(['TICKET-12'], array_column($listed['tickets'], 'id'));

    [$code, $out] = $this->binary($root, $env, 'ticket', 'new', '--title=From the agent', '--type=bug');
    $this->assertSame(0, $code, $out);
    $this->assertStringContainsString('"TICKET-13"', $out);

    [$code, $out, $err] = $this->binary($root, $env, 'ticket', 'move', 'TICKET-12', 'done');
    $this->assertSame(2, $code);
    $this->assertStringContainsString('move tickets in the cockpit', $err);

    [$code, $out, $err] = $this->binary($root, $env, 'run', '--ticket=TICKET-12');
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state, $out . $err);
    $this->assertSame('TICKET-12', $state->workItem['id'] ?? NULL);
    $this->assertSame('droost_cockpit', $state->workItem['source'] ?? NULL);
    $dir = (new RunStateStore($root))->directory();
    $this->assertFileExists($dir . '/work-item-TICKET-12.json', 'the bound ticket is cached for an offline restart');

    // Every event the run wrote is at the cockpit, and the cursor says so.
    $stored = (array) $this->stubState($statePath)['events'];
    $lines = file($dir . '/events.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
    $this->assertNotSame([], $lines);
    $this->assertCount(count($lines), $stored, 'nothing waits');
    $this->assertSame((string) count($lines), trim((string) file_get_contents($dir . '/' . CockpitEventRelay::CURSOR)));

    [$code, $out] = $this->binary($root, $env, 'status');
    $status = json_decode($out, TRUE);
    $this->assertIsArray($status);
    $this->assertSame(['cursor' => count($lines), 'pending' => 0, 'last_error' => NULL], $status['relay'] ?? NULL);

    [$code] = $this->binary($root, $env, 'relay');
    $this->assertSame(0, $code, 'nothing pending');

    // The cockpit down: the next command's events wait, and relay says so.
    $this->setStub($statePath, ['fail' => TRUE]);
    [, , $err] = $this->binary($root, $env, 'run');
    $this->assertSame(1, substr_count($err, 'the cockpit relay stopped'), 'said once for the whole command: ' . $err);
    [$code, $out] = $this->binary($root, $env, 'relay');
    $this->assertSame(1, $code, 'events still wait');
    $this->assertStringContainsString('503', $out);

    // And nothing anywhere carries the token.
    foreach (glob($dir . '/*') ?: [] as $file) {
      if (is_file($file)) {
        $this->assertStringNotContainsString(self::TOKEN, (string) file_get_contents($file), basename($file));
      }
    }
  }

  /**
   * A cockpit that takes the connection and never answers: one timeout.
   */
  public function testSilentCockpitCostsTheRunOneTimeoutAtMost(): void {
    $silent = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    $this->assertIsResource($silent);
    $name = (string) stream_socket_get_name($silent, FALSE);
    $base = 'http://' . $name . '/work-items/v1';

    // With nothing to bind to, a plain run (no --ticket) still relays.
    $quiet = $this->project();
    $started = microtime(TRUE);
    [, , $err] = $this->binary($quiet, $this->env($base), 'run');
    $elapsed = microtime(TRUE) - $started;
    fclose($silent);

    $state = (new RunStateStore($quiet))->load();
    $this->assertNotNull($state, 'the run began: ' . $err);
    $this->assertSame(1, substr_count($err, 'the cockpit relay stopped'), $err);
    $this->assertLessThan(CockpitHttp::TIMEOUT + 3, $elapsed, 'the run paid for one timeout, not one per event');
    $this->assertFileDoesNotExist((new RunStateStore($quiet))->directory() . '/' . CockpitEventRelay::CURSOR);
  }

  /**
   * An unset variable: the run goes on, and says which variable.
   */
  public function testAnUnsetVariableIsNamedNotFatal(): void {
    $root = $this->project();
    [, , $err] = $this->binary($root, ['PATH' => (string) getenv('PATH')], 'run');
    $this->assertNotNull((new RunStateStore($root))->load(), $err);
    $this->assertStringContainsString('DWF_TEST_COCKPIT_URL', $err);
    $this->assertStringContainsString('is not set', $err);
  }

  /**
   * A project in cockpit mode, the variables named in its lever file.
   *
   * @return string
   *   The root.
   */
  private function project(): string {
    return $this->makeRootWithConfig(
      "preset: custom\nmode: agentic\nseekers:\n  on: false\n"
      . "work_item:\n  provider: droost_cockpit\n  cockpit: { url_env: DWF_TEST_COCKPIT_URL, token_env: DWF_TEST_COCKPIT_TOKEN }\n",
    );
  }

  /**
   * The environment the binary runs in, the cockpit's variables set.
   *
   * @param string $base
   *   The API base; the variable holds the URL before the path.
   *
   * @return array<string, string>
   *   The environment.
   */
  private function env(string $base): array {
    return [
      'PATH' => (string) getenv('PATH'),
      'DWF_TEST_COCKPIT_URL' => substr($base, 0, -strlen('/work-items/v1')),
      'DWF_TEST_COCKPIT_TOKEN' => self::TOKEN,
    ];
  }

  /**
   * Runs the binary.
   *
   * @param string $root
   *   The project.
   * @param array<string, string> $env
   *   Its environment.
   * @param string ...$argv
   *   The verb and its arguments.
   *
   * @return array{int, string, string}
   *   The exit code, stdout and stderr.
   */
  private function binary(string $root, array $env, string ...$argv): array {
    $process = proc_open(
      [PHP_BINARY, dirname(__DIR__, 2) . '/bin/droost-workflow', ...array_values($argv), '--project=' . $root],
      [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
      $env,
    );
    $this->assertIsResource($process);
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($process), $out, $err];
  }

}
