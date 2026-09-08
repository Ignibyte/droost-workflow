<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\ContributedGate;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\WorkflowFacade;

/**
 * Every door, the same gates: a run begun blind to the catalog catches up.
 *
 * R31-F5 (the redo of the first live D72 round): the subject began its run
 * with the standalone binary ON THE HOST, where drush cannot reach the site,
 * so the resolver answered "unresolved" and the run froze the lever file's
 * gates only — honestly labelled, and still a run the site's declared gate
 * never joined. The first surface that can see the catalog now weaves the
 * missing gates in, on record, and they run from that phase on.
 */
final class WorkflowFacadeLateWeaveTest extends WorkflowTestCase {

  /**
   * Gate names each executor was asked to run, by surface label.
   *
   * @var array<string, list<string>>
   */
  private array $executed = [];

  /**
   * Blind begin, seeing advance: the gate joins, runs, and is recorded.
   */
  public function testSeeingSurfaceWeavesWhatBlindBeginLeftOut(): void {
    // `low`: no seeker checkpoint, so a phase whose gates pass advances at
    // once and the weave can be followed across three phases. Contributed
    // gates are not moved by the dial, so the level is immaterial to them.
    $root = $this->makeRootWithConfig("preset: low\n");
    $snyk = new ContributedGate('snyk', 'droost_snyk', ['code', 'test'], 'snyk test', 'report', 'exit 0: clean; exit 1: findings.');
    $blind = $this->facade('blind', NULL, 'unresolved — no drush reachable from this surface');
    $seeing = $this->facade('seeing', [$snyk], 'the booted site (GateCatalog)');

    // Begin blind: plan has no gates, so the run advances to code.
    $blind->run($root);
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertSame('code', $state->currentPhase?->value);
    $this->assertArrayNotHasKey('module:snyk', $state->resolvedGates, 'the blind surface froze the lever file\'s gates only');
    $this->assertSame('unresolved — no drush reachable from this surface', $state->contributedSource, 'and the record says so');
    $this->assertSame([], $state->lateWoven);

    // Advance from a surface that sees the catalog: the gate joins at code.
    $outcome = $seeing->run($root);
    $state = $outcome->state;
    $this->assertArrayHasKey('module:snyk', $state->resolvedGates);
    $this->assertSame('droost_snyk', $state->resolvedGates['module:snyk']['provider']);
    $this->assertSame('report', $state->resolvedGates['module:snyk']['mode']);
    $this->assertSame(['module:snyk' => 'code'], $state->lateWoven);
    $this->assertContains('module:snyk', $state->phaseGates['code']);
    $this->assertContains('module:snyk', $state->phaseGates['test']);
    $this->assertContains('module:snyk', $state->phaseGates['complete']);
    $this->assertNotContains('module:snyk', $state->phaseGates['plan'], 'a phase already left stays as recorded');
    $this->assertContains('module:snyk', $this->executed['seeing'], 'the woven gate RAN at the phase it joined');
    $row = $this->gateRow($state->gateResults, 'code', 'module:snyk');
    $this->assertSame(GateStatus::Reported->value, $row['status'], 'report mode: the failure is recorded, the phase advances');
    $this->assertIsString($row['summary']);
    $this->assertStringStartsWith('report — ', $row['summary']);
    $this->assertSame('test', $state->currentPhase?->value);
    $this->assertSame('unresolved — no drush reachable from this surface', $state->contributedSource, 'the begin surface\'s source stays on the record');

    // The blind surface advances again: the record carries the gate now, so
    // it runs there too — frozen in, never dropped.
    $this->executed['blind'] = [];
    $blind->run($root);
    $this->assertContains('module:snyk', $this->executed['blind']);
    $reloaded = (new RunStateStore($root))->load();
    $this->assertNotNull($reloaded);
    $this->assertSame(['module:snyk' => 'code'], $reloaded->lateWoven, 'late_woven survives the round trip through run.json');
  }

