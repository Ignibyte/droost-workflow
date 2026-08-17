<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * The built-in lever sets a config file starts from.
 *
 * A preset is a BASE, not an alternative to per-gate control: explicit gates:
 * entries are applied over whichever preset is named, so "factory but without
 * Playwright" is one line rather than a fork. The resolved result is always
 * reported, because a run whose levers cannot be read back is a run whose
 * report cannot be trusted.
 */
final class PresetResolver {

  /**
   * Every preset name a config file may use.
   */
  public const KNOWN_PRESETS = ['custom', 'factory', 'fast'];

  /**
   * The preset assumed whenever a document does not name one.
   *
   * Unspecified means the strictest gates, not the loosest: a repo that has
   * said nothing has not opted out of anything. Critically this is ONE rule
   * covering three situations that are the same fact — no file, an empty
   * file, and a file that sets other things but never mentions a preset.
   *
   * An earlier revision defaulted a file-that-exists to "custom" and only a
   * missing file to "factory". That made `touch droost.workflow.yml` turn
   * mutation, playwright and coverage off and drop PHPStan from max to 6,
   * with no error and no warning — a gate set silently weakened by creating
   * an empty file, which is the exact failure this package exists to prevent.
   *
   * The gentler "custom" set is still the ergonomic starting point: it is
   * what `init` writes, spelled out gate by gate, so choosing it is visible
   * in a diff rather than inherited from a blank file.
   */
  public const DEFAULT_PRESET = 'factory';

  /**
   * The standard phpcs asks for unless a repo says otherwise.
   */
  private const DEFAULT_STANDARD = 'Drupal,DrupalPractice';

  /**
   * Whether a name is a known preset.
   *
   * @param string $name
   *   The candidate name.
   *
   * @return bool
   *   TRUE when known.
   */
  public static function isKnown(string $name): bool {
    return in_array($name, self::KNOWN_PRESETS, TRUE);
  }

  /**
   * The base lever set for a preset.
   *
   * @param string $preset
   *   A name from self::KNOWN_PRESETS.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base mode, retry bound and gate set.
   */
  public static function resolve(string $preset): Preset {
    return match ($preset) {
      'factory' => self::factory(),
      'fast' => self::fast(),
      default => self::custom(),
    };
  }

  /**
   * Everything on, strict. The software factory.
   *
   * The two thresholds are deliberately reachable rather than aspirational:
   * they are the first numbers a consuming repo will want to raise, and a
   * default nobody can hit is a default everybody turns off.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function factory(): Preset {
    return new Preset('factory', Mode::Automated, 2, [
      'phpcs' => new GateSettings('phpcs', TRUE, [
        'standard' => self::DEFAULT_STANDARD,
      ]),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 'max']),
      'phpunit' => new GateSettings('phpunit', TRUE),
      'mutation' => new GateSettings('mutation', TRUE, ['msi_min' => 80]),
      'playwright' => new GateSettings('playwright', TRUE),
      'coverage' => new GateSettings('coverage', TRUE, ['min' => 80]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', TRUE),
    ]);
  }

  /**
   * Coding standards and a shallow analysis; nothing that costs minutes.
   *
   * The rendered check stays on even here. It is the artifacts-are-truth leg,
   * and a fast run that stops checking whether the page renders is not fast,
   * it is blind.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function fast(): Preset {
    return new Preset('fast', Mode::Automated, 2, [
      'phpcs' => new GateSettings('phpcs', TRUE, [
        'standard' => self::DEFAULT_STANDARD,
      ]),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 2]),
      'phpunit' => new GateSettings('phpunit', FALSE),
      'mutation' => new GateSettings('mutation', FALSE, ['msi_min' => 0]),
      'playwright' => new GateSettings('playwright', FALSE),
      'coverage' => new GateSettings('coverage', FALSE, ['min' => 0]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', TRUE),
    ]);
  }

  /**
   * The shipped baseline: per-gate control, nothing slow turned on.
   *
   * These are the values the default droost.workflow.yml spells out, so a
   * repo that edits that file sees exactly what it started from.
   *
   * @return \Droost\Workflow\Config\Preset
   *   The base lever set.
   */
  private static function custom(): Preset {
    return new Preset('custom', Mode::Automated, 2, [
      'phpcs' => new GateSettings('phpcs', TRUE, [
        'standard' => self::DEFAULT_STANDARD,
      ]),
      'phpstan' => new GateSettings('phpstan', TRUE, ['level' => 6]),
      'phpunit' => new GateSettings('phpunit', TRUE),
      'mutation' => new GateSettings('mutation', FALSE, ['msi_min' => 0]),
      'playwright' => new GateSettings('playwright', FALSE),
      'coverage' => new GateSettings('coverage', FALSE, ['min' => 0]),
      'rendered_check' => new GateSettings('rendered_check', TRUE),
      'wiki_fresh' => new GateSettings('wiki_fresh', TRUE),
    ]);
  }

}
