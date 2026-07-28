<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Gate;

/**
 * What happened to one gate.
 *
 * Five words, and the two that mean "it did not run" are deliberately not one
 * word. A gate skipped because there is no site is an ordinary fact about a
 * CLI run; a gate whose tool is not installed is a broken setup. Same absence
 * of a result, opposite consequences — collapsing them is how "fast mode" and
 * "nothing was checked" become indistinguishable in a report, which is the
 * failure this whole package exists to prevent.
 */
enum GateStatus: string {

  // The gate ran and the artefact satisfied it.
  case Passed = 'passed';

  // The gate ran and the artefact did not satisfy it.
  case Failed = 'failed';

  // Environmental: the gate needs a booted site and there is none. Does not
  // block the run, and is never rendered as a pass.
  case SkippedNoSite = 'skipped-no-site';

  // Misconfigured: the gate is enabled but its tool is not installed. Fails
  // closed, because an environment that cannot run a gate it was told to run
  // is broken, not lenient.
  case ErrorToolMissing = 'error-tool-missing';

  // Configured off. Visibly distinct from every kind of "could not run".
  case Off = 'off';

  /**
   * Whether this status stops the run.
   *
   * @return bool
   *   TRUE for outcomes a run may not advance past.
   */
  public function blocksAdvance(): bool {
    return $this === self::Failed || $this === self::ErrorToolMissing;
  }

  /**
   * Whether the gate actually executed and was satisfied.
   *
   * The only status that may ever be counted as success. Written as its own
   * method so no caller has to remember which of the five are "sort of fine".
   *
   * @return bool
   *   TRUE only for Passed.
   */
  public function isPass(): bool {
    return $this === self::Passed;
  }

  /**
   * A short phrase for a human-facing report.
   *
   * @return string
   *   The rendering.
   */
  public function label(): string {
    return match ($this) {
      self::Passed => 'passed',
      self::Failed => 'FAILED',
      self::SkippedNoSite => 'skipped — no site',
      self::ErrorToolMissing => 'ERROR — tool missing',
      self::Off => 'off',
    };
  }

}
