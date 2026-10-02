<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * The guard records which Claude Code ran each session (E5, 2026-10-02).
 *
 * Read from the transcript the hook is handed, once per session, into the
 * state directory, and shown by status. Claude Code 2.1.287 turned mods on,
 * and a mod runs before the guard, so a run's record names its host.
 */
final class GuardHostRecordTest extends WorkflowTestCase {

  /**
   * The version is read from the transcript and kept per session.
   */
  public function testTheHostIsRecordedOncePerSession(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    $transcript = $root . '/t1.jsonl';
    file_put_contents($transcript, "{\"type\":\"summary\"}\n" . '{"type":"user","version":"2.1.287","sessionId":"s1"}' . "\n");

    $this->call($root, 's1', $transcript);
    $host = json_decode((string) file_get_contents($root . '/droost/droost-workflow/host.json'), TRUE);
    $this->assertIsArray($host);
    $this->assertSame('2.1.287', $host['claude_code']);
    $this->assertSame('s1', $host['session_id']);

    // The same session again reads nothing new; another session is added.
    file_put_contents($transcript, '{"version":"9.9.9"}' . "\n");
    $this->call($root, 's1', $transcript);
    $again = json_decode((string) file_get_contents($root . '/droost/droost-workflow/host.json'), TRUE);
    $this->assertIsArray($again);
    $this->assertSame('2.1.287', $again['claude_code'], 'once per session');
    $this->call($root, 's2', $transcript);
    $next = json_decode((string) file_get_contents($root . '/droost/droost-workflow/host.json'), TRUE);
    $this->assertIsArray($next);
    $this->assertSame('9.9.9', $next['claude_code']);
    $this->assertIsArray($next['sessions']);
    $this->assertCount(2, $next['sessions']);

    $status = (new WorkflowFacade(
      new class implements GateExecutorInterface {

        /**
         * {@inheritdoc}
         */
        public function execute(GateSettings $gate, string $projectRoot): GateResult {
          return new GateResult($gate->name, GateStatus::Passed, 0, 5, 'ok');
        }

      },
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-10-02T12:00:00+00:00',
      static fn (): string => 'run-host',
    ))->status($root);
    $this->assertIsArray($status['host']);
    $this->assertSame('9.9.9', $status['host']['claude_code']);
  }

  /**
   * A project with no state directory gains no record, and no directory.
   */
  public function testNoStateDirectoryWritesNothing(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/t.jsonl', '{"version":"2.1.287"}' . "\n");
    $this->call($root, 's1', $root . '/t.jsonl');
    $this->assertDirectoryDoesNotExist($root . '/droost/droost-workflow');
  }

  /**
   * One harmless tool call through the guard, from a session.
   */
  private function call(string $root, string $session, string $transcript): void {
    [$code] = $this->guard($root, 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => 'ls'],
      'session_id' => $session,
      'transcript_path' => $transcript,
    ]);
    $this->assertSame(0, $code);
  }

}
