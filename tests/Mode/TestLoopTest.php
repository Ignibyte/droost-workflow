<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Mode;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * The fast flow's loop, end to end (0.11).
 *
 * The owner, 2026-09-29: a failure at test goes back to code, within
 * `max_loops`; one out of the ticket's scope, or still there when the budget
 * is spent, becomes a follow-up ticket; at max a spent budget fails the run.
 * Each invocation is a fresh facade, as separate processes arrive.
 */
class TestLoopTest extends WorkflowTestCase {

  /**
   * A test failure returns the run to code, and code's gates run again.
   */
  public function testTestFailureReturnsTheRunToCode(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates(['playwright' => [$this->browserFails()]]);

    $this->walkTo($root, $gates, Phase::Test);
    $codeRuns = $gates->executions['phpcs'] ?? 0;
    $returned = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Returned, $returned->outcome);
    $this->assertSame('code', $returned->state->currentPhase?->value);
    $this->assertSame(PhaseStatus::Active, $returned->state->statusOf(Phase::Code));
    $this->assertSame(PhaseStatus::Pending, $returned->state->statusOf(Phase::Test));
    $this->assertSame(1, $returned->state->loop->spent());
    $this->assertSame(1, $returned->state->loop->remaining(), 'low allows two');
    $this->assertSame([], $returned->state->feedbackAttempts, 'no in-place retry was spent');
    $this->assertSame('returned', $returned->toArray()['outcome']);
    $this->assertSame('returned_to_code', $returned->blocked[0]['check'] ?? NULL, 'the envelope names what to do');
    $this->assertStringContainsString('tests/e2e/mine.spec.ts', $returned->blocked[0]['why'] ?? '');

    $store = new EvidenceStore($root);
    $held = array_column($store->unresolved($returned->state->runId, 'code'), 'name');
    $this->assertContains('returned_to_code', $held, 'the stop is held at code until code runs again');

    $again = $this->facade($gates)->run($root);
    $this->assertSame(Outcome::Advanced, $again->outcome);
    $this->assertSame($codeRuns + 1, $gates->executions['phpcs'], 'code\'s gates ran again');
    $this->assertNotContains('returned_to_code', array_column($store->unresolved($again->state->runId, 'code'), 'name'));
    $this->assertSame('test', $again->state->currentPhase?->value);

