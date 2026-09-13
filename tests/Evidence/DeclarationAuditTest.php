<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\Fault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Scope is audited against the diff, and the asymmetry is deliberate.
 *
 * Growing past the plan blocks; shrinking inside it does not. A run that wedged
 * because the agent found a simpler way would teach it to pad its declarations,
 * which is worse than no declaration at all.
 */
#[CoversClass(DeclarationAudit::class)]
final class DeclarationAuditTest extends TestCase {

  /**
   * One check from an audit, by name.
   *
   * @param \Droost\Workflow\Evidence\DeclarationAudit $audit
   *   The audit.
   * @param string $name
   *   The item.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord
   *   The check. Absent is a failed test, not a NULL — see hasCheck().
   */
  private function check(DeclarationAudit $audit, string $name): CheckRecord {
    foreach ($audit->checks() as $check) {
      if ($check->name === $name) {
        return $check;
      }
    }
    // Not `return NULL` behind a `?->`: a missing check then reads as an
    // assertion about a state that is NULL, which is the wrong sentence for
    // "the audit never produced this check at all".
    $this->fail(sprintf('the audit produced no "%s" check', $name));
  }

  /**
   * A file touched that nobody declared blocks, and is named.
   */
  public function testUndeclaredFilesBlockAsScopeCreep(): void {
    $audit = new DeclarationAudit(
      ['web/themes/custom/kchockey'],
      [],
      ['web/themes/custom/kchockey/css/tokens.css', 'web/modules/custom/sneaky/sneaky.module'],
    );

    $this->assertSame(['web/modules/custom/sneaky/sneaky.module'], $audit->undeclared());
    $check = $this->check($audit, 'declared_files');
    $this->assertSame(CheckState::Blocked, $check->state);
    $this->assertSame(Fault::Agent, $check->fault);
    $this->assertStringContainsString('sneaky.module', (string) $check->summary);
  }

