<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A pipe hands its reader a program only when the reader runs what it reads.
 *
 * `echo "drush droost:workflow:bypass x" | sh` runs the pipe as shell, and the
 * guard reads what `echo` hands over as a command line. It did that for any
 * interpreter at the reading end, anywhere on the line, so `curl … | python3
 * -c "…"`, where the pipe is only data for python's own program, turned every
 * quoted argument on the whole line into a command line. P6 run 11's `…|
 * python3 -c "…"; ddev drush php:eval '$n=…; $n->set(…)'` was refused as a
 * program named `$n` (F-122).
 *
 * And code piped into PHP, Python, Perl, Ruby or Node was judged by nothing:
 * the tokeniser re-read `echo`'s argument as shell, where the code has no
 * command to find, so `echo "open('<guard>','w').write('')" | python3` emptied
 * the guard at `hard`, while the same code as `python3 -c` is refused
 * (F-123, found bisecting F-122).
 */
final class GuardPipedProgramTest extends WorkflowTestCase {

  /**
   * What a pipe must not carry past the guard.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function carried(): array {
    return [
      'a verb piped into a bare shell' => ['echo "drush droost:workflow:bypass x" | sh'],
      'a verb piped into bash -s' => ['echo "drush droost:workflow:bypass x" | bash -s'],
      'a verb piped into bash -o pipefail' => ['echo "drush droost:workflow:bypass x" | bash -o pipefail'],
      'a verb piped into bash -e' => ['echo "drush droost:workflow:bypass x" | bash -e'],
      'a verb piped into env sh' => ['echo "drush droost:workflow:bypass x" | env sh'],
      'a verb piped past a line end' => ["echo \"drush droost:workflow:bypass x\" |\nsh"],
      'the record removed through a shell' => ['echo "rm droost/droost-workflow/run.json" | sh'],
      'the guard emptied through python' => ['echo "open(\'.claude/hooks/droost-workflow-guard.php\',\'w\').write(\'\')" | python3'],
      'the record removed through python' => ['echo "import os; os.remove(\'droost/droost-workflow/run.json\')" | python3'],
      'a verb in python on stdin' => ['echo "import os; os.system(\'drush droost:workflow:bypass x\')" | python3'],
      'a verb in php on stdin' => ['echo "<?php shell_exec(\'drush droost:workflow:bypass x\');" | php'],
      'the record emptied through php' => ['echo \'<?php file_put_contents("droost/droost-workflow/run.json", "");\' | php'],
      'a cat\'d program emptying the guard' => ['cat empty-the-guard.php | php'],
      'a variable program after a data pipe' => ['echo x | python3 -c "import sys"; $B declare-criterion AC-1 "x"'],
    ];
  }

  /**
   * Each is refused.
   */
  #[DataProvider('carried')]
  public function testWhatPipeCarriesIsJudged(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(2, $exit, $command . ' was allowed: ' . $stderr);
  }

  /**
   * A pipe into a reader with a program of its own is data.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function data(): array {
    return [
      'the line P6 run 11 was refused' => ['ddev exec \'curl -s "localhost:8025/api/v1/search?query=to:x@example.com" \' | python3 -c "import json,sys; d=json.load(sys.stdin); print(d[\'messages\'][0][\'Subject\'])"; ddev drush php:eval \'$n=\Drupal\node\Entity\Node::load(20); $n->set("field_capacity", 3)->save();\''],
      'drush output into json.tool' => ['ddev drush php:eval \'$n=1; echo json_encode([$n]);\' | python3 -m json.tool'],
      'python -c, then quoted php' => ['echo x | python3 -c "import sys"; echo \'$n; a\''],
      'node -e reads stdin' => ['cat package.json | node -e "let s=\'\';process.stdin.on(\'data\',d=>s+=d)"; echo \'$x; y\''],
      'perl -ne filters stdin' => ['echo x | perl -ne \'print\'; echo \'$n; a\''],
      'php -R per line' => ['echo x | php -R \'echo $argn;\'; echo \'$n; a\''],
      'a python script reads stdin' => ['echo x | python3 bin/count.py; echo \'$n; a\''],
      'python reads the record from stdin code' => ['echo "print(open(\'droost/droost-workflow/run.json\').read())" | python3'],
      'php code with variables on stdin' => ['echo \'<?php $x = 1; echo $x;\' | php'],
    ];
  }

  /**
   * Each is allowed.
   */
  #[DataProvider('data')]
  public function testDataPipeStaysOpen(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(0, $exit, $command . ' was refused: ' . $stderr);
  }

  /**
   * A project with a run under way at `hard` and the files a run rests on.
   *
   * @return string
   *   The root.
   */
  private function liveRoot(): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow/history', 0755, TRUE);
    mkdir($root . '/.claude/hooks', 0755, TRUE);
    file_put_contents($root . '/.claude/hooks/droost-workflow-guard.php', "<?php\n");
    file_put_contents($root . '/droost/droost-workflow/tool-calls.jsonl', "\n");
    file_put_contents($root . '/empty-the-guard.php', '<?php file_put_contents(".claude/hooks/droost-workflow-guard.php", "");');
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-piped',
      'current_phase' => 'code',
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
