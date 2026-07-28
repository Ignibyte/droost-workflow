<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Gate;

/**
 * What one gate reported.
 *
 * Carries the invocation as well as the outcome. "phpcs failed" sends someone
 * looking at their code; "phpcs failed, and here is the command that was run"
 * lets them see that the standard was wrong — and when a tool is missing
 * entirely, the attempted invocation is the whole of the useful information.
 */
final class GateResult {

  /**
   * How many findings a result carries before it is truncated.
   */
  public const FINDINGS_CAP = 50;

  /**
   * Constructs a GateResult.
   *
   * @param string $gate
   *   The gate name.
   * @param \Drupal\droost_workflow\Gate\GateStatus $status
   *   What happened.
   * @param int|null $exitCode
   *   The tool's exit code, or NULL when nothing ran.
   * @param int|null $durationMs
   *   How long it took, supplied by the caller — no value object here reads a
   *   clock, so that every report is reproducible.
   * @param string $summary
   *   One line a human can read.
   * @param list<array<string, mixed>> $findings
   *   Structured findings, already capped.
   * @param bool $truncated
   *   Whether findings were dropped to fit the cap.
   * @param string|null $skipReason
   *   Why the gate did not run, when it did not.
   * @param string|null $invocation
   *   The command that ran, or that would have.
   */
  public function __construct(
    public readonly string $gate,
    public readonly GateStatus $status,
    public readonly ?int $exitCode = NULL,
    public readonly ?int $durationMs = NULL,
    public readonly string $summary = '',
    public readonly array $findings = [],
    public readonly bool $truncated = FALSE,
    public readonly ?string $skipReason = NULL,
    public readonly ?string $invocation = NULL,
  ) {}

  /**
   * A gate that is configured off.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return self
   *   The result.
   */
  public static function off(string $gate): self {
    return new self($gate, GateStatus::Off, summary: 'configured off');
  }

  /**
   * A gate that needs a booted site when there is none.
   *
   * @param string $gate
   *   The gate name.
   * @param string $reason
   *   Why no site was available.
   *
   * @return self
   *   The result.
   */
  public static function skippedNoSite(
    string $gate,
    string $reason = 'no booted site (CLI surface)',
  ): self {
    return new self(
      $gate,
      GateStatus::SkippedNoSite,
      summary: 'not run — ' . $reason,
      skipReason: $reason,
    );
  }

  /**
   * A gate whose tool is not installed.
   *
   * @param string $gate
   *   The gate name.
   * @param string $invocation
   *   The command that could not be run.
   *
   * @return self
   *   The result.
   */
  public static function toolMissing(
    string $gate,
    string $invocation,
  ): self {
    return new self(
      $gate,
      GateStatus::ErrorToolMissing,
      summary: 'could not run: ' . $invocation,
      invocation: $invocation,
    );
  }

  /**
   * Caps a finding list, reporting whether anything was dropped.
   *
   * @param string $gate
   *   The gate name.
   * @param \Drupal\droost_workflow\Gate\GateStatus $status
   *   What happened.
   * @param int $exitCode
   *   The tool's exit code.
   * @param int $durationMs
   *   How long it took.
   * @param string $summary
   *   One line a human can read.
   * @param list<array<string, mixed>> $findings
   *   Every finding, before capping.
   * @param string $invocation
   *   The command that ran.
   *
   * @return self
   *   The result, with findings capped.
   */
  public static function ran(
    string $gate,
    GateStatus $status,
    int $exitCode,
    int $durationMs,
    string $summary,
    array $findings,
    string $invocation,
  ): self {
    return new self(
      $gate,
      $status,
      $exitCode,
      $durationMs,
      $summary,
      array_slice($findings, 0, self::FINDINGS_CAP),
      count($findings) > self::FINDINGS_CAP,
      NULL,
      $invocation,
    );
  }

  /**
   * This result as plain data.
   *
   * @return array<string, mixed>
   *   The serialized result.
   */
  public function toArray(): array {
    return [
      'gate' => $this->gate,
      'status' => $this->status->value,
      'exit_code' => $this->exitCode,
      'duration_ms' => $this->durationMs,
      'summary' => $this->summary,
      'findings' => $this->findings,
      'truncated' => $this->truncated,
      'skip_reason' => $this->skipReason,
      'invocation' => $this->invocation,
    ];
  }

}
