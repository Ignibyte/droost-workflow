<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit;

use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Gate\GateExecutorInterface;
use Drupal\droost_workflow\Gate\GateResult;
use Drupal\droost_workflow\Gate\GateStatus;
use Drupal\droost_workflow\Gate\NullSiteDriver;
use Drupal\droost_workflow\Gate\SiteDriverInterface;
use Drupal\droost_workflow\Mode\RunStateOnlySink;
use Drupal\droost_workflow\State\StateError;
use Drupal\droost_workflow\WorkflowFacade;

/**
 * REQ-003: the two surfaces produce the same report.
 *
 * The requirement this whole ticket exists for — "run it via a live site
 * and/or in a cli" is only true if the two agree. They agree by construction:
 * both call one facade and differ only in which SiteDriver they inject, so
 * these tests assert that the construction actually holds rather than that
 * two implementations happen to match today.
 */
class SurfaceParityTest extends WorkflowTestCase {

  /**
   * Both surfaces report the same levers for the same config.
   */
  public function testStatusIsIdenticalAcrossSurfaces(): void {
    $root = $this->makeRootWithConfig("preset: fast\n");

    $cli = $this->facade(new NullSiteDriver())->status($root);
    $live = $this->facade($this->fakeSiteDriver())->status($root);

    $this->assertSame($cli, $live);
    // Narrowed one level at a time: asserting the outer array does not narrow
    // what is inside it, and a chained offset into the result is an
    // offset-on-mixed error at level max.
    $levers = $cli['levers'];
    $this->assertIsArray($levers);
    $this->assertSame('fast', $levers['preset']);
    // Status explains WHEN each gate runs, so "why did plan run nothing"
    // is answerable without reading the engine.
    $phaseGates = $levers['phase_gates'];
    $this->assertIsArray($phaseGates);
    $this->assertSame([], $phaseGates['plan']);
    $this->assertSame(['phpcs', 'phpstan'], $phaseGates['code']);
  }

  /**
   * The two surfaces differ in exactly one thing: the site gate.
   *
   * Everything else — which gates ran, their verdicts, the phase, the
   * advance decision — must be identical. If any other field diverges, the
   * facade has stopped being the single orchestration point.
   *
   * Two invocations per surface: the first works plan, which the phase map
   * leaves gateless (asserted, since that is itself new behavior); the
   * second works test, where the site gate is due and the surfaces may
   * lawfully differ.
   */
  public function testOnlyTheSiteGateDiffersBetweenSurfaces(): void {
    $config = "preset: custom\nphases: [plan, test, complete]\n";
    $cliRoot = $this->makeRootWithConfig($config);
    $liveRoot = $this->makeRootWithConfig($config);
    $cliFacade = $this->facade(new NullSiteDriver());
    $liveFacade = $this->facade($this->fakeSiteDriver());

    $cliPlan = $cliFacade->run($cliRoot);
    $livePlan = $liveFacade->run($liveRoot);
    $this->assertNotNull($cliPlan->report);
    $this->assertNotNull($livePlan->report);
    $this->assertSame([], $cliPlan->report->results, 'plan ran a gate');
    $this->assertSame([], $livePlan->report->results, 'plan ran a gate');

    $cli = $cliFacade->run($cliRoot);
    $live = $liveFacade->run($liveRoot);

    $this->assertNotNull($cli->report);
    $this->assertNotNull($live->report);

    $cliGates = $this->byGate($cli->report->toArray());
    $liveGates = $this->byGate($live->report->toArray());

    $this->assertSame(array_keys($cliGates), array_keys($liveGates));

    foreach ($cliGates as $name => $cliResult) {
      if ($name === 'rendered_check') {
        $this->assertSame('skipped-no-site', $cliResult['status']);
        $this->assertSame('passed', $liveGates[$name]['status']);
        continue;
      }
      $this->assertSame(
        $cliResult['status'],
        $liveGates[$name]['status'],
        $name . ' differs between surfaces',
      );
    }
  }

  /**
   * The CLI surface names every gate it could not run, and passes none.
   */
  public function testTheCliSurfaceReportsItsSkipsRatherThanOmittingThem(): void {
    $root = $this->makeRootWithConfig(
      "preset: custom\nphases: [plan, test, complete]\n",
    );
    $facade = $this->facade(new NullSiteDriver());

    // Advance past the gateless plan phase to test, where the site gate is
    // due.
    $facade->run($root);
    $outcome = $facade->run($root);

    $this->assertNotNull($outcome->report);
    $skipped = $outcome->report->skipped();
    $this->assertCount(1, $skipped);
    $this->assertSame('rendered_check', $skipped[0]->gate);
    $this->assertNotNull($skipped[0]->skipReason);
  }

