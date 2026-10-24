<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Config\WorkflowConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The house rules the shipped lever file shows are rules this loads (S5-2).
 *
 * The example once read `{ id: HR1, …, judge: gate }` with no check, which
 * the loader refuses: a site that uncommented it got an error, not a rule.
 */
final class ShippedHouseRulesExampleTest extends TestCase {

  /**
   * Uncommented, the example loads, every rule with its judge.
   */
  public function testTheExampleLoads(): void {
    $lines = file(dirname(__DIR__, 2) . '/pack/droost.yml', FILE_IGNORE_NEW_LINES) ?: [];
    $start = array_search('# rules:', $lines, TRUE);
    $this->assertIsInt($start, 'the shipped lever file shows a rules example');
    $block = [];
    for ($i = $start; $i < count($lines) && str_starts_with($lines[$i], '#'); $i++) {
      $block[] = substr($lines[$i], 2);
    }
    $parsed = Yaml::parse(implode("\n", $block));
    $this->assertIsArray($parsed);

    $house = WorkflowConfig::fromArray($parsed, 'the shipped example')->rules()->house;

    $this->assertSame(['HR1', 'HR2', 'HR3'], array_column($house, 'id'));
    $this->assertSame('sdc_markup', $house[0]['check'] ?? NULL);
    $this->assertSame(['*.{php,module}'], $house[2]['files'] ?? NULL, 'a brace glob is one glob, not split at its comma');
  }

}
