<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A `find` that writes nothing is a read (F-97).
 *
 * P6 run 7's agent searched vendor/ for PHP files mentioning infection:
 * `find vendor -path '*droost*' -name '*.php' | xargs grep -ln infection`.
 * The guard glob-expanded the quoted `'*droost*'` against the project root,
 * as if the shell would, landed on droost.workflow.yml, and refused the
 * search as editing the dial mid-run. A find that deletes or runs something
 * is still judged by what its filters reach.
 */
final class GuardFindReadTest extends WorkflowTestCase {

  /**
   * Searches pass, however their patterns are spelled.
   */
  public function testFindsThatOnlyPrintPass(): void {
    $root = $this->rootInRun();
    foreach ([
      "find vendor -path '*droost*' -name '*.php'",
      "find vendor -path '*droost*' -name '*.php' 2>/dev/null | xargs grep -ln -i 'infection' 2>/dev/null | head",
      "find . -path '*workflow*' -type f | head -20",
      "find droost -name '*.jsonl'",
    ] as $command) {
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(0, $exit, $command . ': ' . $stderr);
    }
  }

  /**
   * A find that removes or rewrites is judged as before.
   */
  public function testFindsThatWriteAreStillJudged(): void {
    $root = $this->rootInRun();
    foreach ([
      'find . -name run.json -delete',
      "find droost -name 'tool-calls.jsonl' -exec rm {} +",
      "find . -name 'droost-workflow-guard.php' | xargs rm",
    ] as $command) {
      [$exit] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command);
    }
  }

  /**
   * A root with a live run, a lever file and a guard to reach.
   *
   * @return string
   *   The root.
   */
  private function rootInRun(): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    mkdir($root . '/vendor/droost/workflow/src', 0755, TRUE);
    mkdir($root . '/.claude/hooks', 0755, TRUE);
    file_put_contents($root . '/vendor/droost/workflow/src/A.php', "<?php\n");
    file_put_contents($root . '/droost.workflow.yml', "preset: xhigh\n");
    file_put_contents($root . '/.claude/hooks/droost-workflow-guard.php', "<?php\n");
    file_put_contents($root . '/droost/droost-workflow/tool-calls.jsonl', "\n");
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-find',
      'current_phase' => 'plan',
      'phases' => ['plan' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
