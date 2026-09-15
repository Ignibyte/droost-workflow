<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceRecorder;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Whether the enforcement hook was there is a row, not an assumption.
 *
 * The record could say what enforcement a run REQUESTED, and the status
 * document could say what it inferred was `effective` from the host the
 * session DECLARED — a claim about a claim. Nothing could say whether the hook
 * was ever invoked. So a run could report `hard`, pass every phase and render
 * a clean evaluation with the guard unwired, and no column contradicted it.
 * Every evaluation to date listed this as a blind spot and called it the
 * largest, because it is what the whole discipline rests on.
 */
final class EnforcementIsObservedTest extends WorkflowTestCase {

  /**
   * An empty ledger is the FINDING, not an absent one.
   *
   * The failure mode this guards: an unavailable answer reading as a clean
   * one. A section that simply omitted itself when there were no rows would
   * leave the most dangerous case — enforcement claimed, never present —
   * looking exactly like a run with nothing to report.
   */
  public function testNoGuardRowsIsItselfTheFinding(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('run-unwired', ['enforcement' => 'hard', 'preset' => 'low']);

    $rendered = (new EvaluationReport($store))->render('run-unwired');

    $this->assertStringContainsString('7a. Enforcement', $rendered);
    $this->assertStringContainsString('No guard invocation was recorded', $rendered);
    // Fragments that do not cross a line break: the section is hard-wrapped,
    // so asserting a whole sentence tests the wrapping rather than the words.
    $this->assertStringContainsString(
      'passed with no wall behind it',
      $rendered,
      'the consequence is spelled out, not left for the reader to infer',
    );
    $this->assertStringContainsString('hard', $rendered, 'and it names what was claimed');
  }

  /**
   * With rows, the section corroborates the level the run asked for.
   */
  public function testGuardRowsCorroborateTheRequestedLevel(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('run-live', ['enforcement' => 'hard', 'preset' => 'low']);
    for ($i = 0; $i < 7; $i++) {
      $store->recordGuardCall('run-live', 'code', 'pre-tool-use', 'invoked');
    }
    $store->recordGuardCall('run-live', 'code', 'operator-commands', 'refuse', 'operator-command:bypass');
    $store->recordGuardCall('run-live', 'code', 'pre-tool-use', 'refuse', 'require-run');

    $rendered = (new EvaluationReport($store))->render('run-live');

    $this->assertStringContainsString('9 invocation(s), 2 refusal(s)', $rendered);
    $this->assertStringContainsString('demonstrably live', $rendered);
    $this->assertStringContainsString('operator-command:bypass', $rendered);
    $this->assertStringContainsString('require-run', $rendered);
  }

  /**
   * The refusal count is presented as a FLOOR, never as a total.
   *
   * The guard has 26 exit paths and no chokepoint, so most rows carry
   * `invoked` — "the hook ran; this row does not say what it decided".
   * Counting those as allows would turn a gap into evidence, which is the
   * exact mistake that made the tool-call ledger report `0:0` on a run that
   * had spent twenty-five minutes grounding.
   */
  public function testTheRefusalCountIsPresentedAsTheFloor(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('run-floor', ['enforcement' => 'soft']);
    $store->recordGuardCall('run-floor', 'plan', 'pre-tool-use', 'invoked');

    $rendered = (new EvaluationReport($store))->render('run-floor');

    $this->assertStringContainsString('is not a verdict', $rendered);
    $this->assertStringContainsString('FLOOR and not a total', $rendered);
    $this->assertStringContainsString('Counting `invoked` as an allow', $rendered);
  }

  /**
   * Rows group by mode, verdict and rule, and count.
   */
  public function testCallsAreGroupedAndCounted(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('run-group', ['enforcement' => 'hard']);
    for ($i = 0; $i < 4; $i++) {
      $store->recordGuardCall('run-group', 'code', 'pre-tool-use', 'refuse', 'require-run');
    }
    $store->recordGuardCall('run-group', 'code', 'stop', 'invoked');

    $grouped = $store->guardCalls('run-group');

    $this->assertCount(2, $grouped, 'two distinct mode/verdict/rule combinations');
    $this->assertSame(4, $grouped[0]['calls'], 'most frequent first');
    $this->assertSame('require-run', $grouped[0]['rule']);
    $this->assertSame('refuse', $grouped[0]['verdict']);
    $this->assertNull($grouped[1]['rule'], 'a row with no rule reports none rather than an empty string');
  }

