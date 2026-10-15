<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Seeker;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Seeker\SeekerError;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * The seeker's ledger is a seeker's, and the levers that make it hold.
 *
 * A client's first observed run at `custom` showed four gaps: a ledger the
 * main agent wrote itself was taken as an independent inspection, open
 * findings held nothing at `custom`, complete finished with no inspection of
 * its own, and the code hold kept saying "the inspection is clean" through
 * two later inspections that found MEDIUMs.
 */
final class SeekerEnforcementTest extends WorkflowTestCase {

  private const CLEAN = "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n";

  private const OPEN_MEDIUM = "## Seeker Inspection\n\nInspector: independent\n\n"
    . "| ID | Severity | Location | Finding | Status |\n"
    . "|----|----------|----------|---------|--------|\n"
    . "| F1 | MEDIUM   | b.php:2  | drift   | open   |\n";

  /**
   * The clock, one second a call, so each ledger is later than the last.
   */
  private int $tick = 0;

  /**
   * With dispatches recorded, a ledger needs a seeker returned since the last.
   */
  public function testLedgerNoSeekerReturnedForIsRefused(): void {
    $root = $this->wired("preset: custom\nmode: agentic\n");
    $this->toInspection($root);
    $runId = (new RunStateStore($root))->load()?->runId;
    $this->assertIsString($runId);

    try {
      $this->facade()->recordSeeker($root, self::CLEAN);
      $this->fail('a ledger with no seeker behind it is refused');
    }
    catch (SeekerError $e) {
      $this->assertStringContainsString('no workflow-seeker has returned since', $e->getMessage());
    }

    // The guard writes the row when the subagent returns.
    $row = $this->dispatch($root, 'workflow-seeker');
    $this->assertSame(['run' => $runId, 'mode' => 'dispatched', 'agent' => 'workflow-seeker'], $row);
    $record = $this->facade()->recordSeeker($root, self::CLEAN);
    $this->assertSame('verified', $record['dispatch']);
    $this->assertSame('code', $record['phase']);

    // The same dispatch does not cover a second ledger, and another subagent
    // is not a seeker.
    $this->dispatch($root, 'general-purpose');
    $this->expectException(SeekerError::class);
    $this->facade()->recordSeeker($root, self::CLEAN);
  }

  /**
   * A launch is not a return, and an independent ledger is the seeker's own.
   *
   * A client's agent launched the seeker in the background, and the launch
   * was recorded as its return while the seeker was still reading (0.14.19).
   */
  public function testLaunchIsNotReturnAndTheLedgerIsTheSeekers(): void {
    $root = $this->wired("preset: custom\nmode: agentic\n");
    $this->toInspection($root);

    $launch = $this->dispatch($root, 'workflow-seeker', 'dispatched', [
      'tool_response' => ['status' => 'async_launched', 'agentId' => 'a1', 'outputFile' => '/tmp/a1'],
    ]);
    $this->assertSame('launched', $launch['mode']);
    try {
      $this->facade()->recordSeeker($root, self::OPEN_MEDIUM);
      $this->fail('a ledger filed on a launch is refused');
    }
    catch (SeekerError $e) {
      $this->assertStringContainsString('no workflow-seeker has returned', $e->getMessage());
    }

    // It returns: SubagentStop carries what it wrote.
    $back = $this->dispatch($root, 'workflow-seeker', 'subagent-stop', [
      'agent_type' => 'workflow-seeker',
      'last_assistant_message' => "Done.\n\n" . self::OPEN_MEDIUM,
    ]);
    $this->assertSame([
      'run' => 'run-seeker',
      'mode' => 'dispatched',
      'agent' => 'workflow-seeker',
      'ledger' => 'F1:MEDIUM:open',
    ], $back);

    // Its rows with the status changed are not its ledger.
    $rewritten = str_replace('| open   |', '| resolved |', self::OPEN_MEDIUM);
    try {
      $this->facade()->recordSeeker($root, $rewritten);
      $this->fail('an independent ledger the seeker did not write is refused');
    }
    catch (SeekerError $e) {
      $this->assertStringContainsString('are not the ones the workflow-seeker returned', $e->getMessage());
    }
    $this->assertSame('verified', $this->facade()->recordSeeker($root, self::OPEN_MEDIUM)['dispatch']);
  }

