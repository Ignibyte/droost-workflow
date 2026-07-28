<?php

declare(strict_types=1);

namespace Drupal\droost_workflow_mcp;

use Drupal\droost_workflow\Cli\CliProcess;
use Drupal\droost_workflow\Driver\BootedSiteDriver;
use Drupal\droost_workflow\Gate\ShellGateExecutor;
use Drupal\droost_workflow\Mode\RunStateOnlySink;
use Drupal\droost_workflow\WorkflowFacade;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The facade construction and project-root resolution both MCP tools share.
 *
 * A trait rather than a shared parent class because the two tools deliberately
 * extend DIFFERENT droost bases: the read-only one extends DroostToolBase and
 * the destructive one extends DestructiveToolBase (which adds the CLI-transport
 * gate). A common parent would have to sit above both and cannot.
 *
 * Everything here is SELF-CONTAINED — it calls no method of the host class, so
 * the analyser needs no assumption about what the trait is mixed into and each
 * piece is unit-testable alone. The facade sits behind one overridable method
 * so a test can subclass a tool and return a fake, which is what makes the
 * argument dispatch testable without a booted container.
 */
trait WorkflowFacadeTrait {

  /**
   * Builds the facade wired for a BOOTED site.
   *
   * Transcribed from the Drush commands' wiring so all four surfaces (the CLI,
   * the Drush commands, and these two tools) drive the identical engine: a
   * shell executor over the package's own runner, the HttpKernel-backed site
   * driver — which is WHY the run tool must be shielded, since that driver
   * renders — and the run-state-only question sink, because an MCP call has no
   * interactive prompt to ask into.
   *
   * @return \Drupal\droost_workflow\WorkflowFacade
   *   The facade.
   */
  protected function facade(): WorkflowFacade {
    $clock = static fn (): int => (int) (hrtime(TRUE) / 1_000_000);
    return new WorkflowFacade(
      new ShellGateExecutor(CliProcess::run(...), $clock),
      new BootedSiteDriver($this->httpKernel(), $clock),
      new RunStateOnlySink(),
      static fn (): string => date('c'),
      static fn (): string => 'run-' . bin2hex(random_bytes(6)),
    );
  }

  /**
   * The HTTP kernel the booted-site driver issues its sub-requests through.
   *
   * @return \Symfony\Component\HttpKernel\HttpKernelInterface
   *   The kernel.
   */
  abstract protected function httpKernel(): HttpKernelInterface;

  /**
   * The repository to act on: the `project` argument, else the site's own root.
   *
   * Pure and total — it validates nothing, so the caller decides what an
   * unusable value means. Both tools then answer the same way: a failure
   * ENVELOPE naming the path, never a thrown exception, because an exception
   * escaping a tool body becomes a JSON-RPC protocol error that tells the
   * calling agent nothing it can act on.
   *
   * @param string $named
   *   The `project` argument, already coerced to a string ('' when absent).
   * @param string $default
   *   The project root to use when `project` is absent or empty.
   *
   * @return string
   *   The resolved root, which may not exist.
   */
  protected static function resolveRoot(string $named, string $default): string {
    return $named === '' ? $default : $named;
  }

  /**
   * Whether `$root` is usable as a project root.
   *
   * @param string $root
   *   The candidate.
   *
   * @return bool
   *   TRUE when it is an existing directory.
   */
  protected static function rootIsUsable(string $root): bool {
    return is_dir($root);
  }

  /**
   * The message returned when a project root cannot be used.
   *
   * Shared so both tools refuse in the same words — an agent that learns the
   * phrasing from one tool reads the other correctly.
   *
   * @param string $root
   *   The unusable root.
   *
   * @return string
   *   The failure message, naming the path.
   */
  protected static function unusableRootMessage(string $root): string {
    return sprintf(
      'Not a directory: "%s". Pass "project" as an absolute path to the repository, or omit it to use this site\'s own root.',
      $root,
    );
  }

}
