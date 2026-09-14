<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A gate's findings are rows a reader can act on, baseline or no baseline.
 *
 * `FindingParsers` already read phpcs, eslint and stylelint into
 * file/line/rule/message rows — and only the baseline partition asked it.
 * The plain path fell through to a generic parser that emitted one "finding"
 * per TOP-LEVEL JSON KEY, so a report was recorded as its wrappers: `totals`,
 * `files`, `errors`. A reviewer's evidence document showed "3 findings" for
 * one real phpstan error, and "— not recorded —" in every File, Line, Rule
 * and Message cell of §8a, with the one defect surviving only inside a
 * truncated Detail blob. The same finding had two shapes depending on
 * whether the operator had adopted a baseline.
 *
 * These drive the whole executor with a fake tool, because the defect was in
 * which path called which parser, not in either parser.
 */
final class FindingsAreRowsTest extends WorkflowTestCase {

  /**
   * A phpcs report is one row per message, naming file, line and rule.
   */
  public function testPhpcsFindingsNameTheFileTheLineAndTheRule(): void {
    $root = $this->projectWith('phpcs');
    $json = json_encode([
      'totals' => ['errors' => 1, 'warnings' => 1, 'fixable' => 0],
      'files' => [
        $root . '/src/Money.php' => [
          'errors' => 1,
          'warnings' => 1,
          'messages' => [
            [
              'message' => 'Missing doc',
              'source' => 'Drupal.Commenting.X',
              'type' => 'ERROR',
              'line' => 12,
              'column' => 1,
            ],
            [
              'message' => 'Long line',
              'source' => 'Drupal.Files.LineLength',
              'type' => 'WARNING',
              'line' => 30,
              'column' => 1,
            ],
          ],
        ],
      ],
    ], JSON_THROW_ON_ERROR);

    $result = $this->executor([1, $json, ''])
      ->execute(new GateSettings('phpcs', TRUE, ['standard' => 'PSR12']), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertCount(2, $result->findings, 'one row per message, not one per JSON wrapper');
    $this->assertSame(
      [
        'file' => 'src/Money.php',
        'line' => 12,
        'rule' => 'Drupal.Commenting.X',
        'message' => 'Missing doc',
        'detail' => 'error',
      ],
      $result->findings[0],
    );
    $this->assertSame('warning', $result->findings[1]['detail'], 'and a warning says it is one');
    foreach ($result->findings as $finding) {
      $this->assertArrayNotHasKey('key', $finding, 'the baseline key is the partition\'s business, not the record\'s');
      $this->assertArrayNotHasKey('totals', $finding, 'and a wrapper is not a finding');
    }
  }

  /**
   * A phpstan report is one row per message, not three wrappers.
   */
  public function testPhpstanFindingsNameTheFileTheLineAndTheMessage(): void {
    $root = $this->projectWith('phpstan');
    file_put_contents($root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n");
    $json = json_encode([
      'totals' => ['errors' => 0, 'file_errors' => 1],
      'files' => [
        $root . '/src/Money.php' => [
          'errors' => 1,
          'messages' => [
            [
              'message' => 'Method addNums() should return string but returns int.',
              'line' => 9,
              'ignorable' => TRUE,
              'identifier' => 'return.type',
            ],
          ],
        ],
      ],
      'errors' => [],
    ], JSON_THROW_ON_ERROR);

    $result = $this->executor([1, $json, ''])
      ->execute(new GateSettings('phpstan', TRUE, ['level' => 6]), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertCount(1, $result->findings, 'one real error is one finding, not three wrappers');
    $this->assertSame('src/Money.php', $result->findings[0]['file']);
    $this->assertSame(9, $result->findings[0]['line']);
    $this->assertSame('return.type', $result->findings[0]['rule']);
    $this->assertSame(
      'Method addNums() should return string but returns int.',
      $result->findings[0]['message'],
    );
  }

  /**
   * A project with the named tool present and one source file.
   *
   * @param string $tool
   *   The binary to stub.
   *
   * @return string
   *   The root.
   */
  private function projectWith(string $tool): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0755, TRUE);
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n");
    file_put_contents($root . '/vendor/bin/' . $tool, "#!/bin/sh\nexit 0\n");
    chmod($root . '/vendor/bin/' . $tool, 0755);

    return $root;
  }

  /**
   * An executor whose tool always produces the given outcome.
   *
   * @param array{int, string, string} $outcome
   *   Exit code, stdout, stderr.
   *
   * @return \Droost\Workflow\Gate\ShellGateExecutor
   *   The executor.
   */
  private function executor(array $outcome): ShellGateExecutor {
    return new ShellGateExecutor(static fn (): array => $outcome, static fn (): int => 0);
  }

}
