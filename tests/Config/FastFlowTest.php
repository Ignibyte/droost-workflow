<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\LoopState;
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
   * Each level sets its loop budget, and the file may say otherwise.
   */
  public function testTheLevelSetsTheLoopBudget(): void {
    $expected = ['low' => 2, 'medium' => 3, 'high' => 5, 'xhigh' => 5, 'max' => 5];
    foreach ($expected as $preset => $loops) {
      $this->assertSame($loops, WorkflowConfig::fromArray(['preset' => $preset], 'test')->maxLoops, $preset);
    }
    $this->assertSame(0, WorkflowConfig::fromArray(['preset' => 'low', 'max_loops' => 0], 'test')->maxLoops);
    $this->assertSame('auto', WorkflowConfig::fromArray(['preset' => 'low'], 'test')->followUps);
  }

  /**
   * A follow-up target that is unknown, or a cockpit nobody set up, is refused.
   */
  public function testFollowUpsNamePlaceThatExists(): void {
    try {
      WorkflowConfig::fromArray(['preset' => 'low', 'follow_ups' => 'jira'], 'test');
      $this->fail('an unknown target loads');
    }
    catch (ConfigError $e) {
      $this->assertStringContainsString('unknown follow_ups "jira"', $e->getMessage());
    }
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('follow_ups: cockpit needs work_item.provider: droost_cockpit');
    WorkflowConfig::fromArray(['preset' => 'low', 'follow_ups' => 'cockpit'], 'test');
  }

  /**
   * A run freezes its loop: the flow, the budget, and how a spent one ends.
   */
  public function testTheRunFreezesItsLoop(): void {
    $low = RunState::begin('r1', '2026-09-29T00:00:00+00:00', WorkflowConfig::fromArray(['preset' => 'low'], 'test'));
    $this->assertTrue($low->loop->loops());
    $this->assertSame(2, $low->loop->maxLoops);
    $this->assertSame('follow-up', $low->loop->whenSpent);

    $fastMax = WorkflowConfig::fromArray(['preset' => 'max', 'flow' => 'fast'], 'test');
    $max = RunState::begin('r2', '2026-09-29T00:00:00+00:00', $fastMax);
    $this->assertSame('fail', $max->loop->whenSpent, 'at max a spent budget fails the run');

    $strict = RunState::begin('r3', '2026-09-29T00:00:00+00:00', WorkflowConfig::fromArray(['preset' => 'high'], 'test'));
    $this->assertFalse($strict->loop->loops());
    $this->expectException(\InvalidArgumentException::class);
    $strict->returnTo(Phase::Code, 'failed', '2026-09-29T00:00:00+00:00', []);
  }

  /**
   * A loop survives run.json, and a record without one never looped.
   */
  public function testTheLoopRoundTrips(): void {
    $loop = (new LoopState('fast', 2, 'follow-up', 'auto'))
      ->returned('test', 'code', 'failed', '2026-09-29T00:00:00+00:00', ['playwright' => 'failed'])
      ->withSubject('code', 'abc')
      ->deferring('test', [['phase' => 'test', 'gate' => 'playwright', 'id' => 'TICKET-1']]);
    $this->assertEquals($loop, LoopState::fromArray($loop->toArray()));
    $this->assertSame(1, $loop->spent());
    $this->assertSame(['test' => ['playwright']], $loop->deferred);
    $this->assertArrayNotHasKey('subjects', $loop->envelope(), 'the envelope carries no fingerprints');
    $this->assertFalse(LoopState::fromArray(NULL)->loops());
  }

  /**
   * A custom gate runs at its own phase, and the fast flow's complete skips it.
   *
   * F-159: 0.11.0 still wove every custom and contributed gate into complete,
   * so a repo's ten-minute suite ran at test and again at complete.
   */
  public function testCustomGateRunsOnceInTheFastFlow(): void {
    $gates = ['custom' => ['suite' => ['on' => TRUE, 'phase' => 'test', 'cmd' => 'bin/gate.sh FULL']]];
    $at = '2026-09-29T00:00:00+00:00';
    $fast = RunState::begin('r1', $at, WorkflowConfig::fromArray(['preset' => 'low', 'gates' => $gates], 'test'));
    $this->assertContains('custom:suite', $fast->phaseGates['test']);
    $this->assertSame(['wiki_fresh'], $fast->phaseGates['complete']);

    $levers = ['preset' => 'low', 'flow' => 'strict', 'gates' => $gates];
    $strict = RunState::begin('r2', $at, WorkflowConfig::fromArray($levers, 'test'));
    $this->assertContains('custom:suite', $strict->phaseGates['complete'], 'strict keeps the terminal sweep');
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
