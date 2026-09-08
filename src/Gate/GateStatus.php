<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * What happened to one gate.
 *
 * Seven words, and the ones that mean "it did not run" or "it did not block"
 * are deliberately not one word. A gate skipped because there is no site is an
 * ordinary fact about a CLI run; a gate whose tool is not installed is a broken
 * setup; a gate that found problems in report mode is a finding nobody may
 * mistake for a pass. Same absence of a blocking result, opposite meanings —
 * collapsing them is how "fast mode" and "nothing was checked" become
 * indistinguishable in a report, which is the failure this whole package
 * exists to prevent.
 */
enum GateStatus: string {

  // The gate ran and the artefact satisfied it.
  case Passed = 'passed';

  // The gate ran and the artefact did not satisfy it.
  case Failed = 'failed';

  // The gate ran in REPORT mode and found problems (or could not run): the
  // phase advances anyway, the findings ride the report, and the status is
  // visibly neither a pass nor a block. A lever-file `mode: report` (or a
  // contributed gate's declared default) is the only thing that produces
  // it, and the mandatory trio can never be put in that mode.
  case Reported = 'reported';

  // Environmental: the gate needs a booted site and there is none. Does not
  // block the run, and is never rendered as a pass.
  case SkippedNoSite = 'skipped-no-site';

  // Misconfigured: the gate is enabled but its tool is not installed. Fails
  // closed, because an environment that cannot run a gate it was told to run
  // is broken, not lenient.
  case ErrorToolMissing = 'error-tool-missing';

  // Configured off. Visibly distinct from every kind of "could not run".
  case Off = 'off';

  // Waived by the OPERATOR for this run, with a recorded reason. Never an
  // agent's move: the waiver enters only through the CLI command, and the
  // report prints it beside its reason — visible where the record is.
  case Waived = 'waived';

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
      self::Reported => 'REPORTED (report mode, not blocking)',
      self::SkippedNoSite => 'skipped — no site',
      self::ErrorToolMissing => 'ERROR — tool missing',
      self::Off => 'off',
      self::Waived => 'waived by the operator',
    };
  }

}
