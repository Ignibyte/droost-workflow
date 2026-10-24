<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

/**
 * Where a site's modules are, and what each one's info file says.
 *
 * Two of them: the booted site's module list (ExtensionListModuleLocator),
 * and a walk of the docroot's info files (FilesystemModuleLocator), so the
 * module docs answer the same way with Drupal running or broken.
 */
interface ModuleLocatorInterface {

  /**
   * Every discovered module.
   *
   * @return array<string, array{path: string, info: array<array-key, mixed>}>
   *   By machine name: the module's directory relative to the docroot, and
   *   its parsed info file.
   */
  public function modules(): array;

}
