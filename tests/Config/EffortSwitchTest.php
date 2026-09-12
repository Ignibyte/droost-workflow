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

  /**
   * The delta names what the move changes for the next run — the bill.
   */
  public function testDeltaNamesWhatTheMoveChanges(): void {
    $root = $this->makeRootWithConfig("preset: low\n");

    $delta = EffortSwitch::apply($root, 'max')->delta();

    $this->assertContains('phpunit: off → on (required to exist)', $delta);
    $this->assertContains('playwright: off → on (required to exist)', $delta);
    $this->assertContains('mutation: off → on (msi ≥ 80, timeout 1800s)', $delta);
    $this->assertContains('coverage: off → on (min 80, timeout 900s)', $delta);
    $this->assertContains('wiki_fresh: off → on', $delta);
    $this->assertContains('phpstan: level 1 → max', $delta);
    $this->assertContains('seekers: off → on', $delta);
    $this->assertContains('enforcement: soft → hard', $delta);
    $this->assertContains('gate retries: 1 → 3', $delta);
    $this->assertNotContains('rendered_check: off → on', $delta, 'a gate on at both levels is not a change');

    $back = EffortSwitch::apply($root, 'low')->delta();
    $this->assertContains('phpunit: on → off', $back);
    $this->assertContains('enforcement: hard → soft', $back);

    $this->assertSame([], EffortSwitch::apply($root, 'low')->delta(), 'the same level changes nothing');
  }

  /**
   * A file that spells out every switch reports an honest empty gate delta.
   */
  public function testDeltaIsEmptyWhereTheFileOverrides(): void {
    $root = $this->makeRootWithConfig(
      "preset: high\ngates:\n  mutation: { on: false, msi_min: 0 }\n  playwright: { on: false }\n  coverage: { on: false, min: 0 }\n",
    );

    $delta = EffortSwitch::apply($root, 'max')->delta();

    $this->assertNotContains('mutation: off → on (msi ≥ 80, timeout 1800s)', $delta, 'the file kept mutation off');
    $this->assertNotContains('playwright: off → on (required to exist)', $delta);
    $this->assertContains('phpunit: required (unset) → yes', $delta, 'phpunit is not spelled out, so max still requires it');
    $this->assertContains('eslint: off → on', $delta, 'the trio is not spelled out either');
  }

  /**
   * A preview computes the same change and writes nothing.
   */
  public function testPreviewWritesNothing(): void {
    $root = $this->makeRootWithConfig("preset: custom\nenforcement: soft\n");
    $before = (string) file_get_contents($root . '/' . WorkflowConfig::FILENAME);

    $change = EffortSwitch::preview($root, 'factory');

    $this->assertSame('custom', $change->previous);
    $this->assertSame('max', $change->level);
    $this->assertSame('factory', $change->alias);
    $this->assertTrue($change->moved());
    $this->assertSame('max', $change->config->preset);
    $this->assertContains('phpunit: required (unset) → yes', $change->delta());
    $this->assertNotContains('enforcement: soft → hard', $change->delta(), 'the file spells enforcement out, so the move leaves it');
    $this->assertSame($before, file_get_contents($root . '/' . WorkflowConfig::FILENAME), 'a preview writes nothing');
    $this->assertSame('custom', WorkflowConfig::load($root)->preset);
  }

}
