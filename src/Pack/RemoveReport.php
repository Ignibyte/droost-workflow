<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

/**
 * What an uninstall actually did.
 *
 * Returned rather than merely done, for the same reason `InitReport` is: an
 * uninstall that quietly succeeds leaves the operator unable to tell whether
 * their own files were touched. The third list is the one that matters most —
 * what was deliberately NOT removed, so nobody has to discover a leftover
 * state directory by finding it in a diff.
 */
final class RemoveReport {

  /**
   * Constructs a RemoveReport.
   *
   * @param list<string> $removed
   *   Paths removed, relative to the project root.
   * @param list<string> $kept
   *   Paths left in place because they are not this package's: a directory
   *   with our name but no sentinel, or a file that could not be removed.
   * @param list<string> $left
   *   The lever file and the state directory, when present. Deliberately not
   *   removed: the lever file is version-controlled intent somebody wrote,
   *   and the state directory holds the run records and the evidence store —
   *   the history of what was built here. Named so an operator can remove
   *   them on purpose rather than having an uninstaller do it for them.
   */
  public function __construct(
    public readonly array $removed = [],
    public readonly array $kept = [],
    public readonly array $left = [],
  ) {}

  /**
   * A short human-readable summary.
   *
   * @return string
   *   One line per outcome that occurred.
   */
  public function summary(): string {
    $lines = [];
    $lines[] = $this->removed === []
      ? 'nothing to do — the pack is not installed here'
      : sprintf('removed %d path(s)', count($this->removed));
    foreach ($this->kept as $path) {
      $lines[] = sprintf('kept %s — not this package\'s to remove', $path);
    }
    if ($this->left !== []) {
      $lines[] = 'left in place, deliberately: ' . implode(', ', $this->left);
      $lines[] = '  the lever file is your intent, and the state directory '
        . 'holds this project\'s run records and evidence. Remove them '
        . 'yourself if you mean to.';
    }

    return implode("\n", $lines);
  }

}