  /**
   * A self-review is taken as one: no round, and no release of a held finding.
   *
   * The pack's status update after fixes (F-44) is the main agent's ledger,
   * and stays legal labelled as what it is.
   */
  public function testSelfReviewCountsAsNoInspectionAndReleasesNoHold(): void {
    $root = $this->wired("preset: custom\nmode: agentic\nseekers:\n  holds: medium\n");
    $this->toInspection($root);
    $selfReview = str_replace('Inspector: independent', 'Inspector: self-reviewed', self::CLEAN);

    $record = $this->facade()->recordSeeker($root, $selfReview);
    $this->assertSame('none', $record['dispatch']);
    $this->assertTrue($record['self_reviewed']);
    $this->assertSame(Outcome::InspectionDue, $this->facade()->run($root)->outcome, 'a self-review is no inspection');

    $this->dispatch($root, 'workflow-seeker');
    $this->facade()->recordSeeker($root, self::OPEN_MEDIUM);
    $this->facade()->recordSeeker($root, $selfReview);
    $this->assertSame(Outcome::InspectionDue, $this->facade()->run($root)->outcome, 'the seeker\'s open MEDIUM still holds');

    $this->dispatch($root, 'workflow-seeker');
    $this->facade()->recordSeeker($root, self::CLEAN);
    $this->assertSame(Outcome::Advanced, $this->facade()->run($root)->outcome, 'a seeker agreeing releases it');
  }

  /**
   * Where the hooks record no dispatches, the ledger is taken and says so.
   */
  public function testWithoutTheDispatchHookTheLedgerIsUnverified(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $this->toInspection($root);

    $record = $this->facade()->recordSeeker($root, self::CLEAN);

    $this->assertSame('unverified', $record['dispatch']);
    $this->assertSame('unverified', (new RunStateStore($root))->load()?->seeker['dispatch'] ?? NULL);
  }

  /**
   * At custom, `seekers.holds: medium` holds code on an open MEDIUM.
   */
  public function testCustomCanHoldOnAnOpenMedium(): void {
    $held = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  holds: medium\n  at_complete: true\n");
    $levers = $this->facade()->status($held)['levers'];
    $this->assertIsArray($levers);
    $this->assertSame('medium', $levers['seeker_holds'], 'status shows the lever before any run freezes it');
    $this->assertTrue($levers['seeker_at_complete']);
    $this->toInspection($held);
    $this->facade()->recordSeeker($held, self::OPEN_MEDIUM);
    $this->assertSame(Outcome::InspectionDue, $this->facade()->run($held)->outcome, 'the open MEDIUM holds');
    $this->assertSame(Phase::Code, (new RunStateStore($held))->load()?->currentPhase);

    $advisory = $this->makeRootWithConfig("preset: custom\nmode: agentic\n");
    $this->toInspection($advisory);
    $this->facade()->recordSeeker($advisory, self::OPEN_MEDIUM);
    $this->assertSame(Outcome::Advanced, $this->facade()->run($advisory)->outcome, 'with no lever, custom stays advisory');
  }

  /**
   * HIGH sits between CRITICAL and MEDIUM, and `holds: high` holds on it alone.
   */
  public function testHoldsHighHoldsOnHighAndNotOnMedium(): void {
    $high = str_replace('| MEDIUM   |', '| HIGH     |', self::OPEN_MEDIUM);
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  holds: high\n");
    $this->toInspection($root);
    $record = $this->facade()->recordSeeker($root, $high);
    $this->assertSame(1, $record['high']);
    $this->assertSame('findings', $record['status']);
    $this->assertSame(Outcome::InspectionDue, $this->facade()->run($root)->outcome, 'an open HIGH holds');

    $medium = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  holds: high\n");
    $this->toInspection($medium);
    $this->facade()->recordSeeker($medium, self::OPEN_MEDIUM);
    $this->assertSame(Outcome::Advanced, $this->facade()->run($medium)->outcome, 'an open MEDIUM does not, at holds: high');
  }

  /**
   * The lever's values, its level defaults, and a word it does not know.
   */
  public function testTheHoldsLever(): void {
    $this->assertSame('critical', WorkflowConfig::fromArray(['preset' => 'high'], 't')->seekerHolds);
    $this->assertSame('none', WorkflowConfig::fromArray(['preset' => 'medium'], 't')->seekerHolds);
    $this->assertSame('none', WorkflowConfig::fromArray(['preset' => 'custom'], 't')->seekerHolds);
    $this->assertSame('medium', WorkflowConfig::fromArray(['preset' => 'custom', 'seekers' => ['holds' => 'medium']], 't')->seekerHolds);
    $this->assertTrue(WorkflowConfig::fromArray(['preset' => 'custom', 'seekers' => ['at_complete' => TRUE]], 't')->seekerAtComplete);

    $this->assertSame('high', WorkflowConfig::fromArray(['preset' => 'custom', 'seekers' => ['holds' => 'high']], 't')->seekerHolds);
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('seekers.holds must be critical, high, medium or none');
    WorkflowConfig::fromArray(['preset' => 'custom', 'seekers' => ['holds' => 'severe']], 't');
  }