  /**
   * THE LINK: the guard's ledger reaches the store through the recorder.
   *
   * The failure this guards is not hypothetical. This morning
   * `recordToolCall()` and the `tool_call` table had both existed for weeks
   * with nothing in production calling either, so every evaluation reported a
   * run that had asked the codebase nothing. A writer nobody calls is the same
   * as no writer, and the only way to know the difference is to drive the real
   * recorder.
   */
  public function testTheGuardsLedgerReachesTheStore(): void {
    $root = $this->makeRoot();
    $dir = $root . '/droost/droost-workflow';
    if (!is_dir($dir)) {
      mkdir($dir, 0777, TRUE);
    }
    $lines = [
      [
        'run' => 'r1',
        'mode' => 'pre-tool-use',
        'verdict' => 'invoked',
        'rule' => NULL,
        'at' => '2026-09-15T01:00:00+00:00',
      ],
      [
        'run' => 'r1',
        'mode' => 'operator-commands',
        'verdict' => 'refuse',
        'rule' => 'operator-command:bypass',
        'at' => '2026-09-15T01:00:01+00:00',
      ],
      // A DIFFERENT run, and one from no run at all — the wall fires outside
      // runs, which is its whole job. Crediting this run with either would be
      // a number worth less than none.
      [
        'run' => 'r0',
        'mode' => 'pre-tool-use',
        'verdict' => 'refuse',
        'rule' => 'require-run',
        'at' => '2026-09-15T00:00:00+00:00',
      ],
      [
        'run' => NULL,
        'mode' => 'pre-tool-use',
        'verdict' => 'refuse',
        'rule' => 'require-run',
        'at' => '2026-09-15T00:30:00+00:00',
      ],
      '{not json',
    ];
    $body = '';
    foreach ($lines as $line) {
      $body .= (is_string($line) ? $line : json_encode($line)) . "\n";
    }
    file_put_contents($dir . '/guard-calls.jsonl', $body);

    $this->recordOnePhase($root, 'r1', 'code');

    $store = new EvidenceStore($root);
    $this->assertSame(2, $store->guardCallCount('r1'), 'this run\'s two lines, and only those');
    $grouped = $store->guardCalls('r1');
    $this->assertCount(2, $grouped);
    $rules = array_column($grouped, 'rule');
    $this->assertContains('operator-command:bypass', $rules);
    $this->assertNotContains('require-run', $rules, 'another run\'s wall is not this run\'s');

    // And a re-record does not double them.
    $this->recordOnePhase($root, 'r1', 'code');
    $this->assertSame(2, $store->guardCallCount('r1'), 'the watermark holds');
  }

  /**
   * The ingest watermark stops a re-recorded phase doubling the rows.
   */
  public function testGuardCallCountIsTheIngestWatermark(): void {
    $root = $this->makeRoot();
    $store = new EvidenceStore($root);
    $store->upsertRun('run-mark', []);
    $this->assertSame(0, $store->guardCallCount('run-mark'));
    $store->recordGuardCall('run-mark', 'plan', 'pre-tool-use', 'invoked');
    $store->recordGuardCall('run-mark', 'plan', 'stop', 'invoked');
    $this->assertSame(2, $store->guardCallCount('run-mark'));
    $this->assertSame(0, $store->guardCallCount('another-run'), 'counted per run');
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
    $state = RunState::begin($runId, '2026-09-15T00:00:00+00:00', $config);
    $green = GateResult::ran('phpcs', GateStatus::Passed, 0, 5, 'clean', [], 'vendor/bin/phpcs src');
    (new EvidenceRecorder($root))->recordPhase($state, $phase, new PhaseReport(Phase::Code, [$green]));
  }

}
