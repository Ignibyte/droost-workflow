<?php

declare(strict_types=1);

namespace Droost\Workflow\Vcs;

/**
 * Version control that can tell a file a run added from one it changed.
 *
 * A house rule about new markup (`sdc_markup`) is about what the run ADDED:
 * editing a template that was already there is maintenance, writing a new
 * one outside a component is the break.
 */
interface AddedFilesInterface {

  /**
   * The files added since a base commit, untracked ones included.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $base
   *   The base commit.
   *
   * @return list<string>|null
   *   Project-relative paths, or NULL when the repository cannot say.
   */
  public function addedFiles(string $projectRoot, string $base): ?array;

}
