<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\State;

use Droost\Workflow\Config\Mode;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\Config\Provenance;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\RunState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A gate that failed and then passed leaves both on the record.
 *
 * `feedback_attempts` counted that a retry happened and nothing said what the
 * gate had objected to, so the run record could prove the feedback loop fired
 * but not what it corrected. In a dogfood round grounding_check failed once
 * and passed on retry; reconstructing the reason meant grepping a transcript.
 */
#[CoversClass(RunState::class)]
final class GateAttemptHistoryTest extends TestCase {

  /**
   * A minimal open run (not `run`, which TestCase declares final).
   *
   * @return \Droost\Workflow\State\RunState
   *   The run.
   */
  private function openRun(): RunState {
    $config = WorkflowConfig::builtIn();

    return new RunState(
      'r1',
      '2026-09-13T00:00:00+00:00',
      Mode::Agentic,
      NULL,
      'medium',
      2,
      Provenance::BuiltIn,
      $config->resolvedGates(),
      ['plan' => PhaseStatus::Passed],
      NULL,
    );
  }

  /**
   * One serialized gate entry.
   *
   * @param string $name
   *   The gate id.
   * @param string $status
   *   The status string.
   * @param string $summary
   *   The summary line.
   *
   * @return array<string, mixed>
   *   The entry.
   */
  private function gate(string $name, string $status, string $summary): array {
    return [
      'gate' => $name,
      'status' => $status,
      'exit_code' => $status === 'passed' ? 0 : 1,
      'duration_ms' => 12,
      'summary' => $summary,
      'findings' => [],
    ];
  }

  /**
   * The failing attempt's summary survives the passing retry.
   */
  public function testFailedAttemptSurvivesTheRetryThatPassed(): void {
    $state = $this->openRun()
      ->withGateReport('code', [
        'phase' => 'code',
        'gates' => [
          $this->gate('grounding_check', 'failed', 'grounding_check FAILED — the ledger records no knowledge-tool call'),
        ],
      ])
      ->withGateReport('code', [
        'phase' => 'code',
        'gates' => [
          $this->gate('grounding_check', 'passed', 'grounding_check passed — 12 citation(s) resolved'),
        ],
      ]);

    $gate = $this->recordedGate($state, 'code', 0);
    $attempts = $this->priorAttempts($gate);
    $this->assertSame('passed', $gate['status']);
    $this->assertCount(1, $attempts);
    $this->assertIsArray($attempts[0]);
    $this->assertSame('failed', $attempts[0]['status']);
    $this->assertIsString($attempts[0]['summary']);
    $this->assertStringContainsString(
      'no knowledge-tool call',
      $attempts[0]['summary'],
      'the sentence that caused the work is the one worth keeping',
    );
  }

  /**
   * A gate that never blocked carries no history.
   */
  public function testNonBlockingStatusesAreNotCarried(): void {
    $state = $this->openRun()
      ->withGateReport('code', [
        'phase' => 'code',
        'gates' => [
          $this->gate('eslint', 'off', 'off — by preset medium'),
          $this->gate('module:snyk', 'reported', 'report — could not run'),
        ],
      ])
      ->withGateReport('code', [
        'phase' => 'code',
        'gates' => [
          $this->gate('eslint', 'passed', 'eslint passed'),
          $this->gate('module:snyk', 'passed', 'snyk passed'),
        ],
      ]);

    $report = $state->gateResults['code'] ?? NULL;
    $this->assertIsArray($report);
    $this->assertIsArray($report['gates'] ?? NULL);
    foreach ($report['gates'] as $gate) {
      $this->assertIsArray($gate);
      $this->assertIsString($gate['gate'] ?? NULL);
      $this->assertArrayNotHasKey('previous_attempts', $gate, $gate['gate'] . ' never blocked');
    }
  }

  /**
   * Repeated failures accumulate, bounded so a stuck loop cannot grow forever.
   */
  public function testHistoryAccumulatesAndIsBounded(): void {
    $state = $this->openRun();
    for ($i = 1; $i <= 7; $i++) {
      $state = $state->withGateReport('test', [
        'phase' => 'test',
        'gates' => [
          $this->gate('phpunit', 'failed', "attempt $i"),
        ],
      ]);
    }
    $state = $state->withGateReport('test', [
      'phase' => 'test',
      'gates' => [
        $this->gate('phpunit', 'passed', 'phpunit passed — 20 test(s), 32 assertion(s)'),
      ],
    ]);

    $history = $this->priorAttempts($this->recordedGate($state, 'test', 0));
    $this->assertCount(5, $history, 'bounded at five');
    $this->assertIsArray($history[0]);
    $this->assertIsArray($history[4]);
    $this->assertSame('attempt 3', $history[0]['summary'], 'the oldest drop first');
    $this->assertSame('attempt 7', $history[4]['summary']);
  }

  /**
   * A report whose gates key is absent is returned untouched.
   */
  public function testReportWithoutGatesIsLeftAlone(): void {
    $state = $this->openRun()
      ->withGateReport('code', [
        'phase' => 'code',
        'gates' => [
          $this->gate('phpcs', 'failed', 'phpcs FAILED'),
        ],
      ])
      ->withGateReport('code', ['phase' => 'code']);

    $this->assertSame(['phase' => 'code'], $state->gateResults['code']);
  }

  /**
   * A recorded gate entry, narrowed out of the opaque blob it lives in.
   *
   * `gateResults` is `array<array-key, mixed>` on purpose — it is the one field
   * round-tripped unvalidated — so walking into it from a test is walking into
   * `mixed`, and an assertion against `mixed` is an assertion that would still
   * pass if the shape collapsed. Narrow once, and fail with a sentence naming
   * which level was not there.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $phase
   *   The phase whose report to read.
   * @param int $index
   *   Which gate in that report.
   *
   * @return array<array-key, mixed>
   *   The gate entry.
   */
  private function recordedGate(RunState $state, string $phase, int $index): array {
    $report = $state->gateResults[$phase] ?? NULL;
    $this->assertIsArray($report, sprintf('a %s report is recorded', $phase));
    $gates = $report['gates'] ?? NULL;
    $this->assertIsArray($gates, sprintf('the %s report carries gates', $phase));
    $gate = $gates[$index] ?? NULL;
    $this->assertIsArray($gate, sprintf('gate #%d of %s is recorded', $index, $phase));

    return $gate;
  }

  /**
   * The carried attempts of a recorded gate.
   *
   * @param array<array-key, mixed> $gate
   *   A gate entry from recordedGate().
   *
   * @return array<array-key, mixed>
   *   The previous attempts.
   */
  private function priorAttempts(array $gate): array {
    $attempts = $gate['previous_attempts'] ?? NULL;
    $this->assertIsArray($attempts, 'the gate carries its previous attempts');

    return $attempts;
  }

}
