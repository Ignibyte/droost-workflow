<?php

declare(strict_types=1);

namespace Droost\Workflow\Pack;

/**
 * The pack could not be installed into a project.
 */
final class PackError extends \RuntimeException {

  /**
   * A directory that this package does not own is in the way.
   *
   * Refused rather than overwritten. The sentinel is the only evidence that a
   * directory was written by this package; without one, somebody else's
   * skill of the same name is about to be destroyed.
   *
   * @param string $path
   *   The directory, relative to the project root.
   *
   * @return self
   *   The error.
   */
  public static function notOurs(string $path): self {
    return new self(sprintf(
      '%s already exists and was not created by droost_workflow (no %s in '
      . 'it) — refusing to overwrite it. Move it aside, or delete it if you '
      . 'want the pack there.',
      $path,
      PackManifest::SENTINEL,
    ));
  }

  /**
   * A pack file is missing from the installed package.
   *
   * @param string $path
   *   The source path that should have existed.
   *
   * @return self
   *   The error.
   */
  public static function sourceMissing(string $path): self {
    return new self(sprintf(
      'The pack file %s is listed in the manifest but missing from this '
      . 'package — the installation is incomplete.',
      $path,
    ));
  }

  /**
   * A destination could not be written.
   *
   * @param string $path
   *   The path, relative to the project root.
   * @param string $why
   *   What went wrong.
   *
   * @return self
   *   The error.
   */
  public static function unwritable(string $path, string $why): self {
    return new self(sprintf('Could not write %s (%s).', $path, $why));
  }

}
