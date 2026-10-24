<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Workflow\Knowledge\SiteCalls;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A run's site calls are its own: the module's tools, and the raw calls.
 */
final class SiteCallsTest extends WorkflowTestCase {

  /**
   * Only the module's tools are counted, only this run's, failures apart.
   */
  public function testToolsAreTheRunsModuleCalls(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    $rows = [
      ['tool' => 'droost_entity_create', 'outcome' => 'ok', 'run' => 'run-a'],
      ['tool' => 'droost_entity_create', 'outcome' => 'fail', 'run' => 'run-a'],
      ['tool' => 'code:wiki', 'outcome' => 'ok', 'run' => 'run-a'],
      ['tool' => 'droost_config_set', 'outcome' => 'ok', 'run' => 'run-old'],
    ];
    $lines = array_map(static fn (array $row): string => (string) json_encode($row + ['at' => '', 'phase' => 'code']), $rows);
    file_put_contents($root . '/droost/droost-workflow/tool-calls.jsonl', implode("\n", $lines) . "\n");

    $this->assertSame(['droost_entity_create' => ['calls' => 2, 'failed' => 1]], SiteCalls::tools($root, 'run-a'));
  }

  /**
   * The raw calls are the guard's marked rows of this run.
   */
  public function testRawCallsAreTheRunsMarkedRows(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    $rows = [
      ['run' => 'run-a'],
      ['run' => 'run-a', 'raw_site' => 'php:eval'],
      ['run' => 'run-old', 'raw_site' => 'sql:query'],
    ];
    file_put_contents($root . '/droost/droost-workflow/guard-calls.jsonl', implode("\n", array_map(static fn (array $row): string => (string) json_encode($row), $rows)) . "\n");

    $this->assertSame([2, [2 => 'php:eval']], SiteCalls::raw($root, 'run-a'));
    $this->assertSame([0, []], SiteCalls::raw($root, 'run-none'));
  }

}
