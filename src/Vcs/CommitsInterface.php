<?php

declare(strict_types=1);

namespace Droost\Workflow\Vcs;

/**
 * Commits a set of paths, and nothing else that is staged.
 *
 * The one write this package makes to the repository: the operator's
 * `droost workflow:baseline --commit` records what it just measured (F-391).
 */
interface CommitsInterface {

  /**
   * Commits these paths as they are in the working tree.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $paths
   *   Project-relative paths.
   * @param string $message
   *   The commit message.
   *
   * @return array{0: string|null, 1: string}
   *   The new commit and who committed it, or NULL with why nothing was
   *   committed (no identity, a placeholder identity, nothing changed).
   */
  public function commitPaths(string $projectRoot, array $paths, string $message): array;

}
