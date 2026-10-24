<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Contract;

use Droost\Workflow\Cli\CliProcess;
use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\RuleFiles;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\Levers;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A project's gate is a file: 0 passed, 1 failed, anything else not run.
 */
final class GateFileContractTest extends WorkflowTestCase {

  /**
   * Levers with every built-in gate off and one gate file at code.
   */
  private const LEVERS = "preset: custom\ngates:\n  phpcs: { on: false }\n  phpstan: { on: false }\n"
    . "  phpunit: { on: false }\n  playwright: { on: false }\n  parity: { on: false }\n"
    . "  house_rules: { on: false }\n  g: { run: droost/gates/g.php, phase: code }\n";

  /**
   * A gate named under gates.<id> with run: is the project's own gate.
   */
  public function testRunDeclaresProjectGate(): void {
    $config = WorkflowConfig::fromArray([
      'preset' => 'low',
      'gates' => [
        'gitleaks' => ['run' => './droost/gates/gitleaks.php', 'phase' => 'code,test', 'mode' => 'report'],
        'config_clean' => ['cmd' => 'drush config:status', 'phase' => 'test'],
      ],
    ], 'droost/droost.yml');

    $gate = $config->gates['custom:gitleaks'];
    $this->assertTrue($gate->on, 'declaring the gate is the opt-in');
    $this->assertSame('droost/gates/gitleaks.php', $gate->option('run'));
    $this->assertSame('code,test', $gate->option('phase'));
    $this->assertSame('report', $gate->mode());
    $this->assertSame('drush config:status', $config->gates['custom:config_clean']->option('cmd'), 'a retired name taken back as the project\'s own');
    $this->assertSame([], $config->deprecations);
  }

  /**
   * A gate names exactly one of cmd or run, and its file stays in the project.
   */
  public function testMalformedGatesAreRefused(): void {
    $bad = [
      'both' => ['cmd' => 'true', 'run' => 'droost/gates/a.php', 'phase' => 'code'],
      'no phase' => ['run' => 'droost/gates/a.php'],
      'absolute' => ['run' => '/etc/passwd', 'phase' => 'code'],
      'climbs' => ['run' => 'droost/../../x.php', 'phase' => 'code'],
      'unknown option' => ['run' => 'droost/gates/a.php', 'phase' => 'code', 'verdict' => 'x'],
    ];
    foreach ($bad as $case => $entry) {
      try {
        WorkflowConfig::fromArray(['gates' => ['scan' => $entry]], 'droost/droost.yml');
        $this->fail('Expected a refusal: ' . $case);
      }
      catch (ConfigError $e) {
        $this->assertStringContainsString('scan', $e->getMessage(), $case);
      }
    }
  }

  /**
   * Hooks, walls and operator-only scripts are read from droost.yml.
   */
  public function testHooksWallsAndOperatorOnlyAreRead(): void {
    $config = WorkflowConfig::fromArray([
      'hooks' => ['run_start' => 'droost/hooks/start.php', 'phase_end' => 'droost/hooks/end.sh'],
      'walls' => ['tracker' => ['tools' => '^mcp__atlassian__(create|edit)', 'run' => 'droost/walls/tracker.php']],
      'operator_only' => ['droost/bin/jira-writes', './droost/bin/arm'],
    ], 'droost/droost.yml');

    $this->assertSame(['run_start' => 'droost/hooks/start.php', 'phase_end' => 'droost/hooks/end.sh'], $config->hooks);
    $this->assertSame(['tracker' => ['tools' => '^mcp__atlassian__(create|edit)', 'run' => 'droost/walls/tracker.php']], $config->walls);
    $this->assertSame(['droost/bin/jira-writes', 'droost/bin/arm'], $config->operatorOnly);

    foreach ([
      ['hooks' => ['on_save' => 'droost/hooks/x.php']],
      ['walls' => ['w' => ['tools' => '(', 'run' => 'droost/walls/w.php']]],
      ['walls' => ['w' => ['tools' => 'x', 'run' => 'droost/walls/w.php', 'mode' => 'block']]],
      ['operator_only' => ['/usr/bin/x']],
    ] as $raw) {
      try {
        WorkflowConfig::fromArray($raw, 'droost/droost.yml');
        $this->fail('Expected a refusal for ' . (string) json_encode($raw));
      }
      catch (ConfigError $e) {
        $this->assertNotSame('', $e->getMessage());
      }
    }
  }

