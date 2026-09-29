<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Each gate runs once, at the phase that owns it (owner, 2026-09-29).
 *
 * P6 run 23 ran its 279-test browser suite three times in 86 minutes, for a
 * ticket whose own tests were two files: at test, again on its retry, and
 * again at complete, which re-ran every enabled gate. In the fast flow code
 * runs the analysers and the unit tests, test runs the browser suite, parity
 * and the rendered check, and complete runs only the check on the
 * documentation it writes.
 */
class FastFlowTest extends WorkflowTestCase {

  /**
   * Complete runs no gate but the one on its own documentation.
   */
  public function testCompleteRunsNothingButTheWikiCheck(): void {
    $this->assertSame(['wiki_fresh'], PhaseGateMap::gatesFor(Phase::Complete, 'fast'));
    $this->assertSame([], PhaseGateMap::gatesFor(Phase::Plan, 'fast'));
  }

  /**
   * The unit tests and every analyser run at code; test runs the browser.
   */
  public function testEachGateRunsAtThePhaseThatOwnsIt(): void {
    $code = PhaseGateMap::gatesFor(Phase::Code, 'fast');
    $owned = [
      'phpcs', 'phpstan', 'eslint', 'stylelint', 'prettier',
      'phpunit', 'mutation', 'coverage', 'grounding_check',
    ];
    foreach ($owned as $gate) {
      $this->assertContains($gate, $code, $gate . ' runs at code');
    }
    $test = PhaseGateMap::gatesFor(Phase::Test, 'fast');
    $this->assertSame(['playwright', 'parity', 'rendered_check', 'config_clean'], $test);
    foreach (['phpcs', 'phpstan', 'phpunit', 'mutation', 'coverage'] as $gate) {
      $this->assertNotContains($gate, $test, $gate . ' is not run again at test');
    }
  }

  /**
   * No gate the strict flow runs is lost in the fast one.
   */
  public function testTheFastFlowDropsNoGate(): void {
    $strict = array_unique(array_merge(...array_values(PhaseGateMap::DEFAULT)));
    $fast = array_unique(array_merge(...array_values(PhaseGateMap::FAST)));
    sort($strict);
    sort($fast);
    $this->assertSame($strict, $fast);
    foreach ($fast as $gate) {
      $this->assertTrue(GateSettings::isKnown($gate), $gate);
    }
  }

  /**
   * Low and medium run fast; the levels above and a custom file run strict.
   */
  public function testTheLevelDecidesTheFlowUnlessTheLeverSaysOtherwise(): void {
    $expected = [
      'low' => 'fast',
      'medium' => 'fast',
      'high' => 'strict',
      'xhigh' => 'strict',
      'max' => 'strict',
      'custom' => 'strict',
    ];
    foreach ($expected as $preset => $flow) {
      $this->assertSame($flow, WorkflowConfig::fromArray(['preset' => $preset], 'test')->flow, $preset);
    }
    $this->assertSame('strict', WorkflowConfig::fromArray(['preset' => 'low', 'flow' => 'strict'], 'test')->flow);
    $this->assertSame('fast', WorkflowConfig::fromArray(['preset' => 'max', 'flow' => 'fast'], 'test')->flow);
  }

  /**
   * An unknown flow is refused by name.
   */
  public function testAnUnknownFlowIsRefused(): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('unknown flow "quick"');
    WorkflowConfig::fromArray(['preset' => 'low', 'flow' => 'quick'], 'test');
  }

  /**
   * The run freezes its flow's map when it begins.
   */
  public function testTheRunIsHeldToTheMapItBeganUnder(): void {
    $low = RunState::begin('r1', '2026-09-29T00:00:00+00:00', WorkflowConfig::fromArray(['preset' => 'low'], 'test'));
    $this->assertSame(['wiki_fresh'], $low->phaseGates['complete']);
    $this->assertNotContains('phpcs', $low->phaseGates['test']);

    $max = RunState::begin('r2', '2026-09-29T00:00:00+00:00', WorkflowConfig::fromArray(['preset' => 'max'], 'test'));
    $this->assertContains('playwright', $max->phaseGates['complete'], 'strict keeps the terminal sweep');
  }

}
