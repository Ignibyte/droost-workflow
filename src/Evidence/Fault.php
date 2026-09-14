<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Gate\GateStatus;

/**
 * Whose problem a blocked item is — and therefore how it can be lifted.
 *
 * Fault never decides whether a phase advances. A blocked item stops the phase
 * whatever its fault; this decides what the agent is told to do about it, and
 * whether anyone may lift it at all.
 *
 * The distinction is not academic. Two runs on 2026-09-13 blocked on the SAME
 * gate for opposite reasons:
 *
 *   * phpunit blocked with two failing tests, one of which was an
 *     HTML-entity-encoded `javascript:` URL surviving a sanitizer. The agent
 *     called it "one small fix from passing" and asked the operator to WAIVE
 *     the gate. Waiving would have shipped a live XSS. No escape may exist.
 *   * phpunit blocked because the project root carried no phpunit.xml — which
 *     `droost:install` never writes and never mentions. The agent had done
 *     nothing wrong and could do nothing right; the run wedged until a human
 *     ruled.
 *
 * Identical status, identical block, opposite remedies. Without this field the
 * system cannot tell "you have not done the work" from "you have been handed an
 * impossible task", and the only tools it has are a retry budget that expires
 * and a waiver that should never have been offered in the first case.
 */
enum Fault: string {

  // Nothing is wrong. Carried by every state except Blocked.
  case None = 'none';

  // The work is not done. There is no escape and there must not be one.
  case Agent = 'agent';

  // The item cannot be satisfied from where the agent stands: a missing tool,
  // an unauthenticated CLI, a config file the installer never wrote. The block
  // still stands — the phase does not advance on a promise — but the record
  // names a remedy, and an OPERATOR may lift it, recorded as
  // CheckState::Unblocked. The agent may propose that and never perform it.
  case Environment = 'environment';

  // Which of the two it is cannot be told from here. A crashed tool is
  // genuinely ambiguous: phpstan dying on PHP the agent just wrote is Agent;
  // snyk dying unauthenticated is Environment. Treated AS Agent — no escape —
  // because the safe default when you cannot tell whose fault it is, is the
  // one that hands out no exit. A gate that knows better declares it.
  case Unknown = 'unknown';

  /**
   * Whether an operator may lift a block carrying this fault.
   *
   * @return bool
   *   TRUE only for Environment.
   */
  public function operatorMayUnblock(): bool {
    return $this === self::Environment;
  }

  /**
   * The fault a gate's outcome carries by default.
   *
   * @param \Droost\Workflow\Gate\GateStatus $status
   *   The gate's outcome.
   *
   * @return self
   *   The fault.
   */
  public static function fromGateStatus(GateStatus $status): self {
    return match ($status) {
      GateStatus::Failed => self::Agent,
      GateStatus::ErrorToolMissing => self::Environment,
      GateStatus::ErrorToolFailed => self::Unknown,
      default => self::None,
    };
  }

  /**
   * The fault for one outcome, honouring a gate's own declaration.
   *
   * `ErrorToolFailed` is the ambiguous one, and the gate that produced it
   * usually knows which it is. A contributed gate already documents its exit
   * codes in the `verdict` string its #[DroostGate] attribute carries — snyk's
   * names exit 2 as "the snyk CLI itself could not run — not authenticated
   * (`snyk auth`), no network, or no manifest it understands" — so a gate may
   * also declare the fault each exit code means, once, in the module that
   * knows.
   *
   * A declaration may only apply to a status that blocks, and may only move it
   * between Agent, Environment and Unknown: no gate may declare itself
   * faultless out of a block.
   *
   * @param \Droost\Workflow\Gate\GateStatus $status
   *   The gate's outcome.
   * @param int|null $exitCode
   *   The process exit code, when there was one.
   * @param array<int|string, string> $declared
   *   The gate's own exit-code-to-fault map, or an empty array.
   *
   * @return self
   *   The fault.
   */
  public static function faultFor(GateStatus $status, ?int $exitCode, array $declared = []): self {
    $default = self::fromGateStatus($status);
    if ($default === self::None || $exitCode === NULL || $declared === []) {
      return $default;
    }
    $named = $declared[$exitCode] ?? NULL;
    if (!is_string($named)) {
      return $default;
    }
    $resolved = self::tryFrom($named);

    return $resolved === NULL || $resolved === self::None ? $default : $resolved;
  }

  /**
   * What the agent is told to do about a block carrying this fault.
   *
   * @return string
   *   The sentence.
   */
  public function guidance(): string {
    return match ($this) {
      self::None => '',
      // "Nothing on YOUR side": an operator can waive a failing GATE on the
      // drush surface, and "there is no waiver for it" beside a run-level
      // remedy offering exactly that read as the envelope contradicting
      // itself. The claim that is true everywhere is about who holds the pen.
      self::Agent, self::Unknown => 'This is the work, not the setup: fix the cause and re-run. Nothing on your side waives it.',
      self::Environment => 'This cannot be fixed from where you are. Show the OPERATOR the remedy below and ask them to run it in their terminal; do not run it yourself and do not retry around it.',
    };
  }

}
