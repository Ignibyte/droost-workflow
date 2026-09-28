<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\WorkItem;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Runs tests/fixtures/cockpit-stub.php, the contract's executable form.
 *
 * Each stub is a `php -S` on a free port over its own state file, stopped
 * in tearDown.
 */
abstract class CockpitStubCase extends WorkflowTestCase {

  /**
   * The seeded bearer token the stub knows and allows.
   */
  protected const TOKEN = 'tok-3f9a1c77e2b04d5a8e6f0c1d2b3a4958';

  /**
   * The stubs this test started.
   *
   * @var list<resource>
   */
  private array $stubs = [];

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    foreach ($this->stubs as $stub) {
      proc_terminate($stub);
      proc_close($stub);
    }
    $this->stubs = [];
    parent::tearDown();
  }

  /**
   * Starts a stub over a seeded state.
   *
   * @param array<string, mixed> $state
   *   What the stub knows: items, switches; the token is added.
   *
   * @return array{string, string}
   *   The API base (URL and path) and the state file.
   */
  protected function stub(array $state = []): array {
    $dir = $this->makeRoot();
    $statePath = $dir . '/stub-state.json';
    $state += ['items' => []];
    $tokens = is_array($state['tokens'] ?? NULL) ? $state['tokens'] : [];
    $tokens[self::TOKEN] = ['allowed' => TRUE];
    $state['tokens'] = $tokens;
    file_put_contents($statePath, json_encode($state, JSON_UNESCAPED_SLASHES));
    $port = $this->freePort();
    $stub = proc_open(
      [PHP_BINARY, '-S', '127.0.0.1:' . $port, dirname(__DIR__) . '/fixtures/cockpit-stub.php'],
      [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
      $pipes,
      $dir,
      ['COCKPIT_STUB_STATE' => $statePath, 'PATH' => (string) getenv('PATH')],
    );
    $this->assertIsResource($stub);
    $this->stubs[] = $stub;
    // Up when it takes a connection.
    for ($i = 0; $i < 100; $i++) {
      $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
      if (is_resource($socket)) {
        fclose($socket);
        break;
      }
      usleep(50000);
    }

    return ['http://127.0.0.1:' . $port . '/work-items/v1', $statePath];
  }

  /**
   * What the stub's state file holds now.
   *
   * @param string $statePath
   *   The state file.
   *
   * @return array<string, mixed>
   *   The state.
   */
  protected function stubState(string $statePath): array {
    $state = json_decode((string) file_get_contents($statePath), TRUE);
    $this->assertIsArray($state);
    $out = [];
    foreach ($state as $key => $value) {
      $out[(string) $key] = $value;
    }
    return $out;
  }

  /**
   * Changes a stub's switches or seeds between requests.
   *
   * @param string $statePath
   *   The state file.
   * @param array<string, mixed> $changes
   *   Top-level keys to set.
   */
  protected function setStub(string $statePath, array $changes): void {
    file_put_contents($statePath, json_encode(array_merge($this->stubState($statePath), $changes), JSON_UNESCAPED_SLASHES));
  }

  /**
   * A port nothing listens on now.
   *
   * @return int
   *   The port.
   */
  protected function freePort(): int {
    $server = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
    $this->assertIsResource($server);
    $name = (string) stream_socket_get_name($server, FALSE);
    fclose($server);
    return (int) substr($name, (int) strrpos($name, ':') + 1);
  }

  /**
   * A ticket as the stub keeps one.
   *
   * @param string $id
   *   Its id.
   * @param string $status
   *   Its state.
   *
   * @return array<string, mixed>
   *   The item.
   */
  protected static function item(string $id, string $status = 'ready'): array {
    return [
      'id' => $id,
      'number' => (int) substr($id, (int) strrpos($id, '-') + 1),
      'title' => 'Ticket ' . $id,
      'status' => $status,
      'type' => 'feature',
      'body' => "## Summary\n\nFrom the cockpit.\n",
      'extra' => ['priority' => 'high'],
    ];
  }

}
