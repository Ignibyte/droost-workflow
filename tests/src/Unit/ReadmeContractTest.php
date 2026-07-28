<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit;

use Drupal\droost_workflow\Config\ConfigError;
use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\WorkflowConfig;

/**
 * The README is served to people and to agents as authoritative.
 *
 * Nothing lints prose and no test asserts a paragraph, so a confident wrong
 * sentence in a README outlives every other kind of defect. These tests take
 * the examples out of the file at run time and put them through the real
 * loader, so the documentation cannot drift away from the code it describes.
 */
class ReadmeContractTest extends WorkflowTestCase {

  /**
   * The README's own sample lever file parses, and means what it says.
   */
  public function testTheReadmeSampleParses(): void {
    $sample = $this->extractBlock('yaml');
    $root = $this->makeRootWithConfig($sample);

    $config = WorkflowConfig::load($root);

    $this->assertSame(Mode::Automated, $config->mode);
    $this->assertSame('custom', $config->preset);
    $this->assertSame(2, $config->maxGateRetries);
    $this->assertSame(Phase::names(), $config->phaseNames());
    $this->assertSame(
      WorkflowConfig::fromArray(['preset' => 'custom'], 'x')->resolvedGates(),
      $config->resolvedGates(),
      'The README sample no longer matches the custom preset it documents.',
    );
  }

  /**
   * The error message the README quotes is the one the code produces.
   */
  public function testTheReadmeErrorMessageIsExact(): void {
    $quoted = $this->extractBlock('unknown gate');
    // The README wraps the message across lines to stay readable.
    $expected = preg_replace('/\s+/', ' ', trim($quoted));

    try {
      WorkflowConfig::fromArray(
        ['gates' => ['phpstain' => ['on' => TRUE]]],
        'droost.workflow.yml',
      );
      $this->fail('Expected a ConfigError.');
    }
    catch (ConfigError $e) {
      $this->assertSame($expected, $e->getMessage());
    }
  }

  /**
   * The README's run-state example is a document the store can read.
   */
  public function testTheReadmeStateExampleIsShapedLikeRealState(): void {
    $sample = $this->extractBlock('json');
    /** @var array<string, mixed>|null $decoded */
    $decoded = json_decode($sample, TRUE);

    $this->assertIsArray($decoded);
    $this->assertSame(1, $decoded['v'] ?? NULL, 'The documented schema '
      . 'version must match the one this build writes.');
  }

  /**
   * The first fenced block whose content matches a marker.
   *
   * @param string $marker
   *   Either a fence language ("yaml", "json") or text the block contains.
   *
   * @return string
   *   The block's contents.
   */
  private function extractBlock(string $marker): string {
    $readme = file_get_contents(dirname(__DIR__, 3) . '/README.md');
    $this->assertIsString($readme, 'README.md is unreadable.');

    $matched = preg_match_all(
      '/^```([a-z]*)\n(.*?)^```/ms',
      $readme,
      $blocks,
      PREG_SET_ORDER,
    );
    $this->assertNotFalse($matched);

    foreach ($blocks as $block) {
      if ($block[1] === $marker || str_contains($block[2], $marker)) {
        return $block[2];
      }
    }

    $this->fail('No fenced block in README.md matches: ' . $marker);
  }

}
