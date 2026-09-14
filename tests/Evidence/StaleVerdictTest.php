<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
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

}
