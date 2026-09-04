<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\Enforcement;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Support\TypedArray;
use PHPUnit\Framework\TestCase;

/**
 * Custom gates: the repo's own commands as first-class gates (0.3, W3).
 *
 * The design's one-line promise is "tune this entirely": a lever file can
 * wire semgrep — or anything — without this package knowing the tool. What
 * these tests pin is the honesty around that freedom: everything about a
 * custom gate is explicit, it runs before complete at the phase it declared,
 * and a missing tool is a broken environment, never a pass.
 */
final class CustomGatesTest extends TestCase {

  /**
   * A custom entry becomes a namespaced gate carrying its cmd and phase.
   */
  public function testCustomGateResolvesNamespaced(): void {
    $config = WorkflowConfig::fromArray([
      'gates' => [
        'custom' => [
          'semgrep' => ['on' => TRUE, 'phase' => 'code', 'cmd' => 'semgrep scan --error --quiet'],
        ],
      ],
    ], 'test');

    $gate = $config->gate('custom:semgrep');
    $this->assertTrue($gate->on);
    $this->assertSame('semgrep scan --error --quiet', $gate->option('cmd'));
    $this->assertSame('code', $gate->option('phase'));
    $this->assertArrayHasKey('custom:semgrep', $config->resolvedGates());
  }

  /**
   * A custom gate may attach to more than one phase — code AND test.
   *
   * The Snyk case: a security scan that must run as code lands and again
   * under the test phase. `phase` takes a comma-separated list (trimmed and
   * deduplicated), and the gate is woven into every named phase — plus
   * complete, where everything enabled re-runs.
   */
  public function testCustomGateAttachesToMultiplePhases(): void {
    $config = WorkflowConfig::fromArray([
      'gates' => [
        'custom' => [
          'snyk' => [
            'on' => TRUE,
            'phase' => 'code, test',
            'cmd' => 'snyk test',
          ],
        ],
      ],
    ], 'test');

    // The list is trimmed and stored normalised.
    $this->assertSame('code,test', $config->gate('custom:snyk')->option('phase'));

    // And it is due at BOTH phases, plus complete (gatesDueFor keys by name).
    $state = RunState::begin('r', 't', $config);
    $this->assertArrayHasKey('custom:snyk', $state->gatesDueFor(Phase::Code));
    $this->assertArrayHasKey('custom:snyk', $state->gatesDueFor(Phase::Test));
    $this->assertArrayHasKey('custom:snyk', $state->gatesDueFor(Phase::Complete));
  }

