<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\WorkflowConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The per-gate `timeout` lever (F-EMT-23).
 *
 * A mutation run over one kernel-test-heavy Drupal module needs more than
 * the executor's ten-minute default, and the only answer used to be a
 * waiver: the number lived in a constructor default no lever file could
 * reach. Every gate that spawns a tool now takes `timeout` (seconds); the
 * two the site driver answers take none.
 */
#[CoversClass(WorkflowConfig::class)]
final class GateTimeoutTest extends TestCase {

  /**
   * A repo raises a slow gate's time, and the run record carries it.
   */
  public function testTimeoutIsReadInSeconds(): void {
    $config = WorkflowConfig::fromArray([
      'preset' => 'max',
      'gates' => [
        'mutation' => ['timeout' => 3600],
        'phpstan' => ['timeout' => 1200],
      ],
    ], 'droost.workflow.yml');

    $this->assertSame(3600, $config->gates['mutation']->option('timeout'));
    $this->assertSame(1200, $config->gates['phpstan']->option('timeout'));
    // Untouched siblings keep the preset's own room.
    $this->assertSame(900, $config->gates['coverage']->option('timeout'));
    $this->assertSame(80, $config->gates['mutation']->option('msi_min'), 'the timeout overlays; the threshold stays the preset\'s');
  }

  /**
   * The slow tiers carry their own time from xhigh up; below, none.
   */
  public function testPresetsGiveTheSlowTiersRoom(): void {
    $max = WorkflowConfig::fromArray(['preset' => 'max'], 'droost.workflow.yml');
    $this->assertSame(1800, $max->gates['mutation']->option('timeout'));
    $this->assertSame(900, $max->gates['coverage']->option('timeout'));

    $high = WorkflowConfig::fromArray(['preset' => 'high'], 'droost.workflow.yml');
    $this->assertNull($high->gates['mutation']->option('timeout'), 'off gates carry no timeout of their own');
    $this->assertNull($high->gates['phpunit']->option('timeout'), 'the executor default applies where the file says nothing');
  }

  /**
   * Zero, negative and non-integer values are refused with the range.
   */
  public function testTimeoutOutsideItsRangeIsRefused(): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('gates.mutation.timeout must be between 1 and 86400, got 0');
    WorkflowConfig::fromArray([
      'preset' => 'max',
      'gates' => ['mutation' => ['timeout' => 0]],
    ], 'droost.workflow.yml');
  }

  /**
   * The gates the site driver answers spawn nothing and take no timeout.
   */
  public function testSiteDriverGatesTakeNoTimeout(): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('gate "rendered_check" has no option "timeout"');
    WorkflowConfig::fromArray([
      'preset' => 'max',
      'gates' => ['rendered_check' => ['timeout' => 30]],
    ], 'droost.workflow.yml');
  }

}
