<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

use Composer\InstalledVersions;

/**
 * Whether the droost running is the one the project's lock pins.
 *
 * A project installs droost in a vendor directory of its own and commits the
 * lock beside it (`tools/droost/composer.lock`). Merging a branch that moves
 * the lock changes nothing on a developer's machine until they install it, so
 * a run could open on an older droost than the one the project reviewed and
 * committed: gates, checks and skills that are not the ones in the diff
 * (owner, 2026-10-30). A run begins only on the droost its lock pins.
 *
 * Only a lock inside the project, beside the vendor directory droost runs
 * from, is consulted: droost's own checkout, a global install, or a lock that
 * does not name droost/workflow say nothing.
 */
final class InstallFreshness {

  /**
   * Why a run must not begin on this install, or NULL when it may.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return string|null
   *   The refusal, or NULL.
   */
  public static function staleness(string $projectRoot): ?string {
    if (!class_exists(InstalledVersions::class) || !InstalledVersions::isInstalled('droost/workflow')) {
      return NULL;
    }
    return self::compare(
      $projectRoot,
      InstalledVersions::getReference('droost/workflow'),
      InstalledVersions::getInstallPath('droost/workflow'),
    );
  }

  /**
   * Compares an installed droost with the lock beside its vendor directory.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string|null $installed
   *   The installed droost/workflow's source reference.
   * @param string|null $installPath
   *   Where it is installed (`<dir>/vendor/droost/workflow`).
   *
   * @return string|null
   *   The refusal, or NULL when the two agree or nothing can be compared.
   */
  public static function compare(string $projectRoot, ?string $installed, ?string $installPath): ?string {
    if ($installed === NULL || $installed === '' || $installPath === NULL) {
      return NULL;
    }
    $root = rtrim((string) (realpath($projectRoot) ?: $projectRoot), '/');
    $dir = realpath(dirname($installPath, 3)) ?: dirname($installPath, 3);
    if ($dir !== $root && !str_starts_with($dir . '/', $root . '/')) {
      return NULL;
    }
    $lock = json_decode((string) @file_get_contents($dir . '/composer.lock'), TRUE);
    $locked = NULL;
    foreach (is_array($lock) ? [...(array) ($lock['packages'] ?? []), ...(array) ($lock['packages-dev'] ?? [])] : [] as $package) {
      if (is_array($package) && ($package['name'] ?? NULL) === 'droost/workflow') {
        $source = is_array($package['source'] ?? NULL) ? $package['source'] : [];
        $dist = is_array($package['dist'] ?? NULL) ? $package['dist'] : [];
        $reference = $source['reference'] ?? $dist['reference'] ?? NULL;
        $locked = is_string($reference) && $reference !== '' ? $reference : NULL;
      }
    }
    if ($locked === NULL || $locked === $installed) {
      return NULL;
    }
    $where = $dir === $root ? '' : substr($dir, strlen($root) + 1);
    return sprintf(
      'droost here is %s, but %scomposer.lock pins %s: the project committed a droost this machine has not installed. '
      . 'Install what the lock pins (`composer install%s`, `ddev composer install%s` on DDEV), then start the run. '
      . 'A run held to an older droost would be measured by gates that are not the ones the project reviewed.',
      substr($installed, 0, 7),
      $where === '' ? '' : $where . '/',
      substr($locked, 0, 7),
      $where === '' ? '' : ' -d ' . $where,
      $where === '' ? '' : ' -d ' . $where,
    );
  }

}
