<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\ContributedGate;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Support\TypedArray;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * Gates a module contributes: enable and go; the site decides if they block.
 *
 * D72 (2026-09-08). A `droost_<tool>` module declares a gate in code — its
 * command, phases, default mode and a sentence saying what the verdict means
 * — and it joins the run as `module:<id>` with provenance. The lever file
 * overrides `on` and `mode` only; the rest is the module's contract.
 */
final class ContributedGatesTest extends WorkflowTestCase {

  /**
   * A declared gate joins the resolved set on, at its phases, with provenance.
   */
  public function testDeclaredGateJoinsTheResolvedSet(): void {
    $config = WorkflowConfig::fromArray(['preset' => 'high'], 'test', contributed: [$this->snyk()]);

    $gate = $config->gate('module:snyk');
    $this->assertTrue($gate->on, 'enabling the module is the opt-in');
    $this->assertSame('snyk test --severity-threshold=high', $gate->option('cmd'));
    $this->assertSame('code,test', $gate->option('phase'));
    $this->assertSame('droost_snyk', $gate->option('provider'));
    $this->assertSame('report', $gate->mode(), 'the declared default mode');
    $this->assertStringContainsString('exit 0', (string) $gate->option('verdict'));
    $this->assertTrue($gate->runsOwnCommand());
    $this->assertArrayHasKey('module:snyk', $config->contributedGates);
    $this->assertSame('droost_snyk', $config->contributedGates['module:snyk']->provider);

    // Woven into its phases and complete, exactly like a custom gate.
    $state = RunState::begin('r', 't', $config);
    $this->assertContains('module:snyk', $state->phaseGates['code']);
    $this->assertContains('module:snyk', $state->phaseGates['test']);
    $this->assertContains('module:snyk', $state->phaseGates['complete']);
    $this->assertArrayHasKey('module:snyk', $state->gatesDueFor(Phase::Code));

    // And it round-trips through the run document with its provenance.
    $document = array_filter($state->toArray(), static fn ($v): bool => $v !== NULL);
    $reloaded = RunState::fromArray(TypedArray::authored($document), 'run.json');
    $this->assertSame('droost_snyk', $reloaded->resolvedGates['module:snyk']['provider']);
    $this->assertSame('report', $reloaded->resolvedGates['module:snyk']['mode']);
  }

  /**
   * The lever file sets on and mode — the site's decision — and nothing else.
   */
  public function testTheLeverOverridesOnAndModeOnly(): void {
    $blocking = WorkflowConfig::fromArray(
      ['gates' => ['contributed' => ['snyk' => ['mode' => 'block']]]],
      'test',
      contributed: [$this->snyk()],
    );
    $this->assertSame('block', $blocking->gate('module:snyk')->mode(), 'the owner\'s sentence: "do not let the coding phase continue if there are snyk findings"');
    $this->assertSame('snyk test --severity-threshold=high', $blocking->gate('module:snyk')->option('cmd'), 'the command is untouched');

    $off = WorkflowConfig::fromArray(
      ['gates' => ['contributed' => ['snyk' => ['on' => FALSE]]]],
      'test',
      contributed: [$this->snyk()],
    );
    $this->assertFalse($off->gate('module:snyk')->on);

    try {
      WorkflowConfig::fromArray(
        ['gates' => ['contributed' => ['snyk' => ['cmd' => 'rm -rf /']]]],
        'droost.workflow.yml',
        contributed: [$this->snyk()],
      );
      $this->fail('the command was overridable');
    }
    catch (ConfigError $e) {
      $this->assertStringContainsString('accepts only "on" and "mode"', $e->getMessage());
      $this->assertStringContainsString('gates.custom', $e->getMessage());
    }
  }

