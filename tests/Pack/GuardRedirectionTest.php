<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * What a redirection writes to, as the shell reads it.
 *
 * The guard's tokeniser marks the word after `>` as a file the command
 * writes. A lone `&` ends a command, and it ended one without clearing that
 * mark, so in `2>&1` the `1` became a file this command writes. At `hard`
 * the shell's plan wall refuses any write outside the state directory, and
 * P6 run 6's agent met it on its first command: `ddev drush … 2>&1 | tail`,
 * refused as a write to `1`. No run before had reached the wall at `hard`.
 */
final class GuardRedirectionTest extends WorkflowTestCase {

  /**
   * Duplicating or closing a descriptor writes no file.
   */
  public function testDuplicatingDescriptorsWritesNoFileDuringPlan(): void {
    foreach ([
      'ddev drush droost:workflow:status 2>&1 | tail -10',
      'vendor/bin/phpunit >/dev/null 2>&1',
      'echo done >&2',
      'exec 3>&-',
      'git status 2>&1; git log -1 2>&1',
      'cat notes <&3',
      'vendor/bin/phpstan analyse 2>&1 || true',
    ] as $command) {
      $root = $this->rootInPlan();
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(0, $exit, $command . ' writes to no file: ' . $stderr);
    }
  }

  /**
   * Every spelling of a redirection to a file is still a write.
   */
  public function testRedirectingToFilesIsStillWritingDuringPlan(): void {
    foreach ([
      'echo x > web/modules/custom/a/a.module 2>&1',
      'echo x 2>&1 > web/modules/custom/a/a.module',
      'echo x &> web/modules/custom/a/a.module',
      'echo x &>> web/modules/custom/a/a.module',
      'echo x >& web/modules/custom/a/a.module',
      'echo x >| web/modules/custom/a/a.module',
      'echo x >>web/modules/custom/a/a.module',
    ] as $command) {
      $root = $this->rootInPlan();
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command . ' writes a project file during plan');
      $this->assertStringContainsString('web/modules/custom/a/a.module', $stderr, $command . ' names the file it writes');
    }
  }

  /**
   * The enforcement stays out of reach through every spelling, run or no run.
   */
  public function testTheEnforcementStaysUnreachableThroughEverySpelling(): void {
    foreach ([
      'echo x >& .claude/settings.json',
      'echo x &> .claude/settings.json',
      'echo x >| .claude/hooks/droost-workflow-guard.php',
      'echo x 2>&1 >.claude/settings.json',
      'echo x 2>&1 >> droost/droost-workflow/run.json',
    ] as $command) {
      $root = $this->makeRoot();
      [$exit] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command . ' reaches the enforcement');
    }
  }

  /**
   * A root whose run is in plan, at hard.
   *
   * @return string
   *   The root.
   */
  private function rootInPlan(): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-redirect',
      'current_phase' => 'plan',
      'phases' => ['plan' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
