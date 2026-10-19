<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\ConfigLocator;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The levers live in droost/droost.yml; the root file is read for a release.
 */
final class ConfigLocatorTest extends WorkflowTestCase {

  /**
   * A project with no lever file is pointed at the new location.
   */
  public function testNoFileMeansTheNewLocation(): void {
    $root = $this->makeRoot();
    $this->assertNull(ConfigLocator::found($root));
    $this->assertSame('droost/droost.yml', ConfigLocator::relative($root));
    $this->assertFalse(ConfigLocator::isLegacy($root));
  }

  /**
   * The new file is read, with no notice.
   */
  public function testTheNewFileIsReadSilently(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost');
    file_put_contents($root . '/droost/droost.yml', "preset: low\n");

    $config = WorkflowConfig::load($root);

    $this->assertSame('low', $config->preset);
    $this->assertSame([], array_values(array_filter(
      $config->deprecations,
      static fn (string $d): bool => str_contains($d, 'old place'),
    )));
  }

  /**
   * The root file still loads, and every lever report says where to move it.
   */
  public function testLegacyFileLoadsWithNotice(): void {
    $root = $this->makeRoot();
    file_put_contents($root . '/droost.workflow.yml', "preset: high\n");

    $config = WorkflowConfig::load($root);

    $this->assertSame('high', $config->preset);
    $this->assertTrue(ConfigLocator::isLegacy($root));
    $this->assertStringContainsString('droost/droost.yml', $config->deprecations[0]);
    $this->assertStringContainsString('droost migrate', $config->deprecations[0]);
  }

  /**
   * When both exist, the new file is the one in force.
   */
  public function testTheNewFileWinsOverTheOld(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost');
    file_put_contents($root . '/droost/droost.yml', "preset: low\n");
    file_put_contents($root . '/droost.workflow.yml', "preset: max\n");

    $this->assertSame('droost/droost.yml', ConfigLocator::found($root));
    $this->assertSame('low', WorkflowConfig::load($root)->preset);
  }

  /**
   * The rules a run is held to are named by path.
   */
  public function testRulePaths(): void {
    $rules = [
      'droost/droost.yml',
      'droost.workflow.yml',
      'droost/agents.md',
      'droost/gates/x.php',
      'droost/walls/a/b.php',
      'droost/hooks',
    ];
    foreach ($rules as $path) {
      $this->assertTrue(ConfigLocator::isRulePath($path), $path);
    }
    $others = ['droost/wiki/x.md', 'droost/droost-workflow/spec.md', 'droost/gatesx/y.php', 'x/droost/droost.yml'];
    foreach ($others as $path) {
      $this->assertFalse(ConfigLocator::isRulePath($path), $path);
    }
  }

  /**
   * The guard, which has no autoloader, reads the same lever file.
   *
   * `require_run` is the lever the guard reads itself, so it is what shows
   * which file it read: a custom-code write with no run is let through only
   * when the file in force says `require_run: off`.
   *
   * @param array<string, string> $files
   *   Lever files to write, path => contents.
   * @param int $expected
   *   The guard's exit for a write into modules/custom with no run.
   */
  #[DataProvider('leverLayouts')]
  public function testTheGuardReadsTheSameLeverFile(array $files, int $expected): void {
    $root = $this->makeRoot();
    foreach ($files as $file => $contents) {
      if (!is_dir(dirname($root . '/' . $file))) {
        mkdir(dirname($root . '/' . $file), 0755, TRUE);
      }
      file_put_contents($root . '/' . $file, $contents);
    }

    [$exit] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $root . '/modules/custom/x/x.module'],
    ]);

    $this->assertSame($expected, $exit);
  }

  /**
   * Lever layouts and the guard's answer to an unrun custom-code write.
   *
   * @return array<string, array{array<string, string>, int}>
   *   The cases.
   */
  public static function leverLayouts(): array {
    return [
      'the new file' => [['droost/droost.yml' => "require_run: off\n"], 0],
      'the legacy file' => [['droost.workflow.yml' => "require_run: off\n"], 0],
      'the new file wins' => [
        ['droost/droost.yml' => "require_run: hard\n", 'droost.workflow.yml' => "require_run: off\n"],
        2,
      ],
      'none' => [[], 2],
    ];
  }

  /**
   * A project's own settings block is left to the files that read it.
   */
  public function testProjectBlockIsTolerated(): void {
    $config = WorkflowConfig::fromArray(
      ['preset' => 'low', 'acme' => ['webroot' => 'docroot']],
      'droost/droost.yml',
    );
    $this->assertSame('low', $config->preset);
  }

  /**
   * A near-miss of a lever, or a scalar, is still a typo.
   */
  public function testTypoIsStillRefused(): void {
    $typos = [
      ['gate' => ['phpcs' => ['on' => TRUE]]],
      ['seeker' => ['on' => TRUE]],
      ['presett' => 'custom'],
      ['acme' => ['a', 'b']],
    ];
    foreach ($typos as $raw) {
      try {
        WorkflowConfig::fromArray($raw, 'droost/droost.yml');
        $this->fail('Expected a refusal for ' . (string) array_key_first($raw));
      }
      catch (ConfigError $e) {
        $this->assertStringContainsString('unknown setting', $e->getMessage());
      }
    }
  }

}
