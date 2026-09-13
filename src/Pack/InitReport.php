<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

/**
 * What an init actually did.
 *
 * Returned rather than merely done, for the same reason the gate report
 * exists: a command that quietly succeeds tells the reader nothing about
 * whether their existing lever file was kept, and "wrote 8 files" and "wrote
 * 8 files and left your config alone" are different outcomes.
 */
final class InitReport {

  /**
   * Constructs an InitReport.
   *
   * @param list<string> $written
   *   Paths written, relative to the project root.
   * @param list<string> $kept
   *   Paths left alone because they already existed and are the user's.
   * @param list<string> $drifted
   *   Pack files the user has edited since droost last shipped them, kept as
   *   they are rather than overwritten. Delete one and re-init to take the
   *   upstream version.
   */
  public function __construct(
    public readonly array $written = [],
    public readonly array $kept = [],
    public readonly array $drifted = [],
  ) {}

  /**
   * This report with a written path added.
   *
   * @param string $path
   *   The path written.
   *
   * @return self
   *   A new report.
   */
  public function withWritten(string $path): self {
    return new self([...$this->written, $path], $this->kept, $this->drifted);
  }

  /**
   * This report with a drifted (user-edited, kept) pack file added.
   *
   * @param string $path
   *   The path kept because the user changed it since it was shipped.
   *
   * @return self
   *   A new report.
   */
  public function withDrifted(string $path): self {
    return new self($this->written, $this->kept, [...$this->drifted, $path]);
  }

  /**
   * This report with a preserved path added.
   *
   * @param string $path
   *   The path left alone.
   *
   * @return self
   *   A new report.
   */
  public function withKept(string $path): self {
    return new self($this->written, [...$this->kept, $path], $this->drifted);
  }

  /**
   * A short human-readable summary.
   *
   * @param list<string> $omit
   *   Paths the caller has already reported on, left out of the kept list so
   *   one file is not announced twice in the same transcript. `written` and
   *   `drifted` are never filtered: the first is a count, and the second is a
   *   warning nobody else issues.
   *
   * @return string
   *   One line per outcome that occurred.
   */
  public function summary(array $omit = []): string {
    $lines = [sprintf('wrote %d file(s)', count($this->written))];
    foreach ($this->kept as $path) {
      if (in_array($path, $omit, TRUE)) {
        // Kept, and somebody else already said so. droost's own installer
        // writes droost.workflow.yml before the pack runs — a lever file
        // shaped for that site, which the pack then declines to overwrite —
        // and reports it on its own line. Printing "kept your existing
        // droost.workflow.yml" under "droost.workflow.yml ... written" is two
        // true statements that read as a contradiction, in the one transcript
        // an operator uses to learn what the install did.
        continue;
      }
      $lines[] = sprintf('kept your existing %s', $path);
    }
    foreach ($this->drifted as $path) {
      $lines[] = sprintf(
        'kept your edited %s (upstream changed; delete it and re-init to take '
        . 'the new version)',
        $path,
      );
    }
    return implode("\n", $lines);
  }

}
