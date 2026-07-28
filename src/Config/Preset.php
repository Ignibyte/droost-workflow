<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Config;

/**
 * A built-in base lever set, before a config file's overrides.
 *
 * A value object rather than an array shape so that "what does factory turn
 * on" is answerable by reading one class, and so that neither the analyser nor
 * the reader has to carry a nested shape annotation around.
 */
final class Preset {

  /**
   * Constructs a Preset.
   *
   * @param string $name
   *   The preset name, one of PresetResolver::KNOWN_PRESETS.
   * @param \Drupal\droost_workflow\Config\Mode $mode
   *   The mode this preset starts a run in.
   * @param int $maxGateRetries
   *   How many times a failing gate may drive the feedback loop.
   * @param array<string, \Drupal\droost_workflow\Config\GateSettings> $gates
   *   Every known gate, keyed by name.
   */
  public function __construct(
    public readonly string $name,
    public readonly Mode $mode,
    public readonly int $maxGateRetries,
    public readonly array $gates,
  ) {}

}
