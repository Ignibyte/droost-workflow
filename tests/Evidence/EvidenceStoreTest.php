<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
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

    $attempts = $store->connection()
      ->query('SELECT attempt, state, summary FROM check_result WHERE name = "phpstan" ORDER BY attempt')
      ->fetchAll();

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

    $rows = $store->connection()->query('SELECT file, line, rule, detail FROM finding ORDER BY seq')->fetchAll();

    $this->assertCount(3, $rows);
    $this->assertSame('src/a.php', $rows[0]['file']);
    $this->assertSame(3, (int) $rows[0]['line']);
    $this->assertNull($rows[0]['detail'], 'a fully-mapped finding needs no overflow');
    $this->assertNotNull($rows[2]['detail'], 'a shape with no columns keeps its content as JSON rather than losing it');
  }

  /**
   * The ledger gains the one thing the JSONL version could never carry.
   */
  public function testToolCallsCarryTheirPhase(): void {
    $store = new EvidenceStore($this->root);
    $store->recordToolCall('r1', 'plan', 'droost_symbol', 'ok');
    $store->recordToolCall('r1', 'plan', 'droost_symbol', 'ok');
    $store->recordToolCall('r1', 'code', 'droost_scaffold', 'fail');

    $this->assertSame(['droost_symbol' => 2], $store->toolTally('r1', 'plan'));
    $this->assertSame(['droost_scaffold' => 1], $store->toolTally('r1', 'code'));
    $this->assertSame(
      ['droost_symbol' => 2, 'droost_scaffold' => 1],
      $store->toolTally('r1'),
      'and the whole run is still one query',
    );
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

    $row = $store->connection()->query('SELECT preset, base_commit, spec_hash FROM run')->fetch();

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
      (int) $reopened->connection()->query('PRAGMA user_version')->fetchColumn(),
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

}
