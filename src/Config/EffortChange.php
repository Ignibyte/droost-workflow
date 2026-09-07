<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * What moving the effort dial did — or, previewed, would do.
 *
 * A value object rather than a printed line so every surface reports the same
 * facts: where the dial was, where it is, whether an alias was typed, which
 * gate switches in the file still override the level, the configuration the
 * file now resolves to, and — when the previous configuration is known — the
 * bill: what the move changes for the NEXT run. D70 round 2 raised a room from
 * custom to max and the first run paid for 26 pre-existing phpstan errors in
 * the code phase; the operator should see that bill at the moment of the move,
 * not from the first run's gate rows.
 */
final class EffortChange {

  /**
   * Constructs an EffortChange.
   *
   * @param string $previous
   *   The canonical level the file resolved to before the rewrite.
   * @param string $level
   *   The canonical level written (or, for a preview, that would be).
   * @param string|null $alias
   *   The alias that was typed, when the level was named by one.
   * @param list<string> $overrides
   *   Gates whose explicit `on:` in the file overrides the level.
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The configuration the rewritten file resolves to.
   * @param \Droost\Workflow\Config\WorkflowConfig|null $previousConfig
   *   The configuration the file resolved to before, when known.
   */
  public function __construct(
    public readonly string $previous,
    public readonly string $level,
    public readonly ?string $alias,
    public readonly array $overrides,
    public readonly WorkflowConfig $config,
    public readonly ?WorkflowConfig $previousConfig = NULL,
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

  /**
   * What the move changes for the next run, one human line per difference.
   *
   * Compares the two RESOLVED configurations — file overrides included — so a
   * file that spells out every gate honestly reports an empty delta: moving
   * the dial changed nothing it did not already say. Gates first, in the
   * engine's order (off → on with the level's tuning, on → off, or the tuning
   * that changed), then the seeker, enforcement and the retry bound.
   *
   * @return list<string>
   *   The differences; empty when nothing changes or the previous
   *   configuration is unknown.
   */
  public function delta(): array {
    if ($this->previousConfig === NULL) {
      return [];
    }
    $lines = [];
    $before = $this->previousConfig->resolvedGates();
    foreach ($this->config->resolvedGates() as $name => $now) {
      $was = $before[$name] ?? ['on' => FALSE];
      $wasOn = ($was['on'] ?? FALSE) === TRUE;
      $nowOn = ($now['on'] ?? FALSE) === TRUE;
      if (!$wasOn && $nowOn) {
        $tuning = self::tuning($now);
        $lines[] = sprintf('%s: off → on%s', $name, $tuning === '' ? '' : ' (' . $tuning . ')');
      }
      elseif ($wasOn && !$nowOn) {
        $lines[] = sprintf('%s: on → off', $name);
      }
      elseif ($wasOn && $nowOn) {
        $changed = self::changedOptions($was, $now);
        if ($changed !== []) {
          $lines[] = sprintf('%s: %s', $name, implode(', ', $changed));
        }
      }
    }
    if ($this->previousConfig->seekers !== $this->config->seekers) {
      $lines[] = sprintf('seekers: %s → %s', $this->previousConfig->seekers ? 'on' : 'off', $this->config->seekers ? 'on' : 'off');
    }
    if ($this->previousConfig->enforcement !== $this->config->enforcement) {
      $lines[] = sprintf('enforcement: %s → %s', $this->previousConfig->enforcement->value, $this->config->enforcement->value);
    }
    if ($this->previousConfig->maxGateRetries !== $this->config->maxGateRetries) {
      $lines[] = sprintf('gate retries: %d → %d', $this->previousConfig->maxGateRetries, $this->config->maxGateRetries);
    }
    return $lines;
  }

  /**
   * A gate's tuning, phrased for a human, in a fixed order.
   *
   * @param array<string, int|string|bool> $gate
   *   The resolved gate (its `on` and options).
   *
   * @return string
   *   E.g. "level max", "msi ≥ 80", "required to exist, paths web/…"; empty
   *   when the gate carries no tuning.
   */
  private static function tuning(array $gate): string {
    $parts = [];
    foreach ($gate as $option => $value) {
      if ($option === 'on') {
        continue;
      }
      $parts[] = match ($option) {
        'required' => $value === TRUE ? 'required to exist' : 'not required to exist',
        'msi_min' => 'msi ≥ ' . self::render($value),
        default => $option . ' ' . self::render($value),
      };
    }
    return implode(', ', $parts);
  }

  /**
   * The options that differ between two resolved states of one gate.
   *
   * @param array<string, int|string|bool> $was
   *   The gate before.
   * @param array<string, int|string|bool> $now
   *   The gate after.
   *
   * @return list<string>
   *   One "option before → after" per difference; `on` is not an option here.
   */
  private static function changedOptions(array $was, array $now): array {
    $lines = [];
    foreach (array_unique([...array_keys($was), ...array_keys($now)]) as $option) {
      if ($option === 'on') {
        continue;
      }
      $before = $was[$option] ?? NULL;
      $after = $now[$option] ?? NULL;
      if ($before === $after) {
        continue;
      }
      $lines[] = sprintf(
        '%s %s → %s',
        $option,
        $before === NULL ? '(unset)' : self::render($before),
        $after === NULL ? '(unset)' : self::render($after),
      );
    }
    return $lines;
  }

  /**
   * A lever value as a word.
   *
   * @param int|string|bool $value
   *   The value.
   *
   * @return string
   *   Booleans as yes/no, the rest as-is.
   */
  private static function render(int|string|bool $value): string {
    if (is_bool($value)) {
      return $value ? 'yes' : 'no';
    }
    return (string) $value;
  }

}
