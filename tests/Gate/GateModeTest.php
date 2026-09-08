<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The gate mode: block (the default) or report.
 *
 * D72 (2026-09-08): a custom or contributed gate — a Snyk scan, say — may
 * legitimately run to REPORT rather than to block, and the site decides which
 * in its lever file. Report mode turns a blocking outcome into REPORTED: the
 * findings ride the report, the seeker reads them, the phase advances, and
 * nothing reads as a pass. The mandatory trio can never be put in report
 * mode — that would be a disarm by another name.
 */
final class GateModeTest extends WorkflowTestCase {

  /**
   * Block is every gate's default, and saying so records nothing.
   */
  public function testBlockIsTheDefaultAndIsNotRecorded(): void {
    $config = WorkflowConfig::builtIn();
    $this->assertSame(GateSettings::DEFAULT_MODE, $config->gate('eslint')->mode());
    $this->assertArrayNotHasKey('mode', $config->gate('eslint')->toArray());

    $root = $this->makeRootWithConfig("preset: max\ngates:\n  eslint: { mode: block }\n");
    $this->assertArrayNotHasKey(
      'mode',
      WorkflowConfig::load($root)->gate('eslint')->toArray(),
      'an explicit block resolves to the same levers as saying nothing',
    );
  }

  /**
   * Report mode is recorded on the resolved levers, for named and custom gates.
   */
  public function testReportModeIsRecordedOnTheResolvedLevers(): void {
    $root = $this->makeRootWithConfig(
      "preset: max\n"
      . "gates:\n"
      . "  eslint: { mode: report }\n"
      . "  custom:\n"
      . "    snyk: { on: true, phase: 'code,test', cmd: 'snyk test', mode: report }\n",
    );
    $config = WorkflowConfig::load($root);

    $this->assertSame('report', $config->gate('eslint')->mode());
    $this->assertSame('report', $config->gate('eslint')->toArray()['mode']);
    $this->assertSame('report', $config->gate('custom:snyk')->mode());
    $this->assertSame('block', $config->gate('stylelint')->mode(), 'a sibling gate is untouched');
  }

  /**
   * A mode word outside block|report is refused, naming both.
   */
  public function testAnUnknownModeWordIsRefused(): void {
    $root = $this->makeRootWithConfig("gates:\n  eslint: { mode: advisory }\n");
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessageMatches('/"block".*"report".*advisory/s');
    WorkflowConfig::load($root);
  }

  /**
   * The mandatory trio stays blocking; the attempt is noticed and superseded.
   */
  public function testTheMandatoryTrioCannotBePutInReportMode(): void {
    $root = $this->makeRootWithConfig("gates:\n  phpcs: { mode: report, standard: Drupal }\n");
    $config = WorkflowConfig::load($root);

    $this->assertSame('block', $config->gate('phpcs')->mode(), 'phpcs stays blocking');
    $this->assertSame('Drupal', $config->gate('phpcs')->option('standard'), 'the tuning beside the attempt still applies');
    $this->assertStringContainsString('mode: report', implode("\n", $config->deprecations));
  }

  /**
   * A failing report-mode gate is REPORTED and the phase advances.
   */
  public function testFailingReportModeGateIsReportedAndThePhaseAdvances(): void {
    $root = $this->makeRootWithConfig("preset: max\ngates:\n  eslint: { mode: report }\n");
    $runner = new GateRunner($this->executor(failing: ['eslint']), new NullSiteDriver());
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', WorkflowConfig::load($root));

    $report = $runner->run($state, Phase::Code, $root);

    $eslint = $this->resultFor($report, 'eslint');
    $this->assertSame(GateStatus::Reported, $eslint->status);
    $this->assertStringStartsWith('report — eslint failed (exit 1)', $eslint->summary);
    $this->assertStringContainsString('would block in mode: block', $eslint->summary);
    $this->assertSame(1, $eslint->exitCode, 'the tool\'s own outcome is kept');
    $this->assertTrue($report->advance(), 'a reported gate never blocks the phase');
    $this->assertSame(1, $report->tally()['reported']);
    $this->assertStringContainsString('1 reported', $report->summaryLine());
    $this->assertFalse($eslint->status->isPass(), 'reported is never counted as a pass');
  }

