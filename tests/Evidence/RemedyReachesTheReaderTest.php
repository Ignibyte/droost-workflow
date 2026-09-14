<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use PHPUnit\Framework\TestCase;

/**
 * An environment block names something somebody can actually do.
 *
 * `Fault::Environment`'s guidance is "Show the OPERATOR the remedy below and
 * ask them to run it in their terminal", and the stop hook prints that remedy
 * only when it is non-empty. No BUILT-IN gate could ever carry one:
 * `EvidenceRecorder::remedy()` reads the gate's frozen LEVERS, and the only
 * writer of that key is a contributed gate's own declaration. A reviewer
 * queried every blocked row in a real run and got `remedy=<NULL>` on all seven
 * — so the message told a reader to go and read a remedy that was the empty
 * string, every time.
 *
 * The text already existed. `ShellGateExecutor::toolFailedHint()` writes a
 * per-gate sentence naming the lever to fix, and it was going into the summary
 * alone. The summary is what HAPPENED; the remedy is what to DO; the surfaces
 * render them differently and a reader needs both.
 *
 * And a crashed tool was landing on `Fault::Unknown`, which is treated as
 * `Agent` — guidance "This is the work, not the setup … There is no waiver for
 * it", about a ruleset the tool could not load. `Unknown` is the right DEFAULT
 * for a crash, because phpstan dying on PHP the agent wrote is the agent's;
 * but the executor produces `toolFailed()` only for exit codes that mean "I
 * could not load my configuration", and there is nothing ambiguous about
 * those.
 */
final class RemedyReachesTheReaderTest extends TestCase {

  /**
   * A missing binary is the operator's to install, and says so.
   */
  public function testMissingToolsNameWhatToInstall(): void {
    $record = CheckRecord::fromGate(GateResult::toolMissing('phpunit', 'vendor/bin/phpunit'));

    $this->assertSame(CheckState::Blocked, $record->state);
    $this->assertSame(Fault::Environment, $record->fault);
    $remedy = (string) $record->remedy;
    $this->assertNotSame('', $remedy, 'the guidance says to read a remedy, so there is one');
    $this->assertStringContainsString('phpunit', $remedy, 'and it names the tool');
    $this->assertStringContainsString(
      'gates.phpunit.on',
      $remedy,
      'and the other answer, for a project that does not use it',
    );
  }

  /**
   * A tool that could not load its config is the setup, not the work.
   */
  public function testToolsThatCouldNotLoadTheirConfigAreEnvironmentFaults(): void {
    $record = CheckRecord::fromGate(GateResult::toolFailed(
      'phpcs',
      16,
      'the "Drupal" coding standard is not installed',
      'phpcs hit a processing error — check the ruleset gates.phpcs.standard names.',
      'vendor/bin/phpcs',
    ));

    $this->assertSame(Fault::Environment, $record->fault);
    $this->assertTrue(
      $record->fault->operatorMayUnblock(),
      'so there is a recorded way out, rather than "there is no waiver for it"',
    );
    $this->assertStringContainsString(
      'gates.phpcs.standard',
      (string) $record->remedy,
      'and the remedy names the lever that fixes it',
    );
  }

  /**
   * A gate cannot talk its way out of an AGENT fault.
   *
   * The declaration overrides only the ambiguous default. Failing lints and
   * failing tests are the work, and a remedy printed beside work somebody must
   * simply do reads as a way out of doing it — which is the invariant
   * `CheckRecord` already enforces and the reason this override is narrow.
   */
  public function testDeclarationsCannotDowngradeAnAgentFault(): void {
    $failed = new GateResult(
      'phpcs',
      GateStatus::Failed,
      exitCode: 3,
      summary: '19 violations',
      remedy: 'ask the operator to make this go away',
      declaredFault: 'environment',
    );

    $record = CheckRecord::fromGate($failed);

    $this->assertSame(Fault::Agent, $record->fault, 'failing lints are the work');
    $this->assertNull(
      $record->remedy,
      'and no command is printed beside work somebody simply has to do',
    );
  }

  /**
   * The project's own lever still wins over the gate's built-in answer.
   */
  public function testTheProjectsOwnRemedyWins(): void {
    $record = CheckRecord::fromGate(
      GateResult::toolMissing('snyk', 'node_modules/.bin/snyk'),
      NULL,
      [],
      'ask #security to add this runner to the CI image',
    );

    $this->assertSame(
      'ask #security to add this runner to the CI image',
      $record->remedy,
      'a project that has said something more useful is not overruled',
    );
  }

}
