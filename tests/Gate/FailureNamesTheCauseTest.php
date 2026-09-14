<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A failed gate's summary and remedy name the cause, not a banner or a guess.
 *
 * The summary propagates into the evidence document as the account of the
 * failure, and the remedy is what a reader is told to do. Two reviewers
 * driving real runs were handed neither.
 */
final class FailureNamesTheCauseTest extends WorkflowTestCase {

  /**
   * PHPStan's failure summary is its error count, not a fragment of its advice.
   *
   * PHPStan 2.x prints an "Instructions for interpreting errors" block even
   * with `--error-format=json`, and it wraps — so the banner-skip regex let a
   * continuation line through and the summary became "or `return.missing`.",
   * advice, not a cause. The JSON report carries the real number.
   */
  public function testPhpstanFailureSummaryIsItsErrorCount(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    $json = json_encode([
      'totals' => ['errors' => 0, 'file_errors' => 2],
      'files' => [
        $root . '/src/Money.php' => [
          'errors' => 2,
          'messages' => [
            ['message' => 'should return string but returns int', 'line' => 9, 'identifier' => 'return.type'],
            ['message' => 'missing iterable value type', 'line' => 14, 'identifier' => 'missingType.iterableValue'],
          ],
        ],
      ],
      'errors' => [],
    ], JSON_THROW_ON_ERROR);
    // The wrapped advice block PHPStan prints to stderr — the fragment that
    // used to become the summary.
    $banner = "Instructions for interpreting errors\n"
      . "The error usually looks like this: something is\n"
      . "or `return.missing`.\n";

    $result = (new ShellGateExecutor(
      static fn (): array => [1, $json, $banner],
      static fn (): int => 0,
    ))->execute(new GateSettings('phpstan', TRUE, ['level' => 6]), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame('phpstan failed (exit 1): 2 errors', $result->summary);
    $this->assertStringNotContainsString('return.missing', $result->summary, 'not the advice fragment');
    $this->assertStringNotContainsString('Instructions', $result->summary);
  }

  /**
   * A missing phpcs standard is told to install it, not to "check the ruleset".
   *
   * `init` writes Drupal's standard for a Drupal project — a module checkout
   * has no drupal/coder in its own vendor/ — so the first run exits 16, "the
   * Drupal coding standard is not installed", an environment fault. The
   * generic hint named the lever, which is not the fix; the fix is to install
   * coder.
   */
  public function testMissingPhpcsStandardNamesCoder(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/A.php', "<?php\n");
    $stderr = 'ERROR: the "Drupal" coding standard is not installed. '
      . 'The installed coding standards are PEAR, PSR1, PSR2, PSR12, Squiz and Zend';

    $result = (new ShellGateExecutor(
      static fn (): array => [16, '', $stderr],
      static fn (): int => 0,
    ))->execute(new GateSettings('phpcs', TRUE, ['standard' => 'Drupal,DrupalPractice']), $root);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $remedy = (string) $result->remedy;
    $this->assertStringContainsString('drupal/coder', $remedy, 'it names what to install');
    $this->assertStringContainsString('composer require --dev', $remedy, 'and how');
    $this->assertStringNotContainsString(
      'check the ruleset gates.phpcs.standard names',
      $remedy,
      'not the generic advice that is not the fix',
    );
  }

  /**
   * A genuinely wrong standard still gets the general advice.
   */
  public function testOtherPhpcsErrorsGetTheGeneralHint(): void {
    $root = $this->projectWithTools();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/A.php', "<?php\n");

    $result = (new ShellGateExecutor(
      static fn (): array => [16, '', 'ERROR: Referenced sniff "Made.Up.Sniff" does not exist'],
      static fn (): int => 0,
    ))->execute(new GateSettings('phpcs', TRUE, ['standard' => 'PSR12']), $root);

    $this->assertStringNotContainsString('drupal/coder', (string) $result->remedy, 'this is not a missing-standard failure');
    $this->assertStringContainsString('gates.phpcs.standard', (string) $result->remedy);
  }

  /**
   * A project root with the mandatory binaries present.
   *
   * @return string
   *   The root.
   */
  private function projectWithTools(): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0755, TRUE);
    foreach (['phpcs', 'phpstan', 'phpunit'] as $tool) {
      file_put_contents($root . '/vendor/bin/' . $tool, "#!/bin/sh\nexit 0\n");
      chmod($root . '/vendor/bin/' . $tool, 0755);
    }

    return $root;
  }

}
