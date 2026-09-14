<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Evidence\WorkType;
use PHPUnit\Framework\TestCase;

/**
 * A question is asked where its answer can still change.
 *
 * `type_coverage` asked whether phpcs, phpstan and phpunit had each measured
 * something, and it asked at TEST. phpcs and phpstan do not run at test. So a
 * `paths` lever pointing at a directory that does not exist — a typo, `docroot`
 * where the project says `web`, a tree not created yet — produced this, one
 * phase after the last moment anything could change the answer:
 *
 *   blocked  type_coverage  agent  "phpcs, phpstan measured nothing this run"
 *   remedy:  ""
 *   guidance: "This is the work, not the setup: fix the cause and re-run.
 *              There is no waiver for it."
 *
 * Every clause of which was false or useless. The cause was the lever file,
 * which the guard refuses during a run and which is frozen for the run anyway;
 * nothing inside `test` can make a code-phase gate measure; the mandatory trio
 * genuinely carries no waiver; and a `Blocked` outcome spends no budget, so the
 * phase never died on its own either. One reviewer drove fifty-six identical
 * invocations to be sure. The only exit was `reset --force`, which throws the
 * plan and the code away, and nothing anywhere mentioned it.
 *
 * Two reviewers reached it independently, one of them by running a real ticket
 * through real tools from `init` defaults — which is to say a first-time user
 * would have reached it too.
 */
final class UnwedgeableRunTest extends TestCase {

  /**
   * Every mandatory gate is asked about at a phase that runs it.
   *
   * The structural version of the bug, stated so it cannot come back by a
   * different route: if a check can name a gate, the phase it names it at has
   * to be one where that gate had a chance to speak.
   */
  public function testEveryGateIsAskedAboutWhereItRuns(): void {
    foreach (WorkType::cases() as $type) {
      foreach (['code', 'test'] as $phase) {
        $audit = new DeclarationAudit(['src'], [], ['src/a.php'], $type, [], []);
        foreach ($audit->checks($phase) as $check) {
          if ($check->name !== 'type_coverage' || $check->state !== CheckState::Blocked) {
            continue;
          }
          foreach ($type->mustMeasure() as $gate) {
            if (!str_contains((string) $check->summary, $gate)) {
              continue;
            }
            $this->assertContains(
              $gate,
              PhaseGateMap::DEFAULT[$phase],
              sprintf(
                '%s is named as unmeasured at "%s", a phase that never runs it — so nothing '
                . 'done in that phase could clear the block',
                $gate,
                $phase,
              ),
            );
          }
        }
      }
    }
  }

  /**
   * The block that the reviewers wedged on now lands where it can be acted on.
   */
  public function testTheCodeGatesAreAnsweredAtCode(): void {
    // Phpcs and phpstan ran and examined nothing; phpunit has not run at all,
    // because this is the code phase.
    $audit = new DeclarationAudit(['src'], [], ['src/Money.php'], WorkType::Code, [], []);

    $check = NULL;
    foreach ($audit->checks('code') as $row) {
      if ($row->name === 'type_coverage') {
        $check = $row;
      }
    }
    $this->assertNotNull($check, 'code answers for its own gates');
    $this->assertSame(CheckState::Blocked, $check->state);
    $this->assertStringContainsString('phpcs', (string) $check->summary);
    $this->assertStringContainsString('phpstan', (string) $check->summary);
    $this->assertStringNotContainsString(
      'phpunit',
      (string) $check->summary,
      'and not for a gate that has not had its turn',
    );
  }

  /**
   * A hollow gate is an environment fault, with a way out.
   *
   * `Fault::Agent` means `operatorMayUnblock()` is FALSE and no waiver exists —
   * which is correct for work somebody simply has to do, and wrong for a tool
   * pointed at the wrong directory. The remedy was also the empty string, so
   * the block handed back the generic guidance line and nothing else.
   */
  public function testHollowGatesCarryAnEnvironmentFaultAndRemedy(): void {
    $audit = new DeclarationAudit(['src'], [], ['src/Money.php'], WorkType::Code, [], []);

    foreach ($audit->checks('code') as $check) {
      if ($check->name !== 'type_coverage') {
        continue;
      }
      $this->assertSame(Fault::Environment, $check->fault);
      $this->assertTrue(
        $check->fault->operatorMayUnblock(),
        'there is a recorded way out, which is the difference between a block and a wedge',
      );
      $remedy = (string) $check->remedy;
      $this->assertStringContainsString('paths', $remedy, 'and it names the lever');
      $this->assertStringContainsString('declare-changes', $remedy, 'and the other answer');
    }
  }

  /**
   * A gate that measured is not re-asked, and a phase can still pass.
   *
   * The counterweight: moving the question earlier must not make it
   * unanswerable in the other direction.
   */
  public function testMeasuringGatesSatisfyTheirPhase(): void {
    $atCode = new DeclarationAudit(
      ['src'], [], ['src/Money.php'], WorkType::Code, ['phpcs', 'phpstan'], [],
    );
    $atTest = new DeclarationAudit(
      ['src'], [], ['src/Money.php'], WorkType::Code, ['phpcs', 'phpstan', 'phpunit'], [],
    );

    foreach ([['code', $atCode], ['test', $atTest]] as [$phase, $audit]) {
      foreach ($audit->checks($phase) as $check) {
        if ($check->name === 'type_coverage') {
          $this->assertSame(
            CheckState::Satisfied,
            $check->state,
            $phase . ' passes when the gates it runs have measured',
          );
        }
      }
    }
  }

}
