<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * What moving the effort dial did.
 *
 * A value object rather than a printed line so every surface reports the same
 * facts: where the dial was, where it is, whether an alias was typed, which
 * gate switches in the file still override the level, and the configuration
 * the file now resolves to.
 */
final class EffortChange {

  /**
   * Constructs an EffortChange.
   *
   * @param string $previous
   *   The canonical level the file resolved to before the rewrite.
   * @param string $level
   *   The canonical level written.
   * @param string|null $alias
   *   The alias that was typed, when the level was named by one.
   * @param list<string> $overrides
   *   Gates whose explicit `on:` in the file overrides the level.
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The configuration the rewritten file resolves to.
   */
  public function __construct(
    public readonly string $previous,
    public readonly string $level,
    public readonly ?string $alias,
    public readonly array $overrides,
    public readonly WorkflowConfig $config,
  ) {}

  /**
   * Whether the dial actually moved.
   *
   * @return bool
   *   TRUE when the written level differs from the one the file resolved to.
   */
  public function moved(): bool {
    return $this->previous !== $this->level;
  }

}
