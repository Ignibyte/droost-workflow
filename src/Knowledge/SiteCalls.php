<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\State\RunStateStore;

/**
 * What one run did to the site from the shell: tool calls and raw calls.
 *
 * Two records answer it, each written by a different hand. The droost
 * module's tools write a row per call to `tool-calls.jsonl` (the ledger
 * contract, `schema/tool-call.v1.json`), and the guard marks each command
 * it saw run raw `drush php:eval`, `php:script` or SQL with a `raw_site`
 * verb on its row in `guard-calls.jsonl` (F-366). The `site_tools` house
 * rule judges the second; the run report counts both.
 */
final class SiteCalls {

  /**
   * The module's tool calls a run made, counted by tool.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $runId
   *   The run.
   *
   * @return array<string, array{calls: int, failed: int}>
   *   Tool id => how many calls, and how many did not succeed, by id.
   */
  public static function tools(string $projectRoot, string $runId): array {
    $tally = [];
    foreach (ToolCallLog::read($projectRoot, $runId) as $call) {
      // The module's tools are `droost_*`; the command's own rows name its
      // verbs (`code:wiki`), which plan_grounded reads.
      if (!str_starts_with($call['tool'], 'droost_')) {
        continue;
      }
      $tally[$call['tool']] ??= ['calls' => 0, 'failed' => 0];
      $tally[$call['tool']]['calls']++;
      if ($call['outcome'] !== 'ok') {
        $tally[$call['tool']]['failed']++;
      }
    }
    ksort($tally);
    return $tally;
  }

  /**
   * The raw drush php and SQL calls a run made, as the guard marked them.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $runId
   *   The run.
   *
   * @return array{0: int, 1: array<int, string>}
   *   How many of the run's guard rows were read, and each marked call's
   *   drush verb keyed by its position among them.
   */
  public static function raw(string $projectRoot, string $runId): array {
    $root = rtrim($projectRoot, '/');
    $path = $root . '/' . RunStateStore::resolveStateDir($root) . '/guard-calls.jsonl';
    $count = 0;
    $calls = [];
    foreach (@file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
      $row = json_decode($line, TRUE);
      if (!is_array($row) || ($row['run'] ?? NULL) !== $runId) {
        continue;
      }
      $count++;
      if (is_string($row['raw_site'] ?? NULL) && $row['raw_site'] !== '') {
        $calls[$count] = $row['raw_site'];
      }
    }
    return [$count, $calls];
  }

}
