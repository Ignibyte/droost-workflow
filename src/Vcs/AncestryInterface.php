<?php

declare(strict_types=1);

namespace Droost\Workflow\Vcs;

/**
 * Which branch is checked out, and whether it descends from a commit.
 *
 * Apart from VcsInterface so that the doubles of that interface need not
 * grow methods only `droost workflow:baseline` asks (F-390).
 */
interface AncestryInterface {

  /**
   * The branch checked out.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return string|null
   *   The branch name, or NULL when HEAD is detached or git cannot say.
   */
  public function branch(string $projectRoot): ?string;

  /**
   * Whether HEAD descends from a commit (or is it).
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $ancestor
   *   The commit.
   *
   * @return bool|null
   *   TRUE or FALSE, or NULL when git cannot say (an unknown commit).
   */
  public function descends(string $projectRoot, string $ancestor): ?bool;

}
