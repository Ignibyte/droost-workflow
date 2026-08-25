<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
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
   * Gate levers and the argv they must produce.
   *
   * @return array<string, array{string, array<string, int|string>, list<string>}>
   *   Case name to gate, options and expected argv tail.
   */
  public static function argvCases(): array {
    return [
      'phpcs carries its standard' => [
        'phpcs',
        ['standard' => 'Drupal,DrupalPractice'],
        ['-q', '--report=json', '--standard=Drupal,DrupalPractice'],
      ],
      'phpstan carries a numeric level' => [
        'phpstan',
        ['level' => 6],
        [
          'analyse',
          '--no-progress',
          '--error-format=json',
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
  public function testPhpunitEmptySuiteIsALabeledPass(): void {
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

}
