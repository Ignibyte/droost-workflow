<?php

declare(strict_types=1);

namespace Droost\Workflow\Intake;

/**
 * One thing an intake's check found missing or wrong.
 */
final class IntakeFinding {

  /**
   * Constructs a finding.
   *
   * @param string $check
   *   The check: files, evidence, coverage, consulted, answered or ladder.
   * @param string $message
   *   What is wrong and what to do, in one sentence.
   */
  public function __construct(
    public readonly string $check,
    public readonly string $message,
  ) {}

  /**
   * The finding as data.
   *
   * @return array{check: string, message: string}
   *   The finding.
   */
  public function toArray(): array {
    return ['check' => $this->check, 'message' => $this->message];
  }

}
