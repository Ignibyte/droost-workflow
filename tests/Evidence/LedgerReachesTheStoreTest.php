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
use PHPUnit\Framework\TestCase;

/**
 * The tool-call ledger reaches the database, with the phase it happened in.
 *
 * `recordToolCall()` existed, the `tool_call` table existed, and nothing in
 * production ever called it — so §4a of every generated evaluation announced
 * "The ledger is empty for this run. Not one droost tool was called" while
 * `droost/droost-workflow/tool-calls.jsonl` sat beside it holding the real
 * calls. Measured on a live round: twelve calls in the file, an empty table,
 * and a document reporting that the run had asked the codebase nothing.
 *
 * §4a calls the ledger "the only place in the system that is not the
 * subject's own account", and the observability chain counts it as the link
 * that corroborates the spec's grounding claims. A link that is always dark
 * corroborates nothing.
 */
final class LedgerReachesTheStoreTest extends TestCase {

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-ledger-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->root));
  }

  /**
   * The calls land as rows, carrying the phase the file cannot know.
   */
  public function testTheLedgerIsIngestedWithItsPhase(): void {
    $this->ledger([
      ['tool' => 'droost_doctor', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:00+00:00'],
      ['tool' => 'droost_decide', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:01+00:00'],
      ['tool' => 'droost_scaffold', 'outcome' => 'refused', 'at' => '2026-09-15T01:00:02+00:00'],
    ]);

    $this->record('code');

    $rows = $this->toolCalls();
    $this->assertCount(3, $rows, 'every line in the file is a row');
    $this->assertSame(['code', 'code', 'code'], array_column($rows, 'phase'), 'tagged with the phase');
    $this->assertSame(
      ['droost_decide', 'droost_doctor', 'droost_scaffold'],
      array_column($rows, 'tool'),
    );
    $this->assertContains('refused', array_column($rows, 'outcome'), 'a refusal is a row like any other');
  }

  /**
   * Recording a phase twice does not double the ledger.
   */
  public function testTheSameCallsAreNotIngestedTwice(): void {
    $this->ledger([['tool' => 'droost_doctor', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:00+00:00']]);
    $this->record('code');
    $this->record('code');

    $this->assertCount(1, $this->toolCalls(), 'the watermark holds across a re-record');

    // A call made LATER lands, and carries the phase it was seen in.
    $this->ledger([
      ['tool' => 'droost_doctor', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:00+00:00'],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => '2026-09-15T02:00:00+00:00'],
    ]);
    $this->record('test');

    $rows = $this->toolCalls();
    $this->assertCount(2, $rows);
    $byTool = [];
    foreach ($rows as $row) {
      $tool = is_string($row['tool']) ? $row['tool'] : '';
      $byTool[$tool] = $row['phase'];
    }
    $this->assertSame('code', $byTool['droost_doctor'], 'the first call keeps the phase it was seen in');
    $this->assertSame('test', $byTool['droost_search'], 'the later one gets its own');
  }

  /**
   * A malformed line is skipped rather than crashing the recorder.
   */
  public function testTheBrokenLineDoesNotStopTheRest(): void {
    file_put_contents(
      $this->root . '/droost/droost-workflow/tool-calls.jsonl',
      "{not json\n" . json_encode(['tool' => 'droost_decide', 'outcome' => 'ok']) . "\n\n",
    );

    $this->record('plan');

    $rows = $this->toolCalls();
    $this->assertCount(1, $rows, 'the good line survives the bad one');
    $this->assertSame('droost_decide', $rows[0]['tool']);
  }

  /**
   * Another run's calls are not this run's, however many of them there are.
   *
   * The file is append-only across the checkout's life and `reset` used to
   * leave it in place, so a count watermark from zero handed a new run every
   * earlier run's calls (F-6). Rows carry the run since 0.9, and the ingest
   * takes only the ones that name this run.
   */
  public function testAnotherRunsCallsAreNotIngested(): void {
    $this->ledger([
      ['tool' => 'droost_structure_create', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:00+00:00', 'run' => 'r0', 'phase' => 'code'],
      ['tool' => 'droost_entity_create', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:01+00:00', 'run' => 'r0', 'phase' => 'code'],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => '2026-09-15T02:00:00+00:00', 'run' => 'r1', 'phase' => 'plan'],
      // A call made with no run open is THIS run's: the plan phase grounds
      // before it opens the run, and reset has already archived the last one.
      ['tool' => 'droost_doctor', 'outcome' => 'ok', 'at' => '2026-09-15T02:00:01+00:00', 'run' => NULL, 'phase' => NULL],
    ]);

    $this->record('code');

    $rows = $this->toolCalls();
    $this->assertCount(2, $rows, 'this run\'s row and the pre-open one; never r0\'s');
    $this->assertSame(['droost_doctor', 'droost_search'], array_column($rows, 'tool'));
  }

  /**
   * A file written before rows carried a run is taken whole, as before.
   *
   * A site mid-upgrade has a ledger nothing can attribute; refusing it would
   * report the run as having asked the codebase nothing, which is the exact
   * lie §4a was written to stop. The rule is the presence of the key on ANY
   * row: once one row names a run, the rows that do not are calls made with
   * no run open, and are nobody's.
   */
  public function testALegacyFileWithNoRunOnAnyRowIsTakenWhole(): void {
    $this->ledger([
      ['tool' => 'droost_doctor', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:00+00:00'],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:01+00:00'],
    ]);

    $this->record('code');

    $this->assertCount(2, $this->toolCalls(), 'unattributable rows are this run\'s, as they always were');
  }

  /**
   * The watermark counts THIS run's rows, so a re-record does not double them
   * and another run's rows do not shift the offset.
   */
  public function testTheWatermarkIsAgainstThisRunsRowsOnly(): void {
    $this->ledger([
      ['tool' => 'droost_symbol', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:00+00:00', 'run' => 'r0', 'phase' => 'code'],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => '2026-09-15T02:00:00+00:00', 'run' => 'r1', 'phase' => 'plan'],
    ]);
    $this->record('plan');
    $this->record('plan');
    $this->assertCount(1, $this->toolCalls(), 'a re-record does not double it');

    $this->ledger([
      ['tool' => 'droost_symbol', 'outcome' => 'ok', 'at' => '2026-09-15T01:00:00+00:00', 'run' => 'r0', 'phase' => 'code'],
      ['tool' => 'droost_search', 'outcome' => 'ok', 'at' => '2026-09-15T02:00:00+00:00', 'run' => 'r1', 'phase' => 'plan'],
      ['tool' => 'droost_graph', 'outcome' => 'ok', 'at' => '2026-09-15T03:00:00+00:00', 'run' => 'r1', 'phase' => 'code'],
    ]);
    $this->record('code');

    $rows = $this->toolCalls();
    $this->assertSame(['droost_graph', 'droost_search'], array_column($rows, 'tool'));
  }

  /**
   * Writes the ledger file.
   *
   * @param list<array<string, string|null>> $calls
   *   The rows. `run` and `phase` are NULL for a call made with no run open.
   */
  private function ledger(array $calls): void {
    $body = '';
    foreach ($calls as $call) {
      $body .= json_encode($call) . "\n";
    }
    file_put_contents($this->root . '/droost/droost-workflow/tool-calls.jsonl', $body);
  }

  /**
   * Records one phase through the real recorder.
   *
   * @param string $phase
   *   The phase name.
   */
  private function record(string $phase): void {
    $config = WorkflowConfig::fromArray(['mode' => 'agentic', 'preset' => 'medium'], 'test');
    $state = RunState::begin('r1', '2026-09-15T00:00:00+00:00', $config);
    $green = GateResult::ran('phpcs', GateStatus::Passed, 0, 5, 'clean', [], 'vendor/bin/phpcs src');

    (new EvidenceRecorder($this->root))->recordPhase($state, $phase, new PhaseReport(Phase::Code, [$green]));
  }

  /**
   * The tool_call rows, ordered by tool.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  private function toolCalls(): array {
    $statement = (new EvidenceStore($this->root))->connection()
      ->prepare('SELECT phase, tool, outcome, at FROM tool_call WHERE run_id = ? ORDER BY tool');
    $statement->execute(['r1']);
    /** @var list<array<string, mixed>> $rows */
    $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

    return $rows;
  }

}
