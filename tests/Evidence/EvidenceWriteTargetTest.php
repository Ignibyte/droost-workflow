<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * The evidence document is written where it says it was written.
 *
 * `--write=<path>` stripped the leading slash off an absolute path and
 * prepended the project root, so `--write=/tmp/round.md` created
 * `<root>/tmp/round.md` — a file nobody asked for, in a tree that is not
 * theirs — while the result reported `/tmp/round.md`. The report did not
 * merely give the wrong location: it named a file that did not exist, which
 * is the one thing an evidence artefact must never do about itself.
 */
final class EvidenceWriteTargetTest extends WorkflowTestCase {

  /**
   * An absolute target is honoured, and reported as itself.
   */
  public function testTheAbsoluteTargetIsWrittenWhereItWasAsked(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $this->facade($this->allGatesPass())->run($root);

    $elsewhere = sys_get_temp_dir() . '/droost-evidence-' . bin2hex(random_bytes(6)) . '/round.md';
    $result = $this->facade($this->allGatesPass())->evidence($root, NULL, $elsewhere);

    $this->assertFileExists($elsewhere, 'the absolute path is where it went');
    $this->assertSame($elsewhere, $result['written_to'] ?? NULL, 'and what it says');
    $this->assertFileDoesNotExist(
      rtrim($root, '/') . $elsewhere,
      'not re-rooted inside the project',
    );
    @unlink($elsewhere);
    @rmdir(dirname($elsewhere));
  }

  /**
   * A relative target still lands inside the project, reported in full.
   */
  public function testTheRelativeTargetStaysInsideTheProject(): void {
    $root = $this->makeRootWithConfig("preset: custom\n");
    $this->facade($this->allGatesPass())->run($root);

    $result = $this->facade($this->allGatesPass())->evidence($root, NULL, 'droost/evidence/here.md');

    $this->assertFileExists(rtrim($root, '/') . '/droost/evidence/here.md');
    $this->assertSame(
      rtrim($root, '/') . '/droost/evidence/here.md',
      $result['written_to'] ?? NULL,
      'the path it names is the path a reader can open',
    );
  }

  /**
   * An executor whose gates all pass, so a run exists to write evidence for.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The executor.
   */
  private function allGatesPass(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
  }

  /**
   * A fresh facade over a shared executor.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   The shared executor.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(GateExecutorInterface $executor): WorkflowFacade {
    return new WorkflowFacade(
      $executor,
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-08-26T12:00:00+00:00',
      static fn (): string => 'run-evidence-target',
    );
  }

}