  /**
   * A run begun on a seeing surface needs no weave, and says so.
   */
  public function testSeeingBeginLeavesNothingToWeave(): void {
    $root = $this->makeRootWithConfig("preset: high\n");
    $snyk = new ContributedGate('snyk', 'droost_snyk', ['code'], 'snyk test', 'block', 'exit 0: clean.');
    $seeing = $this->facade('seeing', [$snyk], 'the booted site (GateCatalog)');

    $seeing->run($root);
    $state = (new RunStateStore($root))->load();
    $this->assertNotNull($state);
    $this->assertArrayHasKey('module:snyk', $state->resolvedGates);
    $this->assertSame([], $state->lateWoven);
    $this->assertSame('the booted site (GateCatalog)', $state->contributedSource);

    $status = $seeing->status($root);
    $this->assertIsArray($status['run']);
    $this->assertSame([], $status['run']['late_woven']);
    $this->assertSame('the booted site (GateCatalog)', $status['run']['contributed_source']);
  }

  /**
   * The lever file's `on: false` on a woven gate is honoured, not bypassed.
   *
   * The blind surface must also LOAD such a lever file: with no catalog it
   * cannot tell a gate it cannot see from a typo, so it leaves the block
   * alone rather than refusing the run.
   */
  public function testWovenGateTurnedOffByLeverStaysOff(): void {
    $root = $this->makeRootWithConfig("preset: high\ngates:\n  contributed:\n    snyk: { on: false }\n");
    $snyk = new ContributedGate('snyk', 'droost_snyk', ['code'], 'snyk test', 'report', 'exit 0: clean.');
    $blind = $this->facade('blind', NULL, NULL);
    $seeing = $this->facade('seeing', [$snyk], 'the booted site (GateCatalog)');

    $blind->run($root);
    $outcome = $seeing->run($root);
    $state = $outcome->state;
    $this->assertArrayHasKey('module:snyk', $state->resolvedGates, 'woven, so the record shows the site declared it');
    $this->assertFalse($state->resolvedGates['module:snyk']['on']);
    $row = $this->gateRow($state->gateResults, 'code', 'module:snyk');
    $this->assertSame(GateStatus::Off->value, $row['status'], 'off by the lever, reported as off — never run, never passed');
    $this->assertNotContains('module:snyk', $this->executed['seeing'] ?? []);
  }

  /**
   * One gate's recorded row from a phase report.
   *
   * @param array<mixed> $results
   *   The run's gate results.
   * @param string $phase
   *   The phase.
   * @param string $gate
   *   The gate name.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function gateRow(array $results, string $phase, string $gate): array {
    $report = $results[$phase] ?? NULL;
    $this->assertIsArray($report);
    $this->assertIsArray($report['gates']);
    foreach ($report['gates'] as $row) {
      $this->assertIsArray($row);
      if (($row['gate'] ?? NULL) === $gate) {
        $out = [];
        foreach ($row as $key => $value) {
          $this->assertIsString($key);
          $out[$key] = $value;
        }
        return $out;
      }
    }
    $this->fail(sprintf('no %s row in the %s report', $gate, $phase));
  }

  /**
   * A facade whose executor records what it ran.
   *
   * The snyk gate fails (exit 1, findings); every other gate passes.
   *
   * @param string $label
   *   Which surface this stands for.
   * @param list<\Droost\Workflow\Config\ContributedGate>|null $contributed
   *   The catalog this surface can see, or NULL when it resolved none.
   * @param string|null $source
   *   Where it says the catalog came from.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(string $label, ?array $contributed, ?string $source): WorkflowFacade {
    $record = function (string $gate) use ($label): void {
      $this->executed[$label][] = $gate;
    };
    $executor = new class($record) implements GateExecutorInterface {

      /**
       * Constructs the recording executor.
       *
       * @param \Closure(string): void $record
       *   Receives each gate name as it is asked to run.
       */
      public function __construct(private readonly \Closure $record) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        ($this->record)($gate->name);
        if ($gate->name === 'module:snyk') {
          return GateResult::ran($gate->name, GateStatus::Failed, 1, 1, 'snyk test exited 1 — 2 high vulnerabilities', [], 'snyk test');
        }
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-08T00:00:00+00:00',
      static fn (): string => 'run-late-weave',
      NULL,
      NULL,
      $contributed,
      $source,
    );
  }

}
