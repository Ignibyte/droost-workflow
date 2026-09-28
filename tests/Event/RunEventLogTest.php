<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Event;

use Droost\Workflow\Event\RunEvent;
use Droost\Workflow\Event\RunEventLog;
use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * The run-event log: append-only lines, a locked sequence, never a failure.
 */
#[CoversClass(RunEventLog::class)]
#[CoversClass(RunEvent::class)]
final class RunEventLogTest extends WorkflowTestCase {

  /**
   * Events append as lines, numbered from 1, and read back after a cursor.
   */
  public function testAppendAndRead(): void {
    $log = new RunEventLog($this->makeRoot(), NULL, static fn (): string => '2026-09-28T12:00:00+00:00');

    $first = $log->append('run-a', NULL, 'run.started', ['preset' => 'low']);
    $second = $log->append('run-a', 'TICKET-7', 'phase.began', ['phase' => 'plan']);
    $third = $log->append('run-a', 'TICKET-7', 'run.reset', []);

    $this->assertNotNull($first);
    $this->assertNotNull($second);
    $this->assertNotNull($third);
    $this->assertSame([1, 2, 3], [$first->seq, $second->seq, $third->seq]);
    foreach ([$first, $second, $third] as $event) {
      $this->assertMatchesRegularExpression('/^evt-[0-9a-f]{16}$/', $event->eventId);
      $this->assertSame(RunEvent::SCHEMA, $event->schema);
    }
    $this->assertCount(3, array_unique([$first->eventId, $second->eventId, $third->eventId]));

    $lines = iterator_to_array($log->lines(), FALSE);
    $this->assertCount(3, $lines);
    $this->assertStringEndsWith('"payload":{}}', $lines[2], 'an empty payload is an object');
    $decoded = json_decode($lines[1], TRUE);
    $this->assertSame([
      'schema' => 'droost.run-event/1',
      'event_id' => $second->eventId,
      'seq' => 2,
      'run_id' => 'run-a',
      'work_item_id' => 'TICKET-7',
      'at' => '2026-09-28T12:00:00+00:00',
      'type' => 'phase.began',
      'payload' => ['phase' => 'plan'],
    ], $decoded);

    $after = array_map(static fn (RunEvent $e): int => $e->seq, iterator_to_array($log->read(1), FALSE));
    $this->assertSame([2, 3], $after);
    $this->assertSame([], iterator_to_array($log->read(3), FALSE));
  }

  /**
   * An empty or absent log reads as nothing.
   */
  public function testNoLogReadsNothing(): void {
    $dir = $this->makeRoot();
    $log = new RunEventLog($dir);
    $this->assertSame([], iterator_to_array($log->read(), FALSE));
    touch($dir . '/' . RunEventLog::FILENAME);
    $this->assertSame([], iterator_to_array($log->lines(), FALSE));
  }

  /**
   * A torn last line is skipped, and the next event starts a line of its own.
   */
  public function testTornLastLineIsSkipped(): void {
    $dir = $this->makeRoot();
    $log = new RunEventLog($dir);
    $log->append('run-a', NULL, 'phase.began', ['phase' => 'plan']);
    file_put_contents($log->path(), '{"schema":"droost.run-event/1","event_id":"evt-00', FILE_APPEND);

    $next = $log->append('run-a', NULL, 'phase.ended', ['phase' => 'plan']);
    $this->assertNotNull($next);
    $this->assertSame(2, $next->seq, 'the sequence continues from the last whole event');
    $this->assertSame([1, 2], array_map(static fn (RunEvent $e): int => $e->seq, iterator_to_array($log->read(), FALSE)));
    $this->assertCount(3, file($log->path(), FILE_IGNORE_NEW_LINES) ?: [], 'the torn line stayed a line of its own');
  }

  /**
   * The next sequence number is read from the end, past a block boundary.
   */
  public function testSequenceIsReadFromTheEndOfLargeLog(): void {
    $log = new RunEventLog($this->makeRoot());
    $big = str_repeat('x', 700);
    for ($i = 1; $i <= 150; $i++) {
      $log->append('run-a', NULL, 'phase.attempted', ['phase' => 'code', 'note' => $big]);
    }
    $this->assertGreaterThan(65536 * 1.5, filesize($log->path()), 'the log spans more than one read block');
    $this->assertSame(151, $log->append('run-a', NULL, 'phase.ended', ['phase' => 'code'])?->seq);
  }

  /**
   * A log that cannot be written is reported, and never raises.
   */
  public function testUnwritableLogIsReportedAndNeverThrows(): void {
    $dir = $this->makeRoot();
    mkdir($dir . '/' . RunEventLog::FILENAME);
    $warnings = [];
    $log = new RunEventLog($dir, static function (string $line) use (&$warnings): void {
      $warnings[] = $line;
    });

    $this->assertNull($log->append('run-a', NULL, 'run.started', []));
    $this->assertCount(1, $warnings);
    $this->assertStringContainsString('the run-event log', $warnings[0]);
    $this->assertStringContainsString('the run goes on', $warnings[0]);
  }

  /**
   * Two processes appending at once never interleave a line or share a seq.
   */
  public function testTwoProcessesNeverInterleave(): void {
    $dir = $this->makeRoot();
    $script = $dir . '/append.php';
    file_put_contents($script, sprintf(
      "<?php\nrequire %s;\n\$log = new \\Droost\\Workflow\\Event\\RunEventLog(%s);\nfor (\$i = 0; \$i < 200; \$i++) {\n  \$log->append(\$argv[1], NULL, 'phase.attempted', ['phase' => 'code', 'i' => \$i]);\n}\n",
      var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', TRUE),
      var_export($dir, TRUE),
    ));
    $children = [];
    foreach (['run-a', 'run-b'] as $run) {
      $child = proc_open([PHP_BINARY, $script, $run], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
      $this->assertIsResource($child);
      $children[] = [$child, $pipes];
    }
    foreach ($children as [$child, $pipes]) {
      stream_get_contents($pipes[1]);
      stream_get_contents($pipes[2]);
      $this->assertSame(0, proc_close($child));
    }

    $lines = file($dir . '/' . RunEventLog::FILENAME, FILE_IGNORE_NEW_LINES) ?: [];
    $this->assertCount(400, $lines);
    $seqs = [];
    foreach ($lines as $line) {
      $event = RunEvent::fromArray(json_decode($line, TRUE));
      $this->assertNotNull($event, 'every line is a whole event: ' . substr($line, 0, 80));
      $seqs[] = $event->seq;
    }
    sort($seqs);
    $this->assertSame(range(1, 400), $seqs, 'seq is a permutation of 1..400');
  }

}
