<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A variable the line binds is read as what it holds.
 *
 * `f=droost/droost-workflow/run.json; rm $f` deleted the run record at `hard`:
 * no rule read `$f`, a limitation this guard stated (F-125). The loop form,
 * `for f in <record>; do rm "$f"; done`, was stopped only by accident: the
 * loop's word list was read as the operands of a command named `for`, which
 * also refused every loop that only READ protected files. P6 run 14's `for f
 * in …/history/*.spec.md; do head -1 $f; done` was refused so (F-124), and so
 * was `git check-ignore` on settings.php, a read git's list did not know.
 */
final class GuardVariableBindingTest extends WorkflowTestCase {

  /**
   * What a variable must not carry past the guard.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function carried(): array {
    return [
      'a variable holding the record, removed' => ['f=droost/droost-workflow/run.json; rm $f'],
      'the loop form' => ['for f in droost/droost-workflow/run.json; do rm "$f"; done'],
      'the state directory through a variable' => ['D=droost/droost-workflow; rm -rf $D'],
      'the guard through a path prefix' => ['G=.claude/hooks; echo x > $G/droost-workflow-guard.php'],
      'exported, then truncated' => ['export F=droost/droost-workflow/run.json; truncate -s0 $F'],
      'braces' => ['f=droost/droost-workflow/run.json; rm ${f}'],
      'a glob over the state directory in a loop' => ['for f in droost/droost-workflow/*; do rm $f; done'],
      'a verb held in a variable' => ['V=droost:workflow:bypass; drush $V "x"'],
      'a loop body building a name it cannot read' => ['for f in droost/droost-workflow/history/*.spec.md; do mv "$f" "${f%.md}.old"; done'],
      'a loop body writing beside each name' => ['for f in droost/droost-workflow/history/*.spec.md; do cp x "$f.bak"; done'],
      'a suffix completing the record\'s name' => ['F=droost/droost-workflow/run; rm $F.json'],
      'the bypass, its verb completed by a suffix' => ['W="ddev drush droost:workflow"; $W:bypass "x"'],
      'a name bound twice holds either' => ['W=rm; false && W=ls; $W droost/droost-workflow/run.json'],
      'a variable copied from another' => ['f=droost/droost-workflow/run.json; g=$f; rm "$g"'],
      'a loop over a variable\'s glob' => ['D=droost/droost-workflow; for f in $D/*; do rm $f; done'],
      'declared, with a flag' => ['declare -x F=droost/droost-workflow/run.json; rm $F'],
      'a program with its flag, in quotes' => ['R="rm -f"; $R droost/droost-workflow/run.json'],
      'a value it cannot read keeps the one it could' => ['f=droost/droost-workflow/run.json; false && f=$(pwd); rm $f'],
    ];
  }

  /**
   * Each is refused.
   */
  #[DataProvider('carried')]
  public function testWhatVariableCarriesIsJudged(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(2, $exit, $command . ' was allowed: ' . $stderr);
  }

  /**
   * What reads, or writes only the agent's own spec, stays open.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function reads(): array {
    return [
      'P6 run 14 read loop' => ['for f in droost/droost-workflow/history/*.spec.md; do head -1 $f; done'],
      'git check-ignore on settings.php' => ['git check-ignore -v web/sites/default/settings.php'],
      'a literal program in a variable' => ['W=vendor/bin/droost-workflow; $W declare-criterion AC-1 "x"'],
      'lint every file' => ['for f in web/modules/custom/*/src/*.php; do php -l "$f"; done'],
      'php code with a variable' => ['f=1; php -r \'echo $f;\''],
      'a loop echoing its own words' => ['for i in 1 2 3; do echo "$i"; done'],
      'the spec, through a variable' => ['F=droost/droost-workflow/spec-t7-camp-filters.md; sed -i \'\' -e \'164s#| |$#| x |#\' $F'],
      'a value the line cannot read binds nothing' => ['f=$(pwd); ls $f'],
      'P6 run 14: the runner in a variable, bound twice' => ["W=\"ddev exec vendor/bin/droost-workflow\"; ls vendor/bin/droost-workflow && W=vendor/bin/droost-workflow\n\$W declare-criterion AC-1 \"x\"\n\$W declare-route /api/camps.json --status=403 \"y\""],
      'P6 runs 12 and 13: the verb completed by a suffix' => ['W="ddev drush droost:workflow"' . "\n" . '$W:declare-criterion AC-1 "x"' . "\n" . '$W:declare-changes --help 2>&1 | head -20'],
      'P6 run 14: settings.php, read' => ['git check-ignore -v web/sites/default/settings.php; ls -l web/sites/default/settings.php; tail -5 web/sites/default/settings.php'],
    ];
  }

  /**
   * Each is allowed.
   */
  #[DataProvider('reads')]
  public function testReadStaysOpen(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(0, $exit, $command . ' was refused: ' . $stderr);
  }

  /**
   * A project with a run under way at `hard` and archived specs in history.
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
    file_put_contents($root . '/droost/droost-workflow/history/run-a.spec.md', "# a\n");
    file_put_contents($root . '/droost/droost-workflow/history/run-b.spec.md', "# b\n");
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-binding',
      'current_phase' => 'code',
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