  /**
   * An undeclared id is a typo to surface — when a catalog is known.
   */
  public function testUnknownContributedIdIsRefusedWhenCatalogKnown(): void {
    try {
      WorkflowConfig::fromArray(
        ['gates' => ['contributed' => ['semgrep' => ['mode' => 'block']]]],
        'droost.workflow.yml',
        contributed: [$this->snyk()],
      );
      $this->fail('an undeclared id was accepted');
    }
    catch (ConfigError $e) {
      $this->assertStringContainsString('no enabled module declares a gate "semgrep"', $e->getMessage());
      $this->assertStringContainsString('declared: snyk', $e->getMessage());
    }

    // Without a catalog the block is left alone: the caller is reading other
    // levers and cannot tell a typo from a gate it cannot see.
    $blind = WorkflowConfig::fromArray(
      ['gates' => ['contributed' => ['semgrep' => ['mode' => 'block']]]],
      'droost.workflow.yml',
    );
    $this->assertArrayNotHasKey('module:semgrep', $blind->gates);
    $this->assertSame([], $blind->contributedGates);
  }

  /**
   * The declaration is validated where it is made, by name.
   */
  public function testDeclarationsAreValidated(): void {
    $cases = [
      'bad id' => [
        fn () => new ContributedGate('Sny k', 'droost_snyk', ['code'], 'snyk test', verdict: 'x'),
        'must match',
      ],
      'no provider' => [
        fn () => new ContributedGate('snyk', '', ['code'], 'snyk test', verdict: 'x'),
        'names no provider',
      ],
      'bad phase' => [
        fn () => new ContributedGate('snyk', 'droost_snyk', ['deploy'], 'snyk test', verdict: 'x'),
        'code, test',
      ],
      'no phase' => [
        fn () => new ContributedGate('snyk', 'droost_snyk', [], 'snyk test', verdict: 'x'),
        'at least one phase',
      ],
      'multi-line cmd' => [
        fn () => new ContributedGate('snyk', 'droost_snyk', ['code'], "a\nb", verdict: 'x'),
        'single-line command',
      ],
      'bad mode' => [
        fn () => new ContributedGate('snyk', 'droost_snyk', ['code'], 'snyk test', 'advisory', 'x'),
        'block, report',
      ],
      'no verdict' => [
        fn () => new ContributedGate('snyk', 'droost_snyk', ['code'], 'snyk test'),
        'what its verdict means',
      ],
    ];
    foreach ($cases as $label => [$make, $fragment]) {
      try {
        $make();
        $this->fail($label . ' was accepted');
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString($fragment, $e->getMessage(), $label);
      }
    }
  }

