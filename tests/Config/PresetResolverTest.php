<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\PresetResolver;
use Droost\Workflow\Config\WorkflowConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Preset bases and how a config file overlays them.
 */
class PresetResolverTest extends TestCase {

  /**
   * REQ-003: each preset resolves to its documented gate set.
   *
   * @param string $preset
   *   The preset name.
   * @param array<string, array<string, int|string|bool>> $expected
   *   The gate set it must produce.
   */
  #[DataProvider('presetTable')]
  public function testPresetsResolveToTheirDocumentedSet(
    string $preset,
    array $expected,
  ): void {
    $config = WorkflowConfig::fromArray(['preset' => $preset], 'test');
    $this->assertSame($expected, $config->resolvedGates());
  }

  /**
   * The full lever set for every preset.
   *
   * Written out rather than computed: the point is that a change to any
   * default has to be made here too, deliberately.
   *
   * @return array<string, array{string, array<string, array<string, int|string|bool>>}>
   *   Preset name to its resolved gates.
   */
  public static function presetTable(): array {
    $standard = 'Drupal,DrupalPractice';
    return [
      'factory' => [
        'factory',
        [
          'phpcs' => ['on' => TRUE, 'standard' => $standard],
          'phpstan' => ['on' => TRUE, 'level' => 'max'],
          'phpunit' => ['on' => TRUE],
          'mutation' => ['on' => TRUE, 'msi_min' => 80],
          'playwright' => ['on' => TRUE],
          'coverage' => ['on' => TRUE, 'min' => 80],
          'rendered_check' => ['on' => TRUE],
          'wiki_fresh' => ['on' => TRUE],
        ],
      ],
      'fast' => [
        'fast',
        [
          'phpcs' => ['on' => TRUE, 'standard' => $standard],
          'phpstan' => ['on' => TRUE, 'level' => 2],
          'phpunit' => ['on' => FALSE],
          'mutation' => ['on' => FALSE, 'msi_min' => 0],
          'playwright' => ['on' => FALSE],
          'coverage' => ['on' => FALSE, 'min' => 0],
          'rendered_check' => ['on' => TRUE],
          'wiki_fresh' => ['on' => TRUE],
        ],
      ],
      'custom' => [
        'custom',
        [
          'phpcs' => ['on' => TRUE, 'standard' => $standard],
          'phpstan' => ['on' => TRUE, 'level' => 6],
          'phpunit' => ['on' => TRUE],
          'mutation' => ['on' => FALSE, 'msi_min' => 0],
          'playwright' => ['on' => FALSE],
          'coverage' => ['on' => FALSE, 'min' => 0],
          'rendered_check' => ['on' => TRUE],
          'wiki_fresh' => ['on' => TRUE],
        ],
      ],
    ];
  }

  /**
   * The rendered check stays on even in the fast preset.
   *
   * Its own justification, pinned: a fast run that stops checking whether the
   * page renders is not fast, it is blind.
   */
  public function testFastKeepsTheRenderedCheck(): void {
    $config = WorkflowConfig::fromArray(['preset' => 'fast'], 'test');
    $this->assertTrue($config->gate('rendered_check')->on);
  }

  /**
   * REQ-003: an explicit gate overlays the preset without erasing it.
   */
  public function testExplicitGatesOverlayRatherThanReplace(): void {
    $config = WorkflowConfig::fromArray([
      'preset' => 'fast',
      'gates' => ['phpstan' => ['level' => 9]],
    ], 'test');

    $this->assertSame(9, $config->gate('phpstan')->option('level'));
    // The overlaid gate keeps its siblings...
    $this->assertFalse($config->gate('phpunit')->on);
    $this->assertTrue($config->gate('rendered_check')->on);
    // ...and the untouched gates keep their own options.
    $this->assertSame(
      'Drupal,DrupalPractice',
      $config->gate('phpcs')->option('standard'),
    );
  }

  /**
   * Switching a gate off does not discard its configuration.
   */
  public function testDisablingGateKeepsItsOptions(): void {
    $config = WorkflowConfig::fromArray([
      'gates' => ['phpcs' => ['on' => FALSE]],
    ], 'test');

    $this->assertFalse($config->gate('phpcs')->on);
    $this->assertSame(
      'Drupal,DrupalPractice',
      $config->gate('phpcs')->option('standard'),
    );
  }

  /**
   * A threshold does not switch its gate on.
   */
  public function testThresholdsNeverImplyOn(): void {
    $config = WorkflowConfig::fromArray([
      'preset' => 'custom',
      'gates' => ['coverage' => ['min' => 95]],
    ], 'test');

    $this->assertFalse($config->gate('coverage')->on);
    $this->assertSame(95, $config->gate('coverage')->option('min'));
  }

  /**
   * The one lever that may switch a gate off says so consistently.
   */
  public function testLevelOffDisablesTheGate(): void {
    $config = WorkflowConfig::fromArray([
      'gates' => ['phpstan' => ['level' => 'off']],
    ], 'test');

    $this->assertFalse($config->gate('phpstan')->on);
    $this->assertSame(
      ['on' => FALSE, 'level' => 'off'],
      $config->resolvedGates()['phpstan'],
    );
  }

  /**
   * Presets are rebuilt per call, so one document cannot poison the next.
   */
  public function testPresetsAreNotSharedBetweenCalls(): void {
    WorkflowConfig::fromArray([
      'gates' => ['phpstan' => ['level' => 9]],
    ], 'first');
    $second = WorkflowConfig::fromArray([], 'second');

    $this->assertSame('max', $second->gate('phpstan')->option('level'));
  }

  /**
   * Every preset defines every known gate, keyed by its own name.
   *
   * Guards a latent null dereference: the overlay reads
   * $gates[$name] without a presence check, so an eighth gate added to the
   * vocabulary but not to all three presets would turn a config typo into a
   * fatal instead of a typed error.
   *
   * @param string $preset
   *   The preset name.
   */
  #[DataProvider('presetNames')]
  public function testEveryPresetCoversEveryGate(string $preset): void {
    $gates = PresetResolver::resolve($preset)->gates;

    $this->assertSame(
      GateSettings::KNOWN_GATES,
      array_keys($gates),
    );
    foreach ($gates as $key => $gate) {
      $this->assertSame($key, $gate->name);
    }
  }

  /**
   * Every known preset name.
   *
   * @return array<string, array{string}>
   *   The names.
   */
  public static function presetNames(): array {
    $cases = [];
    foreach (PresetResolver::KNOWN_PRESETS as $name) {
      $cases[$name] = [$name];
    }
    return $cases;
  }

  /**
   * A gate cannot be constructed under a name that is not in the vocabulary.
   */
  public function testGateSettingsRefusesAnUnknownName(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('No such gate: "bogus"');
    new GateSettings('bogus', TRUE);
  }

}
