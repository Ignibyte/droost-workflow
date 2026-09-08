<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ContributedGate;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A contributed gate survives the trip through JSON, and a bad row is named.
 */
final class ContributedGateArrayTest extends WorkflowTestCase {

  /**
   * ToArray/fromArray round-trip, including the report mode.
   */
  public function testRoundTrip(): void {
    $gate = new ContributedGate('snyk', 'droost_snyk', ['code', 'test'], 'snyk test --severity-threshold=high', 'report', 'exit 0: clean.');
    $row = $gate->toArray();
    $this->assertSame('snyk', $row['id']);
    $this->assertSame('report', $row['mode']);

    $decoded = json_decode(json_encode($row, JSON_THROW_ON_ERROR), TRUE, 8, JSON_THROW_ON_ERROR);
    $this->assertIsArray($decoded);
    $back = ContributedGate::fromArray($decoded);
    $this->assertSame($gate->name(), $back->name());
    $this->assertSame($gate->provider, $back->provider);
    $this->assertSame($gate->phases, $back->phases);
    $this->assertSame($gate->command, $back->command);
    $this->assertSame($gate->defaultMode, $back->defaultMode);
    $this->assertSame($gate->verdict, $back->verdict);
  }

  /**
   * Status prints `default_mode`; a row using that spelling reads back too.
   */
  public function testAcceptsTheStatusSpellingOfMode(): void {
    $back = ContributedGate::fromArray([
      'id' => 'semgrep',
      'provider' => 'droost_semgrep',
      'phases' => ['code'],
      'command' => 'semgrep scan --error',
      'default_mode' => 'report',
      'verdict' => 'exit 0: no findings.',
    ]);
    $this->assertSame('report', $back->defaultMode);
    $back = ContributedGate::fromArray([
      'id' => 'x',
      'provider' => 'm',
      'phases' => ['test'],
      'command' => 'x',
      'verdict' => 'X.',
    ]);
    $this->assertSame('block', $back->defaultMode, 'no mode means the default');
  }

  /**
   * Malformed rows are refused by field, naming the row.
   */
  public function testMalformedRowsAreRefusedByName(): void {
    $base = ['id' => 'a', 'provider' => 'm', 'phases' => ['code'], 'command' => 'c', 'verdict' => 'V.'];
    $cases = [
      'missing command' => [
        array_diff_key($base, ['command' => 1]),
        '"command" must be a string',
      ],
      'phases not a list' => [
        ['phases' => 'code'] + $base,
        '"phases" must be a list',
      ],
      'phase not a string' => [
        ['phases' => [1]] + $base,
        'every phase must be a string',
      ],
      'mode not a string' => [
        ['mode' => 1] + $base,
        '"mode" must be a string',
      ],
      'no id at all' => [
        ['provider' => 'm'],
        'row (no id): "id" must be a string',
      ],
      'constructor rules still apply' => [
        ['phases' => ['plan']] + $base,
        'must name at least one phase of: code, test',
      ],
    ];
    foreach ($cases as $label => [$row, $expected]) {
      try {
        ContributedGate::fromArray($row);
        $this->fail($label . ': accepted a malformed row');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($expected, $e->getMessage(), $label);
      }
    }
  }

}
