<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Evidence\CheckAdjudicatorInterface;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Evidence\UnreachableChecks;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * A contributed check actually reaches a run, and actually holds a phase.
 *
 * This exists because without it the whole subsystem was dead code and the
 * suite was green. `CheckCatalog`, `DroostCheckPluginManager`, the
 * `#[DroostCheck]` attribute, `CheckProviderBase`, a worked example and a
 * contributor guide all shipped, all unit-tested, all registered as services —
 * and nothing anywhere called `adjudicate()`. A site could write a perfect
 * check, install it, watch it never run, and read a green report.
 *
 * That is the same shape as every serious defect found this week: units that
 * each pass their own test, with nothing driving the seam between them. So the
 * assertions here are deliberately about the SEAM — a run advancing or not —
 * and never about the catalog in isolation, which was already green while
 * broken.
 */
final class ContributedCheckIntegrationTest extends WorkflowTestCase {

  use ReadsTheStore;

  /**
   * An adjudicator that returns whatever it was handed.
   *
   * @param list<\Droost\Workflow\Evidence\CheckRecord> $records
   *   What to return from every phase.
   * @param array<string, int> $calls
   *   Filled with a per-phase call count, by reference.
   *
   * @return \Droost\Workflow\Evidence\CheckAdjudicatorInterface
   *   The adjudicator.
   */
  private function adjudicator(array $records, array &$calls): CheckAdjudicatorInterface {
    return new class($records, $calls) implements CheckAdjudicatorInterface {

      /**
       * Constructs the adjudicator.
       *
       * @param list<\Droost\Workflow\Evidence\CheckRecord> $records
       *   The verdicts to return.
       * @param array<string, int> $calls
       *   The call log.
       */
      public function __construct(
        private readonly array $records,
        private array &$calls,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function adjudicate(string $projectRoot, string $phase, string $runId): array {
        $this->calls[$phase] = ($this->calls[$phase] ?? 0) + 1;

        return $this->records;
      }

    };
  }

  /**
   * The facade under test, with every gate passing.
   *
   * @param \Droost\Workflow\Evidence\CheckAdjudicatorInterface|null $checks
   *   The adjudicator, or NULL for a site contributing none.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(?CheckAdjudicatorInterface $checks): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };

    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-13T00:00:00+00:00',
      static fn (): string => 'run-checks',
      NULL,
      NULL,
      NULL,
      NULL,
      $checks,
    );
  }

  /**
   * A blocked contributed check stops a phase whose every gate passed.
   *
   * The assertion that matters. Every gate green, and the run does not advance
   * — which is the entire proposition of contributing a check, and was false.
   */
  public function testBlockedCheckStopsPhaseWithEveryGateGreen(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);
    $calls = [];

    $outcome = $this->facade($this->adjudicator([
      new CheckRecord(
        'check', 'jira_transitioned', CheckState::Blocked, Fault::Environment,
        'PROJ-354 is still In Progress',
        'drush droost:jira:transition PROJ-354 "In Review"',
      ),
    ], $calls))->run($root, $spec);

    // BLOCKED, not failed: a contributed check costs no retry budget and the
    // agent may fix and return. `failed` meant "the budget is spent or this is
    // terminal", which the README answers with `reset` — so an agent reading
    // its own envelope threw away a run one correction would have freed.
    $this->assertSame(Outcome::Blocked, $outcome->outcome, 'a blocked check holds the phase');
    $this->assertSame(['plan' => 1], $calls, 'and it was asked exactly once, at plan');
  }

  /**
   * The same run advances when the same check is satisfied.
   *
   * The other half of the pair: a test that only showed blocking would also
   * pass if checks blocked unconditionally.
   */
  public function testSatisfiedCheckLetsThePhaseAdvance(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);
    $calls = [];

    $outcome = $this->facade($this->adjudicator([
      new CheckRecord(
        'check', 'jira_transitioned', CheckState::Satisfied, Fault::None, 'PROJ-354 is In Review',
      ),
    ], $calls))->run($root, $spec);

