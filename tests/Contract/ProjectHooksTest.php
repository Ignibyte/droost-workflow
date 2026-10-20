<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Contract;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Event\ProjectHooks;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * A hook is told about the run's events, and can never hold it.
 */
final class ProjectHooksTest extends WorkflowTestCase {

  /**
   * Each named event's file runs, with the event on stdin; others do not.
   */
  public function testTheHookIsToldAndRecorded(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/hooks', 0755, TRUE);
    mkdir($root . '/state', 0755, TRUE);
    file_put_contents($root . '/droost/hooks/start.php', '<?php
      $in = json_decode((string) stream_get_contents(STDIN), TRUE);
      file_put_contents(getenv("DROOST_PROJECT_ROOT") . "/told.json", json_encode([$in, getenv("DROOST_HOOK")]));');
    $hooks = new ProjectHooks($root, $root . '/state', ['run_start' => 'droost/hooks/start.php']);

    $row = $hooks->fire('run.started', ['run_id' => 'r1', 'phase' => 'plan'], [], '2026-10-19T00:00:00+00:00');
    $this->assertNull($hooks->fire('phase.began', ['run_id' => 'r1'], [], 'x'), 'an event no hook names runs nothing');

    $this->assertIsArray($row);
    $this->assertTrue($row['ok']);
    $told = json_decode((string) file_get_contents($root . '/told.json'), TRUE);
    $this->assertIsArray($told);
    $this->assertSame('run_start', $told[1] ?? NULL);
    $this->assertIsArray($told[0] ?? NULL);
    $this->assertSame('r1', $told[0]['run_id'] ?? NULL);
    $this->assertSame($root, $told[0]['project_root'] ?? NULL);
    $this->assertStringContainsString('"event":"run_start"', (string) file_get_contents($root . '/state/' . ProjectHooks::FILENAME));
  }

  /**
   * A hook that fails, crashes or is missing is recorded, and nothing throws.
   */
  public function testFailingHookIsRecordedNotRaised(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/hooks', 0755, TRUE);
    file_put_contents($root . '/droost/hooks/fails.php', '<?php fwrite(STDERR, "tracker down\n"); exit(1);');
    file_put_contents($root . '/droost/hooks/crashes.php', '<?php throw new \RuntimeException("boom");');
    $hooks = new ProjectHooks($root, $root, [
      'run_start' => 'droost/hooks/fails.php',
      'phase_end' => 'droost/hooks/crashes.php',
      'run_complete' => 'droost/hooks/missing.php',
    ]);

    $failed = $hooks->fire('run.started', ['run_id' => 'r'], [], 'x');
    $crashed = $hooks->fire('phase.ended', ['run_id' => 'r'], [], 'x');
    $missing = $hooks->fire('run.completed', ['run_id' => 'r'], [], 'x');

    $this->assertIsArray($failed);
    $this->assertFalse($failed['ok']);
    $this->assertSame('tracker down', $failed['note']);
    $this->assertIsArray($crashed);
    $this->assertFalse($crashed['ok']);
    $this->assertIsArray($missing);
    $this->assertFalse($missing['ok']);
    $note = $missing['note'] ?? NULL;
    $this->assertIsString($note);
    $this->assertStringContainsString('not there', $note);
  }

  /**
   * A run fires its hooks, and a failing one does not stop the run.
   */
  public function testRunFiresItsHooks(): void {
    $root = $this->makeRootWithConfig("preset: custom\nseekers: { on: false }\nhooks:\n  run_start: droost/hooks/start.php\n  phase_begin: droost/hooks/begin.php\n");
    mkdir($root . '/droost/hooks', 0755, TRUE);
    file_put_contents($root . '/droost/hooks/start.php', '<?php exit(1);');
    file_put_contents($root . '/droost/hooks/begin.php', '<?php exit(0);');

    $this->facade()->run($root);

    $rows = array_map(
      static fn (string $line): mixed => json_decode($line, TRUE),
      file((new RunStateStore($root))->directory() . '/' . ProjectHooks::FILENAME, FILE_IGNORE_NEW_LINES) ?: [],
    );
    $events = array_map(static fn (mixed $row): mixed => is_array($row) ? ($row['event'] ?? NULL) : NULL, $rows);
    $this->assertSame(['run_start', 'phase_begin'], array_slice($events, 0, 2));
    $this->assertNotNull((new RunStateStore($root))->load(), 'the run opened though its run_start hook failed');
  }

  /**
   * What a hook says reaches the agent, in the run's output.
   *
   * A hook notifies and cannot hold the run, and what it said reached only
   * the hook record, so a project could not tell the agent to move its
   * ticket at code (owner, 2026-10-20).
   */
  public function testHookNoteReachesTheRunOutput(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers: { on: false }\nhooks:\n  phase_begin: droost/hooks/begin.php\n");
    mkdir($root . '/droost/hooks', 0755, TRUE);
    file_put_contents($root . '/droost/hooks/begin.php', '<?php $e = json_decode(stream_get_contents(STDIN), TRUE); echo "phase " . $e["phase"] . " began\n";');

    $facade = $this->facade();
    $facade->run($root);
    $notes = $facade->takeHookNotes();

    $this->assertNotSame([], $notes);
    $this->assertSame('phase_begin', $notes[0]['event']);
    $this->assertStringStartsWith('phase ', $notes[0]['note']);
    $this->assertSame([], $facade->takeHookNotes(), 'taken once');
  }

  /**
   * A facade whose every gate passes.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
    return new WorkflowFacade(
      $executor,
      new RunStateOnlySink(),
      static fn (): string => '2026-10-19T00:00:00+00:00',
      static fn (): string => 'run-hooks',
    );
  }

}
