<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\RunReport;
use PHPUnit\Framework\TestCase;

/**
 * The workchain report: the record as it is, never a falsehood by omission.
 */
final class RunReportTest extends TestCase {

  /**
   * No run is said, not rendered empty.
   */
  public function testNoRunIsSaid(): void {
    $lines = RunReport::lines(['run' => ['run_id' => NULL]], ['active' => FALSE]);

    $this->assertCount(1, $lines);
    $this->assertStringContainsString('No run recorded', $lines[0]);
  }

  /**
   * Each phase and gate, a non-pass with its reason, a spent budget as such.
   */
  public function testPhasesGatesAndWhy(): void {
    $lines = implode("\n", RunReport::lines([
      'run' => [
        'run_id' => 'run-x',
        'started_at' => '2026-10-19T00:00:00+00:00',
        'preset' => 'low',
        'effective_mode' => 'agentic',
        'phases' => ['plan' => 'passed', 'code' => 'failed', 'test' => 'pending'],
        'gate_reports' => [
          'code' => [
            'gates' => [
        ['gate' => 'phpcs', 'status' => 'passed', 'summary' => 'phpcs passed — 3 files'],
        ['gate' => 'stylelint', 'status' => 'passed', 'labelled' => TRUE, 'summary' => 'no stylesheet in scope'],
        [
          'gate' => 'custom:config_clean',
          'status' => 'passed',
          'summary' => 'custom:config_clean passed — [notice] No differences between DB and sync directory.',
        ],
        [
          'gate' => 'custom:loose',
          'status' => 'passed',
          'summary' => 'custom:loose passed — its file exited 0 [its file droost/gates/loose.php changed since this run began]',
        ],
        ['gate' => 'phpstan', 'status' => 'off', 'skip_reason' => 'off — by preset low'],
        [
          'gate' => 'house_rules',
          'status' => 'off',
          'skip_reason' => NULL,
          'summary' => 'no house rule is judged by a gate',
        ],
        ['gate' => 'custom:mine', 'status' => 'error-tool-failed', 'summary' => 'its file exited 3'],
        ['gate' => 'phpunit', 'status' => 'failed', 'summary' => '2 tests failed'],
            ],
          ],
        ],
        'retries' => ['remaining' => ['phpunit' => 0], 'attempts' => ['phpunit' => 2]],
        'criteria' => ['verified' => ['AC1'], 'manual' => [], 'unverified' => ['AC2']],
        'seeker' => [
          'status' => 'clean',
          'open' => 0,
          'critical' => 0,
          'medium' => 1,
          'low' => 0,
          'self_reviewed' => TRUE,
        ],
        'seeker_history' => [['status' => 'found', 'medium' => 1], ['status' => 'clean']],
        'awaiting' => ['question' => 'Ship it?'],
        'answered' => 4,
        'released' => 1,
        'answered_by_agent' => 3,
      ],
    ], ['active' => TRUE, 'reason' => 'a hotfix']));

    $this->assertStringContainsString('✓ plan: passed', $lines);
    $this->assertStringContainsString('✗ code: failed', $lines);
    $this->assertMatchesRegularExpression('/phpcs\s+passed\n/', $lines, 'a plain pass prints bare');
    $this->assertMatchesRegularExpression('/custom:config_clean passed\n/', $lines, 'a tool\'s own [notice] tag is not droost\'s note');
    $this->assertStringContainsString('custom:loose   passed — [its file droost/gates/loose.php changed since this run began', $lines, 'a pass with a bracketed note keeps it');
    $this->assertMatchesRegularExpression('/stylelint\s+passed — labelled: it ran and examined nothing — no stylesheet in scope/', $lines, 'a labelled pass is named in its row (F-326)');
    $this->assertStringContainsString('medium 1, low 0 found, 0 still open)', $lines, 'clean beside a resolved MEDIUM says none is open (F-327)');
    $this->assertMatchesRegularExpression('/house_rules\s+off — no house rule is judged by a gate/', $lines, 'an off with no skip reason prints its summary, never a bare off (F-330)');
    $this->assertMatchesRegularExpression('/phpstan\s+off — off — by preset low/', $lines);
    $this->assertMatchesRegularExpression('/custom:mine\s+error-tool-failed — its file exited 3/', $lines);
    $this->assertStringContainsString('[budget spent after 3 attempt(s) — LAST VERDICT', $lines);
    $this->assertStringContainsString('1 verified by a test, 0 manual, 1 unverified (AC2', $lines);
    $this->assertStringContainsString('SELF-REVIEWED, not independent', $lines);
    $this->assertStringContainsString('across 2 inspection(s)', $lines);
    $this->assertStringContainsString('droost workflow:answer', $lines);
    $this->assertStringContainsString('check-ins answered: 4 · 3 by the agent, not from the operator\'s terminal · 1 released unanswered', $lines, 'who answered is said (F-335)');
    $this->assertStringContainsString('require_run bypass: ACTIVE — a hotfix', $lines);
  }

  /**
   * A finished run has no current seeker verdict, and its trail still prints.
   *
   * Each advance clears the verdict (the next phase is not inspected yet), so
   * a completed run's report printed no seeker line at all (F-329).
   */
  public function testFinishedRunPrintsTheSeekerTrail(): void {
    $lines = implode("\n", RunReport::lines([
      'run' => [
        'run_id' => 'run-x',
        'phases' => ['plan' => 'passed', 'complete' => 'passed'],
        'seeker' => NULL,
        'seeker_history' => [
          ['status' => 'findings', 'open' => 1, 'critical' => 0, 'medium' => 1, 'low' => 0, 'phase' => 'code'],
          ['status' => 'clean', 'open' => 0, 'critical' => 0, 'medium' => 1, 'low' => 0, 'phase' => 'code'],
        ],
      ],
    ], ['active' => FALSE]));

    $this->assertStringContainsString('seeker (latest inspection, at code): clean (critical 0, medium 1, low 0 found, 0 still open)', $lines);
    $this->assertStringContainsString('across 2 inspection(s)', $lines);
  }

}
