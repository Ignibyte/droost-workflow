<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceRecorder;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\RunState;
use PHPUnit\Framework\TestCase;

/**
 * A check that stops being asked does not keep its last answer for ever.
 *
 * `checklist()` returns the newest row PER NAME, and the audit decides whether
 * to advance from its own fresh result. So when a check stopped being emitted,
 * its last `blocked` row stood as the record's current verdict with nothing
 * able to replace it — a check nobody asks cannot answer.
 *
 * A reviewer reached it by following a remedy's own advice:
 *
 *   1. phpcs pointed at an empty directory   -> type_coverage blocked
 *   2. `declare-changes --type=docs`, the remedy's second answer — Docs rests
 *      on no gates, so the audit emits no type_coverage row at all
 *   3. `run`                                 -> advances, blocked: []
 *   4. the stop hook, same moment            -> exit 2, "type_coverage"
 *
 * Three things follow, and the third is the worst. The engine and the record
 * disagree permanently, so anything reading the store is wrong about the run.
 * The block ceiling counts a stale row once and can never fire on it. And at
 * PLAN and COMPLETE, where `answer()` decides from the STORE, a stale blocked
 * row refuses to advance an interactive run for ever — `reset --force` the
 * only exit.
 *
 * SUPERSEDED, NOT HIDDEN. Filtering old rows out of `checklist()` would lose a
 * real blocked verdict that a later pass simply did not re-examine, and
 * attempt numbers are per-name so they cannot be compared across checks.
 * Writing what the newer pass concluded keeps the trail, which is the point of
 * an append-only record.
 */
