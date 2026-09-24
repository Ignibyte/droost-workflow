<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The front-end linters' failures are read, counted and located (F-90).
 *
 * Measured with the tools Drupal core pins, preparing the first run at xhigh.
 * stylelint 16.26.1 wrote its report to stderr, and every reader here took
 * stdout: the gate recorded a failing stylelint with no findings at all. And
 * both linters' summaries were the head of their JSON report, cut at 200
 * characters. The reports below are the tools' own, with the root replaced.
 */
final class LintReportTest extends WorkflowTestCase {

  /**
   * What stylelint 16.26.1 printed on stderr, with core's config.
   */
  private const STYLELINT = '[{"source":"{ROOT}/web/modules/custom/probe_module/probe.css","deprecations":[],"invalidOptionWarnings":[],"parseErrors":[],"errored":true,"warnings":[{"line":1,"column":12,"endLine":1,"endColumn":20,"rule":"declaration-property-value-no-unknown","severity":"error","text":"Unexpected unknown value \"#FFFFFFF\" for property \"color\" (declaration-property-value-no-unknown)"},{"line":1,"column":4,"endLine":1,"endColumn":22,"rule":"prettier/prettier","severity":"error","text":"Replace \"·color:·#FFFFFFF;·\" with \"⏎··color:·#FFFFFFF;⏎\" (prettier/prettier)"}]}]';

  /**
   * What eslint 8.57.1 printed on stdout, with core's config, trimmed.
   */
  private const ESLINT = '[{"filePath":"{ROOT}/web/modules/custom/probe_module/probe.js","messages":[{"ruleId":"func-names","severity":1,"message":"Unexpected unnamed function.","line":1,"column":2},{"ruleId":"no-var","severity":2,"message":"Unexpected var, use let or const instead.","line":2,"column":3},{"ruleId":"no-unused-vars","severity":1,"message":"\'unused\' is assigned a value but never used.","line":2,"column":7},{"ruleId":"eqeqeq","severity":2,"message":"Expected \'===\' and instead saw \'==\'.","line":3,"column":9},{"ruleId":"no-eval","severity":2,"message":"eval can be harmful.","line":3,"column":17}],"errorCount":3,"warningCount":2}]';

  /**
   * A stylelint failure reported on stderr keeps its findings.
   */
  public function testStylelintReportOnStderrIsRead(): void {
    $root = $this->rootWithFile('css', 'a { color: #FFFFFFF; }');
    $result = $this->lint('stylelint', $root, [2, '', str_replace('{ROOT}', $root, self::STYLELINT)]);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertCount(2, $result->findings, 'both problems are recorded');
    $this->assertSame('web/modules/custom/probe_module/probe.css', $result->findings[0]['file']);
    $this->assertSame('stylelint failed (exit 2): 2 errors, at web/modules/custom/probe_module/probe.css:1', $result->summary);
  }

  /**
   * An eslint failure is counted and located, not quoted.
   */
  public function testEslintSummaryCountsAndLocates(): void {
    $root = $this->rootWithFile('js', 'var unused = 1;');
    $result = $this->lint('eslint', $root, [1, str_replace('{ROOT}', $root, self::ESLINT), '']);

    $this->assertSame(
      'eslint failed (exit 1): 3 errors and 2 warnings, at web/modules/custom/probe_module/probe.js:2, web/modules/custom/probe_module/probe.js:3',
      $result->summary,
    );
  }

  /**
   * A prettier failure lists every file, from stderr, where prettier 3 puts them.
   *
   * It recorded no findings and named one file of four (F-95). The output is
   * prettier 3.9.9's --check, with the paths made neutral.
   */
  public function testPrettierFilesAreCountedAndRecorded(): void {
    $root = $this->rootWithFile('css', 'a{color:red}');
    $stderr = "[warn] web/modules/custom/a/a.css\n[warn] web/modules/custom/b/b.css\n"
      . "[warn] web/modules/custom/c/c.css\n[warn] web/modules/custom/d/d.css\n"
      . "[warn] Code style issues found in 4 files. Run Prettier with --write to fix.\n";
    $result = $this->lint('prettier', $root, [1, "Checking formatting...\n", $stderr]);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertCount(4, $result->findings);
    $this->assertSame(
      'prettier failed (exit 1): 4 files not formatted, web/modules/custom/a/a.css, web/modules/custom/b/b.css, web/modules/custom/c/c.css, and more',
      $result->summary,
    );
  }

  /**
   * Stylelint's own crash is still a tool that could not run.
   */
  public function testStylelintWithoutConfigCouldNotRun(): void {
    $root = $this->rootWithFile('css', '.a { color: #fff; }');
    $crash = "ConfigurationError: No configuration provided for {$root}/web/modules/custom/probe_module/probe.css\n";
    $result = $this->lint('stylelint', $root, [78, '', $crash]);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertStringContainsString('.stylelintrc.json', $result->summary);
  }

  /**
   * Each remedy names the fix the agent may make, then the operator's lever.
   *
   * The lever file is one the guard refuses the agent (F-91).
   */
  public function testRemediesNameTheProjectsOwnConfig(): void {
    $eslint = ShellGateExecutor::toolFailedHint('eslint');
    $this->assertStringContainsString('root: true', $eslint);
    $this->assertStringContainsString('you may make', $eslint);
    $this->assertStringContainsString('gates.eslint.config', $eslint);

    $stylelint = ShellGateExecutor::toolFailedHint('stylelint');
    $this->assertStringContainsString('.stylelintrc.json', $stylelint);
    $this->assertStringContainsString('you may make', $stylelint);

    $prettier = ShellGateExecutor::toolFailedHint('prettier');
    $this->assertStringContainsString('.prettierrc.json', $prettier);
    $this->assertStringContainsString('opposite formatting', $prettier, 'a pinned prettier config is invisible to the linters\' prettier rules');
  }

  /**
   * Runs a trio gate on a canned outcome.
   *
   * @param string $gate
   *   The gate.
   * @param string $root
   *   The project root.
   * @param array{int, string, string} $outcome
   *   Exit code, stdout, stderr.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function lint(string $gate, string $root, array $outcome): GateResult {
    $executor = new ShellGateExecutor(static fn (): array => $outcome, static fn (): int => 0);
    return $executor->execute(new GateSettings($gate, TRUE, ['paths' => 'web/modules/custom']), $root);
  }

  /**
   * A root with the trio installed and one file to lint.
   *
   * @param string $extension
   *   The file's extension.
   * @param string $content
   *   Its content.
   *
   * @return string
   *   The root.
   */
  private function rootWithFile(string $extension, string $content): string {
    $root = $this->makeRoot();
    mkdir($root . '/node_modules/.bin', 0775, TRUE);
    foreach (['eslint', 'stylelint', 'prettier'] as $tool) {
      file_put_contents($root . '/node_modules/.bin/' . $tool, '');
    }
    mkdir($root . '/web/modules/custom/probe_module', 0775, TRUE);
    file_put_contents($root . '/web/modules/custom/probe_module/probe.' . $extension, $content . "\n");
    return $root;
  }

}