  /**
   * The file's exit code is the verdict, and anything else is not a pass.
   */
  public function testExitCodesAreTheVerdict(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/gates', 0755, TRUE);
    $cases = [
      'pass' => ['<?php echo "clean\n"; exit(0);', GateStatus::Passed],
      'fail' => ['<?php echo "2 secrets\n"; exit(1);', GateStatus::Failed],
      'not run' => ['<?php fwrite(STDERR, "gitleaks is not installed\n"); exit(3);', GateStatus::ErrorToolFailed],
      'crash' => ['<?php throw new \RuntimeException("boom");', GateStatus::ErrorToolFailed],
    ];
    foreach ($cases as $case => [$code, $expected]) {
      file_put_contents($root . '/droost/gates/g.php', $code);
      $result = $this->executor()->execute($this->gate('droost/gates/g.php'), $root);
      $this->assertSame($expected, $result->status, $case);
      $this->assertSame($expected === GateStatus::Passed, $result->status->isPass(), $case . ': only exit 0 is a pass');
    }
    file_put_contents($root . '/droost/gates/g.php', '<?php fwrite(STDERR, "gitleaks is not installed\n"); exit(3);');
    $notRun = $this->executor()->execute($this->gate('droost/gates/g.php'), $root);
    $this->assertStringContainsString('gitleaks is not installed', $notRun->summary, 'the file\'s own reason');
    $this->assertStringContainsString('could not run', $notRun->summary);
  }

  /**
   * Exit 4 is a gate file saying it ran and examined nothing (F-340).
   *
   * A file had no way to say so: exit 0 was a measured pass, so a lint over
   * a project with no component read `passed`, and any other code read as
   * not run, though the file ran and found nothing to read. Exit 4 is the
   * labelled pass droost's own gates give an empty scope: a pass that the
   * record says measured nothing.
   */
  public function testExitFourIsLabelledPass(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/gates', 0755, TRUE);
    file_put_contents($root . '/droost/gates/g.php', '<?php echo "\ncomponent_lint examined nothing: the project has no custom SDC.\n"; exit(4);');
    $result = $this->executor()->execute($this->gate('droost/gates/g.php'), $root);
    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertTrue($result->labelledPass, 'a pass that measured nothing says so');
    $this->assertSame(4, $result->exitCode);
    $this->assertStringContainsString('examined nothing: the project has no custom SDC', $result->summary, 'the file\'s own reason');
    $this->assertTrue($result->toArray()['labelled'], 'the record carries it');

    file_put_contents($root . '/droost/gates/g.php', '<?php echo "1 SDC meets the requirements.\n"; exit(0);');
    $measured = $this->executor()->execute($this->gate('droost/gates/g.php'), $root);
    $this->assertSame(GateStatus::Passed, $measured->status);
    $this->assertFalse($measured->labelledPass, 'exit 0 is a measured pass');
  }

  /**
   * The verdict a gate file prints last is its summary, plain (F-317).
   *
   * The tool a gate file wraps prints first (gitleaks' coloured log, a
   * Python warning from semgrep); the summary took that first line instead
   * of the file's own verdict.
   */
  public function testTheLastLineIsTheSummary(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/gates', 0755, TRUE);
    file_put_contents($root . '/droost/gates/g.php', '<?php echo "\033[90m5:59PM\033[0m \033[32mINF\033[0m scanned ~2087028 bytes\n"; echo "dependencies.py:4: UserWarning: pkg_resources is deprecated\n"; echo "\ngitleaks passed: no leaks in the custom code.\n"; exit(0);');
    $passed = $this->executor()->execute($this->gate('droost/gates/g.php'), $root);
    $this->assertSame(GateStatus::Passed, $passed->status);
    $this->assertStringEndsWith('passed — gitleaks passed: no leaks in the custom code.', $passed->summary);

    file_put_contents($root . '/droost/gates/g.php', '<?php echo "\033[31mERR\033[0m leak in a.php\n\ngitleaks FAILED: 1 leak.\n"; exit(1);');
    $failed = $this->executor()->execute($this->gate('droost/gates/g.php'), $root);
    $this->assertStringEndsWith('gitleaks FAILED: 1 leak.', $failed->summary);
    $this->assertStringNotContainsString("\033", $failed->summary, 'no colour codes in the record');
  }