  /**
   * A declared directory covers the files created under it.
   *
   * Making an agent enumerate every file it will create would make the
   * declaration a chore nobody writes honestly.
   */
  public function testDeclaredDirectoryCoversWhatIsUnderIt(): void {
    $audit = new DeclarationAudit(
      ['web/themes/custom/kchockey'],
      [],
      [
        'web/themes/custom/kchockey/kchockey.info.yml',
        'web/themes/custom/kchockey/components/navbar/navbar.twig',
      ],
    );

    $this->assertSame([], $audit->undeclared());
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'declared_files')->state);
  }

  /**
   * A plan that shrank is recorded and does not block.
   */
  public function testDeclaredButUntouchedIsRecordedNotBlocked(): void {
    $audit = new DeclarationAudit(['src/A.php', 'src/B.php'], [], ['src/A.php']);

    $this->assertSame(['src/B.php'], $audit->untouched());
    $check = $this->check($audit, 'declared_files');
    $this->assertSame(CheckState::Satisfied, $check->state);
    $this->assertStringContainsString('src/B.php', (string) $check->summary, 'recorded, so a reader can see the plan moved');
  }

  /**
   * A declared test is RECORDED, because droost cannot see which tests ran.
   *
   * This check used to block, and could not be satisfied by any real test name.
   * `ranTests()` returns the test gates' summary and invocation strings, and
   * neither ever contains a test name: a passing phpunit gate stores "phpunit
   * passed — 3 test(s), 7 assertion(s)" and an argv with no filter and no path.
   * A substring search over those could only fail on a real name and succeed on
   * an accident:
   *
   *     --tests=WidgetTest::testReturns   blocked forever, zero budget spent
   *     --tests=phpunit                   advanced immediately
   *     --tests=test                      advanced ("--do-not-fail-on-empty-…")
   *
   * So an agent naming its tests honestly was wedged at every level above
   * `low`, and one naming a meaningless word walked through — the exact
   * inversion this class exists to prevent, shipped inside the class, with the
   * plan skill's own worked example as the trigger.
   *
   * The old test passed because it handed `ranTests` a fabricated
   * `Drupal\Tests\kchockey\Unit\RinkTest::testItRenders` that production
   * cannot produce. A fixture that invents its input proves nothing about the
   * path it claims to cover.
   *
   * droost records the promise and says plainly that it is not a verification.
   * Making it one needs the phpunit gate to emit JUnit XML and the executor to
   * read class and method names out of it.
   */
  public function testDeclaredTestsAreRecordedNotVerified(): void {

    foreach (['RinkTest::testItRenders', 'RinkTest', 'phpunit', 'test'] as $declared) {
      $audit = new DeclarationAudit([], [$declared], []);
      $check = $this->check($audit, 'declared_tests');

      $this->assertSame(
        CheckState::Recorded,
        $check->state,
        sprintf('"%s" is recorded — no declaration is cheaper than another', $declared),
      );
      $this->assertSame(Fault::None, $check->fault);
      $this->assertStringContainsString(
        $declared,
        $check->summary,
        'and the promise itself is on the record',
      );
    }

    $this->assertSame(
      [],
      (new DeclarationAudit([], ['RinkTest'], []))->missingTests(),
      'nothing is reported missing, because nothing here can tell',
    );
  }

  /**
   * The run's own record is never scope creep.
   *
   * The workflow writes run.json, the evidence store and the spec itself.
   * Blocking a phase because the workflow wrote its own record would be
   * absurd, and a lock file a build legitimately rewrote is the same class.
   */
  public function testTheRunsOwnRecordAndLockFilesAreExempt(): void {
    $audit = new DeclarationAudit(
      ['src/A.php'],
      [],
      [
        'src/A.php',
        'droost/droost-workflow/run.json',
        'droost/droost-workflow/tmp-spec-hh-3.md',
        'composer.lock',
        'package-lock.json',
      ],
    );

    $this->assertSame([], $audit->undeclared());
  }

  /**
   * Installing the product is not undeclared work.
   *
   * The lock files were exempt and their MANIFESTS were not, which made the
   * product's own documented first step — `composer require --dev
   * droost/workflow` — a scope-creep block carrying an AGENT fault, on a file
   * the agent did not choose to write. A walk hit it during ordinary setup.
   *
   * The declaration is the requirement; the manifest is how a package manager
   * records it. And the trees the manifests fill are here too: most
   * repositories gitignore them, and the ones that do not saw every transitive
   * dependency of a single `require` reported as creep.
   */
  public function testDependencyBookkeepingIsNotScopeCreep(): void {
    $audit = new DeclarationAudit(
      ['src/A.php'],
      [],
      [
        'src/A.php',
        'composer.json',
        'composer.lock',
        'package.json',
        'package-lock.json',
        'vendor/droost/workflow/src/WorkflowFacade.php',
        'node_modules/left-pad/index.js',
      ],
    );

    $this->assertSame([], $audit->undeclared());
  }

  /**
   * But a source file under a custom module is still creep.
   *
   * The exemption must stay a list of bookkeeping, not a hole wide enough to
   * park real work in.
   */
  public function testRealWorkIsStillCaught(): void {
    $audit = new DeclarationAudit(
      ['src/A.php'],
      [],
      ['src/A.php', 'composer.json', 'web/modules/custom/acme/src/Sneaky.php'],
    );

    $this->assertSame(
      ['web/modules/custom/acme/src/Sneaky.php'],
      $audit->undeclared(),
    );
  }

  /**
   * Two spellings of the same path compare equal.
   */
  public function testPathSpellingsAreNormalised(): void {
    $audit = new DeclarationAudit(['./src//A.php'], [], ['src/A.php']);

    $this->assertSame([], $audit->undeclared());
    $this->assertSame([], $audit->untouched());
  }

  /**
   * With no tests declared, there is no coverage check at all.
   *
   * An item that cannot fail should not be on the list pretending it passed.
   */
  public function testNoDeclaredTestsMeansNoCoverageCheck(): void {
    $audit = new DeclarationAudit(['src/A.php'], [], ['src/A.php']);

    $this->assertFalse($this->hasCheck($audit, 'declared_tests'));
    $this->assertCount(1, $audit->checks());
  }

  /**
   * A legacy project's own record is never counted as scope creep.
   *
   * `ltrim($path, "./")` strips a character SET, so `.droost-workflow/run.json`
   * became `droost-workflow/run.json` and matched no exemption. Latent until
   * the state-dir fix put the evidence store in that directory — at which point
   * a legacy project's audit would have been blocked by its own database, which
   * is the second time that exact absurdity nearly shipped.
   */
  public function testLegacyStateDirIsExemptFromScopeCreep(): void {
    $audit = new DeclarationAudit(
      ['src'],
      [],
      [
        'src/a.php',
        '.droost-workflow/run.json',
        '.droost-workflow/evidence.sqlite',
        './droost/droost-workflow/run.json',
        'composer.lock',
      ],
    );

    $this->assertSame([], $audit->undeclared());
  }

  /**
   * Whether the audit produced a check by that name at all.
   *
   * Absence is a real verdict and a different one from a NULL state: an item
   * that cannot fail should not be on the list pretending it passed.
   *
   * @param \Droost\Workflow\Evidence\DeclarationAudit $audit
   *   The audit.
   * @param string $name
   *   The check name.
   *
   * @return bool
   *   TRUE when the audit produced it.
   */
  private function hasCheck(DeclarationAudit $audit, string $name): bool {
    foreach ($audit->checks() as $check) {
      if ($check->name === $name) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The kind a contributed check reads is a kind some verb can write.
   *
   * `WorkItemDeclared` — the shipped worked example for contributed checks —
   * blocks a phase when `declared($runId, 'work_item')` is empty, with fault
   * `agent`, whose own guidance reads "There is no waiver for it". Nothing
   * anywhere could write that kind: the declaration loop was hard-coded to
   * `file` and `test`, and `declare-changes` took only `--files`, `--tests` and
   * `--type`.
   *
   * So any site adding the documented `work_item:` block got every run failing
   * at plan, permanently, told to declare a ticket by a tool with no way to
   * declare one — the SpecFreeze deadlock's exact shape, which this very file
   * names in a comment fifty lines away.
   *
   * Asserted as a property, because the next contributed check will read the
   * next kind: every kind any check reads must be writable through the facade.
   */
  public function testEveryDeclarationKindReadByCheckIsWritable(): void {
    $root = sys_get_temp_dir() . '/droost-kinds-' . bin2hex(random_bytes(6));
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);

    $store = new EvidenceStore($root);
    $store->upsertRun('r1', ['preset' => 'medium']);

    // The kinds droost itself reads back anywhere, contributed checks included.
    foreach (['file', 'test', 'work_item'] as $kind) {
      $store->declare('r1', 'plan', $kind, 'value-' . $kind, date('c'));
      $this->assertSame(
        ['value-' . $kind],
        $store->declared('r1', $kind),
        sprintf('"%s" is a kind something can actually declare', $kind),
      );
    }

    exec('rm -rf ' . escapeshellarg($root));
  }

}
