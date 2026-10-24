<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\Phase;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\Gate\SettingsRecordingExecutor;
use Droost\Workflow\WorkflowFacade;

/**
 * The run-less gate check leaves out what the caller names, and says so.
 *
 * A pre-flight with no base ran the whole browser suite against the site, and
 * one spec left configuration behind (the first subject's pre-flight). A gate
 * left out never runs, and reads off with its reason: never a pass.
 */
final class WorkflowFacadeGateSkipTest extends WorkflowTestCase {

  /**
   * A skipped gate is not executed, reads off, and does not fail the check.
   */
  public function testSkippedGateNeverRunsAndReadsOff(): void {
    $root = $this->makeRootWithConfig("preset: custom\ngates:\n  phpcs: { on: true }\n  phpstan: { on: true }\n  phpunit: { on: false }\n");
    $executor = new SettingsRecordingExecutor();
    $facade = new WorkflowFacade(
      $executor,
      new RunStateOnlySink(),
      static fn (): string => '2026-10-19T12:00:00+00:00',
      static fn (): string => 'run-skip',
    );

    $result = $facade->gates($root, Phase::Code, NULL, ['phpstan']);

    $by = array_column($result['gates'], NULL, 'gate');
    // The recording executor answers "passed" for whatever reaches it.
    $this->assertSame('passed', $by['phpcs']['status'] ?? NULL, 'phpcs ran');
    $this->assertSame('off', $by['phpstan']['status'] ?? NULL, 'phpstan never reached the executor');
    $this->assertStringContainsString('left out on the command line', (string) ($by['phpstan']['summary'] ?? ''));
    $this->assertTrue($result['passed'], 'a gate left out does not fail the check');
  }

}
