<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

/**
 * A baseline that cannot be read, or a write the ratchet refuses.
 *
 * Typed like the package's other errors so a surface prints the message and
 * exits non-zero without re-explaining it.
 */
final class BaselineError extends \RuntimeException {

  /**
   * The baseline on disk cannot be trusted.
   *
   * @param string $label
   *   The file, as an operator sees it.
   * @param string $why
   *   What is wrong with it.
   *
   * @return self
   *   The error.
   */
  public static function corrupt(string $label, string $why): self {
    return new self(sprintf(
      '%s is not a baseline this package can read: %s. A baseline is written '
      . 'by `droost:workflow:baseline` (or `droost-workflow baseline`) and '
      . 'never by hand — regenerate it from an operator terminal.',
      $label,
      $why,
    ));
  }

  /**
   * A refresh would record MORE debt than the baseline already carries.
   *
   * @param array<string, int> $added
   *   Gate name to the number of findings that would be added.
   *
   * @return self
   *   The error.
   */
  public static function refusedGrowth(array $added): self {
    $parts = [];
    foreach ($added as $gate => $count) {
      $parts[] = sprintf('%s +%d', $gate, $count);
    }
    return new self(sprintf(
      'the baseline only shrinks by default — a refresh would ADD findings '
      . '(%s). Fix them, or record the growth deliberately with '
      . '--grow --reason="…" so the manifest carries who accepted it and why.',
      implode(', ', $parts),
    ));
  }

  /**
   * A growth was requested without saying why.
   *
   * @return self
   *   The error.
   */
  public static function growNeedsReason(): self {
    return new self(
      '--grow needs --reason="…": a baseline that grows without a recorded '
      . 'reason is indistinguishable from debt swept under it.',
    );
  }

  /**
   * Nothing to refresh.
   *
   * @param string $root
   *   The project root.
   *
   * @return self
   *   The error.
   */
  public static function nothingToRefresh(string $root): self {
    return new self(sprintf(
      'no baseline at %s/%s to refresh — write the first one without '
      . '--refresh.',
      rtrim($root, '/'),
      BaselineStore::DIR,
    ));
  }

}