  /**
   * Nothing about a custom gate is inferred: on, phase and cmd are required.
   *
   * @param array<string, mixed> $entry
   *   The gates.custom.broken entry.
   * @param string $message
   *   A fragment the error must carry.
   */
  #[\PHPUnit\Framework\Attributes\DataProvider('invalidEntries')]
  public function testInvalidCustomEntriesAreRefusedByName(array $entry, string $message): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage($message);
    WorkflowConfig::fromArray(
      ['gates' => ['custom' => ['broken' => $entry]]],
      'droost.workflow.yml',
    );
  }

  /**
   * The invalid entries and what each error must name.
   *
   * @return array<string, array{array<string, mixed>, string}>
   *   Case name to entry and message fragment.
   */
  public static function invalidEntries(): array {
    return [
      'missing on' => [
        ['phase' => 'code', 'cmd' => 'true'],
        'custom gate "broken": "on" is required',
      ],
      'missing phase' => [
        ['on' => TRUE, 'cmd' => 'true'],
        'custom gate "broken": "phase" is required',
      ],
      'missing cmd' => [
        ['on' => TRUE, 'phase' => 'code'],
        'custom gate "broken": "cmd" is required',
      ],
      'complete is not a placement' => [
        ['on' => TRUE, 'phase' => 'complete', 'cmd' => 'true'],
        'phase must be one or more of: code, test',
      ],
      'a bad phase in a list is refused' => [
        ['on' => TRUE, 'phase' => 'code,deploy', 'cmd' => 'true'],
        'phase must be one or more of: code, test',
      ],
      'unknown option' => [
        ['on' => TRUE, 'phase' => 'code', 'cmd' => 'true', 'threshold' => 3],
        'unknown option "threshold"',
      ],
      'multi-line cmd' => [
        ['on' => TRUE, 'phase' => 'code', 'cmd' => "true\nrm -rf /"],
        'cmd must be a non-empty single-line command',
      ],
    ];
  }

  /**
   * A malformed name never becomes a gate.
   */
  public function testMalformedCustomNameIsRefused(): void {
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('custom gate "Sem Grep"');
    WorkflowConfig::fromArray(
      ['gates' => ['custom' => ['Sem Grep' => ['on' => TRUE, 'phase' => 'code', 'cmd' => 'true']]]],
      'test',
    );
  }

  /**
   * The frozen phase map places a custom gate at its phase and at complete.
   */
  public function testCustomGateIsWovenIntoTheFrozenMap(): void {
    $config = WorkflowConfig::fromArray([
      'gates' => [
        'custom' => [
          'semgrep' => ['on' => TRUE, 'phase' => 'code', 'cmd' => 'semgrep scan'],
          'behat' => ['on' => TRUE, 'phase' => 'test', 'cmd' => 'vendor/bin/behat'],
        ],
      ],
    ], 'test');
    $state = RunState::begin('r', 't', $config);

    $this->assertContains('custom:semgrep', $state->phaseGates['code']);
    $this->assertNotContains('custom:semgrep', $state->phaseGates['test']);
    $this->assertContains('custom:behat', $state->phaseGates['test']);
    $this->assertContains('custom:semgrep', $state->phaseGates['complete']);
    $this->assertContains('custom:behat', $state->phaseGates['complete']);

    $this->assertArrayHasKey('custom:semgrep', $state->gatesDueFor(Phase::Code));
    $this->assertArrayHasKey('custom:behat', $state->gatesDueFor(Phase::Test));
  }

  /**
   * The executor runs the command through the shell; exit zero passes.
   */
  public function testExecutorRunsCustomCommandsThroughTheShell(): void {
    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );
    $gate = new GateSettings('custom:semgrep', TRUE, ['cmd' => 'semgrep scan --error', 'phase' => 'code']);

    $result = $executor->execute($gate, '/tmp');

    $this->assertSame(['/bin/sh', '-c', 'semgrep scan --error'], $seen);
    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame('semgrep scan --error', $result->invocation);
  }

  /**
   * A non-zero exit fails, and the shell's 127 reports the tool missing.
   */
  public function testExecutorMapsExitCodesHonestly(): void {
    $failing = new ShellGateExecutor(
      static fn (): array => [2, '', 'findings'],
      static fn (): int => 0,
    );
    $gate = new GateSettings('custom:x', TRUE, ['cmd' => 'x --check', 'phase' => 'code']);
    $this->assertSame(GateStatus::Failed, $failing->execute($gate, '/tmp')->status);

    $missing = new ShellGateExecutor(
      static fn (): array => [127, '', 'sh: x: command not found'],
      static fn (): int => 0,
    );
    $result = $missing->execute($gate, '/tmp');
    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
  }

  /**
   * Enforcement is frozen into the run document and survives the round trip.
   */
  public function testEnforcementFreezesIntoTheRunDocument(): void {
    $config = WorkflowConfig::fromArray(['preset' => 'factory'], 'test');
    $state = RunState::begin('r', 't', $config);
    $this->assertSame(Enforcement::Hard, $state->enforcement);

    $document = $state->toArray();
    $this->assertSame('hard', $document['enforcement']);
    // The store strips nulls before writing; this round trip does the same.
    $document = array_filter($document, static fn ($v): bool => $v !== NULL);

    $reloaded = RunState::fromArray(TypedArray::authored($document), 'run.json');
    $this->assertSame(Enforcement::Hard, $reloaded->enforcement);

    // A document written before the lever existed reads as what it was.
    unset($document['enforcement']);
    $legacy = RunState::fromArray(TypedArray::authored($document), 'run.json');
    $this->assertSame(Enforcement::Off, $legacy->enforcement);
  }

}