    $this->assertSame(Outcome::Advanced, $outcome->outcome);
    $this->assertSame(['plan' => 1], $calls);
  }

  /**
   * Every verdict is recorded, attributed, and queryable — not just failures.
   *
   * A report that shows only what blocked cannot answer "has this provider
   * ever actually run", which is the first question anybody asks of a check
   * somebody else contributed.
   */
  public function testEveryVerdictLandsInTheStore(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);
    $calls = [];

    $this->facade($this->adjudicator([
      new CheckRecord('check', 'work_item', CheckState::Satisfied, Fault::None, 'PROJ-354'),
      new CheckRecord('check', 'notes_written', CheckState::Satisfied, Fault::None, 'notes present'),
    ], $calls))->run($root, $spec);

    $rows = $this->storeRows(
      (new EvidenceStore($root))->connection(),
      "SELECT name, state FROM check_result WHERE kind = 'check' ORDER BY name",
    );

    $this->assertCount(2, $rows, 'a satisfied check is evidence too');
    $this->assertSame('notes_written', $rows[0]['name']);
    $this->assertSame('work_item', $rows[1]['name']);
  }

  /**
   * A throwing adjudicator stops the phase without taking the run down.
   *
   * A contributed module's bug must not wedge somebody else's run, and must not
   * read as the agent's fault — but it cannot pass either, because a check that
   * could not run has not passed.
   */
  public function testThrowingAdjudicatorBlocksWithoutCrashing(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);

    $exploding = new class() implements CheckAdjudicatorInterface {

      /**
       * {@inheritdoc}
       */
      public function adjudicate(string $projectRoot, string $phase, string $runId): array {
        throw new \RuntimeException('the provider is broken');
      }

    };

    $outcome = $this->facade($exploding)->run($root, $spec);

    $this->assertSame(Outcome::Blocked, $outcome->outcome, 'the phase stops, recoverably');

    $rows = $this->storeRows(
      (new EvidenceStore($root))->connection(),
      "SELECT name, state, fault, remedy FROM check_result WHERE kind = 'check'",
    );
    $this->assertCount(1, $rows);
    $this->assertSame('blocked', $rows[0]['state']);
    $this->assertSame(
      'environment',
      $rows[0]['fault'],
      'somebody else\'s broken plugin is not the agent\'s fault',
    );
    $this->assertIsString($rows[0]['remedy']);
    $this->assertNotSame('', $rows[0]['remedy'], 'and an environment fault names a way out');
  }

  /**
   * A site contributing no checks is unaffected.
   *
   * The default has to cost nothing: most projects contribute none, and this is
   * the path every existing test and every existing install takes.
   */
  public function testNoAdjudicatorChangesNothing(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);

    $this->assertSame(Outcome::Advanced, $this->facade(NULL)->run($root, $spec)->outcome);

    $this->assertSame(
      0,
      $this->storeCount(
        (new EvidenceStore($root))->connection(),
        "SELECT COUNT(*) FROM check_result WHERE kind = 'check'",
      ),
      'and contributes no rows nobody earned',
    );
  }

  /**
   * A siteless surface says it could not ask, instead of saying nothing.
   *
   * The standalone binary boots no Drupal, so it cannot instantiate a
   * `#[DroostCheck]` plugin. That is a real limitation, not a defect — but it
   * recorded NOTHING, and a run with no check rows reads downstream exactly
   * like a run that was asked and had nothing to answer.
   *
   * A reviewer drove two byte-identical projects, one through each door: drush
   * blocked at plan on a missing work item, and the binary advanced through
   * plan, code and test with zero check rows. The hole closed only if some
   * later phase happened to use a different door. `WorkflowFacadeTrait` states
   * the rule this broke — a rule enforced through one surface is a rule an
   * agent evades through another — for the surface that had already been fixed.
   *
   * `Skipped` is the state for "asked for, could not run here", which is
   * exactly this: non-blocking, not a measurement, and visible.
   */
  public function testSitelessSurfaceRecordsThatItCouldNotAsk(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);
    mkdir($root . '/vendor/bin', 0775, TRUE);
    // A site: the same probe that resolves the contributed GATE catalog, so
    // the two answers cannot disagree about whether one exists.
    file_put_contents($root . '/vendor/bin/drush', "#!/bin/sh\nexit 1\n");

    $facade = $this->facade(new UnreachableChecks(TRUE));
    $this->assertSame(Outcome::Advanced, $facade->run($root, $spec)->outcome, 'it does not block');

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $rows = (new EvidenceStore($root))->checklist($state->runId, 'plan');

    $found = NULL;
    foreach ($rows as $row) {
      if (($row['name'] ?? '') === 'contributed_checks') {
        $found = $row;
      }
    }
    $this->assertIsArray($found, 'the gap is on the record');
    $this->assertSame('skipped', $found['state']);
    $this->assertIsString($found['summary']);
    $this->assertStringContainsString('cannot ask', $found['summary']);
  }

  /**
   * A project with no site records nothing, because there is nothing to miss.
   *
   * A row on every run of every plain PHP repository would be the noise that
   * teaches people to skim the one line that mattered.
   */
  public function testPlainRepositoryRecordsNoSuchGap(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);

    $facade = $this->facade(new UnreachableChecks(FALSE));
    $facade->run($root, $spec);

    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    foreach ((new EvidenceStore($root))->checklist($state->runId, 'plan') as $row) {
      $this->assertNotSame('contributed_checks', $row['name'] ?? '');
    }
  }

  /**
   * A provider that stops answering does not leave its block standing.
   *
   * The same rule the declaration audit needed, on the other adjudicator. A
   * contributed check that stops being emitted — its module uninstalled, its
   * provider deciding the run no longer applies — kept its last `blocked` row
   * as the record's current verdict, and nothing can clear a check nobody
   * asks. The engine would then advance while the store said blocked, and the
   * stop hook would refuse on a row no fresh check could answer.
   *
   * `CheckProviderBase`'s own contract makes this reachable by design: "NULL
   * when it does not apply. A Jira check on a site with no Jira records
   * nothing."
   */
  public function testProvidersThatStopAnsweringLeaveNoBlockStanding(): void {
    $root = $this->makeRoot();
    $spec = $this->writeSpec($root);
    $calls = [];

    $held = $this->facade($this->adjudicator([
      new CheckRecord(
        'check', 'jira_transitioned', CheckState::Blocked, Fault::Environment,
        'PROJ-354 is still In Progress',
        'drush droost:jira:transition PROJ-354 "In Review"',
      ),
    ], $calls))->run($root, $spec);
    $this->assertSame(Outcome::Blocked, $held->outcome, 'the provider holds the phase');

    $store = new EvidenceStore($root);
    $this->assertCount(
      1,
      $store->unresolved($held->state->runId, 'plan'),
      'and the record says which check',
    );

    // The provider now answers for nothing — the module is gone, or the run
    // stopped applying to it.
    $this->facade($this->adjudicator([], $calls))->run($root, $spec);

    $this->assertSame(
      [],
      (new EvidenceStore($root))->unresolved($held->state->runId, 'plan'),
      'a check nobody asks holds nothing, and the record agrees with the engine',
    );
  }

}