final class StaleVerdictTest extends TestCase {

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-stale-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    exec('rm -rf ' . escapeshellarg($this->root));
  }

  /**
   * A blocked check that is no longer emitted is retired, with the trail kept.
   */
  public function testChecksNoLongerAskedAreRetired(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'low']);

    // Pass one: one check passes, one blocks.
    $store->record('r1', 'code', new CheckRecord(
      'declaration', 'declared_files', CheckState::Satisfied, Fault::None, 'ok',
    ));
    $store->record('r1', 'code', new CheckRecord(
      'declaration', 'type_coverage', CheckState::Blocked, Fault::Environment,
      'phpcs measured nothing', 'point gates.phpcs.paths at the code',
    ));
    $this->assertCount(1, $store->unresolved('r1', 'code'), 'the block is real');

    // Pass two re-examines everything it still asks, and no longer asks the
    // other — the declaration changed under it.
    $store->record('r1', 'code', new CheckRecord(
      'declaration', 'declared_files', CheckState::Satisfied, Fault::None, 'ok',
    ));
    $store->retireUnemitted('r1', 'code', 'declaration', ['declared_files']);

    $this->assertSame(
      [],
      $store->unresolved('r1', 'code'),
      'a check nobody asks no longer holds the run',
    );

    $states = [];
    foreach ($store->checklist('r1', 'code') as $row) {
      $name = $row['name'] ?? NULL;
      $state = $row['state'] ?? NULL;
      $this->assertIsString($name);
      $this->assertIsString($state);
      $states[$name] = $state;
    }
    $this->assertSame(
      ['declared_files' => 'satisfied', 'type_coverage' => 'not_applicable'],
      $states,
      'and the record says so, rather than the row vanishing',
    );
  }

  /**
   * A check that is STILL asked keeps its verdict.
   *
   * The counterweight, and the reason this is not implemented by hiding old
   * rows: a blocked check that a later pass re-examined and still blocks must
   * go on blocking.
   */
  public function testChecksStillAskedKeepTheirVerdict(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'low']);
    $store->record('r1', 'code', new CheckRecord(
      'declaration', 'type_coverage', CheckState::Blocked, Fault::Environment, 'measured nothing',
      'point the lever',
    ));

    $store->retireUnemitted('r1', 'code', 'declaration', ['type_coverage']);

    $this->assertCount(
      1,
      $store->unresolved('r1', 'code'),
      'a check this pass still asks is untouched by the retirement',
    );
  }

  /**
   * Another kind's rows are left alone.
   *
   * The declaration audit speaks for declarations and the contributed
   * adjudicator for checks; neither may retire the other's verdicts, and a
   * GATE's verdict belongs to the gate run.
   */
  public function testOtherKindsAreLeftAlone(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'low']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Blocked, Fault::Agent, '19 violations', NULL, NULL, 1,
    ));
    $store->record('r1', 'code', new CheckRecord(
      'check', 'jira_transitioned', CheckState::Blocked, Fault::Environment, 'still In Progress',
      'transition it',
    ));

    // The declaration pass emitted nothing at all, and says nothing about
    // either of these.
    $store->retireUnemitted('r1', 'code', 'declaration', []);

    $this->assertCount(
      2,
      $store->unresolved('r1', 'code'),
      'a failing gate and another provider\'s check are not this pass\'s to retire',
    );
  }

  /**
   * A gate that stopped being emitted is retired like any other check.
   *
   * Declarations and contributed checks were retired when a pass stopped
   * emitting them; gates were not. A gate's blocked row reaches the stop hook
   * (`unresolved()` has no kind filter) while the run envelope skips it
   * (`blockingChecks()` does), so a contributed gate that blocked under drush
   * and then vanished when the same phase re-ran through the standalone binary
   * held the turn for ever — with no name, no reason, and the ceiling blind to
   * it. The gate PASS retires it, which is the counterpart to the test above:
   * another kind's pass may not, its own must.
   */
  public function testGatesNoLongerEmittedAreRetired(): void {
    $config = WorkflowConfig::fromArray(['mode' => 'agentic', 'preset' => 'medium'], 'test');
    $state = RunState::begin('r1', '2026-09-14T00:00:00+00:00', $config);
    $recorder = new EvidenceRecorder($this->root);

    $recorder->recordPhase($state, 'code', new PhaseReport(Phase::Code, [
      new GateResult('phpcs', GateStatus::Passed, exitCode: 0, summary: 'clean'),
      new GateResult('module:snyk', GateStatus::Failed, exitCode: 1, summary: '2 high'),
    ]));
    $this->assertNull($recorder->lastError());
    $this->assertSame(
      ['module:snyk'],
      array_column((new EvidenceStore($this->root))->unresolved('r1', 'code'), 'name'),
      'the failing gate holds the phase',
    );

    // The same phase again, on a surface whose catalog does not carry snyk.
    $recorder->recordPhase($state, 'code', new PhaseReport(Phase::Code, [
      new GateResult('phpcs', GateStatus::Passed, exitCode: 0, summary: 'clean'),
    ]));
    $this->assertNull($recorder->lastError());
    $this->assertSame(
      [],
      (new EvidenceStore($this->root))->unresolved('r1', 'code'),
      'a gate nobody ran this pass no longer holds it',
    );
  }

  /**
   * The ceiling counts only the checks that are STILL blocked.
   *
   * `blockedAttempts()` counted every blocked row since the last pause, so a
   * check that blocked fifty-nine times and then cleared still counted
   * fifty-nine — and the FIRST block of a different check tipped the phase
   * over the ceiling. A reviewer drove it: `ticket_id` cleared on attempt
   * sixty, `transition` blocked once, and the engine paused a run that was
   * making progress to ask "this phase has been blocked 60 times and none of
   * them has cleared". Both halves false, and a healthy run halted on them.
   */
  public function testTheCeilingCountsOnlyChecksStillBlocked(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'low']);
    foreach ([1, 2, 3] as $attempt) {
      $store->record('r1', 'plan', new CheckRecord(
        'check', 'ticket_id', CheckState::Blocked, Fault::Environment, 'no ticket, attempt ' . $attempt,
      ));
    }
    $this->assertSame(3, $store->blockedAttempts('r1', 'plan'), 'three blocks on a check still blocked count three');

    $store->record('r1', 'plan', new CheckRecord(
      'check', 'ticket_id', CheckState::Satisfied, Fault::None, 'PROJ-1',
    ));
    $this->assertSame(
      0,
      $store->blockedAttempts('r1', 'plan'),
      'a check that cleared no longer counts what it cost to clear',
    );

    $store->record('r1', 'plan', new CheckRecord(
      'check', 'transition', CheckState::Blocked, Fault::Environment, 'still In Progress',
    ));
    $this->assertSame(
      1,
      $store->blockedAttempts('r1', 'plan'),
      'and a different check blocking once is one, not four',
    );
  }

  /**
   * The round the run knows about clears what a clean inspection cleared.
   *
   * A clean round writes NO rows, so inferring the round from `MAX(round)`
   * stayed pinned to the last round that found something and kept reporting
   * F1 open after the inspection that cleared it. Latent today — the one
   * reader is consulted only when the ledger is not clean — and one caller
   * away from live, which is the shape this whole file exists to close.
   */
  public function testTheRoundTheRunKnowsAboutClearsWhatItCleared(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'low']);
    $store->recordSeekerFindings('r1', 'code', 1, [
      ['id' => 'F1', 'severity' => 'MEDIUM', 'location' => 'src/x.php', 'finding' => 'dead code', 'status' => 'open'],
    ]);
    $this->assertSame(
      ['seeker:F1'],
      array_column($store->openSeekerFindings('r1', 'code', 1), 'check'),
      'round one found F1',
    );

    $store->recordSeekerFindings('r1', 'code', 2, []);
    $this->assertSame(
      [],
      $store->openSeekerFindings('r1', 'code', 2),
      'the run\'s second round found nothing, and says so',
    );
    $this->assertSame(
      [],
      $store->openSeekerFindings('r1', 'code'),
      'and the row-inferred fallback agrees, because a clean round now leaves a row of its own',
    );
  }

}
