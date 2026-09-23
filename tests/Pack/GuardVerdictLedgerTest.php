<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Every row the guard writes about itself says what it decided.
 *
 * The guard appends one row per invocation to `guard-calls.jsonl`, and the
 * evaluation's §7a counts them. Until this test, only two of the guard's
 * twenty-seven refusals set a verdict first, so every other row read
 * `invoked`. At `soft` that hid little. At `hard`, which is what the `high`
 * preset turns on, the plan wall refuses edits, and the record could not
 * show one of them.
 */
final class GuardVerdictLedgerTest extends WorkflowTestCase {

  /**
   * The guard's source file.
   */
  private const GUARD = __DIR__ . '/../../pack/hooks/droost-workflow-guard.php';

  /**
   * Exactly one blocking exit, inside guard_refuse().
   *
   * A second `exit(2)` would be a refusal that skips the ledger, which is how
   * twenty-five of them came to read `invoked`. Read with the tokenizer, so a
   * comment that mentions exit(2) is not a false alarm and a real one is not
   * missed.
   */
  public function testTheOnlyBlockingExitIsGuardRefuse(): void {
    $tokens = token_get_all((string) file_get_contents(self::GUARD));
    $function = NULL;
    $exits = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
      $token = $tokens[$i];
      if (is_array($token) && $token[0] === T_FUNCTION) {
        $next = $this->nextMeaningful($tokens, $i);
        $function = is_array($tokens[$next]) && $tokens[$next][0] === T_STRING ? $tokens[$next][1] : $function;
      }
      if (!is_array($token) || $token[0] !== T_EXIT) {
        continue;
      }
      $argument = '';
      for ($j = $i + 1; $j < $count && $tokens[$j] !== ';'; $j++) {
        $argument .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
      }
      $exits[] = [str_replace(' ', '', $argument), $function, $token[2]];
    }

