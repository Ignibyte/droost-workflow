<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The directories a project names as its own code (F-154).
 */
class CustomCodeLeverTest extends WorkflowTestCase {

  /**
   * Entries are directories relative to the project, normalised.
   */
  public function testEntriesAreNormalised(): void {
    $config = WorkflowConfig::fromArray(['custom_code' => 'modules, ./recipes/, modules'], 'test');

    $this->assertSame(['modules', 'recipes'], $config->customCode);
  }

  /**
   * Unset, the Drupal custom trees alone.
   */
  public function testUnsetNamesNothing(): void {
    $this->assertSame([], WorkflowConfig::fromArray([], 'test')->customCode);
  }

  /**
   * An entry outside the project is refused by name.
   */
  public function testAnEntryOutsideTheProjectIsRefused(): void {
    foreach (['../elsewhere', '/usr/local', 'modules,../x'] as $value) {
      try {
        WorkflowConfig::fromArray(['custom_code' => $value], 'test');
        $this->fail("custom_code \"$value\" was accepted");
      }
      catch (ConfigError $e) {
        $this->assertStringContainsString('custom_code', $e->getMessage(), $value);
      }
    }
  }

}
