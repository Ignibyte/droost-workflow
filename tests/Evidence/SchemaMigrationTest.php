<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Evidence\WorkType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The store's schema moves forward, one rung at a time, and never backward.
 *
 * Two live installs of droost can share a machine and a checkout: a project on
 * a released tag and this one on dev. So a store written by a build that knows
 * more than this one is not hypothetical, and the old shape of `migrate()`
 * handled it by returning quietly — after which every query ran against a
 * schema this build does not understand and the run died on "no such column"
 * with nothing naming the cause.
 */
#[CoversClass(EvidenceStore::class)]
final class SchemaMigrationTest extends TestCase {

  use ReadsTheStore;

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-migrate-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
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
   * The connection a test uses to inspect or damage the file directly.
   */
  private function raw(): \PDO {
    return new \PDO('sqlite:' . EvidenceStore::pathFor($this->root));
  }

  /**
   * The schema version stamped on the file itself.
   *
   * Read straight off the file rather than through the class: asserting a
   * migration through the code that performs it puts the same bug on both
   * sides of the assertion.
   *
   * @return int
   *   The version.
   */
  private function version(): int {
    return $this->storeCount($this->raw(), 'PRAGMA user_version');
  }

  /**
   * A fresh store lands on the current version.
   */
  public function testFreshStoreIsAtCurrentVersion(): void {
    (new EvidenceStore($this->root))->upsertRun('r1', ['preset' => 'medium']);

    $this->assertSame(EvidenceStore::SCHEMA_VERSION, $this->version());
  }

