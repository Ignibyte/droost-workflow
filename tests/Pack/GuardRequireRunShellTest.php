<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\Levers;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The require_run wall holds the shell as it holds Edit and Write (F-398).
 *
 * With no run open, an Edit to a module README was refused while `sed -i`
 * over 22 module READMEs and `git rm` of 56 test files under the same paths
 * went through Bash (reported by a project's upgrade agent, 2026-10-30). A command that
 * writes, moves or deletes under custom code is the same act as the edit.
 */
final class GuardRequireRunShellTest extends WorkflowTestCase {

  /**
   * What writes under custom code with no run is refused, each way a shell has.
   */
  public function testShellWritesUnderCustomCodeAreWalled(): void {
    $root = $this->makeRoot();
    foreach ([
      "sed -i 's/old/new/' web/modules/custom/acme/README.md",
      "sed -i.bak -e 's/old/new/' web/modules/custom/acme/acme.module",
      'git rm -r -q web/modules/custom/acme/tests',
      'rm -rf web/modules/custom/acme/tests',
      'rm web/themes/custom/olivero_sub/olivero_sub.theme',
      'mv web/modules/custom/acme/a.php web/modules/custom/acme/b.php',
      'git mv web/modules/custom/acme/a.php web/modules/custom/acme/b.php',
      'cp /tmp/x.php web/modules/custom/acme/x.php',
      'touch web/profiles/custom/site/site.profile',
      "perl -pi -e 's/a/b/' web/modules/custom/acme/acme.module",
      'echo x > web/modules/custom/acme/acme.module',
      'printf x | tee web/modules/custom/acme/notes.txt',
      "cd web/modules/custom/acme && sed -i 's/a/b/' acme.module",
      'truncate -s 0 web/modules/custom/acme/acme.module',
    ] as $command) {
      [$exit, , $stderr] = $this->bash($root, $command);
      $this->assertSame(2, $exit, $command . ' writes custom code with no run');
      $this->assertStringContainsString('no active run', $stderr, $command);
      $this->assertStringContainsString('droost workflow:bypass', $stderr, $command);
    }
  }

  /**
   * Reading custom code, and writing anywhere else, are anyone's.
   */
  public function testReadsAndOtherPathsAreFree(): void {
    $root = $this->makeRoot();
    foreach ([
      "sed 's/old/new/' web/modules/custom/acme/acme.module",
      'grep -rn foo web/modules/custom',
      'cat web/modules/custom/acme/acme.module > /tmp/acme.out',
      'cp web/modules/custom/acme/acme.module /tmp/',
      "sed -i 's/a/b/' docs/README.md",
      'git rm docs/old.md',
      'rm -rf /tmp/scratch',
      'git status',
    ] as $command) {
      [$exit, , $stderr] = $this->bash($root, $command);
      $this->assertSame(0, $exit, $command . ': ' . $stderr);
    }
  }

  /**
   * The lever, the operator's bypass and an open run stand the wall down.
   */
  public function testTheWallStandsDownAsTheEditWallDoes(): void {
    $write = "sed -i 's/a/b/' web/modules/custom/acme/README.md";

    $off = $this->makeRootWithConfig("require_run: off\n");
    $this->assertSame(0, $this->bash($off, $write)[0], 'require_run: off');

    $soft = $this->makeRootWithConfig("require_run: soft\n");
    [$exit, $stdout] = $this->bash($soft, $write);
    $this->assertSame(0, $exit, 'require_run: soft nudges');
    $this->assertStringContainsString('no active run', $stdout);

    $granted = $this->makeRoot();
    @mkdir($granted . '/droost/droost-workflow', 0777, TRUE);
    file_put_contents($granted . '/droost/droost-workflow/bypass.json', (string) json_encode([
      'reason' => 'TICKET-1 READMEs',
      'granted_at' => '2026-11-02T00:00:00Z',
    ]));
    $this->assertSame(0, $this->bash($granted, $write)[0], 'the operator granted a bypass');

    $running = $this->makeRoot();
    @mkdir($running . '/droost/droost-workflow', 0777, TRUE);
    file_put_contents($running . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'r1',
      'current_phase' => 'code',
      'phases' => ['code' => 'active'],
      'enforcement' => 'hard',
    ]));
    $this->assertSame(0, $this->bash($running, $write)[0], 'inside a run, code is built');

    $named = $this->makeRoot();
    file_put_contents(Levers::path($named), "require_run: hard\ncustom_code: \"recipes\"\n");
    $this->assertSame(2, $this->bash($named, "sed -i 's/a/b/' recipes/site/recipe.yml")[0], 'a directory custom_code names');
  }

  /**
   * Runs the guard's shell tier on one command.
   *
   * @param string $root
   *   The project.
   * @param string $command
   *   The command.
   *
   * @return array{0: int, 1: string, 2: string}
   *   Exit code, stdout, stderr.
   */
  private function bash(string $root, string $command): array {
    return $this->guard($root, 'operator-commands', ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);
  }

}