    $passed = $this->facade($gates)->run($root);
    $this->assertSame(Outcome::Advanced, $passed->outcome, 'the fix passed at test');
    $this->assertSame(PhaseStatus::Passed, $passed->state->statusOf(Phase::Test));
  }

  /**
   * A spent budget writes each failing gate up and defers the phase.
   */
  public function testSpentBudgetBecomesFollowUpTicket(): void {
    $root = $this->project("preset: low\nmax_loops: 1\n");
    $gates = $this->gates(['playwright' => array_fill(0, 3, $this->browserFails())]);

    $this->walkTo($root, $gates, Phase::Test);
    $this->assertSame(Outcome::Returned, $this->facade($gates)->run($root)->outcome);
    $this->assertSame(Outcome::Advanced, $this->facade($gates)->run($root)->outcome, 'code again');
    $deferred = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Deferred, $deferred->outcome);
    $this->assertSame(PhaseStatus::Deferred, $deferred->state->statusOf(Phase::Test), 'deferred, never passed');
    $this->assertSame('complete', $deferred->state->currentPhase?->value);
    $filed = $deferred->state->loop->followUpsFiled;
    $this->assertCount(1, $filed);
    $this->assertSame('playwright', $filed[0]['gate']);
    $this->assertSame('spent', $filed[0]['why']);
    $this->assertSame('TICKET-1', $filed[0]['id']);
    $this->assertIsString($filed[0]['path']);
    $this->assertStringStartsWith('docs/tickets/open/TICKET-1-', $filed[0]['path']);
    $ticket = (string) file_get_contents($root . '/' . $filed[0]['path']);
    $this->assertStringContainsString('playwright failed (exit 1)', $ticket, 'the ticket carries the failure');
    $this->assertStringContainsString('Follow-up: playwright failed at test (tests/e2e/mine.spec.ts)', $ticket);
    $this->assertStringContainsString('type: bug', $ticket);

    $rows = (new EvidenceStore($root))->checklist($deferred->state->runId, 'test');
    $followUp = array_values(array_filter($rows, static fn (array $row): bool => $row['kind'] === 'follow_up'));
    $this->assertCount(1, $followUp);
    $this->assertSame('recorded', $followUp[0]['state']);
    $this->assertIsString($followUp[0]['summary']);
    $this->assertStringContainsString('TICKET-1', $followUp[0]['summary']);

    $events = array_column($this->events($root), 'type');
    $this->assertContains('phase.returned', $events);
    $this->assertContains('follow_up.filed', $events);

    $completed = $this->facade($gates)->run($root);
    $this->assertSame(Outcome::Completed, $completed->outcome, 'the follow-up file does not read as scope creep');
    $reloaded = (new RunStateStore($root))->load();
    $this->assertSame(PhaseStatus::Deferred, $reloaded?->statusOf(Phase::Test), 'still deferred at the end');
  }

  /**
   * A failure wholly in a spec the run did not change is a follow-up at once.
   */
  public function testFailureOutsideTheTicketIsFollowedUpAtOnce(): void {
    $root = $this->project("preset: low\ngates:\n  playwright:\n    scope: full\n");
    $older = GateResult::ran(
      'playwright',
      GateStatus::Failed,
      1,
      1,
      'playwright failed (exit 1): 1 failed, 4 passed, at tests/e2e/older.spec.ts:9',
      [['key' => 'totals', 'detail' => []], ['file' => 'tests/e2e/older.spec.ts', 'line' => 9, 'detail' => 'failed']],
      'playwright test',
    );
    $gates = $this->gates(['playwright' => [$older]]);

    $this->walkTo($root, $gates, Phase::Test);
    $deferred = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Deferred, $deferred->outcome);
    $this->assertSame(0, $deferred->state->loop->spent(), 'no loop was spent on another ticket\'s spec');
    $this->assertSame('outside', $deferred->state->loop->followUpsFiled[0]['why'] ?? NULL);
  }

  /**
   * The same failure in a spec the run changed goes back to code.
   */
  public function testFailureInTheTicketsOwnSpecAtFullScopeReturns(): void {
    $root = $this->project("preset: low\ngates:\n  playwright:\n    scope: full\n");
    $gates = $this->gates(['playwright' => [$this->browserFails()]]);

    $this->walkTo($root, $gates, Phase::Test);
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "test('the ticket', () => {});\n");
    $this->assertSame(Outcome::Returned, $this->facade($gates)->run($root)->outcome);
  }

  /**
   * With nowhere to write a follow-up, a spent budget fails the phase.
   */
  public function testNoFollowUpsMeansSpentBudgetFails(): void {
    $root = $this->project("preset: low\nmax_loops: 0\nfollow_ups: none\n");
    $gates = $this->gates(['playwright' => [$this->browserFails()]]);

    $this->walkTo($root, $gates, Phase::Test);
    $failed = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Failed, $failed->outcome);
    $this->assertSame(PhaseStatus::Failed, $failed->state->statusOf(Phase::Test));
    $this->assertTrue($failed->exhausted(), 'terminal: a retry in place would test a fix code never measured');
    $this->assertFileDoesNotExist($root . '/docs/tickets');
  }

  /**
   * Code edited at test sends the run back before test's gates run.
   */
  public function testCodeThatMovedAtTestGoesBackAndSpendsNothing(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);

    $this->walkTo($root, $gates, Phase::Test);
    file_put_contents($root . '/src/Thing.php', "<?php // fixed while testing by hand\n");
    $browserRuns = $gates->executions['playwright'] ?? 0;
    $returned = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Returned, $returned->outcome);
    $this->assertSame(0, $returned->state->loop->spent());
    $this->assertSame('moved', $returned->state->loop->returns[0]['reason']);
    $this->assertSame($browserRuns, $gates->executions['playwright'] ?? 0, 'the browser suite did not run on it');

    $this->assertSame(Outcome::Advanced, $this->facade($gates)->run($root)->outcome, 'code measures the edit');
    $this->assertSame(Outcome::Advanced, $this->facade($gates)->run($root)->outcome, 'and test runs');
  }

  /**
   * A browser spec written at test is test's own work, not moved code.
   */
  public function testSpecWrittenAtTestIsNotMovedCode(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);

    $this->walkTo($root, $gates, Phase::Test);
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "test('it', () => {});\n");

    $this->assertSame(Outcome::Advanced, $this->facade($gates)->run($root)->outcome);
  }

  /**
   * The browser tier's snapshots are its output, not moved code (F-203).
   *
   * P7 run 16 went back to code for `.playwright-mcp/`, written when the
   * agent looked at a page through Playwright MCP, and back again from
   * complete when it deleted the folder.
   */
  public function testBrowserSnapshotsAreNotMovedCode(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);

    $this->walkTo($root, $gates, Phase::Test);
    mkdir($root . '/.playwright-mcp');
    file_put_contents($root . '/.playwright-mcp/page-2026-10-02.yml', "- main\n");
    $this->assertSame(Outcome::Advanced, $this->facade($gates)->run($root)->outcome, 'test runs on the code it measured');

    unlink($root . '/.playwright-mcp/page-2026-10-02.yml');
    rmdir($root . '/.playwright-mcp');
    $this->assertSame(Outcome::Completed, $this->facade($gates)->run($root)->outcome, 'and complete, with the folder gone');
  }

  /**
   * Code edited at complete goes back to code, and complete runs nothing.
   */
  public function testEditAtCompleteGoesBackToCode(): void {
    $root = $this->project("preset: low\n");
    $gates = $this->gates([]);

    $this->walkTo($root, $gates, Phase::Complete);
    file_put_contents($root . '/src/Thing.php', "<?php // changed while writing the wiki\n");
    $returned = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Returned, $returned->outcome);
    $this->assertSame('code', $returned->state->currentPhase?->value);
    $this->assertSame(PhaseStatus::Pending, $returned->state->statusOf(Phase::Test));
    $this->assertSame(PhaseStatus::Pending, $returned->state->statusOf(Phase::Complete));
  }

  /**
   * The strict flow keeps the in-place retry.
   */
  public function testTheStrictFlowRetriesInPlace(): void {
    $root = $this->project("preset: low\nflow: strict\n");
    $gates = $this->gates(['playwright' => [$this->browserFails()]]);

    $this->walkTo($root, $gates, Phase::Test);
    $failed = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Failed, $failed->outcome);
    $this->assertSame('test', $failed->state->currentPhase?->value);
    $this->assertSame([], $failed->state->loop->returns);
  }

  /**
   * A cockpit that cannot be reached loses no follow-up: markdown takes it.
   */
  public function testUnreachableCockpitFallsBackToMarkdown(): void {
    $root = $this->project(
      "preset: low\nmax_loops: 0\nfollow_ups: cockpit\nwork_item:\n  provider: droost_cockpit\n"
      . "  cockpit:\n    url_env: DROOST_TEST_NO_SUCH_COCKPIT_URL\n    token_env: DROOST_TEST_NO_SUCH_COCKPIT_TOKEN\n",
    );
    $gates = $this->gates(['playwright' => [$this->browserFails()]]);

    $this->walkTo($root, $gates, Phase::Test);
    $deferred = $this->facade($gates)->run($root);

    $this->assertSame(Outcome::Deferred, $deferred->outcome);
    $filed = $deferred->state->loop->followUpsFiled[0] ?? [];
    $this->assertSame('markdown', $filed['source'] ?? NULL);
    $this->assertStringContainsString('the cockpit refused it', (string) ($filed['note'] ?? ''));
    $this->assertStringNotContainsString('TOKEN=', (string) ($filed['note'] ?? ''));
  }

  /**
   * A project in a repository, with a declared source tree and a spec file.
   *
   * @param string $yaml
   *   The lever file.
   *
   * @return string
   *   The root.
   */
  private function project(string $yaml): string {
    $root = $this->makeRootWithConfig($yaml . "mode: agentic\n");
    mkdir($root . '/src', 0755, TRUE);
    mkdir($root . '/tests/e2e', 0755, TRUE);
    file_put_contents($root . '/src/Thing.php', "<?php\n");
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "\n");
    exec(sprintf(
      'cd %s && git init -q . && git add -A && git -c user.name=droost-test -c user.email=test@example.invalid commit -q -m base 2>&1',
      escapeshellarg($root),
    ), $output, $exit);
    $this->assertSame(0, $exit, implode("\n", $output));

    return $root;
  }

  /**
   * Drives a run until the given phase is current.
   *
   * @param string $root
   *   The project.
   * @param \Droost\Workflow\Gate\GateExecutorInterface $gates
   *   The executor.
   * @param \Droost\Workflow\Config\Phase $phase
   *   Where to stop.
   */
  private function walkTo(string $root, GateExecutorInterface $gates, Phase $phase): void {
    $facade = $this->facade($gates);
    $facade->run($root);
    $facade->declareChanges($root, ['src', 'tests'], [], 'code');
    for ($i = 0; $i < 8 && (new RunStateStore($root))->load()?->currentPhase !== $phase; $i++) {
      $outcome = $this->facade($gates)->run($root);
      $this->assertContains($outcome->outcome, [Outcome::Advanced], json_encode($outcome->toArray()) ?: '');
    }
    $this->assertSame($phase, (new RunStateStore($root))->load()?->currentPhase);
  }

  /**
   * A browser result failing in the ticket's own spec.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The result, as the executor would parse it.
   */
  private function browserFails(): GateResult {
    return GateResult::ran(
      'playwright',
      GateStatus::Failed,
      1,
      1,
      'playwright failed (exit 1): 1 failed, 2 passed, at tests/e2e/mine.spec.ts:3',
      [['key' => 'totals', 'detail' => []], ['file' => 'tests/e2e/mine.spec.ts', 'line' => 3, 'detail' => 'failed']],
      'playwright test tests/e2e/mine.spec.ts',
    );
  }

  /**
   * An executor that passes everything but the queued results.
   *
   * @param array<string, list<\Droost\Workflow\Gate\GateResult>> $queued
   *   Results each gate returns, in order, before it starts passing.
   *
   * @return object{executions: array<string, int>}&\Droost\Workflow\Gate\GateExecutorInterface
   *   The double.
   */
  private function gates(array $queued): object {
    return new class($queued) implements GateExecutorInterface {

      /**
       * Executions per gate.
       *
       * @var array<string, int>
       */
      public array $executions = [];

      /**
       * Constructs the double.
       *
       * @param array<string, list<\Droost\Workflow\Gate\GateResult>> $queued
       *   The queued results.
       */
      public function __construct(private array $queued) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        $this->executions[$gate->name] = ($this->executions[$gate->name] ?? 0) + 1;
        if (($this->queued[$gate->name] ?? []) !== []) {
          return array_shift($this->queued[$gate->name]);
        }

        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
  }

  /**
   * A fresh facade, as a new process would build it.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $gates
   *   The shared executor.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $gates): WorkflowFacade {
    return new WorkflowFacade(
      $gates,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-29T12:00:00+00:00',
      static fn (): string => 'run-loop',
    );
  }

  /**
   * The run-event log's lines.
   *
   * @param string $root
   *   The project.
   *
   * @return list<array<mixed>>
   *   The events.
   */
  private function events(string $root): array {
    $path = (new RunStateStore($root))->directory() . '/events.jsonl';
    $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $events = [];
    foreach ($lines ?: [] as $line) {
      $event = json_decode($line, TRUE);
      if (is_array($event)) {
        $events[] = $event;
      }
    }

    return $events;
  }

}
