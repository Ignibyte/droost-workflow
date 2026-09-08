<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

/**
 * What a gate needs to tell inherited debt from new.
 *
 * The baseline, and what the run has changed so far. Built by the gate
 * runner once per phase — after it has verified that the baseline on disk is
 * the one the run froze at begin — and handed to the executors that know how
 * to use it. Absent (NULL) when the run has no
 * baseline, or the lever turned it off: a gate then judges the whole tree,
 * exactly as before baselines existed.
 */
final class BaselineContext {

  /**
   * Constructs a BaselineContext.
   *
   * @param \Droost\Workflow\Baseline\Baseline $baseline
   *   The baseline the run is held to.
   * @param list<string> $changedFiles
   *   Project-relative files changed since the run's base commit (the working
   *   tree included). Consulted by the gates whose inherited entries are whole
   *   files (prettier): touching a listed file means formatting it.
   */
  public function __construct(
    public readonly Baseline $baseline,
    public readonly array $changedFiles = [],
  ) {}

  /**
   * Whether the run has changed a file.
   *
   * @param string $file
   *   A project-relative path.
   *
   * @return bool
   *   TRUE when it is among the changed files.
   */
  public function changed(string $file): bool {
    return in_array($file, $this->changedFiles, TRUE);
  }

}
