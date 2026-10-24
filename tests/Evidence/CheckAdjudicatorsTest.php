<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckAdjudicatorInterface;
use Droost\Workflow\Evidence\CheckAdjudicators;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Knowledge\CitationResolver;
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
   * A site check's Blocked verdict is never hidden by a namesake (S4-7).
   */
  public function testSameNamedSiteVerdictsAreAllKept(): void {
    $one = $this->returning([new CheckRecord('check', 'ticket', CheckState::Satisfied, Fault::None, 'plugin A')]);
    $two = $this->returning([new CheckRecord('check', 'ticket', CheckState::Blocked, Fault::Agent, 'plugin B')]);

    $records = (new CheckAdjudicators($one, $two))->adjudicate('/nowhere', 'plan', 'run-1');

    $this->assertSame(['plugin A', 'plugin B'], array_map(static fn (CheckRecord $r): string => $r->summary, $records));
  }

  /**
   * The library's own check, throwing, is recorded under its name (S4-8).
   */
  public function testThrowingLibraryCheckKeepsItsName(): void {
    $root = sys_get_temp_dir() . '/dwf-throw-' . bin2hex(random_bytes(4));
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    mkdir($root . '/vendor/bin', 0755, TRUE);
    file_put_contents($root . '/vendor/bin/droost', "#!/usr/bin/env php\n");
    file_put_contents($root . '/droost/droost-workflow/spec-x.md', "# Spec\n\n## Grounding\n\n| Phase | Tier | Asked | Found | Evidence |\n|---|---|---|---|---|\n| plan | custom | a | b | x.php |\n");
    $broken = new class() extends CitationResolver {

      /**
       * {@inheritdoc}
       */
      public function citations(string $projectRoot, string $spec, string $docroot = ''): array {
        throw new \RuntimeException('the index fell over');
      }

    };

    $records = (new CheckAdjudicators(new PlanGroundedCheck($broken)))->adjudicate($root, 'plan', 'run-1');
    exec('rm -rf ' . escapeshellarg($root));

    $this->assertCount(1, $records);
    $this->assertSame(PlanGroundedCheck::NAME, $records[0]->name);
    $this->assertSame([CheckState::Blocked, Fault::Unknown], [$records[0]->state, $records[0]->fault], 'no operator lift for a check that measured nothing');
    $this->assertStringContainsString('the index fell over', $records[0]->summary);
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
