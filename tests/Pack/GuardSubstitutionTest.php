<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A command substitution runs, so the guard judges the command inside it.
 *
 * `$( … )` was kept as one word and never looked inside, so the enforcement a
 * run rests on was one substitution away: the guard overwritten, the run
 * record deleted, both ALLOWED at `hard`. The only scan of a substitution's
 * contents was an accident: a pipe into an interpreter anywhere on the line
 * turned every multi-word argument into a command line. That accident refused
 * P6 run 10's seeker for a curl format string, `-w "$p %{http_code} "`, read
 * as a program named `$p`, because an earlier `URL=$(… | python3 -c …)` held
 * the pipe (F-114).
 */
final class GuardSubstitutionTest extends WorkflowTestCase {

  /**
   * What a substitution must not hide.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function hidden(): array {
    return [
      'the guard overwritten' => ['X=$(echo pwned > .claude/hooks/droost-workflow-guard.php)'],
      'the run record deleted' => ['echo $(rm droost/droost-workflow/run.json)'],
      'in double quotes' => ['echo "$(rm droost/droost-workflow/run.json)"'],
      'nested' => ['echo $(echo $(rm droost/droost-workflow/run.json))'],
      'behind a pipe inside' => ['X=$(ls | tee droost/droost-workflow/tool-calls.jsonl)'],
      'the operator\'s bypass' => ['X=$(drush droost:workflow:bypass hotfix)'],
      'backticks' => ['echo `rm droost/droost-workflow/run.json`'],
    ];
  }

  /**
   * Each is refused, as the same command outside a substitution is.
   */
  #[DataProvider('hidden')]
  public function testWhatSubstitutionRunsIsJudged(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(2, $exit, $command . ' was allowed: ' . $stderr);
  }

  /**
   * What stays ordinary work, the seeker's command among it.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function ordinary(): array {
    return [
      'P6 run 10\'s seeker' => ["URL=\$(ddev describe -j 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)[\"raw\"][\"primary_url\"])'); echo \$URL; for p in adult-leagues youth-leagues; do curl -sk \"\$URL/\$p\" -o /tmp/p.html -w \"\$p %{http_code} \"; done"],
      'a commit message' => ['git commit -m "$(cat msg.txt)"'],
      'the repository root' => ['cd $(git rev-parse --show-toplevel) && ls'],
      'arithmetic' => ['echo $((1 + 2))'],
      'reading the ledger' => ['echo "$(cat droost/droost-workflow/tool-calls.jsonl)"'],
      'single quotes are literal' => ["echo '\$(rm droost/droost-workflow/run.json)'"],
      'quoted parentheses inside' => ["X=\$(python3 -c 'print(\")\")'); echo \$X"],
    ];
  }

  /**
   * Each is allowed.
   */
  #[DataProvider('ordinary')]
  public function testOrdinarySubstitutionsStayOpen(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(0, $exit, $command . ' was refused: ' . $stderr);
  }

  /**
   * A project with a run under way and the files a run rests on.
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
      'run_id' => 'run-substitution',
      'current_phase' => 'code',
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
