<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Cli\CliProcess;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Vcs\CliVcs;
use Droost\Workflow\Vcs\VcsInterface;

/**
 * The run keeps to what the human chose at plan about tests (`tests.ask`).
 *
 * The owner's rule (2026-10-30): no unit tests and no saved browser spec
 * unless the human says yes at plan, and the work checked in the browser at
 * code every time. The answer turns the phpunit and playwright gates on or
 * off (TestsChoice::applyTo()); a gate that is off cannot see a test the
 * agent wrote anyway, which a run with phpunit off did (2026-10-29). So this
 * reads the diff:
 *
 *   - `unit_tests_chosen`: with unit tests declined, a PHPUnit test the run
 *     added or changed blocks. Deleting one is not writing one;
 *   - `spec_chosen`: with no saved spec, a Playwright spec the run added or
 *     changed blocks, deletions again aside;
 *   - `browser_checked`, at code: the guard's diary shows a browser tool call
 *     in this phase. A diary with no MCP call at all cannot tell "never
 *     looked" from "cannot see" (F-37), so that is the environment's to
 *     settle, which the operator may lift, never the agent's fault.
 *
 * With `tests.ask` off, or plan not yet answered, it records nothing.
 */
final class TestsChoiceCheck implements CheckAdjudicatorInterface {

  /**
   * The checks' names in the record.
   */
  public const UNIT = 'unit_tests_chosen';
  public const SPEC = 'spec_chosen';
  public const BROWSER = 'browser_checked';

  /**
   * Who adjudicated them, in the record.
   */
  private const PROVIDER = 'droost/workflow';

  /**
   * A PHPUnit test file: a `*Test.php` under a `tests/` directory.
   */
  private const UNIT_TEST_FILE = '#(^|/)tests/.*Test\.php$#';

  /**
   * Constructs a TestsChoiceCheck.
   *
   * @param \Droost\Workflow\Vcs\VcsInterface|null $vcs
   *   How the diff is read; NULL asks git.
   */
  public function __construct(private readonly ?VcsInterface $vcs = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function adjudicate(string $projectRoot, string $phase, string $runId): array {
    if (!in_array($phase, ['code', 'test', 'complete'], TRUE)) {
      return [];
    }
    $state = (new RunStateStore($projectRoot))->load();
    if ($state === NULL || $state->runId !== $runId || !isset($state->tests['unit'], $state->tests['spec'])) {
      return [];
    }
    $tests = $state->tests;
    $root = rtrim($projectRoot, '/');
    $vcs = $this->vcs ?? new CliVcs(CliProcess::run(...));
    $changed = $state->ownChanges($vcs->changedFiles($root, $state->baseCommit), $root);
    // Present on disk: added or changed. A path gone from the tree was
    // deleted, and removing a test is not writing one.
    $written = array_values(array_filter(
      $changed ?? [],
      static fn (string $path): bool => is_file($root . '/' . $path),
    ));
    $by = is_string($tests['by'] ?? NULL) ? $tests['by'] : 'default';
    $records = [];

    if ($tests['unit'] === FALSE) {
      $units = array_values(array_filter($written, static fn (string $path): bool => preg_match(self::UNIT_TEST_FILE, $path) === 1));
      $records[] = $units === []
        ? $this->record(self::UNIT, CheckState::Satisfied, Fault::None, sprintf('No unit test written, as chosen at plan (%s).', $by))
        : $this->record(
          self::UNIT,
          CheckState::Blocked,
          Fault::Agent,
          sprintf('Unit tests were declined at plan (%s), and this run wrote %d: %s. Remove them (deleting a test is fine): a ticket gets unit tests only when the human says yes at plan.', $by, count($units), self::some($units)),
        );
    }
    if ($tests['spec'] === FALSE) {
      $specs = array_values(array_filter(
        $written,
        static fn (string $path): bool => preg_match(ShellGateExecutor::SPEC_FILE, $path) === 1,
      ));
      $records[] = $specs === []
        ? $this->record(self::SPEC, CheckState::Satisfied, Fault::None, sprintf('No Playwright spec saved, as chosen at plan (%s).', $by))
        : $this->record(
          self::SPEC,
          CheckState::Blocked,
          Fault::Agent,
          sprintf('A saved Playwright spec was declined at plan (%s), and this run wrote %d: %s. Remove them (deleting a spec is fine): check the work in the browser with the Playwright MCP, and save it as a spec only when the human says yes at plan.', $by, count($specs), self::some($specs)),
        );
    }
    if ($phase === 'code') {
      $store = new EvidenceStore($root);
      $looked = $store->browserToolCalls($runId, 'code');
      $records[] = match (TRUE) {
        $looked > 0 => $this->record(
          self::BROWSER,
          CheckState::Satisfied,
          Fault::None,
          sprintf('The work was checked in the browser at code: %d browser tool call(s).', $looked),
        ),
        $store->mcpToolCalls($runId) === 0 => $this->record(
          self::BROWSER,
          CheckState::Blocked,
          Fault::Environment,
          'The guard recorded no MCP call in this run, so it cannot tell a run that never looked from one it cannot see.',
          'Check that `.claude/settings.json` sends MCP calls to droost\'s guard (`droost init` writes it); the OPERATOR may lift this for a run that looked.',
        ),
        default => $this->record(
          self::BROWSER,
          CheckState::Blocked,
          Fault::Agent,
          'The work was not checked in the browser at code: no browser tool call in this phase. Open the pages this ticket changes with the Playwright MCP, check each acceptance criterion there, then run the phase again.',
        ),
      };
    }

    return $records;
  }

  /**
   * One of this check's records.
   *
   * @param string $name
   *   The check.
   * @param \Droost\Workflow\Evidence\CheckState $state
   *   The verdict.
   * @param \Droost\Workflow\Evidence\Fault $fault
   *   Whose to clear.
   * @param string $summary
   *   What was found.
   * @param string|null $remedy
   *   What clears it.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord
   *   The record.
   */
  private function record(string $name, CheckState $state, Fault $fault, string $summary, ?string $remedy = NULL): CheckRecord {
    return new CheckRecord('check', $name, $state, $fault, $summary, $remedy, provider: self::PROVIDER);
  }

  /**
   * The first few paths, and how many more.
   *
   * @param list<string> $paths
   *   The paths.
   *
   * @return string
   *   For a summary.
   */
  private static function some(array $paths): string {
    $shown = array_slice($paths, 0, 5);
    return implode(', ', $shown) . (count($paths) > 5 ? sprintf(' and %d more', count($paths) - 5) : '');
  }

}
