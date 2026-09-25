<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The browser gate says what its suite ran, and a suite that ran nothing fails.
 *
 * "playwright passed" was the whole summary, so a suite of one test and a
 * suite of 128 wrote the same record. And `playwright test` exits 0 when
 * every test is skipped, so at `required: true` a suite of `test.skip()`
 * passed a gate that only caught "No tests found" (P6 run 9, F-111).
 *
 * Every stdout below is REAL, captured 2026-09-25 from `@playwright/test`
 * 1.63 with the list reporter: the all-skipped suite and the mixed one in a
 * scratch project, and the green tail from P6 run 9's own suite.
 */
#[CoversClass(ShellGateExecutor::class)]
final class PlaywrightTotalsTest extends TestCase {

  private const ALL_SKIPPED = <<<'TXT'

Running 2 tests using 1 worker

  -  1 tests/e2e/all-skipped.spec.ts:2:6 › AC-1: skipped
  -  2 tests/e2e/all-skipped.spec.ts:3:6 › AC-2: skipped

  2 skipped
TXT;

  private const GREEN = <<<'TXT'

Running 128 tests using 1 worker

  ✓    1 [chromium] › tests/e2e/camp-calendar.spec.ts:224:7 › the feed › AC-1: text/calendar, VERSION:2.0, a PRODID and one VEVENT per published camp (253ms)
  ✓  128 [chromium] › tests/e2e/contact.spec.ts:429:7 › the edges › AC-14: the anonymous response exposes no recipient's email address (473ms)

  128 passed (3.3m)
TXT;

  private const MIXED = <<<'TXT'

Running 4 tests using 1 worker

  ✓  1 [chromium] › tests/e2e/all-skipped.spec.ts:3:5 › AC-1: passes (2ms)
  ✘  2 [chromium] › tests/e2e/all-skipped.spec.ts:4:5 › AC-2: fails (1ms)
  ✘  3 [chromium] › tests/e2e/all-skipped.spec.ts:4:5 › AC-2: fails (retry #1) (2ms)
  ✘  4 [chromium] › tests/e2e/all-skipped.spec.ts:5:5 › AC-3: flaky (3ms)
  ✓  5 [chromium] › tests/e2e/all-skipped.spec.ts:5:5 › AC-3: flaky (retry #1) (1ms)
  -  6 [chromium] › tests/e2e/all-skipped.spec.ts:6:6 › AC-4: skipped

  1) [chromium] › tests/e2e/all-skipped.spec.ts:4:5 › AC-2: fails ──────────────────────────────────

    Error: expect(received).toBe(expected) // Object.is equality

  1 failed
    [chromium] › tests/e2e/all-skipped.spec.ts:4:5 › AC-2: fails ───────────────────────────────────
  1 flaky
    [chromium] › tests/e2e/all-skipped.spec.ts:5:5 › AC-3: flaky ───────────────────────────────────
  1 skipped
  1 passed (773ms)
TXT;

  /**
   * A required suite whose every test was skipped fails, and says why.
   */
  public function testEverySkippedFailsRequiredSuite(): void {
    $result = $this->verdict(0, self::ALL_SKIPPED, TRUE);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('no test ran to a pass (2 skipped)', $result->summary);
    $this->assertStringContainsString('required: true', $result->summary);
  }

  /**
   * Unrequired, the same suite is a labelled pass, never a plain one.
   */
  public function testEverySkippedIsLabelledPassWhenNotRequired(): void {
    $result = $this->verdict(0, self::ALL_SKIPPED, FALSE);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertStringContainsString('NO TEST RAN TO A PASS (2 skipped)', $result->summary);
  }

  /**
   * A pass counts what passed, and the totals are kept as a finding.
   */
  public function testPassSaysHowManyPassed(): void {
    $result = $this->verdict(0, self::GREEN, TRUE);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame('playwright passed — 128 passed', $result->summary);
    $detail = $result->findings[0]['detail'] ?? NULL;
    $this->assertIsArray($detail);
    $this->assertSame(128, $detail['passed'] ?? NULL);
  }

  /**
   * A failure counts every kind, and names where the failure is, not the flake.
   */
  public function testFailureNamesFailedTestAndNotFlakyOne(): void {
    $result = $this->verdict(1, self::MIXED, TRUE);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame(
      'playwright failed (exit 1): 1 passed, 1 failed, 1 flaky (passed only on a retry), 1 skipped, at tests/e2e/all-skipped.spec.ts:4',
      $result->summary,
    );
  }

  /**
   * An empty project still fails a required suite, before any count is read.
   *
   * The branch had no test of its own: `playwright test` exits 0 on an empty
   * project, so the string match is the whole mechanism.
   */
  public function testNoTestsFoundStillFailsRequiredSuite(): void {
    $result = $this->verdict(0, "Error: No tests found\n", TRUE);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('no tests found', $result->summary);
  }

  /**
   * A reporter that prints no epilogue keeps the plain summary.
   */
  public function testNoEpilogueKeepsThePlainSummary(): void {
    $result = $this->verdict(0, "{\"suites\": []}\n", TRUE);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertStringStartsWith('playwright passed', $result->summary);
    $this->assertStringNotContainsString('—', $result->summary);
  }

  /**
   * Runs the gate over a fake binary that prints the given output.
   *
   * @param int $exit
   *   The exit code the runner returns.
   * @param string $stdout
   *   What it prints.
   * @param bool $required
   *   The gate's `required` lever.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function verdict(int $exit, string $stdout, bool $required): GateResult {
    $root = sys_get_temp_dir() . '/pw-totals-' . bin2hex(random_bytes(4));
    mkdir($root . '/node_modules/.bin', 0755, TRUE);
    file_put_contents($root . '/node_modules/.bin/playwright', "#!/bin/sh\nexit 0\n");
    chmod($root . '/node_modules/.bin/playwright', 0755);
    $executor = new ShellGateExecutor(
      static fn (): array => [$exit, $stdout, ''],
      static fn (): int => 0,
    );

    return $executor->execute(new GateSettings('playwright', TRUE, ['required' => $required]), $root);
  }

}
