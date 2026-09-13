<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Gate\GateStatus;

/**
 * What droost concluded about one item of a phase's checklist.
 *
 * A phase is a set of things that must be true before it ends, and each one is
 * adjudicated here — from evidence droost collected itself, never from the
 * agent's account of it. The distinction that matters is not "did it pass" but
 * "what does this state license": a blocked item stops the phase, and every
 * other state lets it move for a reason the record can name.
 *
 * Six words rather than a boolean, for the reason GateStatus is eight rather
 * than two. A gate that was off by preset, a gate with nothing to analyse and a
 * gate that genuinely checked all report "not blocking", and collapsing them is
 * how a run scores green having measured nothing. Every defect found in the
 * live rounds of 2026-09-12/13 lived in the gap between those meanings.
 *
 * The boolean still exists — `blocksAdvance()` — but it is DERIVED here rather
 * than stored, which is the whole point.
 */
enum CheckState: string {

  // The item exists on the checklist and has not been adjudicated. Distinct
  // from every other state: the absence of a measurement, not a measurement of
  // absence. A phase holding one has not finished its work, so this blocks.
  case Pending = 'pending';

  // Droost adjudicated the item green from evidence it collected itself.
  case Satisfied = 'satisfied';

  // Droost adjudicated the item not green. The phase stops here. Always
  // carries a Fault, which decides how — and whether — it can be lifted.
  case Blocked = 'blocked';

  // Honestly excluded: nothing to measure on this site or at this level. A
  // gate off by preset, or a site gate on a run with no booted site. The
  // record says so in words rather than showing a pass nobody earned.
  case NotApplicable = 'not_applicable';

  // Measured, and configured not to block. A contributed gate in `mode:
  // report` finds what it finds and the run moves; the finding is kept, and
  // the verdict is that the operator chose to be told rather than stopped.
  case Recorded = 'recorded';

  // An operator lifted an environment blocker, and the record says so. Never
  // reachable by the agent, and never from a Fault::Agent block.
  case Unblocked = 'unblocked';

  /**
   * Whether this state stops the phase.
   *
   * @return bool
   *   TRUE for the two states that mean the phase's work is not done.
   */
  public function blocksAdvance(): bool {
    return $this === self::Blocked || $this === self::Pending;
  }

  /**
   * Whether the item was actually measured.
   *
   * `Satisfied` and `Recorded` rest on a measurement. `NotApplicable` and
   * `Unblocked` are honest, and are not measurements — an evaluation that
   * counts them as verification is the reading failure this exists to prevent.
   *
   * @return bool
   *   TRUE when evidence was produced.
   */
  public function measured(): bool {
    return $this === self::Satisfied || $this === self::Recorded;
  }

  /**
   * The state a gate's outcome maps to.
   *
   * Derived rather than declared so no gate has to be rewritten and no two
   * surfaces can disagree: GateStatus stays the vocabulary the gates speak, and
   * this is the one translation of it.
   *
   * @param \Droost\Workflow\Gate\GateStatus $status
   *   The gate's outcome.
   *
   * @return self
   *   The checklist state.
   */
  public static function fromGateStatus(GateStatus $status): self {
    return match ($status) {
      GateStatus::Passed => self::Satisfied,
      GateStatus::Failed, GateStatus::ErrorToolMissing, GateStatus::ErrorToolFailed => self::Blocked,
      GateStatus::Reported => self::Recorded,
      GateStatus::SkippedNoSite, GateStatus::Off => self::NotApplicable,
      GateStatus::Waived => self::Unblocked,
    };
  }

  /**
   * A short phrase for a human-facing report.
   *
   * @return string
   *   The rendering.
   */
  public function label(): string {
    return match ($this) {
      self::Pending => 'not yet checked',
      self::Satisfied => 'satisfied',
      self::Blocked => 'BLOCKED',
      self::NotApplicable => 'not applicable',
      self::Recorded => 'recorded (not blocking)',
      self::Unblocked => 'unblocked by the operator',
    };
  }

}
