<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Evidence\WorkType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The work type is a declaration that gets audited, never a waiver.
 *
 * The distinction this class has to hold: a type may say which gates must have
 * MEASURED for a kind of work, and may make an empty result expected rather
 * than suspicious — but it may never let a run out of a gate. That is the
 * escape hatch removed after an agent asked to waive the gate holding back a
 * live XSS, and a "run type" that could turn gates off would put it straight
 * back with a friendlier name.
 */
#[CoversClass(WorkType::class)]
#[CoversClass(DeclarationAudit::class)]
final class WorkTypeTest extends TestCase {

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
   * A run whose diff matches its declared type passes both type checks.
   */
  public function testHonestDeclarationPasses(): void {
    $audit = new DeclarationAudit(
      ['config'],
      [],
      ['config/sync/node.type.rink.yml', 'config/sync/views.view.rinks.yml'],
      WorkType::ContentModel,
      ['config_clean', 'rendered_check'],
    );

    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'work_type')->state);
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'type_coverage')->state);
  }

  /**
   * Declaring one kind of work and building another is blocked.
   *
   * The type decides which gates must have measured, so a false declaration
   * means the wrong things were checked — which is a finding about the run,
   * not a style complaint.
   */
  public function testDeclaringContentModelAndWritingPhpIsBlocked(): void {
    $audit = new DeclarationAudit(
      ['web/modules/custom/x'],
      [],
      ['web/modules/custom/x/a.php', 'web/modules/custom/x/B.php', 'web/modules/custom/x/C.php'],
      WorkType::ContentModel,
      ['config_clean', 'rendered_check'],
    );

    $check = $this->check($audit, 'work_type');
    $this->assertSame(CheckState::Blocked, $check->state);
    $this->assertSame(Fault::Agent, $check->fault);
    $this->assertStringContainsString('contradict it', (string) $check->summary);
  }

  /**
   * One file that is the WHOLE diff is not a stray file.
   *
   * The proportion rule required more than one contradicting file, so a run
   * blocked on `type_coverage` could re-declare its single changed `.module`
   * as `docs` and advance past phpcs and phpstan with `work_type` reporting
   * "the diff matches" — and, since the un-asked check is retired to
   * `not_applicable`, no tell left in the record. A reviewer drove exactly
   * that. "One stray file is never a lie" is about a file among others.
   */
  public function testTheWholeDiffContradictingIsNotStray(): void {
    $audit = new DeclarationAudit(
      ['web/modules/custom/demo'],
      [],
      ['web/modules/custom/demo/demo.module'],
      WorkType::Docs,
      [],
    );

    $check = $this->check($audit, 'work_type');
    $this->assertSame(CheckState::Blocked, $check->state, 'PHP declared as documentation, and nothing else in the diff');
    $this->assertSame(Fault::Agent, $check->fault);
    $this->assertStringContainsString('1 of 1 changed file(s) contradict it', (string) $check->summary);

    // Two files, both PHP, declared docs: the same lie, twice.
    $both = new DeclarationAudit(
      ['web/modules/custom/demo'],
      [],
      ['web/modules/custom/demo/demo.module', 'web/modules/custom/demo/demo.install'],
      WorkType::Docs,
      [],
    );
    $this->assertSame(CheckState::Blocked, $this->check($both, 'work_type')->state);
  }

  /**
   * One stray file is not a false declaration.
   *
   * A content-model ticket that also touches a `.theme` to register its display
   * is doing the obvious thing. A rule that blocked on it would teach agents to
   * declare `mixed` for everything, which is the same as declaring nothing.
   */
  public function testOneStrayFileDoesNotBlock(): void {
    $audit = new DeclarationAudit(
      ['config', 'web/themes/custom/x'],
      [],
      [
        'config/sync/node.type.rink.yml',
        'config/sync/core.entity_view_display.node.rink.default.yml',
        'web/themes/custom/x/x.theme',
      ],
      WorkType::ContentModel,
      ['config_clean', 'rendered_check'],
    );

    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'work_type')->state);
  }

  /**
   * A gate the type rests on that measured nothing blocks the phase.
   *
   * "Passed" and "looked at something" are different claims, and for the gate a
   * ticket rests on, only the second one counts. A config_clean that passed
   * over an empty export has not checked the content model it was pointed at.
   */
  public function testGateThatMeasuredNothingBlocksItsType(): void {
    $audit = new DeclarationAudit(
      ['config'],
      [],
      ['config/sync/a.yml'],
      WorkType::ContentModel,
      ['rendered_check'],
    );

    $check = $this->check($audit, 'type_coverage');
    $this->assertSame(CheckState::Blocked, $check->state);
    $this->assertStringContainsString('config_clean', (string) $check->summary);
    $this->assertStringContainsString('without examining anything', (string) $check->summary);
    // ENVIRONMENT, with a remedy. A gate that ran and examined nothing is
    // describing its own configuration, and an agent fault here says "this is
    // the work, not the setup" about a lever the agent may not even edit.
    $this->assertSame(Fault::Environment, $check->fault);
    $this->assertStringContainsString('paths', (string) $check->remedy);
  }

  /**
   * Declaring no type leaves both checks absent, not passing.
   *
   * A run that predates the flag, or one whose ticket does not fit a type, must
   * not acquire two green checks it never earned.
   */
  public function testNoTypeMeansNoTypeChecks(): void {
    $audit = new DeclarationAudit(['src'], [], ['src/a.php']);

    $this->assertFalse($this->hasCheck($audit, 'work_type'));
    $this->assertFalse($this->hasCheck($audit, 'type_coverage'));
  }

  /**
   * The `docs` type rests on no gate, and still cannot dodge one.
   *
   * The type with the emptiest `mustMeasure()` is the one an agent would reach
   * for to escape. It produces no coverage check — and it removes nothing: the
   * mandatory trio is decided by the preset, which this never touches.
   */
  public function testDocsRestsOnNothingAndRemovesNothing(): void {
    $this->assertSame([], WorkType::Docs->mustMeasure());

    $audit = new DeclarationAudit(['docs'], [], ['docs/a.md'], WorkType::Docs, []);

    $this->assertFalse(
      $this->hasCheck($audit, 'type_coverage'),
      'nothing to cover means no check, not a free pass',
    );
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'work_type')->state);
  }

  /**
   * Declaring `docs` over a diff full of PHP is caught.
   *
   * The actual escape attempt, and the reason the declaration is audited.
   */
  public function testDeclaringDocsOverCodeIsCaught(): void {
    $audit = new DeclarationAudit(
      ['.'],
      [],
      ['web/modules/custom/x/a.php', 'web/modules/custom/x/b.module', 'docs/readme.md'],
      WorkType::Docs,
      [],
    );

    $this->assertSame(CheckState::Blocked, $this->check($audit, 'work_type')->state);
  }

  /**
   * Every type names its gates and its label.
   */
  public function testEveryTypeIsFullyDescribed(): void {
    foreach (WorkType::cases() as $type) {
      $this->assertNotSame('', $type->label(), $type->value . ' has a label');
      // contradictions() may legitimately be empty: a broad type cannot be
      // contradicted, and pretending otherwise is what punished honesty.
      $this->assertIsArray($type->contradictions(), $type->value . ' answers what contradicts it');
    }
    $this->assertSame(
      ['code', 'content_model', 'theme', 'content', 'docs', 'mixed'],
      WorkType::names(),
    );
  }

  /**
   * Whether the audit produced a check by that name at all.
   *
   * Absence is a real verdict here and a different one from a NULL state: a
   * run that declared no type must acquire no type checks, rather than two
   * green ones nobody earned.
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
   * A waived gate does not then block on having measured nothing.
   *
   * The operator's waiver is the documented way out of a stuck run: a gate
   * blocks, the operator lifts it, the run continues. But a waived gate is
   * `Unblocked` — not measured, not off by level, not skipped by surface — and
   * it sat in `mustMeasure()` and in none of the exemptions. So lifting the
   * gate to free a run immediately blocked it again on `type_coverage`, and the
   * only rescue mechanism the system has created the next wall.
   *
   * Three ways a gate can fail to show a measurement, all of them found one at
   * a time, none of them anything the agent chose: the level turned it off, the
   * surface could not run it, or the operator lifted it.
   */
  public function testWaivedGateDoesNotBlockCoverage(): void {
    $audit = new DeclarationAudit(
      ['src'],
      [],
      ['src/a.php'],
      WorkType::Code,
      ['phpcs', 'phpstan'],
      // phpunit: waived by the operator, so unmeasurable through no choice of
      // the agent's.
      ['phpunit'],
    );

    $states = [];
    foreach ($audit->checks('test') as $check) {
      if ($check->name === 'type_coverage') {
        $states[] = $check->state->value;
      }
    }

    // NOT which word, but whether it holds the phase. At `test` the only gate
    // `code` work rests on that RUNS there is phpunit, and the operator lifted
    // it — so the honest answer is that nothing at this phase can speak for the
    // type, which is `not_applicable`, not a pass nobody earned. What the test
    // is named for is that the rescue rescues, and that is what it asserts.
    $this->assertCount(1, $states, 'exactly one verdict, not a pair that argue');
    $this->assertFalse(
      CheckState::from($states[0])->blocksAdvance(),
      'the rescue rescues, rather than producing the next block',
    );
  }

}
