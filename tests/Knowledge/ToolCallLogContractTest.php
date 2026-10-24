<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Workflow\Knowledge\ToolCallLog;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The tool-call ledger's rows are what schema/tool-call.v1.json says.
 *
 * Two writers append to one file: this package's ToolCallLog and the droost
 * Drupal module's, which links none of this code. The schema is the contract
 * between them, so a row this package writes is held to it here.
 */
final class ToolCallLogContractTest extends WorkflowTestCase {

  /**
   * A row carries every required key, typed as the schema types it.
   */
  public function testRowsMatchTheSchema(): void {
    $schema = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/schema/tool-call.v1.json'), TRUE);
    $this->assertIsArray($schema);
    $this->assertSame(['tool', 'outcome', 'at', 'run', 'phase'], $schema['required']);

    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    ToolCallLog::append($root, 'code:wiki', 'ok');
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-contract',
      'current_phase' => 'plan',
    ]));
    ToolCallLog::append($root, 'droost_entity_create', 'fail');

    $properties = $schema['properties'] ?? NULL;
    $this->assertIsArray($properties);
    $outcome = $properties['outcome'] ?? NULL;
    $this->assertIsArray($outcome);
    $this->assertSame(['ok', 'fail'], $outcome['enum'] ?? NULL);

    $lines = array_values(array_filter(explode("\n", (string) file_get_contents(ToolCallLog::path($root)))));
    $this->assertCount(2, $lines);
    $rows = [];
    foreach ($lines as $line) {
      $row = json_decode($line, TRUE);
      $this->assertIsArray($row);
      foreach ($schema['required'] as $key) {
        $this->assertArrayHasKey($key, $row);
      }
      $this->assertIsString($row['tool']);
      $this->assertContains($row['outcome'], ['ok', 'fail']);
      $this->assertIsString($row['at']);
      $this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DATE_ATOM, $row['at']), 'at is a date-time');
      $rows[] = $row;
    }
    $this->assertNull($rows[0]['run'], 'no run open: run is null');
    $this->assertSame(['run-contract', 'plan'], [$rows[1]['run'], $rows[1]['phase']], 'run and phase read from run.json');
  }

}
