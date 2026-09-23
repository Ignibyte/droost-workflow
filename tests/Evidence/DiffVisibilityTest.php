<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\WorkType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An empty diff means "nothing changed" only when there was a repository.
 *
 * `VcsInterface::changedFiles()` returns the same empty list for "nothing
 * changed" and for "there is no repository to ask", and the audit read both as
 * the first. On ddev with mutagen, `/.git` is not synced into the container
 * where the gates run, so every ddev subject was the second case (F-36). P6 run
 * 1's record said "3 declared and not touched" about a module, 22 config files
 * and a spec the agent had just written: a green that could not have failed.
 */
#[CoversClass(DeclarationAudit::class)]
final class DiffVisibilityTest extends TestCase {

  /**
   * Without a repository, both diff audits say they could not look.
   */
  public function testNoRepositoryMeansNotMeasuredRatherThanSatisfied(): void {
    $blind = new DeclarationAudit(['web/modules/custom/example_directory', 'config/sync'], [], [], WorkType::Mixed, [], [], NULL, FALSE);

    $files = $this->check($blind, 'declared_files');
    $this->assertSame(CheckState::Skipped, $files->state, 'skipped: asked for, and could not run here');
    $this->assertStringContainsString('NOT MEASURED', (string) $files->summary);
    $this->assertStringContainsString('/.git', (string) $files->summary, 'and it says where to look');
    $this->assertSame(CheckState::Skipped, $this->check($blind, 'work_type')->state);
  }

  /**
   * With a repository, an empty diff is still the honest "nothing changed".
   */
  public function testEmptyDiffWithRepositoryIsStillSatisfied(): void {
    $seen = new DeclarationAudit(['web/modules/custom/example_directory'], [], [], WorkType::Mixed, [], []);

    $files = $this->check($seen, 'declared_files');
    $this->assertSame(CheckState::Satisfied, $files->state);
    $this->assertStringContainsString('declared and not touched', (string) $files->summary);
  }

  /**
   * One check from an audit's code-phase answer, by name.
   *
   * @param \Droost\Workflow\Evidence\DeclarationAudit $audit
   *   The audit.
   * @param string $name
   *   The check name.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord
   *   The check.
   */
  private function check(DeclarationAudit $audit, string $name): CheckRecord {
    foreach ($audit->checks('code') as $check) {
      if ($check->name === $name) {
        return $check;
      }
    }
    $this->fail('no ' . $name . ' check in the code phase');
  }

}
