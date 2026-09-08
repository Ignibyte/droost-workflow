<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Vcs\VcsInterface;
use Droost\Workflow\WorkflowFacade;

/**
 * Status says what enforcement amounts to on the host the session declared.
 *
 * D72 (2026-09-08, the owner's steer away from host-specific pieces): the
 * write wall and the phase guard are Claude Code hooks. A host without
 * pre-tool hooks holds `hard` with nothing, and a report that claimed the
 * discipline anyway would be worse than one that says "advisory".
 */
final class WorkflowFacadeHostEnforcementTest extends WorkflowTestCase {

  /**
   * Claude Code keeps the requested level; other hosts read as advisory.
   */
  public function testEffectiveEnforcementFollowsTheDeclaredHost(): void {
    $root = $this->makeRootWithConfig("preset: high\nenforcement: hard\n");
    $facade = $this->facade();
    $facade->run($root);

    $undeclared = $this->enforcement($facade, $root);
    $this->assertSame('hard', $undeclared['requested']);
    $this->assertSame('hard', $undeclared['effective']);
    $this->assertStringContainsString('undeclared', $undeclared['reason']);

    $facade->declareTasks($root, 'claude-code');
    $claude = $this->enforcement($facade, $root);
    $this->assertSame('hard', $claude['effective']);
    $this->assertStringContainsString('runs the pack hooks', $claude['reason']);

    $facade->declareTasks($root, 'codex');
    $codex = $this->enforcement($facade, $root);
    $this->assertSame('hard', $codex['requested'], 'what the run asked for is still on record');
    $this->assertSame('advisory', $codex['effective']);
    $this->assertStringContainsString('(codex) runs no pre-tool hooks', $codex['reason']);
    $this->assertStringContainsString('gates still hold the run server-side', $codex['reason']);
  }

  /**
   * Off is off on every host — there is nothing to be advisory about.
   */
  public function testOffIsOffEverywhere(): void {
    $root = $this->makeRootWithConfig("preset: high\nenforcement: off\n");
    $facade = $this->facade();
    $facade->run($root);
    $facade->declareTasks($root, 'none');

    $off = $this->enforcement($facade, $root);
    $this->assertSame('off', $off['requested']);
    $this->assertSame('off', $off['effective']);
  }

  /**
   * The run half's enforcement block.
   *
   * @param \Droost\Workflow\WorkflowFacade $facade
   *   The facade.
   * @param string $root
   *   The root.
   *
   * @return array{requested: string, effective: string, reason: string}
   *   The block.
   */
  private function enforcement(WorkflowFacade $facade, string $root): array {
    $run = $facade->status($root)['run'];
    $this->assertIsArray($run);
    $block = $run['enforcement'];
    $this->assertIsArray($block);
    $this->assertIsString($block['requested']);
    $this->assertIsString($block['effective']);
    $this->assertIsString($block['reason']);
    return ['requested' => $block['requested'], 'effective' => $block['effective'], 'reason' => $block['reason']];
  }

  /**
   * A facade whose gates all pass and whose vcs knows nothing.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
    $vcs = new class() implements VcsInterface {

      /**
       * {@inheritdoc}
       */
      public function head(string $projectRoot): ?string {
        return NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function changedFiles(string $projectRoot, ?string $base): array {
        return [];
      }

    };
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-08T00:00:00+00:00',
      static fn (): string => 'run-host',
      NULL,
      $vcs,
    );
  }

}
