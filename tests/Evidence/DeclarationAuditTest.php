<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

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
   * @return \Droost\Workflow\Evidence\CheckRecord|null
   *   The check.
   */
  private function check(DeclarationAudit $audit, string $name) {
    foreach ($audit->checks() as $check) {
      if ($check->name === $name) {
        return $check;
      }
    }

    return NULL;
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
    $this->assertSame(CheckState::Blocked, $check?->state);
    $this->assertSame(Fault::Agent, $check?->fault);
    $this->assertStringContainsString('sneaky.module', (string) $check?->summary);
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
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'declared_files')?->state);
  }

  /**
   * A plan that shrank is recorded and does not block.
   */
  public function testDeclaredButUntouchedIsRecordedNotBlocked(): void {
    $audit = new DeclarationAudit(['src/A.php', 'src/B.php'], [], ['src/A.php']);

    $this->assertSame(['src/B.php'], $audit->untouched());
    $check = $this->check($audit, 'declared_files');
    $this->assertSame(CheckState::Satisfied, $check?->state);
    $this->assertStringContainsString('src/B.php', (string) $check?->summary, 'recorded, so a reader can see the plan moved');
  }

  /**
   * A promised test that never ran blocks.
   *
   * "I will cover this" is a promise about verification, and dropping it
   * silently is how a green arrives over untested code.
   */
  public function testPlannedTestThatNeverRanBlocks(): void {
    $audit = new DeclarationAudit([], ['RinkTest'], [], []);

    $this->assertSame(['RinkTest'], $audit->missingTests());
    $check = $this->check($audit, 'declared_tests');
    $this->assertSame(CheckState::Blocked, $check?->state);
    $this->assertSame(Fault::Agent, $check?->fault);
  }

  /**
   * A promised test found anywhere in what ran is satisfied.
   */
  public function testPlannedTestThatRanIsSatisfied(): void {
    $audit = new DeclarationAudit(
      [],
      ['RinkTest'],
      [],
      ['Drupal\Tests\kchockey\Unit\RinkTest::testItRenders'],
    );

    $this->assertSame([], $audit->missingTests());
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'declared_tests')?->state);
  }

  /**
   * The run's own record is never scope creep.
   *
   * The workflow writes run.json, the evidence store and the spec itself; blocking a phase because the workflow wrote its own record would
   * be absurd, and a lock file a build legitimately rewrote is the same class.
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

    $this->assertNull($this->check($audit, 'declared_tests'));
    $this->assertCount(1, $audit->checks());
  }

}
