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
   * @param list<string> $current
   *   Paths the installer rewrote with the bytes they already held. `written`
   *   counted these, so a re-run on an up-to-date project announced "wrote 16
   *   file(s)" while `diff` showed nothing had changed, and the number moved
   *   between runs for reasons an operator could not see. A reviewer read that
   *   as "init never refreshes anything" — which is not true, and is what a
   *   meaningless count invites somebody to conclude.
   */
  public function __construct(
    public readonly array $written = [],
    public readonly array $kept = [],
    public readonly array $drifted = [],
    public readonly array $current = [],
  ) {}

  /**
   * This report with an already-current path added.
   *
   * @param string $path
   *   The path whose shipped bytes were already in place.
   *
   * @return self
   *   A new report.
   */
  public function withCurrent(string $path): self {
    return new self(
      $this->written,
      $this->kept,
      $this->drifted,
      [...$this->current, $path],
    );
  }

  /**
   * This report with one file recorded as written.
   *
   * @param string $path
   *   The destination, project-relative.
   *
   * @return self
   *   A new report.
   */
  public function withWritten(string $path): self {
    return new self([...$this->written, $path], $this->kept, $this->drifted, $this->current);
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
    return new self($this->written, $this->kept, [...$this->drifted, $path], $this->current);
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
    return new self($this->written, [...$this->kept, $path], $this->drifted, $this->current);
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
    $lines = [];
    // The count is what CHANGED. "wrote 21 file(s)" on a project where nothing
    // moved is a number that teaches a reader to ignore the line.
    if ($this->written !== []) {
      $lines[] = sprintf('wrote %d file(s)', count($this->written));
    }
    if ($this->current !== []) {
      $lines[] = sprintf('%d file(s) already current', count($this->current));
    }
    if ($lines === []) {
      $lines[] = 'nothing to do — the pack is current';
    }
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
