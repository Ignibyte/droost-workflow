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
use Droost\Workflow\WorkflowFacade;

/**
 * Status says where a surface got its contributed gates — or that it got none.
 *
 * R31-F3: the standalone surface began a run without the site's contributed
 * gates and nothing in status or the record said the set was shorter.
 */
final class WorkflowFacadeContributedSourceTest extends WorkflowTestCase {

  /**
   * A surface that resolved a catalog names the source it was given.
   */
  public function testTheSourceRidesTheLevers(): void {
    $root = $this->makeRootWithConfig("preset: high\n");
    $snyk = new ContributedGate('snyk', 'droost_snyk', ['code'], 'snyk test', 'report', 'exit 0: clean.');
    $levers = $this->levers($this->facade([$snyk], 'the booted site (GateCatalog)'), $root);
    $this->assertSame('the booted site (GateCatalog)', $levers['contributed_source']);
    $this->assertIsArray($levers['contributed']);
    $this->assertArrayHasKey('module:snyk', $levers['contributed']);
  }

  /**
   * No catalog reads as none resolved; an empty catalog as declared none.
   *
   * NULL and [] are different facts: a surface that could not ask (the
   * host binary with no reachable drush) versus a site that answered
   * "nothing". Only the second may refuse a lever file's entry under
   * `gates.contributed` as naming a gate no module declares.
   */
  public function testNullAndEmptyCatalogsReadDifferently(): void {
    $root = $this->makeRootWithConfig("preset: high\n");
    $this->assertSame('none resolved by this surface', $this->levers($this->facade(NULL, NULL), $root)['contributed_source']);
    $this->assertSame('declared to this surface (none)', $this->levers($this->facade([], NULL), $root)['contributed_source']);
  }

  /**
   * A lever entry for a contributed gate survives a surface that cannot see.
   */
  public function testUnresolvedCatalogLeavesTheLeverBlockAlone(): void {
    $root = $this->makeRootWithConfig("preset: high\ngates:\n  contributed:\n    snyk: { mode: block }\n");
    $levers = $this->levers($this->facade(NULL, 'unresolved — no drush'), $root);
    $this->assertSame([], $levers['contributed'], 'nothing resolved, nothing refused');
    $this->assertSame('unresolved — no drush', $levers['contributed_source']);
  }

  /**
   * The levers block of the status document.
   *
   * @param \Droost\Workflow\WorkflowFacade $facade
   *   The facade.
   * @param string $root
   *   The root.
   *
   * @return array<string, mixed>
   *   The block.
   */
  private function levers(WorkflowFacade $facade, string $root): array {
    $status = $facade->status($root);
    $this->assertIsArray($status['levers']);
    $levers = [];
    foreach ($status['levers'] as $key => $value) {
      $this->assertIsString($key);
      $levers[$key] = $value;
    }
    return $levers;
  }

  /**
   * A facade whose gates all pass.
   *
   * @param list<\Droost\Workflow\Config\ContributedGate>|null $contributed
   *   The declarations, or NULL for a surface that resolved no catalog.
   * @param string|null $source
   *   Where they came from.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(?array $contributed, ?string $source): WorkflowFacade {
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
      static fn (): string => '2026-09-08T00:00:00+00:00',
      static fn (): string => 'run-source',
      NULL,
      NULL,
      $contributed,
      $source,
    );
  }

}
