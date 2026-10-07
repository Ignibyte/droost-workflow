<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Mode;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunOutcome;
use Droost\Workflow\State\RunState;
use PHPUnit\Framework\TestCase;

/**
 * A failed gate that parsed nothing says why in the run envelope (F-183).
 *
 * A custom gate is a shell command, and droost parses nothing out of it. In
 * druplit-02 one went red, and `run`'s JSON said only that: the reason was in
 * `droost-workflow evidence`, and the report did not point there.
 */
final class RunOutcomeOutputTest extends TestCase {

  /**
   * The failed custom gate carries its output's tail and where the rest is.
   */
  public function testFailedGateThatParsedNothingCarriesItsOutput(): void {
    $failed = GateResult::ran('custom:contract', GateStatus::Failed, 1, 40, 'custom:contract failed (exit 1)', [], 'bin/check')
      ->withOutput("checking 3 endpoints\n", "endpoint /api/camps: expected 200, got 500\n");
    $finding = ['file' => 'a.php', 'line' => 3, 'message' => 'x'];
    $parsed = GateResult::ran('phpcs', GateStatus::Failed, 1, 40, 'phpcs: 1 error', [$finding], 'phpcs')
      ->withOutput('a.php:3 x', '');
    $passed = GateResult::ran('phpstan', GateStatus::Passed, 0, 40, 'clean', [], 'phpstan')
      ->withOutput('ok', '');

    $envelope = $this->outcome(new PhaseReport(Phase::Code, [$failed, $parsed, $passed]))->toArray();
    $report = $envelope['report'];
    $this->assertIsArray($report);
    $gates = $report['gates'];
    $this->assertIsArray($gates);

    $this->assertIsArray($gates[0]);
    $this->assertSame("endpoint /api/camps: expected 200, got 500\nchecking 3 endpoints", $gates[0]['output_tail'] ?? NULL);
    $this->assertSame('droost-workflow evidence', $gates[0]['output_in'] ?? NULL);
    $this->assertIsArray($gates[1]);
    $this->assertArrayNotHasKey('output_tail', $gates[1], 'a gate whose output was parsed has its findings');
    $this->assertIsArray($gates[2]);
    $this->assertArrayNotHasKey('output_tail', $gates[2], 'a passed gate needs no reason');
  }

  /**
   * A long output is cut to its end, which is where a tool says what failed.
   */
  public function testLongOutputKeepsItsEnd(): void {
    $failed = GateResult::ran('custom:long', GateStatus::Failed, 1, 40, 'failed', [], 'bin/long')
      ->withOutput(str_repeat("line\n", 2000) . 'THE REASON', '');

    $report = $this->outcome(new PhaseReport(Phase::Code, [$failed]))->toArray()['report'];
    $this->assertIsArray($report);
    $this->assertIsArray($report['gates']);
    $this->assertIsArray($report['gates'][0]);
    $tail = $report['gates'][0]['output_tail'] ?? NULL;
    $this->assertIsString($tail);
    $this->assertStringStartsWith('…', $tail);
    $this->assertStringEndsWith('THE REASON', $tail);
    $this->assertLessThanOrEqual(1503, strlen($tail));
  }

  /**
   * An outcome over a report.
   */
  private function outcome(PhaseReport $report): RunOutcome {
    $config = WorkflowConfig::fromArray(['mode' => 'agentic', 'preset' => 'low'], 'test');
    $state = RunState::begin('r1', '2026-10-07T00:00:00+00:00', $config);
    return new RunOutcome(Outcome::Failed, $state, $report);
  }

}
