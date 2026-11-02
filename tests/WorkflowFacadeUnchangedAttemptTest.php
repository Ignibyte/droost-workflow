<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Mode\UnchangedAttempt;
use Droost\Workflow\Tests\Gate\PhpcsFailingExecutor;
use Droost\Workflow\WorkflowFacade;

/**
 * An attempt that changed nothing spends no retry (F-399, 2026-11-02).
 *
 * The agent ran the code phase three times in three minutes with the same
 * files and the same two failing gates; the third ended a run whose build was
 * sound. Here the same run, replayed: identical re-runs are answered and not
 * counted, and the first attempt that changes something is counted as ever.
 */
class WorkflowFacadeUnchangedAttemptTest extends WorkflowTestCase {

  /**
   * Re-running with nothing changed leaves the budget where it was.
   */
  public function testAnUnchangedAttemptIsNotCounted(): void {
    $root = $this->gitRoot("preset: custom\nmax_gate_retries: 1\n");
    $executor = new PhpcsFailingExecutor('2 errors in src/Thing.php');

    $this->assertSame(Outcome::Advanced, $this->facade($executor)->run($root)->outcome, 'plan');
    file_put_contents($root . '/src/Thing.php', "<?php\n// the ticket's change\n");

    $first = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $first->outcome);
    $this->assertSame(['phpcs' => 1], $first->state->feedbackAttempts, 'the first failure is counted');

    // F-399: the same files, the same failure, run again and again.
    foreach ([1, 2, 3] as $again) {
      $repeat = $this->facade($executor)->run($root);
      $this->assertSame(Outcome::Failed, $repeat->outcome);
      $this->assertSame(['phpcs' => 1], $repeat->state->feedbackAttempts, "repeat {$again} spent nothing");
      $this->assertFalse($repeat->state->retries()['exhausted'], "repeat {$again} did not end the run");
      $this->assertSame([UnchangedAttempt::row()], $repeat->blocked, 'and it says why');
    }
    $this->assertSame(4, $executor->executions['phpcs'], 'the gates still ran: the answer is real, only uncounted');

    // A fix that does not land: the file changed, so this IS a retry.
    file_put_contents($root . '/src/Thing.php', "<?php\n// tried again\n");
    $changed = $this->facade($executor)->run($root);
    $this->assertSame(Outcome::Failed, $changed->outcome);
    $this->assertTrue($changed->state->retries()['exhausted'], 'an attempt that changed something is counted as before');
  }

  /**
   * A gate that now says something else is a different attempt.
   *
   * A Snyk scan run through an MCP tool changes no file but changes what the
   * gate says; that attempt is counted like any other.
   */
  public function testADifferentFailureIsCounted(): void {
    $root = $this->gitRoot("preset: custom\nmax_gate_retries: 2\n");
    $executor = new PhpcsFailingExecutor('2 errors');
    $this->facade($executor)->run($root);
    file_put_contents($root . '/src/Thing.php', "<?php\n// change\n");
    $this->assertSame(['phpcs' => 1], $this->facade($executor)->run($root)->state->feedbackAttempts);

    $executor->summary = '1 error';
    $this->assertSame(['phpcs' => 2], $this->facade($executor)->run($root)->state->feedbackAttempts);
  }

  /**
   * A project root under git, with one committed file and the lever file.
   *
   * @param string $yaml
   *   The lever file.
   *
   * @return string
   *   The root.
   */
  private function gitRoot(string $yaml): string {
    $root = $this->makeRootWithConfig($yaml);
    @mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Thing.php', "<?php\n");
    $git = static function (string ...$args) use ($root): void {
      $cmd = 'git -C ' . escapeshellarg($root) . ' -c user.email=t@example.com -c user.name=t '
        . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
      exec($cmd, $out, $code);
      if ($code !== 0) {
        throw new \RuntimeException(implode("\n", $out));
      }
    };
    $git('init', '-q');
    $git('add', '-A');
    $git('commit', '-qm', 'base');
    return $root;
  }

  /**
   * A fresh facade per invocation, as separate processes arrive.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The gates.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $executor): WorkflowFacade {
    return new WorkflowFacade(
      $executor,
      new RunStateOnlySink(),
      static fn (): string => '2026-11-02T10:00:00+00:00',
      static fn (): string => 'run-unchanged',
    );
  }

}