  /**
   * A v1 store is carried to v2 with its rows intact.
   *
   * The upgrade is what a real project hits: a store opened last week by the
   * build before this one. Its rows are the whole point — a migration that
   * produced a correct schema and lost the record would be worse than none.
   */
  public function testV1StoreUpgradesWithoutLosingRows(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'clean', NULL, NULL, 0, 'phpcs', NULL, 12,
    ));

    // Wind the file back to v1: drop what v2 added and restamp the version.
    $pdo = $this->raw();
    $pdo->exec('PRAGMA user_version = 1');
    $pdo->exec('DROP INDEX IF EXISTS check_by_provider');
    $pdo->exec('ALTER TABLE check_result DROP COLUMN provider');
    $pdo->exec('ALTER TABLE run DROP COLUMN work_type');
    $pdo->exec('ALTER TABLE run DROP COLUMN work_type_declared_at');
    unset($pdo);

    // A new instance, because the old one holds an open connection.
    $upgraded = new EvidenceStore($this->root);
    $upgraded->upsertRun('r1', ['work_type' => 'code']);

    $this->assertSame(EvidenceStore::SCHEMA_VERSION, $this->version(), 'the rung ran');
    $this->assertSame(
      WorkType::Code,
      $upgraded->workType('r1'),
      'and the column v2 added works',
    );

    $checklist = $upgraded->checklist('r1', 'code');
    $this->assertCount(1, $checklist, 'the v1 row survived the upgrade');
    $this->assertSame('phpcs', $checklist[0]['name']);
  }

  /**
   * An existing store gains the guard ledger's tool column.
   *
   * The v7-and-earlier rows keep a NULL tool, which is what makes them
   * distinguishable from a run that made no browser call: browserToolCalls()
   * counts matches rather than negating the complement, so an old store reads
   * as "not recorded" and never as "never looked".
   */
  public function testAnOlderStoreGainsTheGuardToolColumn(): void {
    (new EvidenceStore($this->root))->upsertRun('r1', ['preset' => 'low']);
    $pdo = $this->raw();
    $pdo->exec('DROP INDEX IF EXISTS guard_call_by_tool');
    $pdo->exec('ALTER TABLE guard_call DROP COLUMN tool');
    $pdo->exec('PRAGMA user_version = 7');
    unset($pdo);

    $upgraded = new EvidenceStore($this->root);
    $this->assertSame(0, $upgraded->browserToolCalls('r1'), 'the column is there');
    $this->assertSame(EvidenceStore::SCHEMA_VERSION, $this->version(), 'the rung ran');

    $upgraded->recordGuardCall(
      'r1', 'test', 'pre-tool-use', 'invoked', NULL, NULL,
      'mcp__playwright__browser_navigate',
    );
    $this->assertSame(1, $upgraded->browserToolCalls('r1'));
  }

  /**
   * An EXISTING store gains the guard ledger, not just a fresh one.
   *
   * THIS IS THE CASE EVERY OTHER TEST IN THIS SUITE MISSES, and it cost a live
   * failure to notice. `guard_call` was added to V1's DDL and `SCHEMA_VERSION`
   * was not bumped — so `migrate()` returned immediately on any store already
   * stamped at the current version, the table was never created, and the first
   * read threw "no such table: guard_call". Every test here builds a store from
   * nothing, where the base DDL runs and the bug cannot appear; the dogfood
   * site's four-month-old database found it in one command.
   *
   * A schema change is two edits. This test is what makes the second one
   * non-optional.
   */
  public function testAnOlderStoreGainsTheGuardLedger(): void {
    // A store at the version before the ledger existed, with the table gone.
    (new EvidenceStore($this->root))->upsertRun('r1', ['preset' => 'low']);
    $pdo = $this->raw();
    $pdo->exec('DROP TABLE IF EXISTS guard_call');
    $pdo->exec('PRAGMA user_version = 5');
    unset($pdo);

    $upgraded = new EvidenceStore($this->root);
    // Reading is enough: the connection migrates before it answers.
    $this->assertSame([], $upgraded->guardCalls('r1'), 'the table is there and empty');
    $this->assertSame(EvidenceStore::SCHEMA_VERSION, $this->version(), 'the rung ran');

    $upgraded->recordGuardCall('r1', 'code', 'pre-tool-use', 'refuse', 'require-run');
    $this->assertSame(1, $upgraded->guardCallCount('r1'), 'and it takes rows');
  }

  /**
   * A store written by a NEWER build refuses, and says what to do.
   *
   * Never a downgrade: the newer build's rows are not this build's to
   * reinterpret, and quietly proceeding is what produced the unattributable
   * "no such column" failure this replaces.
   */
  public function testForwardStoreRefusesWithAnActionableMessage(): void {
    (new EvidenceStore($this->root))->upsertRun('r1', ['preset' => 'medium']);
    $this->raw()->exec('PRAGMA user_version = ' . (EvidenceStore::SCHEMA_VERSION + 7));

    try {
      (new EvidenceStore($this->root))->upsertRun('r2', ['preset' => 'medium']);
      $this->fail('a forward store was opened as if this build understood it');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('v' . (EvidenceStore::SCHEMA_VERSION + 7), $e->getMessage());
      $this->assertStringContainsString('v' . EvidenceStore::SCHEMA_VERSION, $e->getMessage());
      $this->assertStringContainsString(
        EvidenceStore::pathFor($this->root),
        $e->getMessage(),
        'and it names the file, because a message that does not is a message nobody can act on',
      );
    }

    $this->assertSame(
      EvidenceStore::SCHEMA_VERSION + 7,
      $this->version(),
      'and the refusal did not stamp the version down',
    );
  }

  /**
   * The version is stamped per rung, so an interrupted upgrade resumes.
   *
   * The old code stamped SCHEMA_VERSION once at the end, so a crash during a
   * multi-rung upgrade left the file at its original version and replayed every
   * rung on the next open. Additive rungs survive that; a rung that backfills
   * or rewrites does not, and the shape should not depend on which kind the
   * next one turns out to be.
   */
  public function testEachRungStampsItsOwnVersion(): void {
    (new EvidenceStore($this->root))->upsertRun('r1', ['preset' => 'medium']);
    $pdo = $this->raw();
    $pdo->exec('PRAGMA user_version = 0');
    unset($pdo);

    (new EvidenceStore($this->root))->upsertRun('r1', ['preset' => 'medium']);

    $this->assertSame(
      EvidenceStore::SCHEMA_VERSION,
      $this->version(),
      'a store rebuilt from zero climbs every rung',
    );
  }

  /**
   * Concurrent writers all land, rather than a third of them vanishing.
   *
   * Droost has several writers — the gate pipeline, the grounding resolver,
   * the declaration audit, and the Stop hook's own connection — and SQLite
   * allows one. A reviewer ran four at once and half the processes died with
   * "database is locked". Every call site swallows that, so the rows simply
   * went missing and nothing said so. Worse, the declaration audit caught it
   * and returned "nothing blocks", which made a contended database a silent
   * free pass through the checks.
   *
   * `busy_timeout` alone does not fix it, and that is the subtle part.
   * `record()` reads the attempt number and then writes, and a connection
   * upgrading a shared lock to a write lock gets SQLITE_BUSY immediately
   * whatever the timeout says — SQLite will not wait on a deadlock it cannot
   * resolve. The write lock has to be taken up front.
   */
  public function testConcurrentWritersAllLand(): void {
    (new EvidenceStore($this->root))->upsertRun('r1', ['preset' => 'medium']);

    $script = $this->root . '/writer.php';
    file_put_contents($script, <<<'PHP'
    <?php
    require $argv[1];
    $store = new \Droost\Workflow\Evidence\EvidenceStore($argv[2]);
    for ($i = 0; $i < 20; $i++) {
      $store->record('r1', 'code', new \Droost\Workflow\Evidence\CheckRecord(
        'gate', 'g' . $argv[3], \Droost\Workflow\Evidence\CheckState::Satisfied,
        \Droost\Workflow\Evidence\Fault::None, 'x',
      ));
    }
    PHP);

    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    $handles = [];
    for ($w = 1; $w <= 4; $w++) {
      $handles[] = proc_open(
        [PHP_BINARY, $script, $autoload, $this->root, (string) $w],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
      );
    }
    foreach ($handles as $handle) {
      if (is_resource($handle)) {
        proc_close($handle);
      }
    }

    $this->assertSame(
      80,
      $this->storeCount($this->raw(), "SELECT COUNT(*) FROM check_result WHERE kind = 'gate'"),
      'every write from every writer landed',
    );
    $this->assertSame(
      4,
      $this->storeCount($this->raw(), "SELECT COUNT(DISTINCT name) FROM check_result WHERE kind = 'gate'"),
      'and no writer was lost entirely',
    );
  }

}
