<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Gate\GateResult;

/**
 * One adjudicated item of a phase's checklist, ready to be stored.
 *
 * Immutable, and deliberately flat: this is a row, not a graph. The findings
 * and the raw output travel separately because they are the two things that
 * made `gate_results` unusable — a single phpcs entry measured 54,734 bytes on
 * a real record, stored twice, and made `gate_results` 113,313 bytes of a
 * 114,250-byte run.json. Rows go in rows.
 */
final class CheckRecord {

  /**
   * Constructs a CheckRecord.
   *
   * @param string $kind
   *   What sort of item: gate, knowledge, declaration, spec or seeker.
   * @param string $name
   *   The item's id within its kind — a gate name, a declaration kind.
   * @param \Droost\Workflow\Evidence\CheckState $state
   *   What droost concluded.
   * @param \Droost\Workflow\Evidence\Fault $fault
   *   Whose problem it is, when blocked.
   * @param string $summary
   *   One line, the sentence a human reads first.
   * @param string|null $remedy
   *   The command or action that clears an Environment fault. Meaningless on
   *   any other fault, and refused there: a remedy beside an Agent block reads
   *   as an escape, which is the one thing that must not exist.
   * @param string|null $subjectHash
   *   A fingerprint of WHAT was examined. This is what makes a green expire on
   *   its own: phpstan passing at 03:00 says nothing at 03:05 if a file moved,
   *   and until this existed nothing noticed.
   * @param int|null $exitCode
   *   The process exit code, when a process ran.
   * @param string|null $invocation
   *   The exact command line, so a reader can run it themselves.
   * @param string|null $startedAt
   *   When the check began, ISO-8601. Gate results carry a duration and no
   *   start, which is why ledger lines cannot be attributed to a phase today.
   * @param int|null $durationMs
   *   How long it took. Zero is a finding in itself — the tool never spawned.
   * @param list<array<string, mixed>> $findings
   *   Structured detail, stored one row each.
   * @param string $stdout
   *   What the tool wrote, already capped. Kept apart from the findings
   *   because they answer different questions: the findings are what droost
   *   parsed, this is what the tool said.
   * @param string $stderr
   *   Companion to $stdout.
   * @param string $provider
   *   The module that contributed this check, or '' for droost's own. Without
   *   it a contributed check is unattributable: "show me everything droost_jira
   *   asserted", and "this provider's checks all failed — is the provider
   *   broken or is the work bad?" are the first two questions anybody asks, and
   *   kind+name answers neither.
   */
  public function __construct(
    public readonly string $kind,
    public readonly string $name,
    public readonly CheckState $state,
    public readonly Fault $fault = Fault::None,
    public readonly string $summary = '',
    public readonly ?string $remedy = NULL,
    public readonly ?string $subjectHash = NULL,
    public readonly ?int $exitCode = NULL,
    public readonly ?string $invocation = NULL,
    public readonly ?string $startedAt = NULL,
    public readonly ?int $durationMs = NULL,
    public readonly array $findings = [],
    public readonly string $stdout = '',
    public readonly string $stderr = '',
    public readonly string $provider = '',
  ) {
    if ($this->kind === '' || $this->name === '') {
      throw new \InvalidArgumentException('A check record needs both a kind and a name.');
    }
    if ($this->state !== CheckState::Blocked && $this->fault !== Fault::None) {
      throw new \InvalidArgumentException(sprintf(
        'Only a blocked check carries a fault; "%s" is %s and declared fault "%s".',
        $this->name,
        $this->state->value,
        $this->fault->value,
      ));
    }
    if ($this->remedy !== NULL && $this->fault !== Fault::Environment) {
      throw new \InvalidArgumentException(sprintf(
        'A remedy belongs only to an environment fault; "%s" carries fault "%s". '
        . 'A remedy printed beside work the agent must simply do reads as a way out of doing it.',
        $this->name,
        $this->fault->value,
      ));
    }
  }

  /**
   * The record for one gate result.
   *
   * The translation happens here and nowhere else, so the gates keep speaking
   * GateStatus and no second surface can disagree about what it meant.
   *
   * @param \Droost\Workflow\Gate\GateResult $result
   *   The gate's outcome.
   * @param string|null $subjectHash
   *   A fingerprint of the files the gate examined, when it is knowable.
   * @param array<int|string, string> $declaredFaults
   *   The gate's own exit-code-to-fault map, from its declaration.
   * @param string|null $remedy
   *   The remedy, when the gate's provider names one.
   *
   * @return self
   *   The record.
   */
  public static function fromGate(
    GateResult $result,
    ?string $subjectHash = NULL,
    array $declaredFaults = [],
    ?string $remedy = NULL,
  ): self {
    $state = CheckState::fromGateStatus($result->status);
    $fault = $state === CheckState::Blocked
      ? Fault::faultFor($result->status, $result->exitCode, $declaredFaults)
      : Fault::None;

    return new self(
      'gate',
      $result->gate,
      $state,
      $fault,
      $result->summary !== '' ? $result->summary : ($result->skipReason ?? ''),
      $fault === Fault::Environment ? $remedy : NULL,
      $subjectHash,
      $result->exitCode,
      $result->invocation,
      NULL,
      $result->durationMs,
      $result->findings,
      $result->stdout,
      $result->stderr,
    );
  }

}
