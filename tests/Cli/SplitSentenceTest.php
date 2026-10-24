<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A sentence that arrives split into words is refused, never truncated.
 *
 * DDEV's custom commands joined the arguments into one bash -c string, so a
 * quoted criterion reached droost as words, and declare-criterion recorded
 * the first one ("When") and exited 0 (F-316, the first subject's run).
 */
final class SplitSentenceTest extends WorkflowTestCase {

  /**
   * Each sentence-taking verb, as a launcher that lost the quoting calls it.
   *
   * @return array<string, array{list<string>}>
   *   The argv each.
   */
  public static function splitCalls(): array {
    return [
      'declare-criterion' => [['declare-criterion', 'AC1', 'When', 'an', 'account', 'node', 'is', 'saved']],
      'verify-criterion' => [['verify-criterion', 'AC1', 'AccountTest::testSave', 'and', 'more']],
      'declare-note' => [['declare-note', 'grounding', 'Node', 'the', 'entity', 'it', 'reads']],
      'gate-waive' => [['gate-waive', 'phpcs', 'too', 'noisy']],
      'bypass' => [['bypass', 'just', 'this', 'once']],
    ];
  }

  /**
   * The split call is refused, says why, and records nothing.
   *
   * @param list<string> $argv
   *   The call.
   */
  #[DataProvider('splitCalls')]
  public function testSplitSentenceIsRefused(array $argv): void {
    $root = $this->makeRootWithConfig("preset: low\n");
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $code = (new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-19T00:00:00+00:00', static fn (): string => 'run-x'))
      ->dispatch($argv, $root);

    $this->assertSame(ArgvDispatcher::EXIT_USAGE, $code, implode("\n", $lines));
    $this->assertStringContainsString('arrived split into words, so nothing was recorded', implode("\n", $lines));
    $this->assertStringContainsString('ExecRaw', implode("\n", $lines));
  }

}
