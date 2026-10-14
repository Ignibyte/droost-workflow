<?php

declare(strict_types=1);

namespace Droost\Workflow\Vcs;

/**
 * Turns a ref a caller named into the commit it names.
 *
 * Apart from VcsInterface so that the doubles of that interface need not
 * grow a method only `droost-workflow gate --base` asks.
 */
interface RefResolverInterface {

  /**
   * The commit a ref names, or NULL when it names none.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $ref
   *   A sha, branch, tag or any other revision git reads.
   *
   * @return string|null
   *   The full commit hash.
   */
  public function resolveCommit(string $projectRoot, string $ref): ?string;

}
