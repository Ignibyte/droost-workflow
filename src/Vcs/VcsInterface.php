<?php

declare(strict_types=1);

namespace Droost\Workflow\Vcs;

/**
 * The two facts a run wants from version control.
 *
 * Where the tree started, and what has changed since. An interface so a
 * surface can inject a fake, and so a project without git
 * answers honestly (NULL, an empty list) rather than failing a run over a
 * missing repository.
 */
interface VcsInterface {

  /**
   * The commit the working tree is at.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return string|null
   *   The commit hash, or NULL when there is no repository to ask.
   */
  public function head(string $projectRoot): ?string;

  /**
   * The files changed since a commit, the working tree included.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string|null $base
   *   The commit to compare against; NULL compares the working tree with HEAD.
   *
   * @return list<string>
   *   Project-relative paths, sorted, deduplicated. Empty when there is no
   *   repository or nothing changed.
   */
  public function changedFiles(string $projectRoot, ?string $base): array;

}
