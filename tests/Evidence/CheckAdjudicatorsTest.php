<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckAdjudicatorInterface;
use Droost\Workflow\Evidence\CheckAdjudicators;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Knowledge\PlanGroundedCheck;
use PHPUnit\Framework\TestCase;

/**
 * Several adjudicators asked as one: a name answered once, a throw contained.
 */
final class CheckAdjudicatorsTest extends TestCase {

  /**
   * The first to answer a check wins; one that throws does not stop the rest.
   */
  public function testFirstAnswerWinsAndThrowIsContained(): void {
    $first = $this->returning([new CheckRecord('check', 'plan_grounded', CheckState::Satisfied, Fault::None, 'library')]);
    $throws = new class() implements CheckAdjudicatorInterface {

      /**
       * {@inheritdoc}
       */
      public function adjudicate(string $projectRoot, string $phase, string $runId): array {
        throw new \RuntimeException('a broken module');
      }

    };
    $site = $this->returning([
      new CheckRecord('check', 'plan_grounded', CheckState::Blocked, Fault::Agent, 'site, now a duplicate'),
      new CheckRecord('check', 'work_item', CheckState::Satisfied, Fault::None, 'PROJ-1'),
    ]);

    $records = (new CheckAdjudicators($first, $throws, $site))->adjudicate('/nowhere', 'plan', 'run-1');

    $this->assertSame(['plan_grounded', 'contributed_checks', 'work_item'], array_map(static fn (CheckRecord $r): string => $r->name, $records));
    $this->assertSame('library', $records[0]->summary, 'the library\'s verdict, not the site\'s duplicate');
    $this->assertSame([CheckState::Blocked, Fault::Environment], [$records[1]->state, $records[1]->fault]);
  }

  /**
   * The doors' adjudicator asks the library's checks first.
   */
  public function testTheLibraryChecksComeFirst(): void {
    $site = $this->returning([new CheckRecord('check', 'plan_grounded', CheckState::Blocked, Fault::Agent, 'site')]);

    // A plan with no spec: the library records nothing, so the site's stands.
    $records = CheckAdjudicators::withLibraryChecks($site)->adjudicate(sys_get_temp_dir() . '/dwf-none-' . bin2hex(random_bytes(4)), 'plan', 'run-1');

    $this->assertSame(['site'], array_map(static fn (CheckRecord $r): string => $r->summary, $records));
    $this->assertSame(PlanGroundedCheck::NAME, $records[0]->name);
  }

  /**
   * An adjudicator returning what it was given.
   *
   * @param list<\Droost\Workflow\Evidence\CheckRecord> $records
   *   The records.
   *
   * @return \Droost\Workflow\Evidence\CheckAdjudicatorInterface
   *   The adjudicator.
   */
  private function returning(array $records): CheckAdjudicatorInterface {
    return new class($records) implements CheckAdjudicatorInterface {

      /**
       * Constructs the adjudicator.
       *
       * @param list<\Droost\Workflow\Evidence\CheckRecord> $records
       *   The records.
       */
      public function __construct(private readonly array $records) {}

      /**
       * {@inheritdoc}
       */
      public function adjudicate(string $projectRoot, string $phase, string $runId): array {
        return $this->records;
      }

    };
  }

}
