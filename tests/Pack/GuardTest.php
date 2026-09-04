<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The enforcement guard, executed for real against fixture run documents.
 *
 * The single most important behaviour is the first one: NO RUN, NO OPINION.
 * A guard that polices regular conversation is a defect worse than no guard,
 * so every path in here starts from what run.json says.
 */
final class GuardTest extends WorkflowTestCase {

  /**
   * Without an active run, both modes stay silent and allow.
   */
  public function testNoRunNoOpinion(): void {
    $root = $this->makeRoot();
    foreach (['pre-tool-use', 'stop'] as $mode) {
      [$exit, $stdout, $stderr] = $this->guard($root, $mode, []);
      $this->assertSame(0, $exit, $mode . ' must allow with no run');
      $this->assertSame('', $stdout);
      $this->assertSame('', $stderr);
    }
  }

  /**
   * With no run, a custom-code edit is walled (require_run default hard).
   *
   * The trigger that makes the pipeline the end game — no lever needed, hard
   * is the default, and the block names the two ways forward.
   */
  public function testRequireRunHardWallsCustomCodeWithNoRun(): void {
    $root = $this->makeRoot();
    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
    ]);
    $this->assertSame(2, $exit, 'building custom code with no run is blocked');
    $this->assertStringContainsString('/droost:workflow:start', $stderr);
    $this->assertStringContainsString('bypass', $stderr);
  }

  /**
   * The wall is narrow: non-custom paths are never walled.
   */
  public function testRequireRunIgnoresNonCustomPaths(): void {
    $root = $this->makeRoot();
    foreach (['README.md', 'web/core/lib/Drupal.php', 'web/modules/contrib/x/x.php', 'droost/droost-workflow/spec.md'] as $path) {
      [$exit, , $stderr] = $this->guard($root, "pre-tool-use", [
        "tool_input" => ["file_path" => $path],
      ]);
      $this->assertSame(0, $exit, "$path is outside the build boundary");
      $this->assertSame('', $stderr, "$path must not be walled");
    }
  }

  /**
   * An operator-granted bypass stands the wall down.
   *
   * Only the operator's command writes reason AND granted_at — the guard
   * honors exactly that shape, so a hand-rolled or corrupt marker is not a
   * grant (writes under droost/droost-workflow/ are outside the wall,
   * and an existence-only check made bypass.json a one-call self-disarm).
   */
  public function testRequireRunBypassAllows(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents(
      $root . '/droost/droost-workflow/bypass.json',
      '{"reason":"hotfix","granted_at":"2026-08-26T12:00:00+00:00"}',
    );
    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
    ]);
    $this->assertSame(0, $exit, 'a granted bypass allows the edit');
    $this->assertSame('', $stderr);
  }

  /**
   * A bypass file that is not the operator command's shape is not a grant.
   */
  public function testRequireRunRejectsMalformedBypasses(): void {
    $cases = [
      'garbage' => 'not json',
      'empty file' => '',
      'no granted_at' => '{"reason":"hotfix"}',
      'no reason' => '{"granted_at":"2026-08-26T12:00:00+00:00"}',
      'empty reason' => '{"reason":"","granted_at":"2026-08-26T12:00:00+00:00"}',
    ];
    foreach ($cases as $label => $content) {
      $root = $this->makeRoot();
      mkdir($root . '/droost/droost-workflow', 0755, TRUE);
      file_put_contents($root . '/droost/droost-workflow/bypass.json', $content);
      [$exit] = $this->guard($root, 'pre-tool-use', [
        'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
      ]);
      $this->assertSame(2, $exit, $label . ' must not stand the wall down');
    }
  }

  /**
   * An ENDED or unreadable run does not stand the wall down.
   *
   * The terminal record persists until reset — the designed end state of
   * every run — so "run.json exists" must not read as "a run is active":
   * that parked the wall after every finished ticket, and junk written into
   * run.json was a silent, permanent self-disarm.
   */
  public function testEndedRunsDoNotStandTheWallDown(): void {
    $payload = [
      'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
    ];

    // The authentic post-0.4.5 terminal shape: no current phase.
    $terminal = $this->makeRoot();
    mkdir($terminal . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($terminal . '/droost/droost-workflow/run.json', json_encode([
      'current_phase' => NULL,
      'phases' => ['plan' => 'passed', 'code' => 'passed', 'test' => 'passed', 'complete' => 'passed'],
      'enforcement' => 'hard',
    ]));
    [$exit, , $stderr] = $this->guard($terminal, 'pre-tool-use', $payload);
    $this->assertSame(2, $exit, 'a completed run is history, not a licence');
    $this->assertStringContainsString('/droost:workflow:start', $stderr);

    // Ended the other ways: the final phase recorded passed; a failed phase.
    $done = $this->rootWithRun('complete', 'passed', 'hard');
    [$exit] = $this->guard($done, 'pre-tool-use', $payload);
    $this->assertSame(2, $exit, 'complete+passed does not disarm the wall');

    $failed = $this->rootWithRun('code', 'failed', 'hard');
    [$exit] = $this->guard($failed, 'pre-tool-use', $payload);
    $this->assertSame(2, $exit, 'a failed run does not disarm the wall');

    // Unreadable is not a run at all.
    $corrupt = $this->makeRoot();
    mkdir($corrupt . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($corrupt . '/droost/droost-workflow/run.json', 'not json');
    [$exit] = $this->guard($corrupt, 'pre-tool-use', $payload);
    $this->assertSame(2, $exit, 'junk in run.json is not a self-disarm');

    // A LIVE run stands the wall down: in-run enforcement takes over, and
    // during the code phase custom-code edits are the phase's work.
    $live = $this->rootWithRun('code', 'active', 'hard');
    [$exit, $stdout, $stderr] = $this->guard($live, 'pre-tool-use', $payload);
    $this->assertSame(0, $exit, 'an active run governs instead of the wall');
    $this->assertSame('', $stdout . $stderr);

    // And an ended run never blocks ENDING the turn: stop stays silent.
    [$exit] = $this->guard($terminal, 'stop', []);
    $this->assertSame(0, $exit, 'a finished run does not police the stop');
  }

  /**
   * The lever regex reads quoted values the way the real parser does.
   *
   * "off" enforced as hard while status reported off was a split brain: the
   * hook greps the raw file, the lib parses it — the two must agree on at
   * least the quoting the parser accepts.
   */
  public function testRequireRunAcceptsQuotedLeverValues(): void {
    $payload = [
      'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
    ];

    $off = $this->makeRootWithConfig("require_run: \"off\"\n");
    [$exit, $stdout, $stderr] = $this->guard($off, 'pre-tool-use', $payload);
    $this->assertSame(0, $exit, 'a double-quoted off is off');
    $this->assertSame('', $stdout . $stderr);

    $soft = $this->makeRootWithConfig("require_run: 'soft'\n");
    [$exit, $stdout] = $this->guard($soft, 'pre-tool-use', $payload);
    $this->assertSame(0, $exit, 'a single-quoted soft never blocks');
    $this->assertStringContainsString('start', $stdout, 'quoted soft still nudges');
  }

  /**
   * Cosmetic respellings of a custom-code path cannot slip past the wall.
   */
  public function testRequireRunNormalizesThePathBeforeMatching(): void {
    foreach ([
      'web/modules/./custom/acme/acme.module',
      'web/modules//custom/acme/acme.module',
      'web/Modules/Custom/acme/acme.module',
    ] as $spelling) {
      $root = $this->makeRoot();
      [$exit] = $this->guard($root, 'pre-tool-use', [
        'tool_input' => ['file_path' => $spelling],
      ]);
      $this->assertSame(2, $exit, $spelling . ' is still custom code');
    }
  }

  /**
   * Off is silent; soft nudges once but allows.
   */
  public function testRequireRunOffAndSoft(): void {
    $off = $this->makeRootWithConfig("require_run: off\n");
    [$exit, $stdout, $stderr] = $this->guard($off, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
    ]);
    $this->assertSame(0, $exit, 'off never blocks');
    $this->assertSame('', $stdout . $stderr, 'off is fully silent');

    $soft = $this->makeRootWithConfig("require_run: soft\n");
    [$exit, $stdout] = $this->guard($soft, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
    ]);
    $this->assertSame(0, $exit, 'soft never blocks');
    $this->assertStringContainsString('start', $stdout, 'soft nudges');
  }

  /**
   * Hard enforcement blocks project edits during plan; the spec passes.
   */
  public function testHardBlocksEditsDuringPlanExceptTheSpec(): void {
    $root = $this->rootWithRun('plan', 'active', 'hard');

    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'src/Thing.php'],
    ]);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('PLAN', $stderr);

    [$exit, $stdout, $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'droost/droost-workflow/spec-thing.md'],
    ]);
    $this->assertSame(0, $exit, 'the spec is plan\'s own artefact');
    $this->assertSame('', $stdout . $stderr);

    // Once the run has advanced past plan, edits are the phase's work.
    $coding = $this->rootWithRun('code', 'active', 'hard');
    [$exit] = $this->guard($coding, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'src/Thing.php'],
    ]);
    $this->assertSame(0, $exit);
  }

  /**
   * The guard resolves its root from CLAUDE_PROJECT_DIR, not the cwd (R27-F1).
   *
   * Claude Code runs the hook from the invoking tool's working directory, and
   * the agent's Bash tool persists a cwd that a `cd` moves out of the project.
   * A run mid-CODE allows a custom-code edit; were the guard to fall back to
   * getcwd() it would find no run at the moved cwd and wall the edit as "no
   * active run". Run here from an unrelated directory with the project root
   * only in the env: the edit must still be allowed.
   */
  public function testResolvesProjectRootFromClaudeProjectDirNotCwd(): void {
    $root = $this->rootWithRun('code', 'active', 'hard');
    $elsewhere = $this->makeRoot();
    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'web/modules/custom/acme/acme.module'],
    ], $elsewhere);
    $this->assertSame(
      0,
      $exit,
      "a code-phase edit must be allowed via CLAUDE_PROJECT_DIR, not walled by the moved cwd; stderr: $stderr",
    );
  }

  /**
   * Soft enforcement warns exactly once per phase, then stays quiet.
   */
  public function testSoftWarnsOncePerPhase(): void {
    $root = $this->rootWithRun('plan', 'active', 'soft');
    $payload = ['tool_input' => ['file_path' => 'src/Thing.php']];

    [$exit, $stdout] = $this->guard($root, 'pre-tool-use', $payload);
    $this->assertSame(0, $exit, 'soft never blocks');
    $decoded = json_decode($stdout, TRUE);
    $this->assertIsArray($decoded);
    $this->assertArrayHasKey('systemMessage', $decoded);

    [$exit, $stdout] = $this->guard($root, 'pre-tool-use', $payload);
    $this->assertSame(0, $exit);
    $this->assertSame('', $stdout, 'the second warning is silence');
  }

  /**
   * Hard enforcement challenges a mid-phase stop — once.
   */
  public function testStopIsChallengedOnceThenReleased(): void {
    $root = $this->rootWithRun('code', 'active', 'hard');

    [$exit, , $stderr] = $this->guard($root, 'stop', []);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('advance it or abandon it', $stderr);

    // Claude reports the stop hook already fired: stand down, no deadlock.
    [$exit, $stdout, $stderr] = $this->guard($root, 'stop', [
      'stop_hook_active' => TRUE,
    ]);
    $this->assertSame(0, $exit);
    $this->assertSame('', $stdout . $stderr);
  }

  /**
   * Ended, failed and unenforced runs are all left alone.
   */
  public function testEndedFailedAndOffRunsAreNotPoliced(): void {
    $done = $this->rootWithRun('complete', 'passed', 'hard');
    [$exit] = $this->guard($done, 'stop', []);
    $this->assertSame(0, $exit, 'a finished run is history, not law');

    $failed = $this->rootWithRun('test', 'failed', 'hard');
    [$exit] = $this->guard($failed, 'stop', []);
    $this->assertSame(0, $exit, 'a failed run is a legitimate end');

    $off = $this->rootWithRun('plan', 'active', 'off');
    [$exit, $stdout, $stderr] = $this->guard($off, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'src/Thing.php'],
    ]);
    $this->assertSame(0, $exit);
    $this->assertSame('', $stdout . $stderr, 'off means the hooks stand down');
  }

  /**
   * The operator's two commands are refused from the agent's shell (R23-F2).
   *
   * Round 23: the operator picked "waive it" in the subject's dialog and the
   * subject ran `drush droost:workflow:gate-waive` itself, overwriting the
   * operator's recorded reason. "CLI-only" excludes the MCP transport, not an
   * agent with a shell. Run state is irrelevant: bypass is granted with no
   * run, a waiver during one — the rule is about who, not when.
   */
  public function testOperatorCommandsAreRefusedFromTheAgentsShell(): void {
    foreach ([$this->makeRoot(), $this->rootWithRun('code', 'active', 'soft')] as $root) {
      foreach ([
        'ddev drush droost:workflow:gate-waive config_clean "false positive"',
        'drush dwfgw phpstan "vendor stub"',
        'vendor/bin/drush droost:workflow:bypass "hotfix"',
        'ddev drush dwfby "just this once" --project=/x',
      ] as $command) {
        [$exit, , $stderr] = $this->guard($root, 'operator-commands', [
          'tool_input' => ['command' => $command],
        ]);
        $this->assertSame(2, $exit, $command . ' must be refused');
        $this->assertStringContainsString("OPERATOR's command", $stderr);
        $this->assertStringContainsString('! drush droost:workflow:', $stderr);
      }
    }

    // Arming a droost write gate is the operator's act as well (R25-F2).
    foreach ([
      'ddev drush droost:gate allow_entity_write on',
      'drush dgate allow_scaffold true',
      'ddev drush config:set droost.settings allow_entity_write true -y',
      'drush cset -y droost.settings allow_destructive 1',
    ] as $command) {
      [$exit, , $stderr] = $this->guard($this->makeRoot(), 'operator-commands', [
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $exit, $command . ' must be refused');
      $this->assertStringContainsString('droost:gate is the OPERATOR', $stderr);
      $this->assertStringContainsString('Disarming a gate', $stderr);
    }

    // Re-arming the wall and disarming a gate are tightenings; anything else
    // is not our business.
    $root = $this->makeRoot();
    foreach ([
      'ddev drush droost:workflow:bypass --off',
      'drush dwfby --off',
      'ddev drush droost:gate allow_entity_write off',
      'drush config:set --input-format=yaml droost.settings allow_entity_write false -y',
      'ddev drush droost:gate allow_scaffold',
      'ddev drush droost:workflow:status',
      'ddev drush cr',
      'git log --oneline -3',
    ] as $command) {
      [$exit, $stdout, $stderr] = $this->guard($root, 'operator-commands', [
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $exit, $command . ' must be allowed');
      $this->assertSame('', $stdout . $stderr, $command . ' must be silent');
    }

    // An empty or missing command is not a refusal either.
    [$exit] = $this->guard($root, 'operator-commands', []);
    $this->assertSame(0, $exit);
  }

  /**
   * A project root holding an active run frozen at the given levers.
   *
   * @param string $phase
   *   The current phase.
   * @param string $status
   *   The current phase's status.
   * @param string $enforcement
   *   The frozen enforcement level.
   *
   * @return string
   *   The root path.
   */
  private function rootWithRun(
    string $phase,
    string $status,
    string $enforcement,
  ): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', json_encode([
      'current_phase' => $phase,
      'phases' => [$phase => $status],
      'enforcement' => $enforcement,
    ]));
    return $root;
  }

  /**
   * Executes the packed guard exactly as Claude Code would.
   *
   * @param string $root
   *   The project root, delivered as CLAUDE_PROJECT_DIR — the way Claude Code
   *   runs the hook, and what the guard resolves its run state against.
   * @param string $mode
   *   The guard mode: pre-tool-use or stop.
   * @param array<string, mixed> $payload
   *   The hook payload delivered on stdin.
   * @param string|null $cwd
   *   The working directory to run from, when it must differ from the project
   *   root (the agent's Bash tool can move it — R27-F1). Defaults to $root.
   *
   * @return array{int, string, string}
   *   Exit code, stdout, stderr.
   */
  private function guard(string $root, string $mode, array $payload, ?string $cwd = NULL): array {
    $script = dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php';
    // Set CLAUDE_PROJECT_DIR explicitly so the fixture root wins over any value
    // in the environment that runs the suite, and so cwd and the project root
    // can be driven apart to exercise the moved-cwd case.
    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $root;
    $process = proc_open(
      [PHP_BINARY, $script, $mode],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $cwd ?? $root,
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

}
