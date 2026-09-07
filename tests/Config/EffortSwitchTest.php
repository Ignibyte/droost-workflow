<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\EffortSwitch;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Moving the effort dial rewrites one line and proves the file still loads.
 */
class EffortSwitchTest extends WorkflowTestCase {

  /**
   * The preset line is replaced in place; nothing else in the file moves.
   */
  public function testRewritesThePresetLineInPlace(): void {
    $root = $this->makeRootWithConfig(
      "mode: agentic\npreset: custom\nenforcement: soft\ngates:\n  mutation: { on: false, msi_min: 0 }\n",
    );

    $change = EffortSwitch::apply($root, 'high');

    $this->assertSame('custom', $change->previous);
    $this->assertSame('high', $change->level);
    $this->assertNull($change->alias);
    $this->assertTrue($change->moved());
    $this->assertSame(
      "mode: agentic\npreset: high\nenforcement: soft\ngates:\n  mutation: { on: false, msi_min: 0 }\n",
      file_get_contents($root . '/' . WorkflowConfig::FILENAME),
    );
    $this->assertSame('high', $change->config->preset, 'the rewritten file resolves to the new level');
    $this->assertSame(['mutation'], $change->overrides, 'the explicit switch is named as an override');
  }

  /**
   * An alias is accepted, and the canonical name is what gets written.
   */
  public function testAliasesWriteTheCanonicalName(): void {
    $root = $this->makeRootWithConfig("preset: light\n");

    $change = EffortSwitch::apply($root, 'factory');

    $this->assertSame('medium', $change->previous, 'the previous level is reported canonical too');
    $this->assertSame('max', $change->level);
    $this->assertSame('factory', $change->alias);
    $this->assertSame("preset: max\n", file_get_contents($root . '/' . WorkflowConfig::FILENAME));
  }

  /**
   * A file with no preset line gets one where a reader expects it.
   */
  public function testAddsTheLineAfterModeWhenAbsent(): void {
    $root = $this->makeRootWithConfig("mode: agentic\nenforcement: soft\n");

    $change = EffortSwitch::apply($root, 'low');

    $this->assertSame('max', $change->previous, 'an unnamed preset resolved to the default');
    $this->assertSame(
      "mode: agentic\npreset: low\nenforcement: soft\n",
      file_get_contents($root . '/' . WorkflowConfig::FILENAME),
    );
    $this->assertSame([], $change->overrides);

    $this->assertSame("preset: medium\nenforcement: soft\n", EffortSwitch::rewrite("enforcement: soft\n", 'medium'), 'with no mode: line either, the switch goes first');
  }

  /**
   * Same level twice is a no-op that says so.
   */
  public function testSameLevelDoesNotMove(): void {
    $root = $this->makeRootWithConfig("preset: high\n");

    $change = EffortSwitch::apply($root, 'high');

    $this->assertFalse($change->moved());
  }

  /**
   * An unknown level is refused before anything is written.
   */
  public function testUnknownLevelIsRefused(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");

    try {
      EffortSwitch::apply($root, 'turbo');
      $this->fail('an unknown level must be refused');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('Unknown preset "turbo"', $e->getMessage());
      $this->assertStringContainsString('custom, low, medium, high, xhigh, max', $e->getMessage());
    }
    $this->assertSame("preset: custom\n", file_get_contents($root . '/' . WorkflowConfig::FILENAME), 'nothing was written');
  }

  /**
   * No lever file means nothing to switch; the error names what writes one.
   */
  public function testMissingFileIsRefused(): void {
    $root = sys_get_temp_dir() . '/droost-effort-' . uniqid();
    mkdir($root);

    try {
      EffortSwitch::apply($root, 'high');
      $this->fail('a missing lever file must be refused');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('nothing to switch', $e->getMessage());
    }
    finally {
      rmdir($root);
    }
  }

  /**
   * Custom gates and explicit switches are listed; tuning-only entries are not.
   */
  public function testOverridesNameOnlyExplicitSwitches(): void {
    $yaml = "preset: max\ngates:\n  phpcs: { paths: \"web/modules/custom\" }\n  mutation: { on: false, msi_min: 0 }\n  rendered_check: { on: true }\n  custom:\n    snyk: { on: true, phase: code, cmd: \"snyk test\" }\n";

    $this->assertSame(['mutation', 'rendered_check', 'snyk'], EffortSwitch::overrides($yaml));
    $this->assertSame([], EffortSwitch::overrides("preset: max\ngates:\n  phpstan: { paths: \"x\" }\n"));
  }

}
