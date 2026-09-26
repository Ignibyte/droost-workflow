<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A shell keyword in front of a command does not hide what the command is.
 *
 * `for i in 1; do php -r '…'; done` splits at its semicolons into `do php
 * -r '…'`, whose first word is `do`, and every rule that asks what a command
 * IS read the keyword. The interpreter rule never saw the `php`, so code that
 * emptied the guard or deleted the run record ran at `hard` inside any loop
 * or `if`, where the same line on its own is refused (F-118). Found replaying
 * P6 run 10's commands through the guard's new generator ledger, which had
 * missed `do ddev drush generate test:unit` for the same reason.
 *
 * And the other way round: after `done`, `fi` or `esac` the shell takes only
 * redirections, so `while read l; do …; done < ledger` reads the ledger. It
 * was refused as a write, because `done` is no command this guard knows.
 */
final class GuardShellKeywordTest extends WorkflowTestCase {

  /**
   * What a keyword must not hide.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function hidden(): array {
    return [
      'php code in a loop' => ['for i in 1; do php -r \'unlink("droost/droost-workflow/run.json");\'; done'],
      'php code in an if' => ['if true; then php -r \'unlink("droost/droost-workflow/run.json");\'; fi'],
      'the guard emptied in a loop' => ['for i in 1; do php -r \'file_put_contents(".claude/hooks/droost-workflow-guard.php", "");\'; done'],
      'python code in a loop' => ['for i in 1; do python3 -c \'import os; os.remove("droost/droost-workflow/run.json")\'; done'],
      'node code in a loop' => ['for i in 1; do node -e \'require("fs").unlinkSync("droost/droost-workflow/run.json")\'; done'],
      'a shell string in a while' => ['while true; do bash -c \'rm droost/droost-workflow/run.json\'; break; done'],
      'after an else' => ['if false; then true; else php -r \'unlink("droost/droost-workflow/run.json");\'; fi'],
      'after a bang' => ['! php -r \'unlink("droost/droost-workflow/run.json");\''],
      'a loop written onto the guard' => ['for i in 1; do echo x; done > .claude/hooks/droost-workflow-guard.php'],
      'an if written onto the record' => ['if true; then echo x; fi > droost/droost-workflow/run.json'],
    ];
  }

  /**
   * Each is refused, as the same command outside the keyword is.
   */
  #[DataProvider('hidden')]
  public function testWhatKeywordsLeadIsJudged(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(2, $exit, $command . ' was allowed: ' . $stderr);
  }

  /**
   * Loops and conditionals that only read or build stay open.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function ordinary(): array {
    return [
      'lint every file' => ['for f in web/modules/custom/*/src/*.php; do php -l "$f"; done'],
      'print a file if it exists' => ['if [ -f composer.json ]; then cat composer.json; fi'],
      'read the ledger into a loop' => ['while read -r line; do echo "$line"; done < droost/droost-workflow/tool-calls.jsonl'],
      'rebuild twice' => ['for i in 1 2; do ddev drush cr; done'],
      'harmless php in an if' => ['if true; then php -r \'echo 1;\'; fi'],
      'read the record in python' => ['if true; then python3 -c \'print(open("droost/droost-workflow/run.json").read())\'; fi'],
      'wait for the site' => ['until ddev drush status; do sleep 1; done'],
      'a variable named like a keyword' => ['else_file=1; echo $else_file'],
    ];
  }

  /**
   * Each is allowed.
   */
  #[DataProvider('ordinary')]
  public function testOrdinaryShellStaysOpen(string $command): void {
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
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-keyword',
      'current_phase' => 'code',
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
