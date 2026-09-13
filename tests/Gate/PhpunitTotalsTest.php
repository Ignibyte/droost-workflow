<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Gate\ShellGateExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The phpunit gate records how much it verified, not merely that it passed.
 *
 * The gate stored `exit: 0` and an empty findings array, so twenty tests and
 * one assertion produced byte-identical records while phpcs beside it stored
 * per-file totals. In a dogfood round the gate that caught a live XSS bypass
 * was the one whose record said the least about what it had checked, and an
 * evaluator scoring the round had to re-run the suite by hand to state its
 * size — the exact out-of-band measurement the run record exists to replace.
 *
 * The two tails below are REAL: both were captured from the same round, the
 * red one before the bypass was fixed and the green one after.
 */
#[CoversClass(ShellGateExecutor::class)]
final class PhpunitTotalsTest extends TestCase {

  /**
   * Every tail phpunit prints, and what should be read out of it.
   *
   * @return array<string, array{string, array{tests: int, assertions: int}|null}>
   *   Label => [stdout, expected counts].
   */
  public static function tails(): array {
    return [
      'green tail, from the HH-1 replay' => [
        "PHPUnit 12.0\n\nOK (20 tests, 32 assertions)\n",
        ['tests' => 20, 'assertions' => 32],
      ],
      'red tail, from HH-1 before the XSS fix' => [
        "FAILURES!\nTests: 14, Assertions: 18, Failures: 2.\n",
        ['tests' => 14, 'assertions' => 18],
      ],
      'singular nouns' => [
        "OK (1 test, 1 assertion)\n",
        ['tests' => 1, 'assertions' => 1],
      ],
      'warnings keep the counted tail' => [
        "OK, but there were issues!\nTests: 7, Assertions: 9, Warnings: 1.\n",
        ['tests' => 7, 'assertions' => 9],
      ],
      'risky, zero assertions is a real reading' => [
        "Tests: 3, Assertions: 0, Risky: 3.\n",
        ['tests' => 3, 'assertions' => 0],
      ],
      'empty suite belongs to the NO TESTS RAN branch' => [
        "No tests executed!\n",
        NULL,
      ],
      'a crash reads as nothing, never as zero' => [
        "PHP Fatal error: something went wrong\n",
        NULL,
      ],
    ];
  }

  /**
   * The tail is parsed into counts, and an unreadable tail stays NULL.
   */
  #[DataProvider('tails')]
  public function testTotalsAreReadFromTheTail(string $stdout, ?array $expected): void {
    $method = new \ReflectionMethod(ShellGateExecutor::class, 'phpunitTotals');

    $this->assertSame(
      $expected,
      $method->invoke($this->executor(), $stdout),
      'the phpunit tail must yield its counts, or NULL when there are none to read',
    );
  }

  /**
   * An executor with no constructor work done — only the parser is exercised.
   *
   * @return \Droost\Workflow\Gate\ShellGateExecutor
   *   The instance.
   */
  private function executor(): ShellGateExecutor {
    return (new \ReflectionClass(ShellGateExecutor::class))->newInstanceWithoutConstructor();
  }

}
