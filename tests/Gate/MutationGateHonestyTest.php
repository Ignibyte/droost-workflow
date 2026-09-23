<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The mutation gate says what infection did, and whose fault it was.
 *
 * Measured on infection 0.35.4 while preparing the first run at xhigh, where
 * the gate arms. With no config, infection started a setup wizard: answered
 * by nobody it wrote infection.json5 into the project, and the gate recorded
 * the wizard's question as the code failing (F-85). With no coverage driver
 * it recorded the environment as the code failing, under the exception's
 * header rather than its message (F-86). A pass was "mutation passed" over
 * any number of mutants, zero included (F-88). The outputs below are
 * infection's own, cut to the lines that matter.
 */
final class MutationGateHonestyTest extends WorkflowTestCase {

  /**
   * A real pass on 0.35.4, which prints no "Mutation Score Indicator" line.
   */
  private const PASS = <<<'OUT'
Infection - PHP Mutation Testing Framework version 0.35.4

[notice] You are running Infection with PCOV enabled.

Running initial tests with PHPUnit version 11.5.56

Generate mutants...

Processing source code files...
M..

3 mutations were generated:
       2 mutants were killed by Test Framework
       1 covered mutants were not detected

Metrics:
         Mutation Code Coverage: 100%
         Covered Code MSI: 66%

Please note that some mutants will inevitably be harmless (i.e. false positives).
OUT;

  /**
   * What infection prints with no coverage driver, wrapped as it wraps it.
   */
  private const NO_DRIVER = <<<'OUT'

In CoverageChecker.php line 89:

  Coverage needs to be generated but no code coverage generator (pcov, phpdbg
   or xdebug) has been detected. Please either:
  - Enable pcov and run Infection again
  - Use phpdbg, e.g. `phpdbg -qrr infection`

OUT;

  /**
   * With no config, infection asks where the code is.
   */
  public function testNoConfigIsMissingConfigurationAndNothingRuns(): void {
    $root = $this->rootWithInfection(FALSE);
    $ran = FALSE;
    $executor = new ShellGateExecutor(
      function () use (&$ran): array {
        $ran = TRUE;
        return [1, "Which source directories do you want to include (comma separated)? [src]:\n", ''];
      },
      static fn (): int => 0,
    );

    $result = $executor->execute($this->gate(), $root);

    $this->assertFalse($ran, 'infection is not started to ask its questions');
    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertStringContainsString('infection.json5', (string) $result->remedy);
    $this->assertStringNotContainsString('Which source directories', $result->summary);
  }

  /**
   * No coverage driver is the environment's, not the code's.
   */
  public function testNoDriverIsMissingToolNotFailure(): void {
    $result = $this->verdict([1, '', self::NO_DRIVER]);

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertStringContainsString('no code coverage driver', $result->summary);
    $this->assertStringContainsString('pcov', (string) $result->remedy);
  }

  /**
   * A failure names infection's message, not where it was thrown.
   */
  public function testFailureNamesTheMessage(): void {
    $stderr = "\nIn NoSourceFound.php line 98:\n\n  No source file found for the configured sources.\n\n";
    $result = $this->verdict([1, '', $stderr]);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('No source file found for the configured sources.', $result->summary);
  }

  /**
   * A pass says how many mutants, at what score.
   */
  public function testPassSaysWhatItMeasured(): void {
    $result = $this->verdict([0, self::PASS, '']);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertFalse($result->labelledPass);
    $this->assertSame('mutation passed — MSI 66% over 3 mutants (min 60%)', $result->summary);
  }

  /**
   * Zero mutants measured nothing, however the config makes that exit 0.
   */
  public function testZeroMutantsIsLabelledPass(): void {
    $empty = str_replace(
      ['3 mutations were generated:', 'Covered Code MSI: 66%'],
      ['0 mutations were generated:', 'Covered Code MSI: 0%'],
      self::PASS,
    );
    $result = $this->verdict([0, $empty, '']);

    $this->assertTrue($result->labelledPass, 'a score over no mutants is unverified');
    $this->assertStringContainsString('NO MUTANTS', $result->summary);
  }

  /**
   * Runs the gate over a root with a config, on a canned outcome.
   *
   * @param array{int, string, string} $outcome
   *   Exit code, stdout, stderr.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function verdict(array $outcome): GateResult {
    $executor = new ShellGateExecutor(static fn (): array => $outcome, static fn (): int => 0);
    return $executor->execute($this->gate(), $this->rootWithInfection(TRUE));
  }

  /**
   * The mutation gate as xhigh arms it.
   *
   * @return \Droost\Workflow\Config\GateSettings
   *   The levers.
   */
  private function gate(): GateSettings {
    return new GateSettings('mutation', TRUE, ['msi_min' => 60, 'timeout' => 1800]);
  }

  /**
   * A root with infection installed, and optionally its config.
   *
   * @param bool $configured
   *   Whether an infection.json5 is there.
   *
   * @return string
   *   The root.
   */
  private function rootWithInfection(bool $configured): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0775, TRUE);
    file_put_contents($root . '/vendor/bin/infection', '');
    if ($configured) {
      file_put_contents($root . '/infection.json5', "{\"source\": {\"directories\": [\"src\"]}}\n");
    }
    return $root;
  }

}
