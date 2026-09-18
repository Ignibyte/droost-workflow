<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\EvidenceRecorder;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Whether the agent looked at what it built is a count, not an assumption.
 *
 * The browser levers ran a SUITE and the record treated that as the answer.
 * It is a different claim: a passing spec says the code behaves under a
 * script somebody wrote, not that a human or an agent ever opened the page
 * and saw what was there. Three rounds of this repo's own dogfooding closed
 * green with no row saying either way.
 *
 * The signal was available the whole time — the guard hook fires on every
 * tool call — and thrown away, because the row it wrote named the verdict and
 * not the tool.
 */
final class BrowserLookIsObservedTest extends WorkflowTestCase {

  /**
   * The hook's row reaches the store with its tool and its own phase.
   */
  public function testTheLedgerCarriesTheToolAndThePhaseItFiredIn(): void {
    $root = $this->makeRoot();
    $dir = $root . '/droost/droost-workflow';
    @mkdir($dir, 0777, TRUE);
    $rows = [
      // A browser call the hook stamped as CODE while the phase closing now
      // is test. The ingest used to stamp every row with the closing phase,
      // which filed a look at unfinished work under the phase whose job is
      // verification.
      [
        'run' => 'r1',
        'phase' => 'code',
        'tool' => 'mcp__playwright__browser_navigate',
        'mode' => 'pre-tool-use',
        'verdict' => 'invoked',
        'rule' => NULL,
        'at' => '2026-09-18T00:00:00+00:00',
      ],
      [
        'run' => 'r1',
        'phase' => 'test',
        'tool' => 'mcp__playwright__browser_snapshot',
        'mode' => 'pre-tool-use',
        'verdict' => 'invoked',
        'rule' => NULL,
        'at' => '2026-09-18T00:01:00+00:00',
      ],
      // Not a browser call, and a row a pre-0.9 hook wrote with no tool at
      // all. Neither may be counted, and the second may not be counted as a
      // zero either — it is unknown.
      [
        'run' => 'r1',
        'phase' => 'test',
        'tool' => 'Edit',
        'mode' => 'pre-tool-use',
        'verdict' => 'invoked',
        'rule' => NULL,
        'at' => '2026-09-18T00:02:00+00:00',
      ],
      [
        'run' => 'r1',
        'mode' => 'pre-tool-use',
        'verdict' => 'invoked',
        'rule' => NULL,
        'at' => '2026-09-18T00:03:00+00:00',
      ],
    ];
    $body = '';
    foreach ($rows as $row) {
      $body .= json_encode($row) . "\n";
    }
    file_put_contents($dir . '/guard-calls.jsonl', $body);

    $this->recordOnePhase($root, 'r1', 'test');

    $store = new EvidenceStore($root);
    $this->assertSame(4, $store->guardCallCount('r1'), 'every row lands');
    $this->assertSame(2, $store->browserToolCalls('r1'), 'two of the four are browser calls');
    $this->assertSame(
      1,
      $store->browserToolCalls('r1', 'test'),
      'and only one of those was made in the phase that verifies',
    );
    $this->assertSame(
      1,
      $store->browserToolCalls('r1', 'code'),
      'the row keeps the phase the hook saw, not the phase that ingested it',
    );
  }

  /**
   * A row with no tool name is unknown, and an unknown is not a zero.
   */
  public function testAnUnnamedToolIsNotCountedEitherWay(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('r2', []);
    $store->recordGuardCall('r2', 'test', 'pre-tool-use', 'invoked');

    $this->assertSame(1, $store->guardCallCount('r2'), 'the invocation is recorded');
    $this->assertSame(0, $store->browserToolCalls('r2'), 'and says nothing about a browser');
  }

  /**
   * Every marker matches, and a name carrying none of them does not.
   *
   * The hosts name these differently and will keep doing so. The markers are
   * substrings for that reason, and the test is here to keep the list honest
   * rather than to bless one client's vocabulary.
   */
  public function testTheMarkersMatchTheNamesTheHostsActuallySend(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('r3', []);
    foreach ([
      'mcp__playwright__browser_navigate',
      'browser_take_screenshot',
      'puppeteer_screenshot',
    ] as $tool) {
      $store->recordGuardCall('r3', 'test', 'pre-tool-use', 'invoked', NULL, NULL, $tool);
    }
    foreach (['Bash', 'Read', 'droost_search', 'mcp__atlassian__getJiraIssue'] as $tool) {
      $store->recordGuardCall('r3', 'test', 'pre-tool-use', 'invoked', NULL, NULL, $tool);
    }

    $this->assertSame(3, $store->browserToolCalls('r3', 'test'));
  }

  /**
   * Drives one phase through the real recorder.
   *
   * @param string $root
   *   The project root.
   * @param string $runId
   *   The run.
   * @param string $phase
   *   The phase to record.
   */
  private function recordOnePhase(string $root, string $runId, string $phase): void {
    $config = WorkflowConfig::fromArray(['mode' => 'agentic', 'preset' => 'medium'], 'test');
    $state = RunState::begin($runId, '2026-09-18T00:00:00+00:00', $config);
    $green = GateResult::ran('phpcs', GateStatus::Passed, 0, 5, 'clean', [], 'vendor/bin/phpcs src');
    (new EvidenceRecorder($root))->recordPhase($state, $phase, new PhaseReport(Phase::Test, [$green]));
  }

}
