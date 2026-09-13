<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Evidence\SubjectHasher;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The store keeps every attempt, and a green that knows what it was about.
 */
#[CoversClass(EvidenceStore::class)]
#[CoversClass(CheckRecord::class)]
#[CoversClass(SubjectHasher::class)]
final class EvidenceStoreTest extends TestCase {

  use ReadsTheStore;

  /**
   * A scratch project root, removed after each test.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-evidence-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/src', 0775, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->root)) {
      exec('rm -rf ' . escapeshellarg($this->root));
    }
  }

  /**
   * A gate that failed and then passed leaves both attempts on the record.
   *
   * The old record overwrote the failing attempt and kept only a counter, so a
   * run could prove the feedback loop fired and never what it corrected.
   */
  public function testFailedAttemptSurvivesThePassThatFollowsIt(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Blocked, Fault::Agent, '2 errors',
    ));
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Satisfied, Fault::None, 'phpstan passed',
    ));

    $attempts = $this->storeRows(
      $store->connection(),
      'SELECT attempt, state, summary FROM check_result WHERE name = "phpstan" ORDER BY attempt',
    );

    $this->assertCount(2, $attempts);
    $this->assertSame('blocked', $attempts[0]['state']);
    $this->assertSame('2 errors', $attempts[0]['summary'], 'the sentence that caused the work survives');
    $this->assertSame('satisfied', $attempts[1]['state']);
    $this->assertSame([], $store->unresolved('r1', 'code'), 'the latest attempt is what the checklist reads');
  }

  /**
   * A green expires by itself when the code it was green about moves.
   *
   * This is the difference between droost determining something is green and
   * droost reading back a stored TRUE.
   */
  public function testGreenExpiresWhenItsSubjectChanges(): void {
    file_put_contents($this->root . '/src/a.php', '<?php // one');
    $before = SubjectHasher::hash($this->root, ['src']);
    $this->assertIsString($before);

    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Satisfied, Fault::None, 'passed', NULL, $before,
    ));
    $this->assertTrue($store->stillGreen('r1', 'code', 'phpstan', $before));

    file_put_contents($this->root . '/src/a.php', '<?php // two');
    $after = SubjectHasher::hash($this->root, ['src']);

    $this->assertNotSame($before, $after, 'the fingerprint follows content');
    $this->assertNotNull($after, 'src hashes to something');
    $this->assertFalse(
      $store->stillGreen('r1', 'code', 'phpstan', $after),
      'a green recorded against different code is not a green now',
    );
  }

  /**
   * A check with no fingerprint is never reported as still green.
   *
   * Some gates have no resolvable subject — they talk to a site, or run a
   * whole suite by config. Their verdict is real; it is simply not
   * self-expiring, and must not pretend to be.
   */
  public function testGreenWithNoSubjectIsNeverStillGreen(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'test', new CheckRecord(
      'gate', 'rendered_check', CheckState::Satisfied, Fault::None, '1 route rendered',
    ));

    $this->assertFalse($store->stillGreen('r1', 'test', 'rendered_check', 'anything'));
  }

  /**
   * Blocked and pending stop a phase; nothing else does.
   */
  public function testOnlyBlockedAndPendingAreUnresolved(): void {
    $store = new EvidenceStore($this->root);
    $states = [
      'phpcs' => CheckState::Satisfied,
      'phpstan' => CheckState::Blocked,
      'eslint' => CheckState::NotApplicable,
      'module:snyk' => CheckState::Recorded,
      'phpunit' => CheckState::Unblocked,
      'coverage' => CheckState::Pending,
    ];
    foreach ($states as $name => $state) {
      $store->record('r1', 'code', new CheckRecord(
        'gate', $name, $state, $state === CheckState::Blocked ? Fault::Agent : Fault::None,
      ));
    }

    $blocking = array_column($store->unresolved('r1', 'code'), 'name');
    sort($blocking);

    $this->assertSame(['coverage', 'phpstan'], $blocking);
  }

  /**
   * Findings become rows, so a big report stops being one opaque entry.
   */
  public function testFindingsAreStoredAsRows(): void {
    $store = new EvidenceStore($this->root);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Blocked, Fault::Agent, '3 errors', NULL, NULL, 2, 'phpcs', NULL, 110,
      [
        ['file' => 'src/a.php', 'line' => 3, 'rule' => 'Drupal.Arrays', 'message' => 'indent'],
        ['file' => 'src/b.php', 'line' => 9, 'rule' => 'Drupal.Commenting', 'message' => 'missing'],
        ['key' => 'totals', 'detail' => ['errors' => 2]],
      ],
    ));

    $rows = $this->storeRows($store->connection(), 'SELECT file, line, rule, detail FROM finding ORDER BY seq');

    $this->assertCount(3, $rows);
    $this->assertSame('src/a.php', $rows[0]['file']);
    $this->assertEquals(3, $rows[0]['line']);
    $this->assertNull($rows[0]['detail'], 'a fully-mapped finding needs no overflow');
    $this->assertNotNull($rows[2]['detail'], 'a shape with no columns keeps its content as JSON rather than losing it');
  }

  /**
   * The ledger gains the one thing the JSONL version could never carry.
   *
   * Asserted through the document that reads it rather than through a method
   * written for the purpose. `toolTally()` existed to answer exactly this and
   * had no production caller — the report queries `tool_call` itself — so the
   * old version of this test kept a dead method alive and proved nothing about
   * the path anybody actually uses.
   */
  public function testToolCallsCarryTheirPhase(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->recordToolCall('r1', 'plan', 'droost_symbol', 'ok');
    $store->recordToolCall('r1', 'plan', 'droost_symbol', 'ok');
    $store->recordToolCall('r1', 'code', 'droost_scaffold', 'fail');

    $rows = $this->storeRows(
      $store->connection(),
      'SELECT phase, tool, COUNT(*) AS n FROM tool_call
        WHERE run_id = ? GROUP BY phase, tool ORDER BY phase, tool',
      ['r1'],
    );
    $this->assertCount(2, $rows, 'two phases, kept apart');
    $this->assertSame('code', $rows[0]['phase']);
    $this->assertSame('plan', $rows[1]['phase']);
    $this->assertEquals(2, $rows[1]['n'], 'and the count is per phase');

    // And the report, which is what reads it, renders both.
    $report = (new EvaluationReport($store))->render('r1');
    $this->assertStringContainsString('droost_symbol', $report);
    $this->assertStringContainsString('droost_scaffold', $report);
  }

  /**
   * A NULL never overwrites a fact the run row already holds.
   *
   * The row is filled in by several surfaces at different moments — the spec
   * is frozen long after the run opens — so a later write that knows less must
   * not erase what an earlier one knew.
   */
  public function testUpsertNeverErasesWhatIsAlreadyKnown(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium', 'base_commit' => 'abc123']);
    $store->upsertRun('r1', ['preset' => NULL, 'spec_hash' => 'deadbeef']);

    $row = $this->storeRow($store->connection(), 'SELECT preset, base_commit, spec_hash FROM run');
    $this->assertNotNull($row, 'the run row exists');

    $this->assertSame('medium', $row['preset']);
    $this->assertSame('abc123', $row['base_commit']);
    $this->assertSame('deadbeef', $row['spec_hash']);
  }

  /**
   * A fault may only ride on a block.
   *
   * A passing check that carries "agent" is a contradiction the record would
   * then have to be read around forever.
   */
  public function testOnlyBlockedChecksCarryFault(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Only a blocked check carries a fault');

    new CheckRecord('gate', 'phpcs', CheckState::Satisfied, Fault::Agent);
  }

  /**
   * A remedy may only ride on an environment fault.
   *
   * This is the load-bearing one. A remedy printed beside work the agent must
   * simply do reads as a way out of doing it — and in a live round the agent
   * asked to waive the gate that was holding back an XSS bypass. There is no
   * command that fixes failing tests, and the record must not imply one.
   */
  public function testRemedyMayOnlyAccompanyAnEnvironmentFault(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('reads as a way out of doing it');

    new CheckRecord('gate', 'phpunit', CheckState::Blocked, Fault::Agent, '2 failing', 'drush something');
  }

  /**
   * An environment block keeps its remedy, and says who may run it.
   */
  public function testAnEnvironmentBlockCarriesItsRemedy(): void {
    $check = new CheckRecord(
      'gate', 'phpunit', CheckState::Blocked, Fault::Environment,
      'no phpunit.xml at the project root', 'drush droost:workflow:install',
    );

    $this->assertSame('drush droost:workflow:install', $check->remedy);
    $this->assertTrue($check->fault->operatorMayUnblock());
    $this->assertStringContainsString('OPERATOR', $check->fault->guidance());
  }

  /**
   * Opening an existing store twice migrates once and loses nothing.
   */
  public function testTheStoreIsIdempotentOnReopen(): void {
    (new EvidenceStore($this->root))->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied,
    ));
    $reopened = new EvidenceStore($this->root);
    $reopened->record('r1', 'code', new CheckRecord('gate', 'phpstan', CheckState::Satisfied));

    $this->assertSame(
      EvidenceStore::SCHEMA_VERSION,
      $this->storeCount($reopened->connection(), 'PRAGMA user_version'),
    );
    $this->assertCount(2, $reopened->checklist('r1', 'code'));
  }

  /**
   * A capped transcript stays valid UTF-8 at every cut boundary.
   *
   * `substr` cuts at a byte, so a multi-byte character straddling the cap
   * became invalid UTF-8 — which SQLite stores happily and which then breaks
   * `json_encode` in the guard and `preg`'s `/u` in the report. Three surfaces
   * had grown defensive code for bytes this cap was manufacturing. Four
   * boundaries because only one of the four alignments was ever broken.
   */
  public function testCappedOutputIsValidAtEveryBoundary(): void {
    $result = GateResult::ran(
      'x', GateStatus::Passed, 0, 1, 's', [], 'i',
    );
    for ($pad = 0; $pad < 4; $pad++) {
      $text = str_repeat('a', intdiv(GateResult::OUTPUT_CAP, 2) - $pad)
        . 'é'
        . str_repeat('b', GateResult::OUTPUT_CAP);

      $this->assertTrue(
        mb_check_encoding($result->withOutput($text, '')->stdout, 'UTF-8'),
        sprintf('a cut at offset -%d leaves valid UTF-8', $pad),
      );
    }
  }

  /**
   * The fingerprint notices changes it used to sleep through.
   *
   * Two silent non-fires, both found by a reviewer driving them rather than
   * reading the code, and the first is the one that matters:
   *
   *   * a file under a `vendor/` INSIDE an explicitly declared path set was
   *     rewritten to `system($_GET['c'])` and the fingerprint did not move. The
   *     skip list exists so a gate does not hash every dependency on every run,
   *     which is a real cost — but it meant a green over a declared tree never
   *     expired when the tree's dependencies changed underneath it;
   *   * a file over 2MB was fingerprinted by size alone, so flipping its first
   *     byte left the digest identical.
   *
   * The residual limits are stated rather than left to be found: a same-size
   * in-place rewrite inside a SKIPPED tree still escapes, as does a same-length
   * edit confined strictly to the middle of a multi-megabyte file. Both are far
   * narrower than "any change at all", and both are written into the hasher.
   */
  public function testFingerprintNoticesWhatItUsedToSleepThrough(): void {
    mkdir($this->root . '/mod/vendor/dep', 0775, TRUE);
    file_put_contents($this->root . '/mod/a.php', '<?php // ok');
    file_put_contents($this->root . '/mod/vendor/dep/x.php', '<?php // harmless');

    $before = SubjectHasher::hash($this->root, ['mod']);
    $this->assertIsString($before);

    file_put_contents($this->root . '/mod/vendor/dep/x.php', '<?php system($_GET["c"]); // and longer');
    $this->assertNotSame(
      $before,
      SubjectHasher::hash($this->root, ['mod']),
      'a dependency rewritten inside a declared path set moves the fingerprint',
    );

    file_put_contents($this->root . '/mod/vendor/dep/x.php', '<?php // harmless');
    $this->assertSame($before, SubjectHasher::hash($this->root, ['mod']), 'and restoring it restores the digest');

    file_put_contents($this->root . '/mod/vendor/dep/added.php', 'new');
    $this->assertNotSame($before, SubjectHasher::hash($this->root, ['mod']), 'so does adding one');
    unlink($this->root . '/mod/vendor/dep/added.php');

    // Over the streaming threshold: size alone was the whole fingerprint.
    $padding = str_repeat('a', 2_200_000);
    file_put_contents($this->root . '/mod/big.php', 'A' . $padding);
    $big = SubjectHasher::hash($this->root, ['mod']);
    $this->assertIsString($big);

    file_put_contents($this->root . '/mod/big.php', 'B' . $padding);
    $this->assertNotSame($big, SubjectHasher::hash($this->root, ['mod']), 'the first byte counts');

    file_put_contents($this->root . '/mod/big.php', 'A' . substr($padding, 0, -1) . 'Z');
    $this->assertNotSame($big, SubjectHasher::hash($this->root, ['mod']), 'and so does the last');
  }

  /**
   * A gate the operator waived is a gate that could not measure.
   *
   * Two ways in, found one at a time, and the second is the one that matters
   * because it turns the rescue into the next wall: a run blocks, the operator
   * waives the gate to free it, the waived gate then measures nothing, and
   * `type_coverage` blocks on exactly that. The one documented way out of a
   * stuck run produced the next stuck run.
   *
   * A waived gate records as `Unblocked` — not measured, not off by level, not
   * skipped by surface, and in none of the exemptions. It was added to this
   * query in fa3486d with no test, which is how it would have come back.
   */
  public function testWaivedAndSkippedGatesAreBothUnmeasurable(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $cases = [
      ['rendered_check', CheckState::Skipped, Fault::None],
      ['snyk', CheckState::Unblocked, Fault::None],
      ['phpcs', CheckState::Satisfied, Fault::None],
      ['phpstan', CheckState::Blocked, Fault::Agent],
      ['phpunit', CheckState::NotApplicable, Fault::None],
      ['config_clean', CheckState::Recorded, Fault::None],
    ];
    foreach ($cases as [$name, $state, $fault]) {
      $store->record('r1', 'test', new CheckRecord(
        kind: 'gate',
        name: $name,
        state: $state,
        fault: $fault,
        summary: 'x',
        exitCode: $state === CheckState::Blocked ? 1 : 0,
      ));
    }

    $unmeasurable = $store->unmeasurableGates('r1');
    sort($unmeasurable);

    $this->assertSame(
      ['rendered_check', 'snyk'],
      $unmeasurable,
      'skipped and waived, and nothing else — a blocked gate is the agent\'s '
      . 'problem and a satisfied one measured',
    );
  }

  /**
   * A check that is not a gate does not appear, whatever its state.
   *
   * The query is scoped to `kind = gate` and the caller feeds the list to the
   * declaration audit as "gates that cannot show a measurement". A contributed
   * check landing in there would exempt a gate nobody waived.
   */
  public function testOnlyGatesAreUnmeasurable(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'complete', new CheckRecord(
      kind: 'check',
      name: 'jira_transitioned',
      state: CheckState::Unblocked,
      summary: 'lifted',
    ));

    $this->assertSame([], $store->unmeasurableGates('r1'));
  }

  /**
   * And it answers for ONE run.
   */
  public function testUnmeasurableGatesAreScopedToTheRun(): void {
    $store = new EvidenceStore($this->root);
    foreach (['r1', 'r2'] as $run) {
      $store->upsertRun($run, ['preset' => 'medium']);
    }
    $store->record('r1', 'test', new CheckRecord(
      kind: 'gate', name: 'rendered_check', state: CheckState::Skipped, summary: 'no site',
    ));
    $store->record('r2', 'test', new CheckRecord(
      kind: 'gate', name: 'snyk', state: CheckState::Unblocked, summary: 'waived',
    ));

    $this->assertSame(['rendered_check'], $store->unmeasurableGates('r1'));
    $this->assertSame(['snyk'], $store->unmeasurableGates('r2'));
  }

}