    $blocking = array_values(array_filter($exits, static fn (array $exit): bool => $exit[0] === '(2)'));
    $this->assertCount(1, $blocking, 'one exit(2) in the guard, and it is the chokepoint');
    $this->assertSame('guard_refuse', $blocking[0][1]);
    foreach ($exits as [$argument, , $line]) {
      $this->assertContains(
        $argument,
        ['(0)', '(2)', '($crashExit())'],
        sprintf('line %d exits with %s, which no reader of the ledger expects', $line, $argument),
      );
    }
  }

  /**
   * Every refusal names its wall with a literal an evaluator can grep for.
   *
   * The operator commands add the verb to a literal prefix, which still
   * starts with the literal. An empty or computed rule would turn the Rule
   * column back into the "does not say" the chokepoint exists to end.
   */
  public function testEveryRefusalNamesItsRuleLiterally(): void {
    $tokens = token_get_all((string) file_get_contents(self::GUARD));
    $rules = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
      $token = $tokens[$i];
      if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== 'guard_refuse') {
        continue;
      }
      $open = $this->nextMeaningful($tokens, $i);
      if ($tokens[$open] !== '(') {
        continue;
      }
      $previous = $this->previousMeaningful($tokens, $i);
      if (is_array($tokens[$previous]) && $tokens[$previous][0] === T_FUNCTION) {
        continue;
      }
      $first = $this->nextMeaningful($tokens, $open);
      $this->assertIsArray($tokens[$first], sprintf('the refusal at line %d names its rule', $token[2]));
      $this->assertSame(T_CONSTANT_ENCAPSED_STRING, $tokens[$first][0], sprintf('the rule at line %d is a literal', $token[2]));
      $rule = trim($tokens[$first][1], '\'"');
      $this->assertMatchesRegularExpression('/^[a-z][a-z:-]*$/', $rule, sprintf('line %d', $token[2]));
      $rules[] = $rule;
    }

    $this->assertGreaterThanOrEqual(27, count($rules), 'every refusal site calls guard_refuse');
    foreach (['plan-wall', 'plan-wall:shell', 'stop-hold', 'require-run', 'protected-path:editor'] as $wall) {
      $this->assertContains($wall, $rules, $wall . ' is a named refusal');
    }
  }

  /**
   * At hard, the plan wall's refusal is a row that says so.
   */
  public function testThePlanWallAtHardRefusesAndNamesItsRule(): void {
    $root = $this->rootWithRun('plan', 'hard');

    [$exit] = $this->guard($root, 'pre-tool-use', ['tool_input' => ['file_path' => 'src/Thing.php']]);

    $this->assertSame(2, $exit);
    $row = $this->lastRow($root);
    $this->assertSame('refuse', $row['verdict']);
    $this->assertSame('plan-wall', $row['rule']);
    $this->assertSame('plan', $row['phase']);
    $this->assertSame('pre-tool-use', $row['mode']);
  }

  /**
   * At soft, every hit on the plan wall is counted, the silent ones too.
   *
   * The message goes out once per phase. The second edit is let through
   * without a word, and it is still an edit the wall exists to catch.
   */
  public function testThePlanWallAtSoftNudgesOnEveryHit(): void {
    $root = $this->rootWithRun('plan', 'soft');

    [$first, $stdout] = $this->guard($root, 'pre-tool-use', ['tool_input' => ['file_path' => 'src/Thing.php']]);
    [$second, $silent] = $this->guard($root, 'pre-tool-use', ['tool_input' => ['file_path' => 'src/Other.php']]);

    $this->assertSame(0, $first);
    $this->assertSame(0, $second);
    $this->assertStringContainsString('still in PLAN', $stdout, 'the first hit is told');
    $this->assertSame('', $silent, 'the second is not');
    $rows = $this->rows($root);
    $this->assertCount(2, $rows);
    foreach ($rows as $row) {
      $this->assertSame('nudge', $row['verdict']);
      $this->assertSame('plan-wall:soft', $row['rule']);
    }
  }

  /**
   * A shell redirect during plan at hard is refused and named.
   */
  public function testTheShellPlanWallAtHardRefusesAndNamesItsRule(): void {
    $root = $this->rootWithRun('plan', 'hard');

    [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => 'echo x > src/Thing.php']]);

    $this->assertSame(2, $exit);
    $this->assertStringContainsString('A shell redirect is the same act as an edit', $stderr);
    $row = $this->lastRow($root);
    $this->assertSame('refuse', $row['verdict']);
    $this->assertSame('plan-wall:shell', $row['rule']);
  }

  /**
   * A stop held mid-phase is a refusal, whichever lever held it.
   */
  public function testHeldStopsAreRefusalsNamingTheLever(): void {
    $hard = $this->rootWithRun('code', 'hard');
    [$exit] = $this->guard($hard, 'stop', []);
    $this->assertSame(2, $exit);
    $this->assertSame(['refuse', 'stop-hold'], $this->verdictOf($this->lastRow($hard)));

    $agentic = $this->rootWithRun('code', 'soft', 'agentic');
    [$exit] = $this->guard($agentic, 'stop', []);
    $this->assertSame(2, $exit);
    $this->assertSame(['refuse', 'stop-hold:agentic'], $this->verdictOf($this->lastRow($agentic)));

    $interactive = $this->rootWithRun('code', 'soft', 'interactive');
    [$exit] = $this->guard($interactive, 'stop', []);
    $this->assertSame(0, $exit);
    $this->assertSame(['nudge', 'stop-hold:soft'], $this->verdictOf($this->lastRow($interactive)));
  }

  /**
   * An edit the phase allows is an allow, with no rule.
   */
  public function testAnEditThePhaseAllowsIsAnAllow(): void {
    $root = $this->rootWithRun('code', 'hard');

    [$exit] = $this->guard($root, 'pre-tool-use', ['tool_input' => ['file_path' => 'src/Thing.php']]);

    $this->assertSame(0, $exit);
    $this->assertSame(['allow', NULL], $this->verdictOf($this->lastRow($root)));
  }

  /**
   * A write to the enforcement itself is refused and the wall named.
   */
  public function testProtectedPathRefusalsNameTheirWall(): void {
    $root = $this->rootWithRun('code', 'hard');

    [$exit] = $this->guard($root, 'pre-tool-use', ['tool_input' => ['file_path' => '.claude/hooks/droost-workflow-guard.php']]);

    $this->assertSame(2, $exit);
    $this->assertSame(['refuse', 'protected-path:editor'], $this->verdictOf($this->lastRow($root)));
  }

  /**
   * An exception after the ledger is ready is a crash row, and still refuses.
   */
  public function testExceptionsAreRecordedAsCrashes(): void {
    $root = $this->rootWithRun('code', 'hard');
    $script = $this->guardWith($root, "throw new \\RuntimeException('probe');");

    [$exit] = $this->runScript($script, $root, 'pre-tool-use', ['tool_input' => ['file_path' => 'src/Thing.php']]);

    $this->assertSame(2, $exit, 'a guard that cannot decide refuses');
    $this->assertSame(['crash', 'crash:RuntimeException'], $this->verdictOf($this->lastRow($root)));
  }

  /**
   * A fatal error still leaves its row.
   *
   * The fatal-error handler is a shutdown function registered before the
   * ledger's, and it ends in exit(). An exit() inside a shutdown function
   * ends every one registered after it, so a fatal wrote no row at all. The
   * handler now writes the row itself before it exits.
   */
  public function testFatalErrorsStillWriteCrashRows(): void {
    $root = $this->rootWithRun('code', 'hard');
    $script = $this->guardWith($root, "ini_set('memory_limit', '16M');\n\$probe = str_repeat('x', 256 * 1024 * 1024);");

    [$exit, , $stderr] = $this->runScript($script, $root, 'pre-tool-use', ['tool_input' => ['file_path' => 'src/Thing.php']]);

    $this->assertSame(2, $exit);
    $this->assertStringContainsString('died before it could decide', $stderr);
    $this->assertSame(['crash', 'crash:fatal'], $this->verdictOf($this->lastRow($root)));
  }

  /**
   * A project root holding a run in one phase.
   *
   * @param string $phase
   *   The current phase, active.
   * @param string $enforcement
   *   The frozen enforcement level.
   * @param string $mode
   *   The run's mode.
   *
   * @return string
   *   The root.
   */
  private function rootWithRun(string $phase, string $enforcement, string $mode = 'interactive'): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-ledger',
      'current_phase' => $phase,
      'phases' => [$phase => 'active'],
      'enforcement' => $enforcement,
      'mode' => $mode,
    ]));
    return $root;
  }

  /**
   * The ledger's rows, in order.
   *
   * @param string $root
   *   The project root.
   *
   * @return list<array<string, mixed>>
   *   The decoded rows.
   */
  private function rows(string $root): array {
    $path = $root . '/droost/droost-workflow/guard-calls.jsonl';
    $this->assertFileExists($path, 'the guard wrote its ledger');
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $row = json_decode($line, TRUE);
      $this->assertIsArray($row);
      // The ledger's own fields, by name: a row the guard wrote with a field
      // missing reads as NULL here and fails the assertion that reads it.
      $rows[] = [
        'run' => $row['run'] ?? NULL,
        'phase' => $row['phase'] ?? NULL,
        'tool' => $row['tool'] ?? NULL,
        'mode' => $row['mode'] ?? NULL,
        'verdict' => $row['verdict'] ?? NULL,
        'rule' => $row['rule'] ?? NULL,
        'at' => $row['at'] ?? NULL,
      ];
    }
    return $rows;
  }

  /**
   * The ledger's last row.
   *
   * @param string $root
   *   The project root.
   *
   * @return array<string, mixed>
   *   The row.
   */
  private function lastRow(string $root): array {
    $rows = $this->rows($root);
    $this->assertNotSame([], $rows);
    return $rows[count($rows) - 1];
  }

  /**
   * A row's verdict and rule.
   *
   * @param array<string, mixed> $row
   *   The row.
   *
   * @return array{mixed, mixed}
   *   The verdict and the rule.
   */
  private function verdictOf(array $row): array {
    return [$row['verdict'] ?? NULL, $row['rule'] ?? NULL];
  }

  /**
   * A copy of the guard with code injected once the ledger is ready.
   *
   * @param string $root
   *   The project root the copy is written under.
   * @param string $code
   *   The PHP to run right after the ledger's writer is registered.
   *
   * @return string
   *   The copy's path.
   */
  private function guardWith(string $root, string $code): string {
    $source = (string) file_get_contents(self::GUARD);
    $anchor = "register_shutdown_function(\$recordCall);\n";
    $this->assertSame(1, substr_count($source, $anchor), 'the ledger writer is registered once');
    $path = $root . '/guard-copy.php';
    file_put_contents($path, str_replace($anchor, $anchor . $code . "\n", $source));
    return $path;
  }

  /**
   * Runs a guard script as the host would.
   *
   * @param string $script
   *   The script.
   * @param string $root
   *   The project root.
   * @param string $mode
   *   The guard mode.
   * @param array<string, mixed> $payload
   *   The hook payload.
   *
   * @return array{int, string, string}
   *   Exit code, stdout, stderr.
   */
  private function runScript(string $script, string $root, string $mode, array $payload): array {
    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $root;
    $process = proc_open(
      [PHP_BINARY, '-d', 'display_errors=stderr', $script, $mode],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
      $env,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode($payload));
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
  }

  /**
   * The index of the next token that is not whitespace or a comment.
   *
   * @param array<int, mixed> $tokens
   *   The tokens.
   * @param int $from
   *   The index to search after.
   *
   * @return int
   *   The index.
   */
  private function nextMeaningful(array $tokens, int $from): int {
    for ($i = $from + 1; $i < count($tokens); $i++) {
      if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
        return $i;
      }
    }
    return $from;
  }

  /**
   * The index of the previous token that is not whitespace or a comment.
   *
   * @param array<int, mixed> $tokens
   *   The tokens.
   * @param int $from
   *   The index to search before.
   *
   * @return int
   *   The index.
   */
  private function previousMeaningful(array $tokens, int $from): int {
    for ($i = $from - 1; $i >= 0; $i--) {
      if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], TRUE)) {
        return $i;
      }
    }
    return $from;
  }

}
