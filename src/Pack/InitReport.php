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
   */
  public function __construct(
    public readonly array $written = [],
    public readonly array $kept = [],
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
    return new self([...$this->written, $path], $this->kept);
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
    return new self($this->written, [...$this->kept, $path]);
  }

  /**
   * A short human-readable summary.
   *
   * @return string
   *   One line per outcome that occurred.
   */
  public function summary(): string {
    $lines = [sprintf('wrote %d file(s)', count($this->written))];
    foreach ($this->kept as $path) {
      $lines[] = sprintf('kept your existing %s', $path);
    }
    return implode("\n", $lines);
  }

}
