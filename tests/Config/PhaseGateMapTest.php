<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use PHPUnit\Framework\TestCase;

/**
 * Which gates are due at which phase.
 */
class PhaseGateMapTest extends TestCase {

  /**
   * The map covers every phase, exactly once, in canonical order.
   */
  public function testTheMapCoversEveryPhase(): void {
    $this->assertSame(Phase::names(), array_keys(PhaseGateMap::DEFAULT));
  }

  /**
   * Every gate the map names exists in the closed vocabulary.
   */
  public function testEveryMappedGateIsKnown(): void {
    foreach (PhaseGateMap::DEFAULT as $phase => $gates) {
      foreach ($gates as $gate) {
        $this->assertTrue(
          GateSettings::isKnown($gate),
          sprintf('"%s" at phase "%s" is not a known gate', $gate, $phase),
        );
      }
    }
  }

  /**
   * Plan and document run no gates: there is nothing yet to measure.
   */
  public function testPlanAndDocumentAreGateless(): void {
    $this->assertSame([], PhaseGateMap::gatesFor(Phase::Plan));
    $this->assertSame([], PhaseGateMap::gatesFor(Phase::Document));
  }

  /**
   * Code runs static analysis and nothing functional.
   */
  public function testCodeRunsStaticAnalysisOnly(): void {
    $this->assertSame(
      ['phpcs', 'phpstan'],
      PhaseGateMap::gatesFor(Phase::Code),
    );
  }

  /**
   * Complete is the safety net: it re-runs the full vocabulary.
   *
   * This is what makes dropped phases safe — a run without a test phase
   * still meets every enabled gate once, at the end.
   */
  public function testCompleteRunsTheFullVocabulary(): void {
    $this->assertSame(
      GateSettings::KNOWN_GATES,
      PhaseGateMap::gatesFor(Phase::Complete),
    );
  }

  /**
   * The site gate is due only where a site could exist to serve it.
   */
  public function testTheSiteGateIsDueOnlyAtTestAndComplete(): void {
    $where = [];
    foreach (PhaseGateMap::DEFAULT as $phase => $gates) {
      if (in_array('rendered_check', $gates, TRUE)) {
        $where[] = $phase;
      }
    }
    $this->assertSame(['test', 'complete'], $where);
  }

  /**
   * Together, code + test cover everything complete re-runs.
   *
   * No gate may exist that ONLY complete runs: it would first fire at the
   * terminal phase, where a failure is most expensive to act on.
   */
  public function testNoGateFirstAppearsAtComplete(): void {
    $earlier = array_unique(array_merge(
      PhaseGateMap::gatesFor(Phase::Code),
      PhaseGateMap::gatesFor(Phase::Test),
    ));
    foreach (PhaseGateMap::gatesFor(Phase::Complete) as $gate) {
      $this->assertContains(
        $gate,
        $earlier,
        sprintf('"%s" would first run at the terminal phase', $gate),
      );
    }
  }

  /**
   * The frozen map carries only the phases a run configures, in run order.
   */
  public function testForPhasesFiltersToTheConfiguredRun(): void {
    $this->assertSame(
      [
        'plan' => [],
        'code' => ['phpcs', 'phpstan'],
        'complete' => GateSettings::KNOWN_GATES,
      ],
      PhaseGateMap::forPhases(['plan', 'code', 'complete']),
    );
  }

}
