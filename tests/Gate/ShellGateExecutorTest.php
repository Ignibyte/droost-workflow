<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Spawning the consuming repo's own tools.
 *
 * The subprocess itself is injected, so these tests drive argv construction,
 * exit handling and parsing without installing a toolchain into the suite.
 * What they cannot prove is that a real phpcs accepts the argv — that first
 * happens against a real repo at the live-surface ticket.
 */
class ShellGateExecutorTest extends WorkflowTestCase {

  /**
   * Each gate is invoked with the arguments its tool expects.
   *
   * @param string $gate
   *   The gate name.
   * @param array<string, int|string> $options
   *   The gate's options.
   * @param list<string> $expected
   *   Argv fragments that must appear, in order after the binary.
   */
  #[DataProvider('argvCases')]
  public function testArgvIsBuiltPerGate(
    string $gate,
    array $options,
    array $expected,
  ): void {
    $root = $this->rootWithBinaries(['phpcs', 'phpstan', 'phpunit', 'infection']);
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $executor->execute(new GateSettings($gate, TRUE, $options), $root);

    $this->assertSame($expected, array_slice($seen, 1));
  }

  /**
   * The runner is told each gate's own time: its lever, else the default.
   */
  public function testTheRunnerGetsTheGatesTimeout(): void {
    $root = $this->rootWithBinaries(['infection', 'phpcs']);
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv, string $dir, int $timeout) use (&$seen): array {
        $seen[] = $timeout;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $executor->execute(new GateSettings('mutation', TRUE, ['msi_min' => 80, 'timeout' => 1800]), $root);
    $executor->execute(new GateSettings('phpcs', TRUE, ['standard' => 'Drupal']), $root);

    $this->assertSame([1800, ShellGateExecutor::DEFAULT_TIMEOUT], $seen);
  }