  /**
   * The executor runs the declared command; a failure repeats the verdict.
   */
  public function testExecutorRunsTheCommandAndRepeatsTheVerdictOnFailure(): void {
    $seen = [];
    $failing = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [1, "✗ High severity vulnerability found in lodash\n", ''];
      },
      static fn (): int => 0,
    );
    $gate = $this->snyk()->toSettings();

    $result = $failing->execute($gate, '/tmp');

    $this->assertSame(['/bin/sh', '-c', 'snyk test --severity-threshold=high'], $seen);
    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('module:snyk failed (exit 1)', $result->summary);
    $this->assertStringContainsString('what this means: exit 0', $result->summary);

    $passing = new ShellGateExecutor(static fn (): array => [0, 'ok', ''], static fn (): int => 0);
    $this->assertSame(GateStatus::Passed, $passing->execute($gate, '/tmp')->status);
    $this->assertNull($passing->measure($gate, '/tmp'), 'a contributed gate is never baselined — its cmd is the gate');
  }

  /**
   * Report mode by default: the run advances over findings and says so.
   */
  public function testDefaultReportModeAdvancesTheRunAndBlockOverrideStopsIt(): void {
    $root = $this->makeRootWithConfig("preset: high\n");
    $failing = new ShellGateExecutor(
      static function (array $argv): array {
        // Only the contributed gate fails; the named gates pass.
        if (in_array('snyk test --severity-threshold=high', $argv, TRUE)) {
          return [1, 'findings', ''];
        }
        return [0, '{"totals":{"errors":0,"warnings":0}}', ''];
      },
      static fn (): int => 0,
    );
    mkdir($root . '/vendor/bin', 0775, TRUE);
    foreach (['vendor/bin/phpcs', 'vendor/bin/phpstan', 'vendor/bin/phpunit'] as $binary) {
      file_put_contents($root . '/' . $binary, '');
    }
    file_put_contents($root . '/phpunit.xml', '<phpunit/>');

    $runner = new GateRunner($failing, new NullSiteDriver(), contributed: [$this->snyk()]);
    $config = WorkflowConfig::load($root, [$this->snyk()]);
    $report = $runner->run(RunState::begin('r', 't', $config), Phase::Code, $root);
    $snyk = $this->resultFor($report, 'module:snyk');
    $this->assertSame(GateStatus::Reported, $snyk->status);
    $this->assertStringContainsString('what this means', $snyk->summary);
    $this->assertTrue($report->advance(), 'report mode: findings recorded, phase advances');

    file_put_contents($root . '/droost.workflow.yml', "preset: high\ngates:\n  contributed:\n    snyk: { mode: block }\n");
    $config = WorkflowConfig::load($root, [$this->snyk()]);
    $report = $runner->run(RunState::begin('r', 't', $config), Phase::Code, $root);
    $this->assertSame(GateStatus::Failed, $this->resultFor($report, 'module:snyk')->status);
    $this->assertFalse($report->advance(), 'block mode: the coding phase does not continue');

    file_put_contents($root . '/droost.workflow.yml', "preset: high\ngates:\n  contributed:\n    snyk: { on: false }\n");
    $config = WorkflowConfig::load($root, [$this->snyk()]);
    $report = $runner->run(RunState::begin('r', 't', $config), Phase::Code, $root);
    $off = $this->resultFor($report, 'module:snyk');
    $this->assertSame(GateStatus::Off, $off->status);
    $this->assertStringContainsString('gates.contributed', $off->summary, 'off says who turned it off');
  }

  /**
   * The status document lists contributed gates with their provenance.
   */
  public function testStatusListsContributedGatesWithProvenance(): void {
    $root = $this->makeRootWithConfig("preset: high\n");
    $facade = new WorkflowFacade(
      new ShellGateExecutor(static fn (): array => [0, '', ''], static fn (): int => 0),
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-08T00:00:00+00:00',
      static fn (): string => 'run-1',
      contributed: [$this->snyk()],
    );

    $status = $facade->status($root);

    $levers = $status['levers'];
    $this->assertIsArray($levers);
    $gates = $levers['gates'];
    $this->assertIsArray($gates);
    $this->assertArrayHasKey('module:snyk', $gates);
    $contributed = $levers['contributed'];
    $this->assertIsArray($contributed);
    $snyk = $contributed['module:snyk'];
    $this->assertIsArray($snyk);
    $this->assertSame('droost_snyk', $snyk['provider']);
    $this->assertSame(['code', 'test'], $snyk['phases']);
    $this->assertSame('report', $snyk['default_mode']);
    $toolchain = $status['toolchain'];
    $this->assertIsArray($toolchain);
    $this->assertArrayNotHasKey('module:snyk', $toolchain, 'no binary row: its cmd runs through the shell');
  }

  /**
   * The reference declaration: droost_snyk's gate.
   *
   * @return \Droost\Workflow\Config\ContributedGate
   *   The gate.
   */
  private function snyk(): ContributedGate {
    return new ContributedGate(
      'snyk',
      'droost_snyk',
      ['code', 'test'],
      'snyk test --severity-threshold=high',
      'report',
      'exit 0: no vulnerabilities at or above the severity threshold; exit 1: vulnerabilities found; exit 2: the snyk CLI itself failed (auth, network).',
    );
  }

  /**
   * The result for one gate.
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
    $this->fail('no result for ' . $gate);
  }

}
