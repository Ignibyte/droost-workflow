<?php

declare(strict_types=1);

namespace Droost\Workflow\Vcs;

/**
 * Version control that can count the lines each file gained and lost.
 *
 * The run panel's Files tab says how much each file the run touched changed,
 * so a one-line fix and a rewritten file do not read alike.
 */
interface LineCountsInterface {

  /**
   * Each tracked file's lines added and removed since a base commit.
   *
   * Untracked files are not in it: git has no lines of theirs to compare.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $base
   *   The base commit.
   *
   * @return array<string, array{added: int|null, removed: int|null}>|null
   *   The counts by project-relative path, NULL counts for a binary file; or
   *   NULL when the repository cannot say.
   */
  public function lineCounts(string $projectRoot, string $base): ?array;

}
