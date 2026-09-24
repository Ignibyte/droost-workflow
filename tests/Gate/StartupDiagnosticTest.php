<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A PHP that warns at startup broke the run, and that is not the tests (F-93).
 *
 * Measured on the subject after pcov was added to its ddev image: every PHP
 * process printed "JIT is incompatible with third party extensions that
 * override zend_execute_ex(). JIT disabled." Drupal's kernel tests run in
 * separate processes, and PHPUnit made each one an error carrying that line;
 * infection stopped its initial run at it. The gates said the tests failed.
 * The outputs below are PHPUnit 11.5.56's and infection 0.35.4's, verbatim.
 */
final class StartupDiagnosticTest extends WorkflowTestCase {

  /**
   * The warning, as PHP writes it to stderr.
   */
  private const WARNING = "PHP Warning:  JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0\n";

  /**
   * PHPUnit's report of a kernel test on that PHP.
   */
  private const PHPUNIT = "\nWarning: JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0\n"
    . "PHPUnit 11.5.56 by Sebastian Bergmann and contributors.\n\nRuntime:       PHP 8.4.18\nConfiguration: /var/www/html/phpunit.xml\n\n"
    . "Time: 00:01.634, Memory: 12.00 MB\n\nThere was 1 error:\n\n"
    . "1) Drupal\\Tests\\example_home\\Kernel\\FeaturedContentTest::testNothingFeatured\n"
    . "PHPUnit\\Framework\\Exception: PHP Warning:  JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0\n\n"
    . "ERRORS!\nTests: 5, Assertions: 7, Errors: 1.\n";

  /**
   * Infection's report of the initial run it stopped.
   */
  private const INFECTION = "Infection - PHP Mutation Testing Framework version 0.35.4\n\n"
    . "Running initial tests with PHPUnit version 11.5.56\n\n"
    . " [ERROR] Project tests must be in a passing state before running Infection.\n"
    . "         PHPUnit reported an exit code of 143.\n"
    . "         Refer to the PHPUnit's output below:\n"
    . "         STDERR:\n"
    . "         PHP Warning:  JIT is incompatible with third party extensions that\n"
    . "         override zend_execute_ex(). JIT disabled. in Unknown on line 0\n";

  /**
   * The phpunit gate: the kernel test's error is the environment's.
   */
  public function testPhpunitBrokenByStartupWarningIsTheEnvironment(): void {
    $result = $this->verdict('phpunit', [2, self::PHPUNIT, self::WARNING]);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertStringContainsString('JIT is incompatible', $result->summary);
    $this->assertStringContainsString('opcache.jit=disable', (string) $result->remedy);
  }

  /**
   * The coverage gate, over the same run.
   */
  public function testCoverageBrokenByStartupWarningIsTheEnvironment(): void {
    $result = $this->verdict('coverage', [2, self::PHPUNIT, self::WARNING]);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
  }

  /**
   * The mutation gate: infection gave up on a suite that passes.
   */
  public function testMutationStoppedByStartupWarningIsTheEnvironment(): void {
    $result = $this->verdict('mutation', [1, self::INFECTION, self::WARNING]);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertStringContainsString('opcache.jit=disable', (string) $result->remedy);
  }

  /**
   * A suite that fails on its own assertions, on a noisy PHP, still fails.
   */
  public function testRealFailureOnNoisyPhpIsStillTheTests(): void {
    $stdout = "\nWarning: JIT is incompatible with third party extensions that override zend_execute_ex(). JIT disabled. in Unknown on line 0\n"
      . "PHPUnit 11.5.56 by Sebastian Bergmann and contributors.\n\nThere was 1 failure:\n\n"
      . "1) Drupal\\Tests\\example\\Unit\\AgeTest::testBoundary\nFailed asserting that false is true.\n\n"
      . "FAILURES!\nTests: 4, Assertions: 6, Failures: 1.\n";
    $result = $this->verdict('phpunit', [1, $stdout, self::WARNING]);

    $this->assertSame(GateStatus::Failed, $result->status);
  }

  /**
   * A failure's summary names the tool's message, never PHP's startup noise.
   */
  public function testSummarySkipsStartupNoise(): void {
    $stderr = self::WARNING . "\nIn NoSourceFound.php line 98:\n\n  No source file found for the configured sources.\n";
    $result = $this->verdict('mutation', [1, '', $stderr]);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('No source file found for the configured sources.', $result->summary);
  }

  /**
   * Runs a gate over a root that has its tool and config, on a canned outcome.
   *
   * @param string $gate
   *   The gate.
   * @param array{int, string, string} $outcome
   *   Exit code, stdout, stderr.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function verdict(string $gate, array $outcome): GateResult {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0775, TRUE);
    file_put_contents($root . '/vendor/bin/phpunit', '');
    file_put_contents($root . '/vendor/bin/infection', '');
    file_put_contents($root . '/phpunit.xml', '<phpunit/>');
    file_put_contents($root . '/infection.json5', "{}\n");
    $executor = new ShellGateExecutor(static fn (): array => $outcome, static fn (): int => 0);
    $options = match ($gate) {
      'mutation' => ['msi_min' => 60],
      'coverage' => ['min' => 60],
      default => [],
    };
    return $executor->execute(new GateSettings($gate, TRUE, $options), $root);
  }

}
