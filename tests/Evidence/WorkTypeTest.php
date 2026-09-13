<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

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
   * @return \Droost\Workflow\Evidence\CheckRecord|null
   *   The check, or NULL when the audit produced none by that name.
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
   * A run whose diff matches its declared type passes both type checks.
   */
  public function testHonestDeclarationPasses(): void {
    $audit = new DeclarationAudit(
      ['config'],
      [],
      ['config/sync/node.type.rink.yml', 'config/sync/views.view.rinks.yml'],
      [],
      WorkType::ContentModel,
      ['config_clean', 'rendered_check'],
    );

    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'work_type')?->state);
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'type_coverage')?->state);
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
      [],
      WorkType::ContentModel,
      ['config_clean', 'rendered_check'],
    );

    $check = $this->check($audit, 'work_type');
    $this->assertSame(CheckState::Blocked, $check?->state);
    $this->assertSame(Fault::Agent, $check?->fault);
    $this->assertStringContainsString('not that kind of work', (string) $check?->summary);
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
      [],
      WorkType::ContentModel,
      ['config_clean', 'rendered_check'],
    );

    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'work_type')?->state);
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
      [],
      WorkType::ContentModel,
      ['rendered_check'],
    );

    $check = $this->check($audit, 'type_coverage');
    $this->assertSame(CheckState::Blocked, $check?->state);
    $this->assertStringContainsString('config_clean', (string) $check?->summary);
    $this->assertStringContainsString('measured nothing', (string) $check?->summary);
  }

  /**
   * Declaring no type leaves both checks absent, not passing.
   *
   * A run that predates the flag, or one whose ticket does not fit a type, must
   * not acquire two green checks it never earned.
   */
  public function testNoTypeMeansNoTypeChecks(): void {
    $audit = new DeclarationAudit(['src'], [], ['src/a.php']);

    $this->assertNull($this->check($audit, 'work_type'));
    $this->assertNull($this->check($audit, 'type_coverage'));
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

    $audit = new DeclarationAudit(['docs'], [], ['docs/a.md'], [], WorkType::Docs, []);

    $this->assertNull($this->check($audit, 'type_coverage'), 'nothing to cover means no check, not a free pass');
    $this->assertSame(CheckState::Satisfied, $this->check($audit, 'work_type')?->state);
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
      [],
      WorkType::Docs,
      [],
    );

    $this->assertSame(CheckState::Blocked, $this->check($audit, 'work_type')?->state);
  }

  /**
   * Every type names its gates and its label.
   */
  public function testEveryTypeIsFullyDescribed(): void {
    foreach (WorkType::cases() as $type) {
      $this->assertNotSame('', $type->label(), $type->value . ' has a label');
      $this->assertNotSame([], $type->expects(), $type->value . ' says what it expects');
    }
    $this->assertSame(
      ['code', 'content_model', 'theme', 'content', 'docs', 'mixed'],
      WorkType::names(),
    );
  }

}
