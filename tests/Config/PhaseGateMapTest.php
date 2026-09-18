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
   * Plan runs no gates: there is nothing yet to measure.
   */
  public function testPlanIsGateless(): void {
    $this->assertSame([], PhaseGateMap::gatesFor(Phase::Plan));
  }

  /**
   * The wiki gate is due at complete, and nowhere earlier.
   *
   * Since 0.4 the documentation work is the first half of complete itself,
   * so complete is the first phase at which the wiki CAN be current. Due
   * any earlier, `wiki_fresh` would gate a phase on documentation that
   * phase had not yet produced.
   */
  public function testTheWikiGateRunsAtCompleteOnly(): void {
    $where = [];
    foreach (PhaseGateMap::DEFAULT as $phase => $gates) {
      if (in_array('wiki_fresh', $gates, TRUE)) {
        $where[] = $phase;
      }
    }
    $this->assertSame(['complete'], $where);
  }

  /**
   * Code runs static analysis and nothing functional.
   */
  public function testCodeRunsStaticAnalysisOnly(): void {
    $this->assertSame(
      ['phpcs', 'phpstan', 'eslint', 'stylelint', 'prettier', 'config_clean', 'grounding_check'],
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
   * Together, code + test cover everything complete re-runs — one exception.
   *
   * No gate may exist that ONLY complete runs: it would first fire at the
   * terminal phase, where a failure is most expensive to act on. wiki_fresh
   * is the carved-out exception, and the carve-out is the point: its subject
   * — the documentation — is produced inside complete itself (0.4 folded the
   * document phase in), so the terminal phase is the FIRST one at which the
   * check can be true.
   */
  public function testNoGateFirstAppearsAtComplete(): void {
    $earlier = array_unique(array_merge(
      PhaseGateMap::gatesFor(Phase::Code),
      PhaseGateMap::gatesFor(Phase::Test),
    ));
    foreach (PhaseGateMap::gatesFor(Phase::Complete) as $gate) {
      if ($gate === 'wiki_fresh') {
        continue;
      }
      $this->assertContains(
        $gate,
        $earlier,
        sprintf('"%s" would first run at the terminal phase', $gate),
      );
    }
  }

  /**
   * Every mandatory gate is due at the phase whose output it reads.
   *
   * No lever can disarm phpcs or phpstan (GateSettings::MANDATORY): the
   * toolchain core itself develops with is not optional. That is only true
   * of a run if the phases that write source are all held to it — a test
   * phase exempt from the standards it is testing against writes source no
   * later run can read. P2-KCH-2 failed exactly there.
   */
  public function testTheStaticGatesAreDueAtEveryPhaseThatWritesSource(): void {
    foreach (['phpcs', 'phpstan'] as $gate) {
      foreach ([Phase::Code, Phase::Test, Phase::Complete] as $phase) {
        $this->assertContains(
          $gate,
          PhaseGateMap::gatesFor($phase),
          sprintf('"%s" writes source but does not run %s', $phase->value, $gate),
        );
      }
    }
  }

  /**
   * The static gates precede the suite they are checking the shape of.
   *
   * Gates execute in KNOWN_GATES order, so this is an ordering claim about
   * the constant, not about the map: a phpcs failure on a test file must be
   * reported before phpunit's verdict on that file is recorded, or the run
   * shows a green suite above a red standard and the agent fixes the wrong
   * one first.
   */
  public function testTheShapeOfTheSuiteIsCheckedBeforeItsResult(): void {
    $order = array_flip(GateSettings::KNOWN_GATES);
    $this->assertLessThan($order['phpunit'], $order['phpcs']);
    $this->assertLessThan($order['phpunit'], $order['phpstan']);

    $due = PhaseGateMap::gatesFor(Phase::Test);
    $this->assertSame(
      array_values(array_intersect(GateSettings::KNOWN_GATES, $due)),
      $due,
      'The test phase gates are not in execution order',
    );
  }

  /**
   * The frozen map carries only the phases a run configures, in run order.
   */
  public function testForPhasesFiltersToTheConfiguredRun(): void {
    $this->assertSame(
      [
        'plan' => [],
        'code' => ['phpcs', 'phpstan', 'eslint', 'stylelint', 'prettier', 'config_clean', 'grounding_check'],
        'complete' => GateSettings::KNOWN_GATES,
      ],
      PhaseGateMap::forPhases(['plan', 'code', 'complete']),
    );
  }

}
