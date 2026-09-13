<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\GateStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every gate outcome maps to a state and a fault, and the map is total.
 *
 * The table is written out rather than computed so that adding a GateStatus
 * without deciding what it means to a checklist fails here, loudly, instead of
 * defaulting to something plausible. A status that silently became "satisfied"
 * would be the exact defect this design exists to remove.
 */
#[CoversClass(CheckState::class)]
#[CoversClass(Fault::class)]
final class CheckStateTest extends TestCase {

  /**
   * Every case of GateStatus, and what it means to a checklist.
   *
   * @return array<string, array{GateStatus, CheckState, Fault, bool, bool}>
   *   Label => [status, state, fault, blocks, measured].
   */
  public static function outcomes(): array {
    return [
      'a gate that ran and was satisfied' => [
        GateStatus::Passed, CheckState::Satisfied, Fault::None, FALSE, TRUE,
      ],
      'a gate that found problems' => [
        GateStatus::Failed, CheckState::Blocked, Fault::Agent, TRUE, FALSE,
      ],
      'a report-mode gate, measured but not blocking' => [
        GateStatus::Reported, CheckState::Recorded, Fault::None, FALSE, TRUE,
      ],
      'a site gate on a run with no site' => [
        GateStatus::SkippedNoSite, CheckState::NotApplicable, Fault::None, FALSE, FALSE,
      ],
      'a tool this project does not carry' => [
        GateStatus::ErrorToolMissing, CheckState::Blocked, Fault::Environment, TRUE, FALSE,
      ],
      'a tool that crashed — whose fault is unknowable from here' => [
        GateStatus::ErrorToolFailed, CheckState::Blocked, Fault::Unknown, TRUE, FALSE,
      ],
      'a gate off by preset' => [
        GateStatus::Off, CheckState::NotApplicable, Fault::None, FALSE, FALSE,
      ],
      'a gate an operator waived' => [
        GateStatus::Waived, CheckState::Unblocked, Fault::None, FALSE, FALSE,
      ],
    ];
  }

  /**
   * The mapping holds, in both directions, for every outcome.
   */
  #[DataProvider('outcomes')]
  public function testOutcomeMapsToStateAndFault(
    GateStatus $status,
    CheckState $state,
    Fault $fault,
    bool $blocks,
    bool $measured,
  ): void {
    $this->assertSame($state, CheckState::fromGateStatus($status));
    $this->assertSame($fault, Fault::fromGateStatus($status));
    $this->assertSame($blocks, CheckState::fromGateStatus($status)->blocksAdvance());
    $this->assertSame($measured, CheckState::fromGateStatus($status)->measured());
  }

  /**
   * The map covers every case, so a new status cannot slip through.
   */
  public function testTheMapIsTotal(): void {
    $covered = array_map(static fn (array $row): GateStatus => $row[0], array_values(self::outcomes()));

    $this->assertEqualsCanonicalizing(
      GateStatus::cases(),
      $covered,
      'a GateStatus with no checklist meaning must fail here, not default to something plausible',
    );
  }

  /**
   * A pending item blocks: the absence of a measurement is not a pass.
   */
  public function testPendingBlocksAndMeasuresNothing(): void {
    $this->assertTrue(CheckState::Pending->blocksAdvance());
    $this->assertFalse(CheckState::Pending->measured());
  }

  /**
   * The honest non-measurements advance without counting as verification.
   *
   * The whole discrimination problem in one assertion: these move the run and
   * must never be counted as verification.
   */
  public function testHonestNonMeasurementsAdvanceWithoutCountingAsVerified(): void {
    foreach ([CheckState::NotApplicable, CheckState::Unblocked] as $state) {
      $this->assertFalse($state->blocksAdvance(), $state->value . ' advances');
      $this->assertFalse($state->measured(), $state->value . ' measured nothing');
    }
  }

  /**
   * Only an environment fault may be lifted, and only by an operator.
   */
  public function testOnlyEnvironmentIsUnblockable(): void {
    $this->assertTrue(Fault::Environment->operatorMayUnblock());
    $this->assertFalse(Fault::Agent->operatorMayUnblock());
    $this->assertFalse(Fault::Unknown->operatorMayUnblock(), 'unknown is treated as agent — no exit');
    $this->assertFalse(Fault::None->operatorMayUnblock());
  }

  /**
   * A gate may declare what its own exit codes mean.
   *
   * Snyk exit 2 is "the CLI could not run — not authenticated", which is the
   * operator's problem; the same crash status from phpstan is not.
   */
  public function testGateMayDeclareTheFaultOfItsOwnExitCodes(): void {
    $this->assertSame(
      Fault::Environment,
      Fault::faultFor(GateStatus::ErrorToolFailed, 2, [2 => 'environment']),
      'snyk says exit 2 is an unauthenticated CLI',
    );
    $this->assertSame(
      Fault::Unknown,
      Fault::faultFor(GateStatus::ErrorToolFailed, 1, [2 => 'environment']),
      'an exit code the gate did not name keeps the default',
    );
  }

  /**
   * No declaration can turn a block into a pass, or invent a fault on a pass.
   */
  public function testDeclarationCannotEscapeBlockOrInventFault(): void {
    $this->assertSame(
      Fault::Agent,
      Fault::faultFor(GateStatus::Failed, 1, [1 => 'none']),
      'declaring "none" on a failure would be a gate excusing itself',
    );
    $this->assertSame(
      Fault::None,
      Fault::faultFor(GateStatus::Passed, 0, [0 => 'environment']),
      'a passing gate carries no fault whatever it declares',
    );
  }

}
