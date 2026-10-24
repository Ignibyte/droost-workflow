<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\HouseRulesCheck;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A house rule can hold a run to droost's site tools (check: site_tools).
 *
 * The rebuilds read and changed their sites through 366 raw drush php and SQL
 * calls, none held by the run (F-366). droost's tools are recorded and held;
 * raw PHP and SQL are neither. The guard marks each raw call on its own row,
 * and this check reads the open run's rows.
 */
final class HouseRuleSiteToolsTest extends WorkflowTestCase {

  /**
   * The rule.
   *
   * @var array<string, string>
   */
  private const RULE = [
    'id' => 'HR-SITE',
    'rule' => 'Site state is read and changed through droost\'s tools.',
    'judge' => 'gate',
    'check' => 'site_tools',
    'mode' => 'block',
  ];

  /**
   * Each raw call this run made is a finding; other runs' are not.
   */
  public function testRawCallsOfThisRunAreBreaks(): void {
    $root = $this->rootWithCalls([
      ['run' => 'run-a', 'mode' => 'operator-commands', 'raw_site' => 'php:eval'],
      ['run' => 'run-a', 'mode' => 'operator-commands'],
      ['run' => 'run-a', 'mode' => 'operator-commands', 'raw_site' => 'sql:query'],
      ['run' => 'run-old', 'mode' => 'operator-commands', 'raw_site' => 'php:eval'],
    ]);

    $result = HouseRulesCheck::run([self::RULE], [], [], $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $messages = '';
    foreach ($result->findings as $finding) {
      $this->assertIsString($finding['message']);
      $messages .= $finding['message'] . "\n";
    }
    $this->assertStringContainsString('drush php:eval', $messages);
    $this->assertStringContainsString('drush sql:query', $messages);
    $this->assertCount(2, $result->findings, 'the old run\'s call is not this run\'s');
  }

  /**
   * A run that used only droost's tools holds the rule.
   */
  public function testToolsOnlyHolds(): void {
    $root = $this->rootWithCalls([['run' => 'run-a', 'mode' => 'operator-commands']]);
    $result = HouseRulesCheck::run([self::RULE], [], [], $root);
    $this->assertSame(GateStatus::Passed, $result->status, $result->summary);
  }

  /**
   * The guard marks a raw drush php or SQL call on its row.
   */
  public function testTheGuardMarksRawCalls(): void {
    $root = $this->rootWithCalls([]);
    foreach (['ddev drush php:eval "echo 1;"', 'drush status'] as $command) {
      $this->guard($root, 'operator-commands', ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);
    }
    $rows = array_map(
      static fn (string $line): mixed => json_decode($line, TRUE),
      file($root . '/droost/droost-workflow/guard-calls.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    );
    $marked = array_values(array_filter($rows, static fn (mixed $row): bool => is_array($row) && isset($row['raw_site'])));
    $this->assertCount(1, $marked);
    $this->assertIsArray($marked[0]);
    $this->assertSame('php:eval', $marked[0]['raw_site']);
  }

  /**
   * A root with run-a open at code, and these guard rows.
   *
   * @param list<array<string, string>> $calls
   *   The rows.
   *
   * @return string
   *   The root.
   */
  private function rootWithCalls(array $calls): string {
    $root = $this->makeRoot();
    $dir = $root . '/droost/droost-workflow';
    mkdir($dir, 0755, TRUE);
    file_put_contents($dir . '/run.json', (string) json_encode([
      'run_id' => 'run-a',
      'current_phase' => 'code',
      'phases' => ['code' => 'active'],
      'enforcement' => 'hard',
    ]));
    $lines = '';
    foreach ($calls as $call) {
      $lines .= json_encode($call + ['tool' => 'Bash', 'verdict' => 'allow', 'at' => '2026-10-24T00:00:00+00:00']) . "\n";
    }
    file_put_contents($dir . '/guard-calls.jsonl', $lines);
    return $root;
  }

}