  /**
   * A tool killed at the timeout could not run — and the line names the lever.
   *
   * F-ADOPT-23: infection over one kernel-test-heavy module lost the race
   * against a fixed ten minutes every time; the verdict used to be a bare
   * exit 124 with nothing to act on.
   */
  public function testKilledAtTheTimeoutNamesTheLever(): void {
    $root = $this->rootWithBinaries(['infection']);
    $executor = new ShellGateExecutor(
      static fn (): array => [ShellGateExecutor::EXIT_KILLED, '', ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('mutation', TRUE, ['msi_min' => 80, 'timeout' => 1800]), $root);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertTrue($result->status->blocksAdvance());
    $this->assertStringContainsString('killed after 1800s', $result->summary);
    $this->assertStringContainsString('gates.mutation.timeout', $result->summary);
  }

  /**
   * Gate levers and the argv they must produce.
   *
   * @return array<string, array{string, array<string, int|string>, list<string>}>
   *   Case name to gate, options and expected argv tail.
   */
  public static function argvCases(): array {
    return [
      'phpcs carries its standard, the Drupal extensions, and ignores vendored trees' => [
        'phpcs',
        ['standard' => 'Drupal,DrupalPractice'],
        [
          '-q',
          '--report=json',
          // The project root. A `--standard` with no path is not a scan: phpcs
          // exits 16, "You must supply at least one file or directory", and the
          // gate recorded a labeled pass over nothing. Eleven real violations,
          // gate `passed`.
          '.',
          '--standard=Drupal,DrupalPractice',
          // Without this, PHP_CodeSniffer 4 checks `php` only and a Drupal
          // project whose code is .module/.theme/.install files gets
          // "No files were checked" — a hard failure on healthy code.
          '--extensions=php,module,install,inc,theme,profile,engine,css,js',
          '--ignore=*/node_modules/*,*/vendor/*,*/.claude/*,'
          . '*/droost/droost-workflow/*,*/droost/baseline/*,*/.droost-workflow/*',
        ],
      ],
      'phpstan carries a numeric level' => [
        'phpstan',
        ['level' => 6],
        [
          'analyse',
          '--no-progress',
          '--error-format=json',
          // No `.` here, unlike phpcs. phpcs takes `--ignore`; phpstan has no
          // such flag, so a bare `.` walked vendor/ and node_modules/ and the
          // gate reported hundreds of errors in third-party code. What phpstan
          // analyses is named by its own config or by the `paths` lever, and
          // being pointed at neither is a labelled non-measurement — see
          // testPhpstanWithNoPathDoesNotFail.
          '--level=6',
          '--memory-limit=1G',
        ],
      ],
      'phpstan carries a word level' => [
        'phpstan',
        ['level' => 'max'],
        [
          'analyse',
          '--no-progress',
          '--error-format=json',
          '--level=max',
          '--memory-limit=1G',
        ],
      ],
      'coverage asks for a summary, not a threshold' => [
        'coverage',
        ['min' => 80],
        // PHPUnit has no --min-coverage option; the floor is enforced by
        // parsing the summary, so the argv must not invent a flag.
        [
          '--no-progress',
          '--coverage-text',
          '--only-summary-for-coverage-text',
        ],
      ],
      'mutation carries its floor' => [
        'mutation',
        ['msi_min' => 70],
        ['--no-progress', '--min-msi=70'],
      ],
    ];
  }

  /**
   * REQ-003: a missing binary is tool-missing, and it names the invocation.
   */
  public function testMissingBinaryIsToolMissing(): void {
    $root = $this->makeRoot();
    $executor = new ShellGateExecutor(
      static fn (): array => throw new \LogicException('must not spawn'),
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertTrue($result->status->blocksAdvance());
    $this->assertNotNull($result->invocation);
    $this->assertStringContainsString('vendor/bin/phpcs', $result->invocation);
  }

  /**
   * A zero exit passes; anything else fails.
   *
   * @param int $exit
   *   The tool's exit code.
   * @param \Droost\Workflow\Gate\GateStatus $expected
   *   The status it must produce.
   */
  #[DataProvider('exitCodes')]
  public function testExitCodeDecidesTheVerdict(
    int $exit,
    GateStatus $expected,
  ): void {
    $root = $this->rootWithBinaries(['phpcs']);
    $executor = new ShellGateExecutor(
      static fn (): array => [$exit, '', 'something went wrong'],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);

    $this->assertSame($expected, $result->status);
    $this->assertSame($exit, $result->exitCode);
  }

  /**
   * Warnings alone pass phpcs; errors fail — the verdict reads the totals.
   *
   * PHP_CodeSniffer exits non-zero on warnings too, so a committed minified
   * stylesheet ("file appears to be minified") failed the gate exactly like a
   * coding standards violation (round 24, R24-F7). The exit code stays on the
   * record; the status follows totals.errors, and the pass says how many
   * warnings it carried so it is never mistaken for a silent one.
   */
  public function testPhpcsWarningsAlonePassErrorsFail(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    $warningsOnly = json_encode([
      'totals' => ['errors' => 0, 'warnings' => 1, 'fixable' => 0],
      'files' => [
        '/x/theme.css' => [
          'errors' => 0,
          'warnings' => 1,
          'messages' => [
            ['message' => 'File appears to be minified and cannot be processed', 'type' => 'WARNING'],
          ],
        ],
      ],
    ]);
    $executor = new ShellGateExecutor(
      static fn (): array => [2, (string) $warningsOnly, ''],
      static fn (): int => 0,
    );
    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);
    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame(2, $result->exitCode, 'the exit code stays on the record');
    $this->assertStringContainsString('1 warning(s) and no errors', $result->summary);
    $this->assertNotSame([], $result->findings, 'the warning rides along as a finding');

    $withErrors = json_encode([
      'totals' => ['errors' => 3, 'warnings' => 1, 'fixable' => 2],
      'files' => [],
    ]);
    $executor = new ShellGateExecutor(
      static fn (): array => [2, (string) $withErrors, ''],
      static fn (): int => 0,
    );
    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);
    $this->assertSame(GateStatus::Failed, $result->status);

    // Output that is not phpcs's JSON keeps the exit-code rule.
    $executor = new ShellGateExecutor(
      static fn (): array => [2, 'PHP Fatal error: something', ''],
      static fn (): int => 0,
    );
    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);
    $this->assertSame(GateStatus::Failed, $result->status);
  }

  /**
   * Exit codes and their verdicts.
   *
   * @return array<string, array{int, \Droost\Workflow\Gate\GateStatus}>
   *   Case name to exit code and status.
   */
  public static function exitCodes(): array {
    return [
      'clean' => [0, GateStatus::Passed],
      'findings' => [1, GateStatus::Failed],
      'errors' => [2, GateStatus::Failed],
      'killed' => [137, GateStatus::Failed],
    ];
  }

  /**
   * Measured coverage at or above the floor passes.
   */
  public function testCoverageAtTheFloorPasses(): void {
    $result = $this->coverageRun(80, [0, self::coverageSummary('80.00'), '']);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame('coverage 80.0% meets min 80%', $result->summary);
  }

  /**
   * Measured coverage under the floor fails, and the summary says by what.
   */
  public function testCoverageUnderTheFloorFails(): void {
    $result = $this->coverageRun(80, [0, self::coverageSummary('61.20'), '']);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame('coverage 61.2% is under min 80%', $result->summary);
  }

  /**
   * A green suite that measured nothing is a broken setup, not a pass.
   *
   * Without a coverage driver phpunit exits 0 and prints no percentage — an
   * exit-code verdict would wave the gate through having checked nothing.
   */
  public function testCoverageWithoutDriverIsToolMissing(): void {
    $result = $this->coverageRun(
      80,
      [0, "PHPUnit 12.5.32\n\nOK (10 tests)\n", ''],
    );

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertTrue($result->status->blocksAdvance());
    $this->assertStringContainsString('xdebug or pcov', $result->summary);
  }

  /**
   * A failing suite fails the coverage gate before coverage is a question.
   */
  public function testCoverageWithFailingSuiteFails(): void {
    $result = $this->coverageRun(
      80,
      [1, self::coverageSummary('90.00'), 'there were failures'],
    );

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame(1, $result->exitCode);
    $this->assertStringContainsString('exit 1', $result->summary);
  }

  /**
   * A failure summary carries the tool's own first line.
   */
  public function testFailureSummaryQuotesTheTool(): void {
    $root = $this->rootWithBinaries(['phpstan']);
    $executor = new ShellGateExecutor(
      static fn (): array => [1, '', "Ignored error pattern\nsecond line"],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpstan', TRUE), $root);

    $this->assertStringContainsString('exit 1', $result->summary);
    $this->assertStringContainsString('Ignored error pattern', $result->summary);
    $this->assertStringNotContainsString('second line', $result->summary);
  }

  /**
   * Unparseable output costs the report its detail, never its verdict.
   */
  public function testUnparseableOutputStillYieldsVerdict(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    $executor = new ShellGateExecutor(
      static fn (): array => [1, 'not json at all', ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame([], $result->findings);
  }

  /**
   * Duration comes from the injected clock, so a report is reproducible.
   */
  public function testDurationComesFromTheClock(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    $ticks = [1000, 1250];
    $executor = new ShellGateExecutor(
      static fn (): array => [0, '', ''],
      static function () use (&$ticks): int {
        return (int) array_shift($ticks);
      },
    );

    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);

    $this->assertSame(250, $result->durationMs);
  }

  /**
   * Runs the coverage gate against a scripted subprocess outcome.
   *
   * @param int $min
   *   The gate's floor.
   * @param array{int, string, string} $outcome
   *   Exit code, stdout and stderr the runner returns.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function coverageRun(int $min, array $outcome): GateResult {
    $root = $this->rootWithBinaries(['phpunit']);
    $executor = new ShellGateExecutor(
      static fn (): array => $outcome,
      static fn (): int => 0,
    );
    return $executor->execute(
      new GateSettings('coverage', TRUE, ['min' => $min]),
      $root,
    );
  }

  /**
   * The summary block phpunit prints for --only-summary-for-coverage-text.
   *
   * @param string $lines
   *   The Lines percentage, as phpunit renders it.
   *
   * @return string
   *   The stdout payload.
   */
  private static function coverageSummary(string $lines): string {
    return "PHPUnit 12.5.32\n\nOK (10 tests)\n\n"
      . "Code Coverage Report Summary:\n"
      . "  Classes: 50.00% (4/8)\n"
      . "  Methods: 66.67% (10/15)\n"
      . "  Lines:   {$lines}% (153/250)\n";
  }

  /**
   * A paths lever REPLACES the default subject; it does not widen it.
   *
   * The comment beside the `.` in `argvFor()` has always said "a `paths` lever
   * replaces this below", and `array_merge` never did. The invocation a real
   * project recorded was:
   *
   *   phpcs -q --report=json . --standard=PSR12 … src tests
   *
   * — the whole project AND the scope. So the lever an operator sets to narrow
   * a gate widened it instead: 935 violations came back, 876 of them in
   * `.claude/hooks/droost-workflow-guard.php`, a file `init` had installed. The
   * run failed terminally on a mandatory gate, and the obvious next step —
   * phpcbf on what was reported — would have rewritten droost's own hook.
   *
   * It is also the lever the gate's own error message recommends setting.
   */
  public function testPathsLeversReplaceTheDefaultSubject(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $executor->execute(
      new GateSettings('phpcs', TRUE, ['standard' => 'Drupal', 'paths' => 'src']),
      $root,
    );

    $this->assertContains('src', $seen, 'the scope is what gets scanned');
    $this->assertNotContains(
      '.',
      $seen,
      'and the project root is not scanned alongside it — which would include '
      . 'the hook droost itself installed',
    );
  }

  /**
   * A paths lever appends the paths that exist and hold analysable files.
   *
   * The configured pair names one directory with real code and one that does
   * not exist — the argv must carry the first and never the second, because
   * both tools error out on paths they cannot read.
   */
  public function testPathsAppendedAndMissingOnesFiltered(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    mkdir($root . '/web/modules/custom/fx', 0755, TRUE);
    file_put_contents($root . '/web/modules/custom/fx/fx.module', "<?php\n");
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $result = $executor->execute(
      new GateSettings('phpcs', TRUE, [
        'standard' => 'Drupal',
        'paths' => 'web/modules/custom,web/themes/custom',
      ]),
      $root,
    );

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame('web/modules/custom', end($seen));
    $this->assertNotContains('web/themes/custom', $seen);
  }

  /**
   * A tool that crashes is "could not run", never a failing lint (F-ADOPT-9).
   *
   * Live at xhigh on a Drupal site: eslint walked up to core's scaffolded
   * .eslintrc.json, could not load the plugins it extends, exited 2 with a
   * banner — and the phase read that as findings. A crash names the
   * environment, not the code; it still fails closed, and the summary says
   * why and what lever fixes it.
   */
  public function testToolThatCrashesIsCouldNotRunNotFindings(): void {
    $root = $this->makeRoot();
    mkdir($root . '/node_modules/.bin', 0755, TRUE);
    file_put_contents($root . '/node_modules/.bin/eslint', "#!/bin/sh\nexit 2\n");
    chmod($root . '/node_modules/.bin/eslint', 0755);
    mkdir($root . '/web/modules/custom/fx/js', 0755, TRUE);
    file_put_contents($root . '/web/modules/custom/fx/js/fx.js', "console.log(1);\n");
    $executor = new ShellGateExecutor(
      static fn (): array => [
        2,
        '',
        "Oops! Something went wrong! :(\n\nESLint: 8.57.1\n\nESLint couldn't find the config \"airbnb-base\" to extend from. Please check that the name of the config is correct.\n",
      ],
      static fn (): int => 0,
    );

    $result = $executor->execute(
      new GateSettings('eslint', TRUE, ['paths' => 'web/modules/custom']),
      $root,
    );

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertTrue($result->status->blocksAdvance(), 'fails closed, like a missing tool');
    $this->assertSame([], $result->findings, 'a crash is not findings');
    $this->assertStringStartsWith('eslint could not run (exit 2): ESLint couldn\'t find the config "airbnb-base"', $result->summary);
    $this->assertStringContainsString('gates.eslint.config', $result->summary);
    $this->assertSame('ERROR — tool could not run', $result->status->label());

    // The exit codes that mean "found problems" keep their verdict.
    $lint = new ShellGateExecutor(
      static fn (): array => [
        1,
        '[{"filePath":"/x/fx.js","messages":[{"ruleId":"semi","message":"Missing semicolon.","line":1,"severity":2}]}]',
        '',
      ],
      static fn (): int => 0,
    );
    $this->assertSame(GateStatus::Failed, $lint->execute(new GateSettings('eslint', TRUE, ['paths' => 'web/modules/custom']), $root)->status);
  }

  /**
   * Each tool's exit codes for "could not run" versus "found problems".
   *
   * @param string $gate
   *   The gate.
   * @param int $exit
   *   The exit code.
   * @param bool $crash
   *   Whether it means the tool did not run.
   */
  #[DataProvider('crashExitCases')]
  public function testToolFailedToRunKnowsEachToolsVocabulary(string $gate, int $exit, bool $crash): void {
    $this->assertSame($crash, ShellGateExecutor::toolFailedToRun($gate, $exit));
  }

  /**
   * Exit-code vocabulary per tool.
   *
   * @return array<string, array{string, int, bool}>
   *   Gate, exit, whether it is a crash.
   */
  public static function crashExitCases(): array {
    return [
      'eslint 1 is findings' => ['eslint', 1, FALSE],
      'eslint 2 is a crash' => ['eslint', 2, TRUE],
      'stylelint 2 is findings' => ['stylelint', 2, FALSE],
      'stylelint 1 is a fatal' => ['stylelint', 1, TRUE],
      'stylelint 78 is a bad config' => ['stylelint', 78, TRUE],
      'prettier 1 is unformatted files' => ['prettier', 1, FALSE],
      'prettier 2 is a crash' => ['prettier', 2, TRUE],
      'phpcs 2 is fixable errors' => ['phpcs', 2, FALSE],
      'phpcs 3 is a processing error' => ['phpcs', 3, TRUE],
      'phpstan is never classified here' => ['phpstan', 255, FALSE],
    ];
  }

  /**
   * The `config` lever pins the project's own file and turns discovery off.
   *
   * ESLint's spelling depends on the major installed: 8 (eslintrc) takes
   * --no-eslintrc, 9 (flat config) --no-config-lookup. stylelint and
   * prettier take --config. Without the lever nothing is added.
   *
   * @param string $gate
   *   The gate.
   * @param string|null $eslintVersion
   *   The installed eslint version to fake, or NULL for none installed.
   * @param list<string> $expected
   *   The argv fragments the lever must add, in order, before the files.
   */
  #[DataProvider('configLeverCases')]
  public function testConfigLeverPinsTheProjectsOwnConfig(string $gate, ?string $eslintVersion, array $expected): void {
    $root = $this->makeRoot();
    mkdir($root . '/node_modules/.bin', 0755, TRUE);
    file_put_contents($root . '/node_modules/.bin/' . $gate, "#!/bin/sh\nexit 0\n");
    chmod($root . '/node_modules/.bin/' . $gate, 0755);
    if ($eslintVersion !== NULL) {
      mkdir($root . '/node_modules/eslint', 0755, TRUE);
      file_put_contents(
        $root . '/node_modules/eslint/package.json',
        json_encode(['name' => 'eslint', 'version' => $eslintVersion]),
      );
    }
    mkdir($root . '/web/modules/custom/fx/js', 0755, TRUE);
    mkdir($root . '/web/modules/custom/fx/css', 0755, TRUE);
    file_put_contents($root . '/web/modules/custom/fx/js/fx.js', "console.log(1);\n");
    file_put_contents($root . '/web/modules/custom/fx/css/fx.css', ".a{}\n");
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '[]', ''];
      },
      static fn (): int => 0,
    );

    $executor->execute(
      new GateSettings($gate, TRUE, ['paths' => 'web/modules/custom', 'config' => '.lintrc.json']),
      $root,
    );
    $tail = array_slice($seen, 1);
    $this->assertSame(
      $expected,
      array_slice($tail, 1, count($expected)),
      'the lever\'s flags follow the format flag',
    );
    $this->assertStringStartsWith('web/modules/custom/fx/', (string) end($seen), 'the files still come last');

    $bare = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '[]', ''];
      },
      static fn (): int => 0,
    );
    $bare->execute(new GateSettings($gate, TRUE, ['paths' => 'web/modules/custom']), $root);
    $this->assertNotContains('--config', $seen, 'no lever, no flags: discovery as before');
  }

  /**
   * The config lever's spelling per tool and eslint major.
   *
   * @return array<string, array{string, string|null, list<string>}>
   *   Gate, faked eslint version, expected flags.
   */
  public static function configLeverCases(): array {
    return [
      'eslint 8 (eslintrc)' => ['eslint', '8.57.1', ['--no-eslintrc', '--config', '.lintrc.json']],
      'eslint 9 (flat config)' => ['eslint', '9.12.0', ['--no-config-lookup', '--config', '.lintrc.json']],
      'eslint unknown reads as current' => ['eslint', NULL, ['--no-config-lookup', '--config', '.lintrc.json']],
      'stylelint' => ['stylelint', NULL, ['--config', '.lintrc.json']],
      'prettier' => ['prettier', NULL, ['--config', '.lintrc.json']],
    ];
  }

  /**
   * The front-end trio are handed concrete files, never a bare directory.
   *
   * A real defect caught live (adopter dogfood): stylelint given the directory
   * `web/modules/custom` globbed EVERY file under it — .info.yml, .install,
   * .php, .twig — and parsed each as CSS, raising a CssSyntaxError on all of
   * them. The tool must see only the files it owns; phpcs/phpstan keep taking
   * the directory (they filter by extension themselves).
   */
  public function testFrontEndTrioScopeToConcreteFiles(): void {
    $root = $this->makeRoot();
    // stylelint's binary lives under node_modules/.bin, not vendor/bin.
    mkdir($root . '/node_modules/.bin', 0755, TRUE);
    file_put_contents($root . '/node_modules/.bin/stylelint', "#!/bin/sh\nexit 0\n");
    chmod($root . '/node_modules/.bin/stylelint', 0755);
    $mod = $root . '/web/modules/custom/fx';
    mkdir($mod . '/css', 0755, TRUE);
    file_put_contents($mod . '/css/fx.css', ".a { color: red; }\n");
    // The non-CSS neighbours a bare directory would sweep in.
    file_put_contents($mod . '/fx.info.yml', "name: FX\n");
    file_put_contents($mod . '/fx.module', "<?php\n");
    file_put_contents($mod . '/fx.libraries.yml', "fx: {}\n");
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '[]', ''];
      },
      static fn (): int => 0,
    );

    $result = $executor->execute(
      new GateSettings('stylelint', TRUE, [
        'paths' => 'web/modules/custom,web/themes/custom',
      ]),
      $root,
    );

    $this->assertSame(GateStatus::Passed, $result->status);
    // The one real stylesheet is passed by its concrete path...
    $this->assertContains('web/modules/custom/fx/css/fx.css', $seen);
    // ...and none of the non-CSS neighbours, nor the bare directory that
    // would have swept them in, is ever handed to stylelint.
    $this->assertNotContains('web/modules/custom', $seen);
    $this->assertNotContains('web/modules/custom/fx/fx.info.yml', $seen);
    $this->assertNotContains('web/modules/custom/fx/fx.module', $seen);
    $this->assertNotContains('web/modules/custom/fx/fx.libraries.yml', $seen);
  }

  /**
   * A paths lever whose scope holds nothing is a labeled pass, not a run.
   *
   * PHPStan exits non-zero on a path set with no PHP in it, so running would
   * report a failing gate on every repo whose custom-code directories are
   * still empty. The pass must SAY it analysed nothing — that label is what
   * keeps it distinguishable from a clean scan.
   */
  public function testEmptyPathsScopeIsLabeledPass(): void {
    $root = $this->rootWithBinaries(['phpstan']);
    mkdir($root . '/web/modules/custom', 0755, TRUE);
    file_put_contents($root . '/web/modules/custom/notes.txt', "no code\n");
    $executor = new ShellGateExecutor(
      static fn (): array => throw new \LogicException('must not spawn'),
      static fn (): int => 0,
    );

    $result = $executor->execute(
      new GateSettings('phpstan', TRUE, [
        'level' => 6,
        'paths' => 'web/modules/custom,web/themes/custom',
      ]),
      $root,
    );

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertStringContainsString('nothing to analyse', $result->summary);
    $this->assertStringContainsString('web/modules/custom', $result->summary);
  }

  /**
   * Vendored trees under a path are not the project's code (R24-F3).
   *
   * A subtheme with a build step keeps node_modules/ on disk, and the Drupal
   * standard sniffs JavaScript — so a theme directory whose only files are
   * vendored must read as "nothing to analyse" rather than spawn phpcs over
   * someone else's minified bundle. The moment a real template or stylesheet
   * appears beside it, the path is analysable again.
   */
  public function testVendoredTreesAreNotAnalysable(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    $theme = $root . '/web/themes/custom/mysite';
    mkdir($theme . '/node_modules/tailwindcss/dist', 0755, TRUE);
    mkdir($theme . '/vendor/acme/lib', 0755, TRUE);
    file_put_contents($theme . '/node_modules/tailwindcss/dist/lib.js', "var x;\n");
    file_put_contents($theme . '/vendor/acme/lib/Thing.php', "<?php\n");
    /** @var \ArrayObject<int, list<string>> $spawns */
    $spawns = new \ArrayObject();
    $executor = new ShellGateExecutor(
      static function (array $argv) use ($spawns): array {
        $spawns[] = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );
    $gate = new GateSettings('phpcs', TRUE, ['standard' => 'Drupal', 'paths' => 'web/themes/custom']);

    $result = $executor->execute($gate, $root);
    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertStringContainsString('nothing to analyse', $result->summary);
    $this->assertCount(0, $spawns, 'vendored trees alone never spawn the tool');

    // Real code beside the vendored trees makes the path analysable again.
    file_put_contents($theme . '/mysite.theme', "<?php\n");
    $result = $executor->execute($gate, $root);
    $this->assertCount(1, $spawns, 'a real file makes the path analysable');
    $this->assertStringNotContainsString('nothing to analyse', $result->summary);
  }

  /**
   * What counts as analysable is the gate's call, not one shared list.
   *
   * The Drupal standard genuinely sniffs css, so a css-only theme directory
   * is real work for phpcs — and nothing at all for phpstan.
   */
  public function testPathsAnalysabilityIsPerGate(): void {
    $root = $this->rootWithBinaries(['phpcs', 'phpstan']);
    mkdir($root . '/web/themes/custom/fxt/css', 0755, TRUE);
    file_put_contents($root . '/web/themes/custom/fxt/css/tokens.css', "a {}\n");
    $spawned = 0;
    $executor = new ShellGateExecutor(
      function () use (&$spawned): array {
        $spawned++;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $phpcs = $executor->execute(
      new GateSettings('phpcs', TRUE, ['paths' => 'web/themes/custom']),
      $root,
    );
    $phpstan = $executor->execute(
      new GateSettings('phpstan', TRUE, ['paths' => 'web/themes/custom']),
      $root,
    );

    $this->assertSame(1, $spawned, 'Only phpcs had something to run on.');
    $this->assertSame(GateStatus::Passed, $phpcs->status);
    $this->assertSame(GateStatus::Passed, $phpstan->status);
    $this->assertStringContainsString('nothing to analyse', $phpstan->summary);
    $this->assertStringNotContainsString('nothing to analyse', $phpcs->summary);
  }

  /**
   * A missing tool outranks an empty scope: the environment is still broken.
   */
  public function testMissingBinaryOutranksEmptyPathsScope(): void {
    $root = $this->makeRoot();
    $executor = new ShellGateExecutor(
      static fn (): array => throw new \LogicException('must not spawn'),
      static fn (): int => 0,
    );

    $result = $executor->execute(
      new GateSettings('phpcs', TRUE, ['paths' => 'web/modules/custom']),
      $root,
    );

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
  }

  /**
   * The playwright gate runs the npm binary, as a test-suite invocation.
   *
   * Until 0.4 the mapping pointed at vendor/bin/playwright, which no repo
   * on earth has — a gate that could only ever report tool-missing. And the
   * subcommand matters: bare `playwright` prints usage and exits zero,
   * which would read as a pass with no tests run.
   */
  public function testPlaywrightRunsTheNpmBinary(): void {
    $root = $this->makeRoot();
    mkdir($root . '/node_modules/.bin', 0755, TRUE);
    file_put_contents($root . '/node_modules/.bin/playwright', "#!/bin/sh\nexit 0\n");
    chmod($root . '/node_modules/.bin/playwright', 0755);
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('playwright', TRUE), $root);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame($root . '/node_modules/.bin/playwright', $seen[0]);
    $this->assertSame(['test'], array_slice($seen, 1));
  }

  /**
   * A repo without playwright gets tool-missing naming the npm path.
   */
  public function testPlaywrightMissingNamesTheNpmPath(): void {
    $executor = new ShellGateExecutor(
      static fn (): array => [0, '', ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(
      new GateSettings('playwright', TRUE),
      $this->makeRoot(),
    );

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertStringContainsString('node_modules/.bin/playwright', $result->summary);
  }

  /**
   * A phpunit gate with no suite config is config-missing, never a pass.
   *
   * A test run is defined by its config file; bare phpunit against a bare
   * root would error, and the error would read as a failing suite. The
   * refusal happens before anything spawns.
   */
  public function testPhpunitWithoutSuiteConfigIsToolMissing(): void {
    $root = $this->rootWithBinaries(['phpunit']);
    unlink($root . '/phpunit.xml.dist');
    $spawned = FALSE;
    $executor = new ShellGateExecutor(
      function () use (&$spawned): array {
        $spawned = TRUE;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpunit', TRUE), $root);

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertStringContainsString('phpunit.xml', $result->summary);
    $this->assertFalse($spawned, 'nothing spawns against a configless root');
  }

  /**
   * An empty suite is a LABELED pass, never a clean one.
   *
   * The config exists and the runner worked; there is simply nothing to run
   * yet. The label is what keeps "no tests yet" from being read as "the
   * tests passed" — and the first written test hardens the gate with no
   * lever touched.
   */
  public function testPhpunitEmptySuiteIsLabeledPass(): void {
    $root = $this->rootWithBinaries(['phpunit']);
    $executor = new ShellGateExecutor(
      static fn (): array => [0, "PHPUnit 12.5\n\nNo tests executed!\n", ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpunit', TRUE), $root);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertStringContainsString('no tests yet', $result->summary);
  }

  /**
   * With `required`, an empty suite is a FAILURE — the top of the dial.
   *
   * Same runner output as the labelled pass above; the lever alone flips the
   * verdict, so a max run cannot complete without tests that exist.
   */
  public function testPhpunitEmptySuiteFailsWhenRequired(): void {
    $root = $this->rootWithBinaries(['phpunit']);
    $executor = new ShellGateExecutor(
      static fn (): array => [0, "PHPUnit 12.5\n\nNo tests executed!\n", ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(
      new GateSettings('phpunit', TRUE, ['required' => TRUE]),
      $root,
    );

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('required', $result->summary);
  }

  /**
   * A project root with stub binaries in place.
   *
   * @param list<string> $tools
   *   Binary names to create under vendor/bin.
   *
   * @return string
   *   The project root.
   */
  private function rootWithBinaries(array $tools): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0755, TRUE);
    foreach ($tools as $tool) {
      file_put_contents($root . '/vendor/bin/' . $tool, "#!/bin/sh\nexit 0\n");
      chmod($root . '/vendor/bin/' . $tool, 0755);
    }
    // The phpunit/coverage gates refuse a root with no suite config before
    // they spawn anything; a root credible enough to carry stub binaries
    // carries the stub config too.
    file_put_contents($root . '/phpunit.xml.dist', "<phpunit/>\n");
    return $root;
  }

  /**
   * Phpcs is always given something to check, and uses the repo's own ruleset.
   *
   * The mandatory gate checked NOTHING on a stock project. `--standard` was
   * injected unconditionally, and `--standard` makes phpcs discard the ruleset
   * file — including the `<file>` paths that say what to scan. With no `paths`
   * lever either, the invocation was `phpcs -q --report=json --standard=…
   * --extensions=…` and no path at all: exit 16, "You must supply at least one
   * file or directory to process", recorded as a labeled pass.
   *
   * A reviewer put eleven real violations in a file, ran their own phpcs
   * (eleven errors), and watched the gate report `passed`. It also caused a
   * wall they could not pass: nothing measured means `type_coverage` blocks at
   * test, and phpcs does not re-run there.
   *
   * The lever file has promised the fix all along — "omitted, each tool
   * discovers the repo's own config" — while shipping a `standard` that made it
   * impossible.
   */
  public function testPhpcsAlwaysHasSomethingToCheck(): void {
    $root = $this->makeRoot();
    $method = new \ReflectionMethod(ShellGateExecutor::class, 'argvFor');
    $executor = new ShellGateExecutor(
      static fn (array $argv, string $cwd, int $timeout): array => [0, '', ''],
      static fn (): int => 0,
    );
    $gate = new GateSettings('phpcs', TRUE, ['standard' => 'Drupal,DrupalPractice']);

    // No ruleset of its own: the standard is injected AND a path is supplied,
    // because a standard without a path is not a scan.
    $argv = $method->invoke($executor, $gate, '/bin/phpcs', $root);
    $this->assertIsArray($argv);
    // A plain project with nothing of its own still gets the project root:
    // the guarantee is that phpcs is told WHERE to look, because a standard
    // with no path is not a scan. On a Drupal site it is told the custom
    // trees instead — `.` there means core, which the gates never judge.
    $this->assertContains('.', $argv, 'phpcs is told what to look at');
    $this->assertNotSame([], array_filter($argv, static fn (mixed $a): bool => is_string($a) && str_starts_with($a, '--standard=')));

    // Its own ruleset: honoured, not overridden. The ruleset names the
    // standard, the extensions and the files, and overriding any of them is
    // how the gate stopped agreeing with the command a developer runs by hand.
    file_put_contents($root . '/phpcs.xml.dist', '<?xml version="1.0"?><ruleset name="p"><rule ref="PSR12"/><file>src</file></ruleset>');
    $own = $method->invoke($executor, $gate, '/bin/phpcs', $root);
    $this->assertIsArray($own);
    $this->assertSame(
      [],
      array_filter($own, static fn (mixed $a): bool => is_string($a) && str_starts_with($a, '--standard=')),
      "the repo's own standard wins, which is what the lever file has always promised",
    );
    $this->assertSame(
      [],
      array_filter($own, static fn (mixed $a): bool => is_string($a) && str_starts_with($a, '--extensions=')),
      'and its own extensions',
    );
  }

  /**
   * A failing gate's record names what failed, not the tool's version.
   *
   * `summarise()` took the FIRST line of output, which is a banner in every
   * tool this runs — and the summary is what a reader sees first and what
   * propagates into the evidence document as the account of the failure:
   *
   *   "phpunit failed (exit 1): PHPUnit 13.3.3 by Sebastian Bergmann…"
   *   "phpstan failed (exit 1): Instructions for interpreting errors"
   *
   * Neither says anything about what failed. A reviewer driving from the
   * envelope was handed a version number and asked to fix something, and the
   * feedback loop's whole premise is that the record names the cause.
   */
  public function testFailingGatesNameWhatFailed(): void {
    $root = $this->rootWithBinaries(['phpunit']);
    $output = "PHPUnit 13.3.3 by Sebastian Bergmann and contributors.\n\n"
      . "Runtime:       PHP 8.4.24\nConfiguration: /x/phpunit.xml\n\n..FF\n\n"
      . "There were 2 failures:\n\n"
      . "1) Acme\\Tests\\ReorderTest::testReportKeysBySku\n"
      . "Failed asserting that two arrays are identical.\n--- Expected\n+++ Actual\n\n"
      . "/private/tmp/x/tests/ReorderTest.php:25\n";
    file_put_contents($root . '/phpunit.xml', '<phpunit/>');
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [1, $output, ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpunit', TRUE), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringNotContainsString(
      'Sebastian Bergmann',
      $result->summary,
      'the banner is not the account of a failure',
    );
    $this->assertStringContainsString(
      '2 failures',
      $result->summary,
      'what failed is',
    );
  }

  /**
   * And a failing suite's tests reach the record as findings.
   *
   * Phpunit emits no machine format the gate asks for, so it recorded ZERO
   * findings — the only account of a failing suite was that summary line. The
   * same command by hand named the failing test, the assertion it broke, and
   * the file and line.
   */
  public function testFailingSuitesRecordWhichTestsFailed(): void {
    $root = $this->rootWithBinaries(['phpunit']);
    file_put_contents($root . '/phpunit.xml', '<phpunit/>');
    $output = "PHPUnit 13.3.3 by Sebastian Bergmann and contributors.\n\n..FF\n\n"
      . "There were 2 failures:\n\n"
      . "1) Acme\\Tests\\ReorderTest::testReportKeysBySku\n"
      . "Failed asserting that two arrays are identical.\n\n"
      . "/private/tmp/x/tests/ReorderTest.php:25\n\n"
      . "2) Acme\\Tests\\ReorderTest::testTotals\n"
      . "Failed asserting that 4 matches expected 5.\n\n"
      . "/private/tmp/x/tests/ReorderTest.php:41\n";
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [1, $output, ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpunit', TRUE), $root);

    $this->assertCount(2, $result->findings, 'one finding per failing test');
    $rendered = (string) json_encode($result->findings);
    $this->assertStringContainsString('testReportKeysBySku', $rendered, 'named');
    $this->assertStringContainsString('two arrays are identical', $rendered, 'with the assertion');
    $this->assertStringContainsString('ReorderTest.php:25', $rendered, 'and where');
  }

  /**
   * PHP_CodeSniffer 4 renumbered its exit codes, and the gate read the old set.
   *
   * Phpcs 4's ExitCode is OKAY 0, FIXABLE 1, NON_FIXABLE 2, FAILED_TO_FIX 4,
   * PROCESS_ERROR 16. So 3 is `1|2` — ordinary violations, some fixable, which
   * is the commonest possible result of running phpcs — and under phpcs 3 it
   * was the processing error.
   *
   * Reading 3 as "could not run" inverted the gate in both directions at once,
   * measured on a real project: 935 real violations came back as a broken
   * ENVIRONMENT and skipped the feedback loop entirely (the agent is told to
   * check its ruleset, not to fix its code), while exit 16 — `the "Drupal"
   * coding standard is not installed` — was recorded as a labelled PASS on a
   * mandatory gate.
   *
   * The gate asks what phpcs produced rather than sniffing its version: a run
   * that judged anything emits a JSON report, on either major.
   */
  public function testPhpcsFindingsAreFindingsOnEitherMajor(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    $report = (string) json_encode([
      'totals' => ['errors' => 2, 'warnings' => 0, 'fixable' => 1],
      'files' => [
        'src/Money.php' => [
          'errors' => 2,
          'warnings' => 0,
          'messages' => [
            [
              'message' => 'Line indented incorrectly',
              'source' => 'Drupal.WhiteSpace.ScopeIndent.IncorrectExact',
              'line' => 12,
              'type' => 'ERROR',
            ],
            [
              'message' => 'Missing short description',
              'source' => 'Drupal.Commenting.DocComment.Missing',
              'line' => 3,
              'type' => 'ERROR',
            ],
          ],
        ],
      ],
    ]);
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [3, $report, ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);

    $this->assertSame(
      GateStatus::Failed,
      $result->status,
      'violations are a verdict on the code, and belong in the feedback loop',
    );
    $this->assertNotSame(
      GateStatus::ErrorToolFailed,
      $result->status,
      'not a broken environment, which skips the loop and blames the ruleset',
    );
  }

  /**
   * Exit 16 means two opposite things, and phpcs says which.
   *
   * Both are PROCESS_ERROR on phpcs 4: "you handed me nothing" and "I cannot
   * load that standard". The whole of 16 was read as the first, so a ruleset
   * the tool could not load came back as a PASS on a gate that has no waiver.
   */
  public function testExitSixteenIsReadFromWhatPhpcsSaid(): void {
    $root = $this->rootWithBinaries(['phpcs']);

    $broken = new ShellGateExecutor(
      static fn (array $argv): array => [16, '', 'ERROR: the "Drupal" coding standard is not installed.'],
      static fn (): int => 0,
    );
    $result = $broken->execute(new GateSettings('phpcs', TRUE), $root);
    $this->assertSame(
      GateStatus::ErrorToolFailed,
      $result->status,
      'a standard it cannot load is a gate that did not run, not a pass',
    );
    $this->assertFalse($result->labelledPass, 'and not a measurement of anything');
  }

  /**
   * Exit 16 out of phpcs is a labelled pass, produced here.
   *
   * `LabelledPassTest` covers what the store and the report do with one, in
   * four tests, and builds every fixture by calling
   * `GateResult::labelledPass()` by hand. Nothing drove `execute()` with the
   * exit code that is supposed to produce one — consumer thoroughly tested,
   * producer not at all — so deleting the branch outright left the suite green.
   *
   * What that would ship: exit 16 is "No files were checked", and without the
   * branch it falls through to the ordinary result path, where a project
   * carrying a phpcs baseline renders it as "passed — 0 new, 0 inherited". A
   * confident green over a scan of nothing, which is the defect the branch
   * exists to prevent, restored silently.
   */
  public function testPhpcsExitSixteenIsLabelledAsMeasuringNothing(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [
        16,
        '',
        'ERROR: You must supply at least one file or directory to process.',
      ],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);

    $this->assertSame(GateStatus::Passed, $result->status, 'it does not fail the phase');
    $this->assertTrue(
      $result->labelledPass,
      'and it is flagged as having measured nothing, which is what type_coverage reads',
    );
    $this->assertStringContainsString('not a measurement', $result->summary);
  }

  /**
   * And it stays a labelled pass when a baseline is configured.
   *
   * The branch's own docblock says it must come BEFORE the baseline partition,
   * because `againstBaseline()` renders exit 16 as "passed — 0 new, 0
   * inherited" — the most reassuring sentence in the report, about a scan that
   * read no files. Ordering is not something a reader can check by eye, so it
   * is asserted.
   */
  public function testExitSixteenIsNotPartitionedByTheBaseline(): void {
    $root = $this->rootWithBinaries(['phpcs']);
    mkdir($root . '/droost/baseline', 0775, TRUE);
    file_put_contents(
      $root . '/droost/baseline/phpcs.json',
      (string) json_encode(['findings' => [], 'measured_at' => '2026-09-13T00:00:00+00:00']),
    );
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [16, '', 'ERROR: You must supply at least one file'],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpcs', TRUE), $root);

    $this->assertTrue($result->labelledPass);
    $this->assertStringNotContainsString(
      'inherited',
      $result->summary,
      'a scan of nothing is never partitioned into new and inherited',
    );
  }

  /**
   * No drush means no site to ask, not a broken environment.
   *
   * `wiki_fresh` runs `drush droost:wiki:status`. On a checkout with no drush
   * it reported `error-tool-missing`, which BLOCKS — at `complete`, the last
   * phase, with levers frozen since `begin` so turning it off no longer helps.
   * The only way out was `reset --force` and redoing three phases, so no
   * standalone run could finish at the levers `init` itself writes.
   *
   * The first fix moved the gate into `GateRunner::SITE_GATES`, which
   * dispatches to the site driver — and `DrupalSiteDriver::supports()` names
   * three gates, so on a REAL site it became `toolMissing` there instead. The
   * same wall, moved onto the surface a real run uses. Both halves are asserted
   * here so neither can come back.
   */
  public function testWikiFreshSkipsWithoutDrushAndRunsWithIt(): void {
    $bare = $this->makeRoot();
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [0, '', ''],
      static fn (): int => 0,
    );

    $absent = $executor->execute(new GateSettings('wiki_fresh', TRUE), $bare);
    $this->assertSame(GateStatus::SkippedNoSite, $absent->status);
    $this->assertFalse(
      $absent->status->blocksAdvance(),
      'a checkout with no site must still be able to finish a run',
    );

    // And where drush IS present the gate really runs, rather than having been
    // quietly switched off by the fix for the case above.
    $withDrush = $this->rootWithBinaries(['drush']);
    $spawned = NULL;
    $running = new ShellGateExecutor(
      function (array $argv) use (&$spawned): array {
        $spawned = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );

    $present = $running->execute(new GateSettings('wiki_fresh', TRUE), $withDrush);

    $this->assertSame(GateStatus::Passed, $present->status);
    $this->assertIsArray($spawned, 'it spawned something');
    $this->assertStringContainsString('drush', (string) ($spawned[0] ?? ''));
    $this->assertContains('droost:wiki:status', $spawned);
  }

  /**
   * A drush that cannot bootstrap is no site either.
   *
   * The missing-binary branch answered only "drush is absent". Most Drupal
   * checkouts have `vendor/bin/drush` whether or not a site is bootable, and
   * there it exits 1 with:
   *
   *     PHP Fatal error: Uncaught AssertionError:
   *     assert($this->bootstrap instanceof DrupalBoot8)
   *
   * recorded as `failed` — blocking, at the last phase, on a surface that has
   * no site to give it. Same absence, two spellings, and only one was answered;
   * a walk found the second one the day the first was fixed.
   */
  public function testWikiFreshSkipsWhenDrushCannotBootstrap(): void {
    $root = $this->rootWithBinaries(['drush']);
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [
        1,
        '',
        'PHP Fatal error:  Uncaught AssertionError: assert($this->bootstrap '
        . 'instanceof DrupalBoot8) in .../drush/src/Boot/BootstrapManager.php:119',
      ],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('wiki_fresh', TRUE), $root);

    $this->assertSame(GateStatus::SkippedNoSite, $result->status);
    $this->assertFalse($result->status->blocksAdvance());
  }

  /**
   * But a working drush reporting a stale wiki still fails the gate.
   *
   * The half that keeps the gate a gate: "no site" must not become a way for
   * every wiki_fresh failure to disappear.
   */
  public function testWikiFreshStillFailsOnStaleDocumentation(): void {
    $root = $this->rootWithBinaries(['drush']);
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [1, '{"stale":3}', ''],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('wiki_fresh', TRUE), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertTrue($result->status->blocksAdvance());
  }

  /**
   * And the site driver is not asked for it, because it cannot answer.
   *
   * `GateRunner::SITE_GATES` is dispatched to the driver, and a site gate the
   * driver does not implement is `toolMissing` by design. Naming wiki_fresh
   * there put it in exactly that hole on every real site.
   */
  public function testWikiFreshIsNotDispatchedToTheSiteDriver(): void {
    $this->assertNotContains(
      'wiki_fresh',
      GateRunner::SITE_GATES,
      'SITE_GATES is about a booted kernel in-process, and the Drupal driver '
      . 'implements exactly the three gates named there',
    );
  }

  /**
   * A gate pointed at nothing has not failed, and has not measured.
   *
   * With no phpstan config and no `paths` lever it is invoked with no path at
   * all, exits 1, and says "At least one path must be specified to analyse" —
   * which the gate recorded as `failed`: the CODE reading as broken, in the
   * first code phase of an ordinary project.
   *
   * The first fix appended `.` as the phpcs branch does. phpcs can, because it
   * takes `--ignore`; phpstan has no such flag, so `.` walked `vendor/` and a
   * mandatory blocking gate reported 404 errors — 401 of them third-party — on
   * a project whose own files were clean. One wrong verdict for a louder one.
   *
   * A labelled pass is the honest third answer, and it carries the lever that
   * fixes it. `type_coverage` then holds the run on an unmeasured mandatory
   * gate, because somebody has to say what phpstan should look at.
   */
  public function testPhpstanWithNoPathDoesNotFail(): void {
    $root = $this->rootWithBinaries(['phpstan']);
    $seen = NULL;
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [1, '', 'At least one path must be specified to analyse.'];
      },
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpstan', TRUE), $root);

    $this->assertSame(GateStatus::Passed, $result->status, 'the code did not fail');
    $this->assertTrue($result->labelledPass, 'and nothing was measured');
    $this->assertStringContainsString('gates.phpstan.paths', $result->summary);
    $this->assertNotContains('.', $seen ?? [], 'it was never pointed at the whole tree');
  }

  /**
   * A real phpstan failure is still a failure.
   *
   * The branch above keys on phpstan's own sentence, so it must not swallow an
   * ordinary non-zero exit — which is the whole gate.
   */
  public function testPhpstanStillFailsOnRealErrors(): void {
    $root = $this->rootWithBinaries(['phpstan']);
    $executor = new ShellGateExecutor(
      static fn (array $argv): array => [
        1,
        (string) json_encode(['totals' => ['file_errors' => 3, 'errors' => 0], 'files' => []]),
        '',
      ],
      static fn (): int => 0,
    );

    $result = $executor->execute(new GateSettings('phpstan', TRUE), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertFalse($result->labelledPass);
  }

}
