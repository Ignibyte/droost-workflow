<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\Enforcement;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\WorkflowConfig;

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

    $this->assertSame(Mode::Agentic, $config->mode);
    $this->assertSame('custom', $config->preset);
    $this->assertSame(2, $config->maxGateRetries);
    $this->assertSame(Phase::names(), $config->phaseNames());
    $this->assertSame(
      Enforcement::Hard,
      $config->requireRun,
      'The README sample documents the require_run lever the guard enforces.',
    );
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
   * The README's phase map is the engine's, line for line.
   *
   * The map is prose in the README and a constant in the engine; nothing
   * else holds the two together. Parsed from the fenced block rather than
   * quoted here, so editing either side alone fails the build.
   */
  public function testTheReadmePhaseMapMatchesTheEngine(): void {
    $block = $this->extractBlock('text');
    $this->assertStringStartsWith('plan:', trim($block));

    $documented = [];
    foreach (explode("\n", trim($block)) as $line) {
      [$phase, $gates] = explode(':', $line, 2);
      $gates = trim($gates);
      $documented[trim($phase)] = $gates === 'none'
        ? []
        : array_map(trim(...), explode(',', $gates));
    }

    $this->assertSame(PhaseGateMap::DEFAULT, $documented);
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
    $readme = file_get_contents(dirname(__DIR__) . '/README.md');
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
