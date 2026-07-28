<?php

declare(strict_types=1);

namespace Drupal\Tests\droost_workflow\Unit\Gate;

use Drupal\Tests\droost_workflow\Unit\WorkflowTestCase;
use Drupal\droost_workflow\Config\GateSettings;
use Drupal\droost_workflow\Gate\GateStatus;
use Drupal\droost_workflow\Gate\ShellGateExecutor;
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
        ['analyse', '--no-progress', '--error-format=json', '--level=6'],
      ],
      'phpstan carries a word level' => [
        'phpstan',
        ['level' => 'max'],
        ['analyse', '--no-progress', '--error-format=json', '--level=max'],
      ],
      'coverage carries its floor' => [
        'coverage',
        ['min' => 80],
        ['--no-progress', '--coverage-text', '--min-coverage=80'],
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
   * @param \Drupal\droost_workflow\Gate\GateStatus $expected
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
   * @return array<string, array{int, \Drupal\droost_workflow\Gate\GateStatus}>
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
    return $root;
  }

}
