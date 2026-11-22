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

  /**
   * A site tool called over MCP is counted from the guard's rows (F-507).
   *
   * The module records a call in the ledger only when it ran from the
   * command line. Over a web MCP server the call is a web request, so it
   * went to the module's own audit table and the run's record showed a site
   * built by nothing. The guard sees every MCP call before it runs.
   */
  public function testCallsOverMcpAreCountedFromTheGuardsRows(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    $rows = [
      ['run' => 'run-a', 'tool' => 'mcp__drupal__tool_api__droost_entity_create', 'verdict' => 'allow'],
      ['run' => 'run-a', 'tool' => 'mcp__drupal__tool_api__droost_entity_create', 'verdict' => 'allow'],
      ['run' => 'run-a', 'tool' => 'mcp__drupal__tool_api__droost_config_set', 'verdict' => 'refuse'],
      ['run' => 'run-a', 'tool' => 'mcp__drupal__tool_api__droost_entities', 'verdict' => 'allow'],
      ['run' => 'run-a', 'tool' => 'mcp__tracker__create_issue', 'verdict' => 'allow'],
      ['run' => 'run-a', 'tool' => 'Bash', 'verdict' => 'allow'],
      ['run' => 'run-old', 'tool' => 'mcp__drupal__tool_api__droost_entity_create', 'verdict' => 'allow'],
    ];
    file_put_contents($root . '/droost/droost-workflow/guard-calls.jsonl', implode("\n", array_map(static fn (array $row): string => (string) json_encode($row), $rows)) . "\n");

    $this->assertSame(['droost_entities' => 1, 'droost_entity_create' => 2], SiteCalls::overMcp($root, 'run-a'), 'what ran, by tool; a refused call did not run');
    $this->assertSame([], SiteCalls::overMcp($root, 'run-none'));
  }

}
