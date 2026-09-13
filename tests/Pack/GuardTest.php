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
        // Moving the effort dial names a level: the operator's act.
        'ddev drush droost:workflow:effort low',
        'drush dwfe --project=/x high',
        'ddev drush droost:workflow:effort factory',
        // Writing or growing the adoption baseline decides what every later
        // run inherits: a baseline the agent can write is a finding it can
        // hide (D71).
        'ddev drush droost:workflow:baseline',
        'drush dwfbl --refresh',
        'ddev drush droost:workflow:baseline --refresh --grow --reason="legacy"',
        'vendor/bin/droost-workflow baseline --refresh',
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
      // A bare effort only reports the current level; a preview shows the
      // bill of a level without writing — how an agent grounds a proposal.
      'ddev drush droost:workflow:effort',
      'drush dwfe --project=/x',
      'ddev drush droost:workflow:effort max --preview',
      'drush dwfe --preview high',
      // The bill and the record are read-only: exactly how an agent grounds a
      // proposal to baseline.
      'ddev drush droost:workflow:baseline --measure',
      'drush dwfbl --status',
      'vendor/bin/droost-workflow baseline --status',
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
   * An operator command QUOTED in a data heredoc is not the agent running it.
   *
   * The guard's own refusal tells the agent to show the operator the exact
   * command; a live run did so in a pull-request body written through
   * `cat > file <<'EOF'` and was refused for it (F-EMT-11). A heredoc fed
   * to something that is not an interpreter is text for a human and is not
   * scanned. One piped into a shell — or a real command after the heredoc —
   * still is.
   */
  public function testOperatorCommandsQuotedInDataHeredocsAreNotRefused(): void {
    $root = $this->makeRoot();
    $body = "The run is held at code: two gates need the operator.\n"
      . "Run in your terminal: `ddev drush droost:workflow:gate-waive eslint \"tool crash\"`\n"
      . "and then `drush droost:workflow:baseline` once the debt is agreed.\n";
    foreach ([
      "cat > /tmp/pr-body.md <<'EOF'\n" . $body . "EOF\n",
      "gh pr create --draft --body-file - <<EOF\n" . $body . "EOF",
      "git commit -F - <<-'MSG'\n" . $body . "\tMSG\n",
      "tee notes.md <<\"TXT\" >/dev/null\n" . $body . "TXT",
    ] as $command) {
      [$exit, $stdout, $stderr] = $this->guard($root, 'operator-commands', [
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $exit, $command . ' quotes the command for a human and must be allowed');
      $this->assertSame('', $stdout . $stderr);
    }

    // The same text fed to an interpreter IS the command; and a real command
    // outside the heredoc is still seen.
    foreach ([
      "bash <<'EOF'\nddev drush droost:workflow:gate-waive eslint \"x\"\nEOF",
      "ddev exec bash <<EOF\ndrush droost:workflow:gate-waive eslint \"x\"\nEOF",
      "cat > notes.md <<'EOF'\n" . $body . "EOF\nddev drush droost:workflow:gate-waive eslint \"x\"",
      "drush php:script - <<'PHP'\n<?php drush droost:workflow:bypass \"hotfix\"\nPHP",
    ] as $command) {
      [$exit, , $stderr] = $this->guard($root, 'operator-commands', [
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $exit, $command . ' runs the command and must be refused');
      $this->assertStringContainsString("OPERATOR's command", $stderr);
    }
  }

  /**
   * The baseline directory is never the agent's to edit — run or no run (D71).
   *
   * The one edit that makes the agent's own finding disappear. Refused at
   * every run state, before the wall or the phase rules get a say; a file
   * merely NAMED like the directory is not it.
   */
  public function testBaselineDirectoryIsNeverTheAgentsToEdit(): void {
    $roots = [
      'no run' => $this->makeRoot(),
      'plan' => $this->rootWithRun('plan', 'active', 'hard'),
      'code' => $this->rootWithRun('code', 'active', 'soft'),
      'ended' => $this->rootWithRun('complete', 'passed', 'hard'),
    ];
    foreach ($roots as $label => $root) {
      foreach (['droost/baseline/phpcs.json', '/abs/repo/droost/baseline/baseline.json', 'droost/baseline'] as $path) {
        [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
          'tool_input' => ['file_path' => $path],
        ]);
        $this->assertSame(2, $exit, "$label: $path must be refused");
        $this->assertStringContainsString("OPERATOR's adoption record", $stderr);
        $this->assertStringContainsString('--refresh', $stderr);
      }
    }
    // A sibling that only sounds alike is judged by the ordinary rules.
    [$exit, , $stderr] = $this->guard($this->makeRoot(), 'pre-tool-use', [
      'tool_input' => ['file_path' => 'docs/droost-baseline-notes.md'],
    ]);
    $this->assertSame(0, $exit);
    $this->assertSame('', $stderr);
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
   * A shell reaches the store and the baseline, and the guard refuses it.
   *
   * The file-path guard covers Edit, Write, MultiEdit and NotebookEdit. Bash is
   * wired to a DIFFERENT mode, which only ever inspected drush command names —
   * so the identical write went through: `Write` to `evidence.sqlite` was
   * refused, and `sqlite3 evidence.sqlite "UPDATE …"` was not.
   *
   * A reviewer took a real completed run, flipped its three blocked checks to
   * satisfied, invented a `security_audit` gate that has never existed, forged
   * grounding rows and a tool-call ledger, and rendered a clean evaluation —
   * for a run with no booted site and zero MCP calls. The report calls that
   * ledger "the only place in the system that is not the subject's account of
   * itself". It was the subject's account of itself, in SQL.
   *
   * Every claim resting on "droost wrote these rows and the agent could not"
   * was false for as long as an agent had a shell.
   */
  public function testShellCannotReachTheStoreOrTheBaseline(): void {
    $root = $this->makeRoot();

    $refused = [
      'sqlite3 rewriting a verdict' => 'sqlite3 droost/droost-workflow/evidence.sqlite "UPDATE check_result SET state=x"',
      'a legacy state dir' => 'sqlite3 .droost-workflow/evidence.sqlite ".dump"',
      'php reaching the store' => 'php -r "new PDO(sqlite:droost/droost-workflow/evidence.sqlite)"',
      'moving a file onto it' => 'mv /tmp/forged droost/droost-workflow/evidence.sqlite',
      'a redirect into the baseline' => 'echo {} > droost/baseline/phpstan.json',
      'even reading the baseline' => 'cat droost/baseline/phpcs.json',
    ];
    foreach ($refused as $label => $command) {
      [$code, , $stderr] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $label . ' is refused');
      $this->assertNotSame('', $stderr, $label . ' says why');
    }

    // And the work an agent actually does is untouched: a guard that stops real
    // work is a guard somebody turns off.
    $allowed = [
      'running the suite' => 'vendor/bin/phpunit --no-coverage',
      'the sanctioned evidence surface' => 'php vendor/bin/droost-workflow evidence --write',
      'the sanctioned baseline surface' => 'php vendor/bin/droost-workflow baseline --status',
      'an ordinary commit' => 'git add -A && git commit -m x',
      'a file that merely shares a word' => 'cat docs/baseline-notes.md',
    ];
    foreach ($allowed as $label => $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $code, $label . ' still works');
    }
  }

  /**
   * An agent cannot disarm its own wall, or rewrite the rules mid-run.
   *
   * Found by asking the obvious next question after the store hole: if a shell
   * could reach the evidence store, what else could it reach? Everything. The
   * guard script, the settings file that wires it in, the lever file and the
   * briefs were all writable, through both doors. Overwriting
   * `droost-workflow-guard.php` with `<?php exit(0);` removes every rule this
   * pipeline has, and needs no run to be active — disarm first, run second is
   * one extra step.
   *
   * Two tiers, because they answer different questions. The guard and its
   * wiring are ALWAYS out of reach: every claim the pipeline makes rests on
   * those two files. The lever file and the briefs are out of reach only while
   * a run is under way — setting a project up is legitimate work an agent may
   * be asked to do, but changing the rules you are currently being judged by is
   * a different act.
   */
  public function testAgentCannotDisarmTheWallOrRewriteTheRulesMidRun(): void {
    $root = $this->makeRoot();

    $wall = [
      'the guard script' => '.claude/hooks/droost-workflow-guard.php',
      'the file that wires it in' => '.claude/settings.json',
    ];
    $duringRun = [
      'the lever file' => 'droost.workflow.yml',
      'a skill brief' => '.claude/skills/workflow-code/SKILL.md',
      'an agent brief' => '.claude/agents/workflow-seeker.md',
    ];

    // With no run: the wall is shut, project setup is open.
    foreach ($wall as $label => $path) {
      $this->assertSame(2, $this->writeAttempt($root, $path), $label . ' is refused with no run');
      $this->assertSame(2, $this->shellAttempt($root, $path), $label . ' is refused through a shell too');
    }
    foreach ($duringRun as $label => $path) {
      $this->assertSame(0, $this->writeAttempt($root, $path), $label . ' is ordinary before a run');
    }

    // With a run under way, the second tier closes.
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', '{"run_id":"r1","current_phase":"code"}');
    foreach ($duringRun as $label => $path) {
      $this->assertSame(2, $this->writeAttempt($root, $path), $label . ' is refused during a run');
      $this->assertSame(2, $this->shellAttempt($root, $path), $label . ' is refused through a shell during a run');
    }
    foreach ($wall as $label => $path) {
      $this->assertSame(2, $this->writeAttempt($root, $path), $label . ' is still refused');
    }

    // And the work an agent is here to do is untouched.
    $this->assertSame(0, $this->shellAttempt($root, 'vendor/bin/phpunit --no-coverage'));
    $this->assertSame(0, $this->writeAttempt($root, 'web/modules/custom/x/x.module'));
  }

  /**
   * The exit code of writing to a path through the editing tools.
   *
   * @param string $root
   *   The project.
   * @param string $path
   *   The file.
   *
   * @return int
   *   The guard's exit code.
   */
  private function writeAttempt(string $root, string $path): int {
    [$code] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $path, 'content' => 'x'],
    ]);

    return $code;
  }

  /**
   * The exit code of touching a path through a shell.
   *
   * @param string $root
   *   The project.
   * @param string $command
   *   The command, or a bare path to be redirected into.
   *
   * @return int
   *   The guard's exit code.
   */
  private function shellAttempt(string $root, string $command): int {
    [$code] = $this->guard($root, 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => str_contains($command, ' ') ? $command : 'echo x > ' . $command],
    ]);

    return $code;
  }

  /**
   * A cosmetic spelling of a protected path is the same path to the wall.
   *
   * `require_run_guard()` normalised — separators, `//`, `/./`, case — and had
   * a test pinning it. The two path guards added since did not, and a reviewer
   * walked through all three of them with `//`, `/./`, `..` and a capital
   * letter, proving each reached the same inode. `.claude//hooks/…guard.php`
   * was permitted: one ordinary Write, and the wall is gone.
   *
   * Three implementations of "is this the same path" is three chances to be
   * wrong, and two of them were. There is one now.
   */
  public function testCosmeticSpellingsOfProtectedPathsAreRefused(): void {
    $root = $this->makeRoot();

    $spellings = [
      '.claude//hooks/droost-workflow-guard.php',
      '.claude/./hooks/droost-workflow-guard.php',
      '.claude/hooks/../hooks/droost-workflow-guard.php',
      '.claude/hooks/Droost-Workflow-Guard.php',
      '.claude//settings.json',
      '.claude/Settings.json',
      'droost//baseline/phpstan.json',
      'droost/./baseline/phpstan.json',
      'droost/Baseline/phpstan.json',
      'droost/droost-workflow//evidence.sqlite',
      'droost/droost-workflow/./evidence.sqlite',
      'droost/droost-workflow/Evidence.sqlite',
    ];
    foreach ($spellings as $path) {
      [$code] = $this->guard($root, 'pre-tool-use', [
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => $path, 'content' => 'x'],
      ]);
      $this->assertSame(2, $code, $path . ' is the same file, however it is spelled');
    }
  }

  /**
   * The run's own record is not the agent's to write, through either door.
   *
   * `baseline_dir_guard()` protected the evidence store and not `run.json`
   * beside it, and a single ordinary Write to that file takes the whole wall
   * down: set `enforcement: off`, mark the phase passed, mark it failed, or
   * simply corrupt it — the stop hook permitted on every one of those.
   *
   * The comment in the guard claiming that hole was closed was about
   * `require_run_guard()`, which returns immediately unless the mode is
   * pre-tool-use. Under `stop` — the only mode that matters for this — it was a
   * bare permit.
   */
  public function testTheRunRecordIsNotTheAgentsToWrite(): void {
    $root = $this->makeRoot();

    foreach (['droost/droost-workflow/run.json', '.droost-workflow/run.json'] as $path) {
      [$code] = $this->guard($root, 'pre-tool-use', [
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => $path, 'content' => '{}'],
      ]);
      $this->assertSame(2, $code, $path . ' is refused through the editing tools');

      [$shell] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => 'echo {} > ' . $path],
      ]);
      $this->assertSame(2, $shell, $path . ' is refused through a shell');
    }
  }

  /**
   * A damaged run record does not end the turn.
   *
   * Every branch that decides "this is not an active run" calls
   * `require_run_guard()` and exits zero — and that function returns at once
   * unless the mode is pre-tool-use. So under `stop`, corrupting run.json was a
   * silent, permanent self-disarm, which is precisely what the comment above
   * that branch claimed to prevent.
   *
   * A file that EXISTS and cannot be parsed is not "no run". It is a run whose
   * record is damaged, and a turn does not end on one.
   */
  public function testDamagedRunRecordDoesNotEndTheTurn(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', 'not json at all');

    [$code, , $stderr] = $this->guard($root, 'stop', ['tool_name' => 'Stop', 'tool_input' => []]);

    $this->assertSame(2, $code, 'the turn is held open');
    $this->assertStringContainsString('cannot be read', $stderr);
    $this->assertStringContainsString('reset --force', $stderr, 'and it names the way out');
  }

  /**
   * But it does not hold the turn open for ever.
   *
   * The refusal above fires a hundred lines before the stop branch that honours
   * `stop_hook_active`, so it did not honour it: Claude was made to continue
   * once, tried to stop again, and got the identical exit 2 — with no way out,
   * because the remedy the message names is an OPERATOR command the guarded
   * agent may not run. One enforced continuation per stop attempt is the
   * contract everywhere else in this file, and a refusal that can never be
   * satisfied is a hang wearing enforcement's clothes.
   */
  public function testDamagedRunRecordStillYieldsOnTheSecondStop(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    file_put_contents($root . '/droost/droost-workflow/run.json', 'not json at all');

    [$code, , $stderr] = $this->guard($root, 'stop', [
      'tool_name' => 'Stop',
      'tool_input' => [],
      'stop_hook_active' => TRUE,
    ]);

    $this->assertSame(0, $code, 'the second stop attempt is allowed');
    $this->assertSame('', $stderr);
  }

  /**
   * A soft nudge survives a malformed byte out of a tool, and burns no marker.
   *
   * Soft enforcement's entire product is the message. It is emitted at most
   * once per phase per mode, through a marker file — and `json_encode` returns
   * FALSE on malformed UTF-8, while the message carries a gate summary built
   * from the tool's own bytes. So one stray 0xC3 out of phpstan produced an
   * empty echo AFTER the marker had been touched: the operator was told
   * nothing, and then told nothing again, permanently, for that phase.
   *
   * The encode now happens BEFORE the marker is burned, substitutes invalid
   * bytes, and falls back to ASCII rather than emitting nothing. Untested until
   * now, on a path that only fires when the input is already hostile or broken
   * — which is the input that matters.
   */
  public function testSoftNudgeSurvivesMalformedBytes(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    // A blocked gate whose summary carries a lone continuation byte, which is
    // exactly what a truncated multibyte sequence out of a tool looks like.
    $this->seedBlockedCheck($root, 'phpstan', 'found ' . chr(0xC3) . ' errors');
    file_put_contents(
      $root . '/droost/droost-workflow/run.json',
      (string) json_encode([
        'run_id' => 'r1',
        'current_phase' => 'code',
        'phases' => ['code' => 'running'],
        'enforcement' => 'soft',
      ]),
    );

    [$code, $stdout] = $this->guard($root, 'stop', ['tool_name' => 'Stop', 'tool_input' => []]);

    $this->assertSame(0, $code, 'soft enforcement allows the stop');
    $this->assertNotSame('', $stdout, 'and says something — the message IS the product');
    $decoded = json_decode($stdout, TRUE);
    $this->assertIsArray($decoded, 'what it says is valid JSON the host can read');
    $this->assertArrayHasKey('systemMessage', $decoded);
    $this->assertNotSame('', $decoded['systemMessage']);
  }

  /**
   * Records one blocked gate in a root's evidence store.
   *
   * Written with raw PDO rather than through EvidenceStore, because the guard
   * reads the file with raw PDO too — it carries no autoloader by design, and a
   * fixture built through the library would test a path the guard never takes.
   *
   * @param string $root
   *   The project root.
   * @param string $name
   *   The gate name.
   * @param string $summary
   *   The summary, byte for byte.
   */
  private function seedBlockedCheck(string $root, string $name, string $summary): void {
    $pdo = new \PDO('sqlite:' . $root . '/droost/droost-workflow/evidence.sqlite');
    $pdo->exec(
      'CREATE TABLE check_result (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id TEXT, '
      . 'phase TEXT, attempt INTEGER, kind TEXT, name TEXT, state TEXT, fault TEXT, '
      . 'summary TEXT, remedy TEXT)'
    );
    $pdo->prepare(
      'INSERT INTO check_result (run_id, phase, attempt, kind, name, state, fault, summary, remedy) '
      . 'VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?)'
    )->execute(['r1', 'code', 'gate', $name, 'blocked', 'agent', $summary, NULL]);
  }

  /**
   * A trailing shell comment does not buy an operator's signature.
   *
   * The read-only exemptions were lookaheads over the whole command string,
   * which asks "does this word appear on the line". The shell asks a narrower
   * question, and the gap was four characters: `bypass "hotfix" # --off` read
   * as the re-arming form and was permitted. That one command stands the
   * `require_run` wall down completely, and the same trick worked on
   * `baseline --refresh # --status` and `effort low # --preview`.
   */
  public function testTrailingCommentsBuyNoExemption(): void {
    $root = $this->makeRoot();
    $cases = [
      'bypass' => 'drush droost:workflow:bypass "hotfix" # --off',
      'baseline' => 'drush droost:workflow:baseline --refresh # --status',
      'effort' => 'drush droost:workflow:effort low # --preview',
      'bypass by alias' => 'drush dwfby "hotfix" # --off',
    ];
    foreach ($cases as $label => $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $label . ' is still the operator\'s command');
    }
  }

  /**
   * Nor does the exemption hiding inside a quoted argument.
   *
   * Not hypothetical: a bypass takes a REASON, and "needed --off for the
   * hotfix" is an ordinary sentence carrying its own exemption.
   */
  public function testQuotedArgumentsBuyNoExemption(): void {
    [$code] = $this->guard($this->makeRoot(), 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => 'drush droost:workflow:bypass "needed --off for the hotfix"'],
    ]);

    $this->assertSame(2, $code);
  }

  /**
   * The real read-only forms still pass, which is the point of having them.
   *
   * A guard that refuses `baseline --status` teaches an agent to stop grounding
   * its proposals, and an ungrounded proposal is the thing the exemption exists
   * to encourage.
   */
  public function testTheGenuineReadOnlyFormsStillPass(): void {
    $root = $this->makeRoot();
    foreach ([
      'drush droost:workflow:bypass --off',
      'drush droost:workflow:baseline --status',
      'drush droost:workflow:baseline --measure',
      'drush droost:workflow:effort high --preview',
      'drush droost:workflow:effort',
      'drush droost:gate allow_entity_write off',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $code, $command . ' reads or tightens, and is the agent\'s');
    }
  }

  /**
   * A flag between the verb and the gate name does not arm it unnoticed.
   *
   * The pattern required `allow_*` to be the word immediately after the verb,
   * and `--yes` is the first thing anybody adds to a drush command they expect
   * to prompt them.
   */
  public function testFlagBeforeTheGateNameDoesNotHelp(): void {
    [$code] = $this->guard($this->makeRoot(), 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => 'drush droost:gate --yes allow_entity_write on'],
    ]);

    $this->assertSame(2, $code);
  }

  /**
   * A symlinked directory does not reach a protected file.
   *
   * `normalised_path()` canonicalises spelling and holds against every spelling
   * attack tried against it. It never resolves the inode, and that is a
   * different question: `ln -s .claude/hooks tools` and then a write to
   * `tools/droost-workflow-guard.php` names no protected path, overwrites the
   * guard, and leaves the turn free to end.
   */
  public function testSymlinkedDirectoryReachesNoProtectedFile(): void {
    $root = $this->makeRoot();
    mkdir($root . '/.claude/hooks', 0775, TRUE);
    touch($root . '/.claude/hooks/droost-workflow-guard.php');
    symlink('.claude/hooks', $root . '/tools');

    foreach ([
      'tools/droost-workflow-guard.php',
      './tools/./droost-workflow-guard.php',
      $root . '/tools/droost-workflow-guard.php',
    ] as $path) {
      [$code, , $stderr] = $this->guard($root, 'pre-tool-use', [
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => $path],
      ]);
      $this->assertSame(2, $code, $path . ' lands on the guard');
      $this->assertStringContainsString('IS the enforcement', $stderr);
    }
  }

  /**
   * But an ordinary file reached through the same link is still ordinary.
   *
   * Resolving the path must not turn the state directory into a wall: the
   * skills tell the agent to write its spec there.
   */
  public function testSymlinkToUnprotectedFileStillPasses(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    symlink('droost/droost-workflow', $root . '/state');

    [$code] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => 'state/spec-thing.md'],
    ]);

    $this->assertSame(0, $code);
  }

  /**
   * Taking the directory away is not a way around the files inside it.
   *
   * Every protected entry named a file, so the cheapest route past all of them
   * was `mv droost/droost-workflow /tmp/dw`. The move is the worse form,
   * because it is REVERSIBLE: stash the directory, work ungoverned, put it
   * back, and the record has no gap to notice.
   */
  public function testMovingOrRemovingTheStateDirectoryIsRefused(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    foreach ([
      'mv droost/droost-workflow /tmp/dw',
      'rm -rf droost/droost-workflow',
      'rm -rf droost',
      'mv droost droost.bak',
      'rm -rf "droost"',
      'rm -rf droost/baseline',
      'mv .claude/hooks /tmp/h',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $command . ' takes the enforcement with it');
    }
  }

  /**
   * And ordinary work that merely says the word is not refused.
   *
   * The first cut matched the directory name anywhere in the command and so
   * refused `git commit -m "droost work"`. A guard that blocks ordinary work is
   * a guard somebody turns off, which costs more than the hole it closed.
   */
  public function testOrdinaryCommandsMentioningDroostStillPass(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    foreach ([
      'git commit -m "droost work"',
      'ls droost/droost-workflow/',
      'cat droost/droost-workflow/spec-x.md',
      'grep -rn droost src/',
      'rm -rf node_modules',
      'mv src/Foo.php src/Bar.php',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $code, $command . ' is ordinary work');
    }
  }

  /**
   * An empty new state directory does not disarm a legacy project.
   *
   * The state directory was resolved by which one EXISTS, so on a project still
   * using `.droost-workflow/` a single `mkdir -p droost/droost-workflow` won
   * the resolution, held no run.json, and every reader concluded there was no
   * active run. The real record sat untouched, and `status` agreed with the
   * guard, so nothing looked wrong. It is reachable by accident, too: the
   * skills name that directory when they tell the agent where to write a spec.
   */
  public function testEmptyNewStateDirectoryDisarmsNoLegacyRun(): void {
    $root = $this->makeRoot();
    mkdir($root . '/.droost-workflow', 0775, TRUE);
    file_put_contents($root . '/.droost-workflow/run.json', (string) json_encode([
      'run_id' => 'r1',
      'current_phase' => 'plan',
      'phases' => ['plan' => 'running'],
      'enforcement' => 'hard',
    ]));

    $write = ['tool_name' => 'Write', 'tool_input' => ['file_path' => 'web/themes/mine/x.twig']];
    [$before] = $this->guard($root, 'pre-tool-use', $write);
    $this->assertSame(2, $before, 'the legacy run is enforced');

    mkdir($root . '/droost/droost-workflow', 0775, TRUE);

    [$after] = $this->guard($root, 'pre-tool-use', $write);
    $this->assertSame(2, $after, 'and an empty directory beside it changes nothing');

    [$stop] = $this->guard($root, 'stop', ['tool_name' => 'Stop', 'tool_input' => []]);
    $this->assertSame(2, $stop, 'nor does it let the turn end');
  }

  /**
   * The bypass grant is not the agent's to write.
   *
   * It is two strings in a JSON file and it stands the `require_run` wall down
   * completely — and it was in NEITHER protected list, so an agent could write
   * its own and the guard read it as the operator's decision. The same file
   * also clears droost's destructive tool base, so one forged grant reaches
   * past this hook.
   */
  public function testTheBypassGrantCannotBeForged(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);

    [$write, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => 'droost/droost-workflow/bypass.json'],
    ]);
    $this->assertSame(2, $write);
    $this->assertStringContainsString('forging the signature', $stderr);

    [$shell] = $this->guard($root, 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => [
        'command' => 'echo \'{"reason":"x","granted_at":"now"}\' > droost/droost-workflow/bypass.json',
      ],
    ]);
    $this->assertSame(2, $shell, 'the shell is a file editor too');
  }

  /**
   * The local settings file disarms the wall exactly as settings.json does.
   */
  public function testTheLocalSettingsFileIsProtectedToo(): void {
    [$code] = $this->guard($this->makeRoot(), 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => '.claude/settings.local.json'],
    ]);

    $this->assertSame(2, $code);
  }

  /**
   * The store's rollback journal is part of the store.
   */
  public function testTheSqliteJournalIsProtected(): void {
    [$code] = $this->guard($this->makeRoot(), 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => 'droost/droost-workflow/evidence.sqlite-journal'],
    ]);

    $this->assertSame(2, $code);
  }

  /**
   * A quote does not manufacture the word boundary a flag needs.
   *
   * The comment-and-quote stripper emitted a SPACE for each quote, and a quote
   * JOINS adjacent words in the shell. `bypass "urgent"--off` is one argument —
   * the reason `urgent--off` — with no `--off` flag anywhere, and the space
   * split it into two so the exemption test saw one and allowed the grant. The
   * fix for a bypass hole opened the same hole through its own door.
   */
  public function testQuotesManufactureNoFlag(): void {
    $root = $this->makeRoot();
    foreach ([
      'double quotes' => 'drush droost:workflow:bypass "urgent"--off',
      'single quotes' => "drush droost:workflow:bypass 'urgent'--off",
      'an escaped space' => 'drush droost:workflow:bypass urgent\ --off',
      'a baseline refresh' => 'drush droost:workflow:baseline --refresh "x"--status',
      'the effort dial' => 'drush droost:workflow:effort low "x"--preview',
    ] as $label => $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $label . ' is one argument, not a flag');
    }
  }

  /**
   * A containerised or remote drush is judged as the command line it is.
   *
   * `ddev exec "drush droost:workflow:bypass --off"` is how this project runs
   * drush. The verb was found in the raw command while the exemption test
   * dropped quoted spans — so the `--off` that makes it the SAFE form vanished,
   * and the operator standing the wall back down was refused. So were the
   * agent's sanctioned grounding commands in every container form. A guard that
   * refuses the documented way out is worse than a hole: it is why somebody
   * switches the guard off.
   */
  public function testNestedCommandLineIsUnwrapped(): void {
    $root = $this->makeRoot();
    $allowed = [
      'ddev exec "drush droost:workflow:bypass --off"',
      'ddev exec "drush droost:workflow:baseline --status"',
      "bash -c 'drush droost:workflow:bypass --off'",
      'ssh web "drush droost:workflow:baseline --measure"',
      'ddev exec "drush droost:workflow:effort low --preview"',
    ];
    foreach ($allowed as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $code, $command . ' reads or tightens');
    }

    // And unwrapping must not become a way THROUGH: the grant forms stay
    // refused inside the container exactly as outside it.
    foreach ([
      'ddev exec "drush droost:workflow:bypass hotfix"',
      "bash -c 'drush droost:workflow:gate-waive phpcs'",
      'ddev exec "drush droost:gate allow_entity_write on"',
      'ddev exec "drush droost:workflow:baseline --refresh"',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $command . ' is still the operator\'s');
    }
  }

  /**
   * A trailing slash is not a way to act on something inside a directory.
   *
   * The scan matched the directory name with a regex whose character classes
   * did not list `/`, so `rm -rf droost/` — the exact command it was written to
   * stop — went straight through, and so did `./droost`, `droost//` and
   * `droost/.`. Matching a path by regex is the mistake; a token goes through
   * `normalised_path()`, which collapses every one of those and has been
   * attacked for it.
   */
  public function testPathSpellingsDoNotEvadeTheDirectoryScan(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    mkdir($root . '/droost/baseline', 0775, TRUE);
    mkdir($root . '/.claude/hooks', 0775, TRUE);
    foreach ([
      'rm -rf droost/',
      'rm -rf droost/droost-workflow/',
      'rm -rf ./droost',
      'rm -rf droost//',
      'rm -rf droost/.',
      'mv ./droost/droost-workflow /tmp/dw',
      'rm -rf .claude/',
      'rm -rf droost/baseline/',
      // An alias bypass and an absolute binary are the same command.
      '\\rm -rf droost',
      '/bin/mv droost /tmp',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $command . ' takes the enforcement with it');
    }
  }

  /**
   * A destructive verb only governs its OWN command.
   *
   * Scanning the whole string for a verb and a name refused `rm -rf
   * node_modules && ls droost`, where the two belong to different commands —
   * and refused reading a file out of the state directory in the same breath as
   * an unrelated cleanup. False positives are the more dangerous half here: a
   * guard that blocks ordinary work is a guard somebody turns off.
   */
  public function testDestructiveVerbReachesNoOtherCommand(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow', 0775, TRUE);
    foreach ([
      'rm -rf node_modules && ls droost',
      'rm -rf build; cat droost/droost-workflow/spec-x.md',
      'cp droost/droost-workflow/spec-x.md /tmp/backup.md',
      'git commit -m "droost work"',
      'rm -rf vendor',
      'npm ci',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $code, $command . ' is ordinary work');
    }
  }

  /**
   * The quoted value still arms a write gate, and is still refused.
   *
   * `drush droost:gate allow_entity_write "on"` armed one. The value was
   * matched as a raw word and `"on"` is not `on` — the shell hands the command
   * `[droost:gate] [allow_entity_write] [on]` either way.
   */
  public function testQuotedValueHidesNoArming(): void {
    $root = $this->makeRoot();
    foreach ([
      'drush droost:gate allow_entity_write "on"',
      "drush droost:gate allow_entity_write 'on'",
      'drush droost:gate allow_entity_write o"n"',
      'drush config:set droost.settings allow_eval "true"',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $command . ' arms a write gate');
    }
  }

  /**
   * The verb split by a quote is the same verb.
   *
   * `drush "droost:workflow:byp"ass` is `droost:workflow:bypass` to the shell
   * and was nothing at all to a regex — which defeated ALL FIVE refusals with
   * one pair of quotes. Quoting splits a word to a pattern and joins it to the
   * shell, and only one of those is what actually runs.
   */
  public function testVerbSplitByQuotesIsStillTheVerb(): void {
    $root = $this->makeRoot();
    foreach ([
      'drush "droost:workflow:byp"ass "reason"',
      'drush droost:workflow:gate-"waive" phpcs',
      'drush droost:workflow:eff"ort" max',
      "drush 'droost:gate' allow_db_write on",
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $command . ' is the operator\'s command');
    }
  }

  /**
   * The exemption must belong to the command it exempts.
   *
   * `bypass "hotfix"; echo --off` read its `--off` out of the `echo` — a
   * different command entirely — and granted the bypass. A flag is an argument
   * of one invocation, not a word on a line.
   */
  public function testExemptionFromAnotherCommandDoesNotCount(): void {
    $root = $this->makeRoot();
    foreach ([
      'drush droost:workflow:bypass "hotfix"; echo --off',
      'drush droost:workflow:baseline --refresh; echo --status',
      'drush droost:workflow:effort max && echo --preview',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(2, $code, $command . ' has no exemption of its own');
    }
  }

  /**
   * And the exemption in the SAME command still counts, nested or not.
   *
   * The half that keeps the guard usable: a real `--off`, `--status`,
   * `--measure` or `--preview` is the documented way out, and refusing those is
   * how a guard gets switched off.
   */
  public function testTheCommandsOwnExemptionStillCounts(): void {
    $root = $this->makeRoot();
    foreach ([
      'drush droost:workflow:bypass --off',
      'drush droost:workflow:baseline --status',
      'drush droost:workflow:baseline --measure',
      'drush droost:workflow:effort high --preview',
      'drush droost:workflow:effort',
      'drush droost:gate allow_entity_write off',
      'drush droost:gate allow_entity_write "off"',
      'ddev exec "drush droost:workflow:bypass --off"',
      "bash -c 'drush droost:workflow:baseline --measure'",
      'drush config:set other.settings allow_eval true',
    ] as $command) {
      [$code] = $this->guard($root, 'operator-commands', [
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command],
      ]);
      $this->assertSame(0, $code, $command . ' reads, tightens, or is not droost\'s');
    }
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
    $code = proc_close($process);

    // A CRASH IS NOT AN ANSWER. The guard speaks in two exit codes — 0 allows,
    // 2 blocks — and anything else is a PHP error, which a host reads as "not a
    // block". A top-level `const` added to this file was not hoisted the way a
    // function declaration is, so every operator-command check died with an
    // uncaught Error and exited 255; the shell probe used to find it asked "is
    // the code 2?" and reported that as ALLOWED. Enforcement was off for an
    // hour and the probe said it was working.
    //
    // Asserted here rather than in each test, so a test cannot pass by reading
    // a crash as permission.
    $this->assertContains(
      $code,
      [0, 2],
      sprintf(
        "The guard exited %d, which is neither allow (0) nor block (2) — it "
        . "crashed. stderr:\n%s",
        $code,
        $stderr,
      ),
    );

    return [$code, $stdout, $stderr];
  }

}
