<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Knowledge\ToolCallLog;
use Droost\Workflow\Tests\Evidence\ReadsTheStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The record of which droost tools a run called, written and read with no site.
 */
final class ToolCallLogTest extends WorkflowTestCase {

  use KnowledgeFixture;
  use ReadsTheStore;

  /**
   * A call carries the run open at the time, or none, and reaches the store.
   */
  public function testCallCarriesTheOpenRun(): void {
    $root = $this->makeRoot();

    ToolCallLog::append($root, 'droost_symbol', 'ok');
    $run = (string) json_encode(['run_id' => 'run-1', 'current_phase' => 'plan']);
    $this->put($root, 'droost/droost-workflow/run.json', $run);
    ToolCallLog::append($root, 'droost_graph', 'fail', ['answered' => 'nothing']);

    $raw = (string) file_get_contents($root . '/droost/droost-workflow/tool-calls.jsonl');
    $rows = array_map(static fn (string $line): mixed => json_decode($line, TRUE), array_values(array_filter(explode("\n", $raw))));
    $this->assertCount(2, $rows);
    $this->assertIsArray($rows[0]);
    $this->assertIsArray($rows[1]);
    $this->assertNull($rows[0]['run']);
    $this->assertArrayNotHasKey('detail', $rows[0], 'no detail, no key');
    $this->assertSame(
      ['run-1', 'plan', ['answered' => 'nothing']],
      [$rows[1]['run'], $rows[1]['phase'], $rows[1]['detail']],
    );

    $mirrored = $this->storeRows(
      (new EvidenceStore($root))->connection(),
      'SELECT run_id, phase, tool, outcome FROM tool_call',
    );
    $this->assertSame([['run_id' => 'run-1', 'phase' => 'plan', 'tool' => 'droost_graph', 'outcome' => 'fail']], $mirrored);
  }

  /**
   * A run reads its own calls and the unattributed ones, never another's.
   */
  public function testRunReadsItsOwnCalls(): void {
    $root = $this->makeRoot();
    $this->ledger($root, [
      ['tool' => 'droost_symbol', 'outcome' => 'ok', 'at' => 'a', 'run' => NULL, 'phase' => NULL],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => 'b', 'run' => 'run-2', 'phase' => 'plan'],
      ['tool' => 'droost_graph', 'outcome' => 'ok', 'at' => 'c', 'run' => 'run-1', 'phase' => 'plan'],
    ]);

    $this->assertSame(['droost_symbol', 'droost_graph'], array_column(ToolCallLog::read($root, 'run-1'), 'tool'));
    $this->assertCount(3, ToolCallLog::read($root));
    $this->assertSame(['droost_symbol' => 1, 'droost_graph' => 1], ToolCallLog::tally($root, 'run-1'));
  }

  /**
   * A ledger from before rows carried a run is read whole.
   */
  public function testLedgerWithNoRunsIsReadWhole(): void {
    $root = $this->makeRoot();
    $this->ledger($root, [
      ['tool' => 'droost_symbol', 'outcome' => 'ok', 'at' => 'a'],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => 'b'],
    ]);

    $this->assertCount(2, ToolCallLog::read($root, 'run-1'));
  }

}
