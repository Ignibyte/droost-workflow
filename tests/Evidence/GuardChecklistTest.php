<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use PHPUnit\Framework\TestCase;

/**
 * The Stop hook names what is unresolved, and never hardens without a record.
 *
 * The guard is a standalone script with no autoloader, so it is exercised the
 * way it actually runs: as a process, with a payload on stdin.
 */
final class GuardChecklistTest extends TestCase {

  /**
   * A scratch project root.
   */
  private string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    $this->root = sys_get_temp_dir() . '/droost-guard-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents(
      $this->root . '/droost/droost-workflow/run.json',
      json_encode([
        'v' => 1,
        'run_id' => 'run-abc',
        'current_phase' => 'code',
        'phases' => ['code' => 'active'],
        'enforcement' => 'hard',
      ]),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->root)) {
      exec('rm -rf ' . escapeshellarg($this->root));
    }
  }

  /**
   * Runs the guard as a process, the way an editor does.
   *
   * @return array{0: int, 1: string}
   *   The exit code and everything it said.
   */
  private function guard(string $mode = 'stop'): array {
    $script = dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php';
    // PHP_BINARY and the inherited environment, the way GuardTest does it:
    // $_ENV is empty under this build's variables_order, so a hand-built env
    // loses PATH and the child cannot find php at all.
    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $this->root;
    $process = proc_open(
      [PHP_BINARY, $script, $mode],
      [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
      $pipes,
      $this->root,
      $env,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], '{}');
    fclose($pipes[0]);
    $said = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $said];
  }

  /**
   * With no evidence store, the guard says exactly what it always said.
   *
   * The whole read fails open. A hook that hardened because its record was
   * missing would turn a storage problem into an agent that cannot end a turn.
   */
  public function testTheMessageIsUnchangedWithoutAnyStore(): void {
    [$exit, $said] = $this->guard();

    $this->assertSame(2, $exit, 'enforcement is hard, so a mid-phase stop is still refused');
    $this->assertStringContainsString('a run is active in phase "code"', $said);
    $this->assertStringNotContainsString('unresolved', $said, 'nothing is claimed about checks it cannot see');
  }

  /**
   * The unresolved items are named, with their faults.
   */
  public function testBlockedChecksAreNamedWithTheirFault(): void {
    $store = new EvidenceStore($this->root);
    $store->record('run-abc', 'code', new CheckRecord(
      'gate', 'phpstan', CheckState::Blocked, Fault::Agent, '3 errors in src/',
    ));
    $store->record('run-abc', 'code', new CheckRecord('gate', 'phpcs', CheckState::Satisfied));

    [$exit, $said] = $this->guard();

    $this->assertSame(2, $exit);
    $this->assertStringContainsString('1 check(s) are unresolved', $said);
    $this->assertStringContainsString('phpstan [agent] — 3 errors in src/', $said);
    $this->assertStringNotContainsString('phpcs', $said, 'a satisfied check is not a blocker');
  }

  /**
   * An environment fault carries its remedy, addressed to the operator.
   *
   * The case this exists for: phpunit blocking because the project root has no
   * phpunit.xml is not the agent failing to do the work, and telling it to try
   * harder wedges the run.
   */
  public function testAnEnvironmentBlockNamesTheOperatorRemedy(): void {
    (new EvidenceStore($this->root))->record('run-abc', 'code', new CheckRecord(
      'gate', 'phpunit', CheckState::Blocked, Fault::Environment,
      'no phpunit.xml at the project root', 'drush droost:workflow:install',
    ));

    [, $said] = $this->guard();

    $this->assertStringContainsString('phpunit [environment]', $said);
    $this->assertStringContainsString('the OPERATOR clears this with: drush droost:workflow:install', $said);
  }

  /**
   * A check the run never adjudicated still holds the phase.
   */
  public function testPendingChecksHoldThePhase(): void {
    (new EvidenceStore($this->root))->record('run-abc', 'code', new CheckRecord(
      'gate', 'coverage', CheckState::Pending,
    ));

    [, $said] = $this->guard();

    $this->assertStringContainsString('coverage', $said);
  }

  /**
   * Checks belonging to another phase are not this phase's problem.
   */
  public function testOnlyTheOpenPhaseCounts(): void {
    (new EvidenceStore($this->root))->record('run-abc', 'test', new CheckRecord(
      'gate', 'playwright', CheckState::Blocked, Fault::Agent, 'a failing spec',
    ));

    [, $said] = $this->guard();

    $this->assertStringNotContainsString('playwright', $said);
  }

  /**
   * The guard's SQL and `CheckState::blocksAdvance()` name the same states.
   *
   * There are two definitions of "what stops a phase ending" and they are not
   * connected. `EvidenceStore::unresolved()` filters with the enum; the guard
   * cannot call it — it carries no autoloader, deliberately, and must keep
   * none — so it re-asks the question in raw SQL as `state IN ('blocked',
   * 'pending')`.
   *
   * They agree today. Nothing made them. Add a state, or change what
   * `blocksAdvance()` returns, and the enum moves while a string literal in a
   * 540-line procedural script does not — and the thing that silently stops
   * agreeing is the WALL, which is the one component whose failure mode is
   * letting a turn end on unresolved work. So the connection is this test.
   */
  public function testTheGuardsSqlAgreesWithTheEnum(): void {
    $guard = file_get_contents(dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php');
    $this->assertIsString($guard, 'the guard is where it is expected to be');

    $found = preg_match("/state IN \\(([^)]*)\\)/", $guard, $match);
    $this->assertSame(1, $found, "the guard still filters with `state IN (…)`");

    // The guard's SQL lives inside a single-quoted PHP string, so every quote
    // in it arrives here backslash-escaped. Unescape before reading, or the
    // list comes back empty and the test passes by matching nothing against
    // nothing — which is the failure mode this test exists to prevent.
    preg_match_all("/'([a-z_]+)'/", str_replace('\\', '', $match[1]), $states);
    $inSql = $states[1];
    sort($inSql);
    $this->assertNotSame([], $inSql, 'the state list was actually read out of the SQL');

    $fromEnum = [];
    foreach (CheckState::cases() as $case) {
      if ($case->blocksAdvance()) {
        $fromEnum[] = $case->value;
      }
    }
    sort($fromEnum);

    $this->assertSame(
      $fromEnum,
      $inSql,
      'the guard blocks on exactly the states the enum says block; one of the two moved',
    );
  }

}