  /**
   * The facade writes run state — the thing nothing did until now.
   */
  public function testRunningWritesRunState(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");

    $this->assertFileDoesNotExist($root . '/.droost-workflow/run.json');
    $this->facade(new NullSiteDriver())->run($root);
    $this->assertFileExists($root . '/.droost-workflow/run.json');

    $status = $this->facade(new NullSiteDriver())->status($root);
    $this->assertIsArray($status['run']);
    $this->assertSame('run-test', $status['run']['run_id']);
  }

  /**
   * A run walks the phases and finishes, rather than repeating one.
   */
  public function testRunAdvancesThroughItsPhasesToCompletion(): void {
    $root = $this->makeRootWithConfig("phases: [plan, complete]\n");
    $facade = $this->facade(new NullSiteDriver());

    $first = $facade->run($root);
    $this->assertSame('complete', $first->state->currentPhase?->value);

    $second = $facade->run($root);
    $this->assertSame('completed', $second->outcome->value);

    // Re-running an ended run says so rather than starting a second one.
    $third = $facade->run($root);
    $this->assertSame('completed', $third->outcome->value);
    $this->assertSame($second->state->runId, $third->state->runId);
  }

  /**
   * Answering when nothing is waiting is a typed error, not a crash.
   */
  public function testAnsweringWithNoRunIsTyped(): void {
    $root = $this->makeRoot();

    $this->expectException(StateError::class);
    $this->expectExceptionMessage('no run in progress');
    $this->facade(new NullSiteDriver())->answer($root, 'yes');
  }

  /**
   * Every surface renders the ONE envelope RunOutcome::toArray() builds.
   *
   * The same five fields used to be assembled three times — bin, drush,
   * MCP — which is precisely the second-implementation drift the facade
   * exists to prevent. Two halves: the envelope itself has the agreed
   * shape, and each surface's source actually calls it (the drush and MCP
   * classes cannot be instantiated in this suite, so the call is pinned
   * the way the MCP structural tests pin theirs — in source).
   */
  public function testEverySurfaceRendersTheSharedEnvelope(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $outcome = $this->facade(new NullSiteDriver())->run($root);

    $envelope = $outcome->toArray();
    $this->assertSame(
      ['outcome', 'current_phase', 'report', 'awaiting', 'retries'],
      array_keys($envelope),
    );
    $retries = $envelope['retries'];
    $this->assertIsArray($retries);
    $this->assertSame(
      ['attempts', 'max_gate_retries', 'exhausted'],
      array_keys($retries),
    );

    $surfaces = [
      'bin' => __DIR__ . '/../../../src/Cli/ArgvDispatcher.php',
      'drush' => __DIR__ . '/../../../src/Drush/Commands/WorkflowCommands.php',
      'mcp' => __DIR__
      . '/../../../modules/droost_workflow_mcp/src/Plugin/Tool/WorkflowRun.php',
    ];
    foreach ($surfaces as $name => $path) {
      $src = (string) file_get_contents($path);
      $this->assertStringContainsString(
        '$outcome->toArray()',
        $src,
        $name . ' must render the shared envelope, not assemble its own',
      );
    }
  }

  /**
   * Gate results keyed by gate name.
   *
   * @param array<string, mixed> $report
   *   A serialized PhaseReport.
   *
   * @return array<string, array<array-key, mixed>>
   *   Gate name to its serialized result.
   */
  private function byGate(array $report): array {
    /** @var array<string, array<array-key, mixed>> $out */
    $out = [];
    $gates = $report['gates'];
    $this->assertIsArray($gates);
    foreach ($gates as $gate) {
      $this->assertIsArray($gate);
      $name = $gate['gate'];
      $this->assertIsString($name);
      $out[$name] = $gate;
      unset($gate);
    }
    return $out;
  }

  /**
   * A facade with a deterministic clock and identity.
   *
   * @param \Drupal\droost_workflow\Gate\SiteDriverInterface $driver
   *   The driver that makes it a CLI or a live surface.
   *
   * @return \Drupal\droost_workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(SiteDriverInterface $driver): WorkflowFacade {
    return new WorkflowFacade(
      new class() implements GateExecutorInterface {

        /**
         * {@inheritdoc}
         */
        public function execute(
          GateSettings $gate,
          string $projectRoot,
        ): GateResult {
          return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
        }

      },
      $driver,
      new RunStateOnlySink(),
      static fn (): string => '2026-07-27T10:00:00+00:00',
      static fn (): string => 'run-test',
    );
  }

  /**
   * A driver that stands in for a booted site.
   *
   * @return \Drupal\droost_workflow\Gate\SiteDriverInterface
   *   The double.
   */
  private function fakeSiteDriver(): SiteDriverInterface {
    return new class() implements SiteDriverInterface {

      /**
       * {@inheritdoc}
       */
      public function available(): bool {
        return TRUE;
      }

      /**
       * {@inheritdoc}
       */
      public function supports(): array {
        return ['rendered_check'];
      }

      /**
       * {@inheritdoc}
       */
      public function run(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 5, 'rendered');
      }

    };
  }

}