  /**
   * With `seekers.at_complete`, complete needs an inspection of its own.
   */
  public function testCompleteCanNeedItsOwnInspection(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: agentic\nseekers:\n  at_complete: true\n");
    $this->toInspection($root);
    $this->facade()->recordSeeker($root, self::CLEAN);

    $outcome = $this->facade()->run($root);
    for ($i = 0; $i < 6 && $outcome->outcome === Outcome::Advanced; $i++) {
      $outcome = $this->facade()->run($root);
    }
    $this->assertSame(Outcome::InspectionDue, $outcome->outcome);
    $this->assertSame(Phase::Complete, $outcome->state->currentPhase, 'complete waits for its own inspection');

    $this->facade()->recordSeeker($root, self::CLEAN);
    $this->assertSame(Outcome::Completed, $this->facade()->run($root)->outcome);
  }

  /**
   * A ledger filed while a hold is open releases it, so it is asked again.
   */
  public function testNewLedgerReleasesTheHoldAskedOnTheLastOne(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $this->facade()->run($root);
    $this->facade()->answer($root, 'go on');
    $this->assertSame(Outcome::InspectionDue, $this->facade()->run($root)->outcome);
    $this->facade()->recordSeeker($root, self::CLEAN);
    $held = $this->facade()->run($root);
    $this->assertSame(Outcome::Paused, $held->outcome, 'the code hold, asked on a clean ledger');

    $this->facade()->recordSeeker($root, self::OPEN_MEDIUM);

    $state = (new RunStateStore($root))->load();
    $this->assertNull($state?->awaiting, 'the stale hold is no longer standing');
    $history = $state->qaHistory ?? [];
    $last = $history === [] ? [] : $history[array_key_last($history)];
    $this->assertIsArray($last);
    $this->assertArrayHasKey('released_at', $last, 'and the record keeps it as asked and released');
  }

  /**
   * Runs the guard as a returning subagent's PostToolUse hook.
   *
   * The guard stamps its row with the wall clock; the row is restamped on
   * this test's clock, so the order of dispatches and ledgers is the test's.
   *
   * @param string $root
   *   The project.
   * @param string $type
   *   The subagent's type.
   * @param string $mode
   *   The guard mode: `dispatched` (PostToolUse) or `subagent-stop`.
   * @param array<string, mixed> $extra
   *   More of the hook's payload (a tool_response, a last message).
   *
   * @return array<string, mixed>
   *   The row's run, mode and agent.
   */
  private function dispatch(string $root, string $type, string $mode = 'dispatched', array $extra = []): array {
    [$code] = $this->guard($root, $mode, [
      'tool_name' => 'Agent',
      'tool_input' => ['subagent_type' => $type, 'prompt' => 'inspect'],
    ] + $extra);
    $this->assertSame(0, $code, 'recording never refuses');
    $path = $root . '/droost/droost-workflow/guard-calls.jsonl';
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $row = json_decode((string) array_pop($lines), TRUE);
    $this->assertIsArray($row);
    $row['at'] = $this->clock();
    $lines[] = (string) json_encode($row, JSON_UNESCAPED_SLASHES);
    file_put_contents($path, implode("\n", $lines) . "\n");

    return ['run' => $row['run'] ?? NULL, 'mode' => $row['mode'] ?? NULL, 'agent' => $row['agent'] ?? NULL] + (isset($row['ledger']) ? ['ledger' => $row['ledger']] : []);
  }

  /**
   * The test's clock: one second later at every reading.
   *
   * @return string
   *   An ISO-8601 time.
   */
  private function clock(): string {
    return date('c', strtotime('2026-10-15T12:00:00+00:00') + $this->tick++);
  }

  /**
   * A project whose hooks record subagent dispatches.
   *
   * @param string $yaml
   *   The lever file.
   *
   * @return string
   *   The root.
   */
  private function wired(string $yaml): string {
    $root = $this->makeRootWithConfig($yaml);
    mkdir($root . '/.claude', 0755, TRUE);
    file_put_contents($root . '/.claude/settings.json', json_encode([
      'hooks' => [
        'PostToolUse' => [[
          'matcher' => 'Agent|Task',
          'hooks' => [
            [
              'type' => 'command',
              'command' => 'php "$CLAUDE_PROJECT_DIR/.claude/hooks/droost-workflow-guard.php" dispatched',
            ],
          ],
        ],
        ],
      ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $root;
  }

  /**
   * Opens a run and walks it to the code phase's inspection.
   *
   * @param string $root
   *   The project.
   */
  private function toInspection(string $root): void {
    for ($i = 0; $i < 4; $i++) {
      if ($this->facade()->run($root)->outcome === Outcome::InspectionDue) {
        return;
      }
    }
    $this->fail('the run reached the code inspection');
  }

  /**
   * A facade on a clock that moves, every gate passing.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $gates = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
    return new WorkflowFacade(
      $gates,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      fn (): string => $this->clock(),
      static fn (): string => 'run-seeker',
    );
  }

}
