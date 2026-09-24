<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A heredoc fed to php, python, perl, node or ruby is code, not shell (F-78).
 *
 * P6 run 6's agent edited a PHP file with `python3 - <<'EOF'`. The guard kept
 * the body for its checks, as it keeps any interpreter's, and tokenised it as
 * shell. Python's `'''` flipped the quote parity, a PHP line like
 * `  $values = [];` fell outside every quote, and the guard refused the edit
 * as a command whose program is a variable. The same lesson the guard learned
 * for `python3 -c` code: a body in another language is checked as text, for
 * operator verbs and for writes to the enforcement, and never parsed as shell.
 */
final class GuardHeredocCodeTest extends WorkflowTestCase {

  /**
   * The shape of the command P6 run 6 was refused, cut down.
   */
  private const RUN_SIX = <<<'CMD'
cd web/modules/custom/example_home; python3 - <<'EOF'
p='example_home.deploy.php'
s=open(p).read()
start=s.index("  $hero = '03377ad5")
new='''  $hero = '03377ad5';
  $items = [
    ['uuid' => $hero, 'inputs' => ['title' => "The rink's own headline"]],
  ];

  $values = [];
  foreach ($items as $item) {
    $values[] = $item;
  }
'''
s=s[:start]+new
open(p,'w').write(s)
EOF
CMD;

  /**
   * The refused edit is an edit, and it passes.
   */
  public function testCodeHeredocIsNotReadAsShell(): void {
    [$exit, , $stderr] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => self::RUN_SIX]]);
    $this->assertSame(0, $exit, 'a Python edit to a PHP file is not a variable run as a program: ' . $stderr);

    [$exit] = $this->guard($this->rootInPlan(), 'operator-commands', ['tool_input' => ['command' => "python3 - <<'EOF'\nif 2 > 1:\n    print('ok')\nEOF"]]);
    $this->assertSame(0, $exit, 'a comparison in Python is not a redirect during plan');
  }

  /**
   * What matters in code is still found in code.
   */
  public function testCodeHeredocStillCarriesItsChecks(): void {
    foreach ([
      "python3 - <<'EOF'\nimport subprocess\nsubprocess.run(['drush', 'droost:workflow:bypass', 'x'])\nEOF" => 'an operator verb',
      "python3 - <<'EOF'\nopen('.claude/hooks/droost-workflow-guard.php', 'w').write('')\nEOF" => 'a write to the guard',
      "ddev drush php:script - <<'PHP'\n<?php file_put_contents('droost/droost-workflow/run.json', '{}');\nPHP" => 'a write to the run record through drush',
      "node <<'JS'\nrequire('fs').writeFileSync('droost/droost-workflow/evidence.sqlite', '')\nJS" => 'a write to the store',
    ] as $command => $what) {
      [$exit] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $what . ' is still refused');
    }
  }

  /**
   * The run's spec is the agent's in code too; its evidence is not (F-98).
   *
   * P6 run 7's agent filled the spec's Verified By cells with a Python
   * heredoc and was refused as writing the enforcement, told the file was
   * the operator's, while the Write tool and `sed -i` both allow the spec.
   */
  public function testCodeMayEditTheSpecAndNotTheEvidence(): void {
    $spec = "python3 - <<'EOF'\np='droost/droost-workflow/spec-t7-camp-filters.md'\ns=open(p).read()\nopen(p,'w').write(s.replace('| |','| proved |'))\nEOF";
    [$exit, , $stderr] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => $spec]]);
    $this->assertSame(0, $exit, 'a script editing the spec: ' . $stderr);
    [$exit] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => "python3 -c \"open('droost/droost-workflow/spec.md','a').write('x')\""]]);
    $this->assertSame(0, $exit, 'inline code editing the spec');

    foreach ([
      "python3 - <<'EOF'\nopen('droost/droost-workflow/tool-calls.jsonl','a').write('{\"tool\":\"droost_search\"}')\nEOF",
      "python3 -c \"open('droost/droost-workflow/guard-calls.jsonl','w')\"",
      "python3 - <<'EOF'\np='droost/droost-workflow/spec-x.md'\nd='droost/droost-workflow'\nopen(d+'/scaffolded.jsonl','w')\nEOF",
    ] as $command) {
      [$exit] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command);
    }
  }

  /**
   * A shell heredoc is still shell, and a data heredoc is still data.
   */
  public function testShellHeredocsAreStillShell(): void {
    [$exit] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => "bash <<'EOF'\n\$runner droost:workflow:bypass x\nEOF"]]);
    $this->assertSame(2, $exit, 'a variable run as a program inside bash is still refused');

    [$exit] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => "cat > notes.php <<'PHP'\n<?php \$x = 1;\nPHP"]]);
    $this->assertSame(0, $exit, 'a data heredoc is data');
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
      'run_id' => 'run-heredoc',
      'current_phase' => 'plan',
      'phases' => ['plan' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
