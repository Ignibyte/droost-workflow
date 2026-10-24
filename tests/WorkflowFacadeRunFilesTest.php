<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\WorkflowFacade;

/**
 * The files a run changed, as the panel's Files tab lists them.
 */
final class WorkflowFacadeRunFilesTest extends WorkflowTestCase {

  private const LEVERS = "preset: custom\nmode: agentic\nseekers:\n  on: false\n";

  /**
   * Added, modified and deleted, counted, newest first; not what it found.
   *
   * A file the tree already held changed when the run began, and left alone
   * since, is not the run's; nor is the run's own state directory.
   */
  public function testTheRunsOwnChangesWithTheirLines(): void {
    $root = $this->makeRootWithConfig(self::LEVERS);
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Old.php', "<?php\n\$a = 1;\n");
    file_put_contents($root . '/src/Gone.php', "<?php\n\$b = 2;\n\$c = 3;\n");
    file_put_contents($root . '/README.md', "readme\n");
    exec(sprintf('cd %s && git init -q . && git add -A && git -c user.name=t -c user.email=t@example.invalid commit -q -m base 2>&1', escapeshellarg($root)), $out, $exit);
    $this->assertSame(0, $exit, implode("\n", $out));
    file_put_contents($root . '/README.md', "readme, edited before the run\n");

    $facade = $this->facade();
    $facade->run($root);

    file_put_contents($root . '/src/Old.php', "<?php\n\$a = 2;\n");
    touch($root . '/src/Old.php', 1000);
    unlink($root . '/src/Gone.php');
    file_put_contents($root . '/src/New.php', "<?php\n\$d = 4;");
    touch($root . '/src/New.php', 2000);

    $this->assertSame([
      ['path' => 'src/New.php', 'status' => 'A', 'added' => 2, 'removed' => 0],
      ['path' => 'src/Old.php', 'status' => 'M', 'added' => 1, 'removed' => 1],
      ['path' => 'src/Gone.php', 'status' => 'D', 'added' => 0, 'removed' => 3],
    ], $facade->runFiles($root));
  }

  /**
   * Outside a repository there is no saying, which is not "nothing changed".
   */
  public function testNoRepositoryIsUnknown(): void {
    $root = $this->makeRootWithConfig(self::LEVERS);
    $facade = $this->facade();
    $facade->run($root);
    $this->assertNull($facade->runFiles($root));
  }

  /**
   * A facade whose gates all pass.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(): WorkflowFacade {
    $passes = new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return new GateResult($gate->name, GateStatus::Passed, 0, 1, 'ok');
      }

    };
    return new WorkflowFacade(
      $passes,
      new RunStateOnlySink(),
      static fn (): string => '2026-10-22T12:00:00+00:00',
      static fn (): string => 'run-files',
    );
  }

}
