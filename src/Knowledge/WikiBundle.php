<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Engine\Wiki\BundleLocatorInterface;

/**
 * A wiki bundle at a known directory.
 *
 * The booted site resolves the bundle from its settings (WikiSettings);
 * `vendor/bin/droost` has read the same setting from config/sync already.
 */
final class WikiBundle implements BundleLocatorInterface {

  /**
   * Constructs a WikiBundle.
   *
   * @param string $path
   *   The bundle's directory, absolute.
   */
  public function __construct(private readonly string $path) {}

  /**
   * {@inheritdoc}
   */
  public function bundlePath(): string {
    return $this->path;
  }

}