  /**
   * A missing or unexecutable file judged nothing, and says why.
   */
  public function testFileThatCannotRunIsNotRun(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/gates', 0755, TRUE);
    $missing = $this->executor()->execute($this->gate('droost/gates/none.php'), $root);
    $this->assertSame(GateStatus::ErrorToolMissing, $missing->status);
    $this->assertStringContainsString('not there', (string) $missing->summary . $missing->remedy);

    file_put_contents($root . '/droost/gates/g.sh', "#!/bin/sh\nexit 0\n");
    chmod($root . '/droost/gates/g.sh', 0644);
    $plain = $this->executor()->execute($this->gate('droost/gates/g.sh'), $root);
    $this->assertSame(GateStatus::ErrorToolMissing, $plain->status);
    chmod($root . '/droost/gates/g.sh', 0755);
    $this->assertSame(GateStatus::Passed, $this->executor()->execute($this->gate('droost/gates/g.sh'), $root)->status);
  }

  /**
   * The file reads the run on stdin and the project root in its environment.
   */
  public function testTheFileIsToldWhoAsks(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/gates', 0755, TRUE);
    file_put_contents($root . '/droost/gates/g.php', '<?php
      $in = json_decode((string) stream_get_contents(STDIN), TRUE);
      file_put_contents(getenv("DROOST_PROJECT_ROOT") . "/seen.json", json_encode(["in" => $in, "env" => getenv("DROOST_PROJECT_ROOT"), "gate" => getenv("DROOST_GATE"), "cwd" => getcwd()]));
      exit(0);');
    file_put_contents(Levers::path($root), self::LEVERS);
    $config = WorkflowConfig::load($root);
    $state = RunState::begin('run-ctx', '2026-10-19T00:00:00+00:00', $config, NULL, NULL, NULL, RuleFiles::hash($root, $config));

    (new GateRunner($this->executor()))->run($state, Phase::Code, $root);

    $seen = json_decode((string) file_get_contents($root . '/seen.json'), TRUE);
    $this->assertIsArray($seen);
    $env = $seen['env'] ?? NULL;
    $cwd = $seen['cwd'] ?? NULL;
    $in = $seen['in'] ?? NULL;
    $this->assertIsString($env);
    $this->assertIsString($cwd);
    $this->assertIsArray($in);
    $this->assertSame(realpath($root), realpath($env));
    $this->assertSame('g', $seen['gate'] ?? NULL);
    $this->assertSame(realpath($root), realpath($cwd));
    $this->assertSame('g', $in['gate'] ?? NULL);
    $this->assertSame('run-ctx', $in['run_id'] ?? NULL);
    $this->assertSame('code', $in['phase'] ?? NULL);
    $this->assertSame(rtrim($root, '/'), $in['project_root'] ?? NULL);
  }

  /**
   * The run freezes each rule file's hash, and a changed file is named.
   */
  public function testChangedFileIsNamedAtItsGate(): void {
    $root = $this->makeRoot();
    mkdir($root . '/droost/gates', 0755, TRUE);
    file_put_contents($root . '/droost/gates/g.php', '<?php exit(1);');
    file_put_contents(Levers::path($root), self::LEVERS . "walls:\n  w: { tools: '^Bash$', run: droost/walls/w.php }\n");
    $config = WorkflowConfig::load($root);
    $frozen = RuleFiles::hash($root, $config);
    $this->assertSame(['droost/agents.md', 'droost/gates/g.php', 'droost/walls/w.php'], array_keys($frozen));
    $this->assertNull($frozen['droost/walls/w.php'], 'a file the levers name and that is missing is frozen as missing');
    $state = RunState::begin('run-h', '2026-10-19T00:00:00+00:00', $config, NULL, NULL, NULL, $frozen);
    $this->assertSame($frozen, $state->ruleFiles);

    file_put_contents($root . '/droost/gates/g.php', '<?php exit(0);');
    $report = (new GateRunner($this->executor()))->run($state, Phase::Code, $root);

    $g = NULL;
    foreach ($report->results as $result) {
      if ($result->gate === 'custom:g') {
        $g = $result;
      }
    }
    $this->assertNotNull($g);
    $this->assertSame(GateStatus::Passed, $g->status, 'the verdict stands');
    $this->assertStringContainsString('its file droost/gates/g.php changed since this run began', $g->summary);
  }

  /**
   * A project gate that runs a file at code.
   *
   * @param string $run
   *   The file.
   *
   * @return \Droost\Workflow\Config\GateSettings
   *   The gate.
   */
  private function gate(string $run): GateSettings {
    return new GateSettings('custom:g', TRUE, ['run' => $run, 'phase' => 'code']);
  }

  /**
   * An executor that really runs the file.
   *
   * @return \Droost\Workflow\Gate\ShellGateExecutor
   *   The executor.
   */
  private function executor(): ShellGateExecutor {
    return new ShellGateExecutor(
      static fn (array $argv, string $cwd, int $timeout): array => CliProcess::run($argv, $cwd, $timeout),
      static fn (): int => 0,
    );
  }

}