  /**
   * The same failure in block mode still blocks — the mode is the only change.
   */
  public function testTheSameFailureInBlockModeBlocks(): void {
    $root = $this->makeRootWithConfig("preset: max\n");
    $runner = new GateRunner($this->executor(failing: ['eslint']), new NullSiteDriver());
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', WorkflowConfig::load($root));

    $report = $runner->run($state, Phase::Code, $root);

    $this->assertSame(GateStatus::Failed, $this->resultFor($report, 'eslint')->status);
    $this->assertFalse($report->advance());
  }

  /**
   * A missing tool in report mode is reported too; a pass stays a pass.
   */
  public function testToolMissingIsReportedAndPassesAreUntouched(): void {
    $root = $this->makeRootWithConfig("preset: max\ngates:\n  eslint: { mode: report }\n  stylelint: { mode: report }\n");
    $runner = new GateRunner($this->executor(missing: ['eslint']), new NullSiteDriver());
    $state = RunState::begin('run-1', '2026-09-08T00:00:00+00:00', WorkflowConfig::load($root));

    $report = $runner->run($state, Phase::Code, $root);

    $eslint = $this->resultFor($report, 'eslint');
    $this->assertSame(GateStatus::Reported, $eslint->status);
    $this->assertStringContainsString('could not run', $eslint->summary);
    $this->assertSame(GateStatus::Passed, $this->resultFor($report, 'stylelint')->status, 'report mode changes nothing about a pass');
    $this->assertTrue($report->advance());
  }

  /**
   * The verdict rule at its source: REPORTED never blocks a phase report.
   */
  public function testPhaseReportAdvancesOverReportedGate(): void {
    $report = (new PhaseReport(Phase::Code))
      ->with(new GateResult('custom:snyk', GateStatus::Reported, 1, 3, 'report — snyk failed'));
    $this->assertTrue($report->advance());
    $this->assertFalse(GateStatus::Reported->blocksAdvance());
    $this->assertSame('REPORTED (report mode, not blocking)', GateStatus::Reported->label());
  }

  /**
   * The result for one gate in a phase report.
   *
   * @param \Droost\Workflow\Gate\PhaseReport $report
   *   The report.
   * @param string $gate
   *   The gate name.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The result.
   */
  private function resultFor(PhaseReport $report, string $gate): GateResult {
    foreach ($report->results as $result) {
      if ($result->gate === $gate) {
        return $result;
      }
    }
    $this->fail(sprintf('no result for gate %s', $gate));
  }

  /**
   * An executor that fails or lacks the named gates and passes the rest.
   *
   * @param list<string> $failing
   *   Gates that exit 1.
   * @param list<string> $missing
   *   Gates whose tool is absent.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The executor.
   */
  private function executor(array $failing = [], array $missing = []): GateExecutorInterface {
    return new class($failing, $missing) implements GateExecutorInterface {

      /**
       * Constructs the fake.
       *
       * @param list<string> $failing
       *   Gates that exit 1.
       * @param list<string> $missing
       *   Gates whose tool is absent.
       */
      public function __construct(
        private readonly array $failing,
        private readonly array $missing,
      ) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        if (in_array($gate->name, $this->missing, TRUE)) {
          return GateResult::toolMissing($gate->name, 'node_modules/.bin/' . $gate->name);
        }
        $fails = in_array($gate->name, $this->failing, TRUE);
        return GateResult::ran(
          $gate->name,
          $fails ? GateStatus::Failed : GateStatus::Passed,
          $fails ? 1 : 0,
          5,
          $fails ? $gate->name . ' failed (exit 1)' : $gate->name . ' passed',
          [],
          'vendor/bin/' . $gate->name,
        );
      }

    };
  }

}
