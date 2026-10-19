<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Contract;

use Droost\Workflow\Evidence\ScaffoldRecord;
use Droost\Workflow\Tests\Levers;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * What a generator writes is recorded as the generator's (F-65, DO8).
 */
final class GeneratorRecordTest extends WorkflowTestCase {

  /**
   * A drush generate's new files land in the scaffold record, hashed.
   */
  public function testGeneratedFilesAreRecorded(): void {
    foreach (['ddev drush generate test:kernel', 'vendor/bin/drush gen plugin:block', 'dcg hook'] as $command) {
      $root = $this->project();
      file_put_contents($root . '/already.txt', "untracked before\n");

      [$exit] = $this->bash($root, 'operator-commands', $command);
      $this->assertSame(0, $exit, $command);
      mkdir($root . '/web/modules/custom/x/tests/src/Kernel', 0755, TRUE);
      file_put_contents($root . '/web/modules/custom/x/tests/src/Kernel/XTest.php', "<?php // generated\n");
      $this->bash($root, 'nudge', $command);

      $this->assertSame(
        ['web/modules/custom/x/tests/src/Kernel/XTest.php'],
        ScaffoldRecord::untouched($root, $root . '/droost/droost-workflow', 'run-gen'),
        $command . ': the generated file, and only it, is the generator\'s',
      );
      file_put_contents($root . '/web/modules/custom/x/tests/src/Kernel/XTest.php', "<?php // written by the agent\n");
      $this->assertSame([], ScaffoldRecord::untouched($root, $root . '/droost/droost-workflow', 'run-gen'), 'once edited, it is the run\'s');
    }
  }

  /**
   * Any other command records nothing.
   */
  public function testOtherCommandsRecordNothing(): void {
    $root = $this->project();
    $this->bash($root, 'operator-commands', 'composer install');
    file_put_contents($root . '/new.php', "<?php\n");
    $this->bash($root, 'nudge', 'composer install');
    $this->assertFileDoesNotExist($root . '/droost/droost-workflow/scaffolded.jsonl');
  }

  /**
   * A git project in a live run.
   *
   * @return string
   *   The root.
   */
  private function project(): string {
    $root = $this->makeRoot();
    file_put_contents(Levers::path($root), "preset: custom\n");
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/.gitignore', "droost/droost-workflow/\n");
    exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1');
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-gen',
      'current_phase' => 'code',
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

  /**
   * A Bash call through one of the guard's modes.
   *
   * @param string $root
   *   The root.
   * @param string $mode
   *   The guard mode.
   * @param string $command
   *   The command.
   *
   * @return array{int, string, string}
   *   Exit, stdout, stderr.
   */
  private function bash(string $root, string $mode, string $command): array {
    return $this->guard($root, $mode, [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => $command],
      'tool_response' => [],
    ]);
  }

}
