<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Mcp;

use Drupal\droost_workflow_mcp\WorkflowFacadeTrait;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A minimal host for the MCP tools' shared trait, so its logic is assertable.
 *
 * The trait is deliberately free of any host-class call, which is exactly what
 * makes this possible: no container, no droost, no MCP SDK — just the pure
 * project-root decisions the two Tool plugins delegate to. A NAMED class rather
 * than an anonymous one so the analyser can see these methods at level max.
 */
final class FacadeTraitHost {

  use WorkflowFacadeTrait;

  /**
   * Exposes the root resolution.
   *
   * @param string $named
   *   The `project` argument as the tool coerced it.
   * @param string $default
   *   The site's own root.
   *
   * @return string
   *   The resolved root.
   */
  public static function probeResolveRoot(string $named, string $default): string {
    return self::resolveRoot($named, $default);
  }

  /**
   * Exposes the usability check.
   *
   * @param string $root
   *   The candidate.
   *
   * @return bool
   *   Whether it is usable as a project root.
   */
  public static function probeRootIsUsable(string $root): bool {
    return self::rootIsUsable($root);
  }

  /**
   * Exposes the refusal message.
   *
   * @param string $root
   *   The unusable root.
   *
   * @return string
   *   The message an agent receives.
   */
  public static function probeUnusableRootMessage(string $root): string {
    return self::unusableRootMessage($root);
  }

  /**
   * {@inheritdoc}
   */
  protected function httpKernel(): HttpKernelInterface {
    throw new \LogicException('the facade is never built in these tests');
  }

}
