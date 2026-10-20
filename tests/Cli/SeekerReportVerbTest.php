<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * Seeker-report takes the ledger from a file or stdin, and says what it frees.
 *
 * The first subject's run: given a file, it ignored it and waited on stdin
 * for good (F-318); recording an inspection at a hold released the hold and
 * nothing said so (F-319).
 */
final class SeekerReportVerbTest extends WorkflowTestCase {

  /**
   * A file named is read; stdin is never touched; a bad file is refused.
   */
  public function testFileArgumentIsReadAndStdinNever(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $stdin = function (): string {
      $this->fail('stdin was read although a file was named');
    };
    [$code, $out] = $this->cli(['seeker-report', 'droost/droost-workflow/nope.md'], $root, $stdin);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('is not a readable file', $out);

    [$code, $out] = $this->cli(['seeker-report', 'a.md', 'b.md'], $root, $stdin);
    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code);
    $this->assertStringContainsString('(one file)', $out);
  }

  /**
   * An inspection recorded at a hold says that it released it.
   */
  public function testReleasedHoldIsSaid(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $facade = $this->facade();
    $this->assertSame(Outcome::Paused, $facade->run($root)->outcome);
    $facade->answer($root, 'continue');
    $section = "## Seeker Inspection\n\nInspector: independent\n\n(no findings)\n";
    $paused = FALSE;
    for ($i = 0; $i < 4 && !$paused; $i++) {
      $outcome = $this->facade()->run($root);
      if ($outcome->outcome === Outcome::InspectionDue) {
        $this->facade()->recordSeeker($root, $section);
      }
      $paused = $outcome->outcome === Outcome::Paused;
    }
    $this->assertTrue($paused, 'the run reached a hold');
    $this->assertNotNull((new RunStateStore($root))->load()->awaiting ?? NULL);

    file_put_contents($root . '/inspection.md', "# Spec\n\n" . $section);
    [$code, $out] = $this->cli(['seeker-report', 'inspection.md'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $this->assertNull((new RunStateStore($root))->load()->awaiting ?? NULL, 'the inspection released the hold');
    $this->assertStringContainsString('recording the inspection released that hold', $out);

    // The released hold is counted as released, never as answered (F-321).
    $run = $this->facade()->status($root)['run'];
    $this->assertIsArray($run);
    $this->assertSame(1, $run['answered'], 'the plan check-in, answered');
    $this->assertSame(1, $run['released'], 'the code hold, released unanswered');
  }

  /**
   * An answer with no terminal is recorded as the agent's, and said (F-335).
   *
   * A check-in is the human's decision; a live run's four were all answered
   * by the agent's own session, and the record read "answered: 4".
   */
  public function testAnswerWithNoTerminalIsTheAgents(): void {
    $root = $this->makeRootWithConfig("preset: custom\nmode: pair\n");
    $this->assertSame(Outcome::Paused, $this->facade()->run($root)->outcome);

    [$code, $out] = $this->cli(['answer', 'build it'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_OK, $code, $out);
    $history = (new RunStateStore($root))->load()->qaHistory ?? [];
    $last = end($history);
    $this->assertIsArray($last);
    $this->assertSame('agent', $last['via'] ?? NULL, 'no terminal: the agent answered');
    $run = $this->facade()->status($root)['run'];
    $this->assertIsArray($run);
    $this->assertSame(1, $run['answered_by_agent']);
  }

  /**
   * Runs the dispatcher, both streams into one.
   *
   * @param list<string> $argv
   *   The arguments.
   * @param string $root
   *   The project.
   * @param (callable(): string)|null $stdin
   *   What stdin holds, or NULL.
   *
   * @return array{int, string}
   *   The exit code and the output.
   */
  private function cli(array $argv, string $root, ?callable $stdin = NULL): array {
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $dispatcher = new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-19T00:00:00+00:00', static fn (): string => 'run-seeker', $stdin);

    return [$dispatcher->dispatch($argv, $root), implode("\n", $lines)];
  }

  /**
   * A facade whose gates all pass.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $executor = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };

    return new WorkflowFacade($executor, new RunStateOnlySink(), static fn (): string => '2026-10-19T00:00:00+00:00', static fn (): string => 'run-seeker');
  }

}
