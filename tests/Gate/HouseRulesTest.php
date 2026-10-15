<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Cli\CliProcess;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\CliVcs;

/**
 * The site's own rules, held on the diff (owner, 2026-10-15).
 *
 * A house rule judged by a gate names a check droost knows; `sdc_markup`
 * fails new markup outside a single-directory component, and a Canvas code
 * component. A rule judged by the seeker is the seeker's, and the gate never
 * reads it.
 */
final class HouseRulesTest extends WorkflowTestCase {

  private const SDC = "rules:\n  house:\n    - { id: HR1, rule: 'New markup lives in single-directory components.', judge: gate, check: sdc_markup, mode: block }\n";

  /**
   * A template the run adds outside a component fails a block-mode rule.
   */
  public function testNewMarkupOutsideComponentsFails(): void {
    $root = $this->repo(self::SDC);
    $this->write($root, 'web/themes/custom/site/templates/card.html.twig', '<div>{{ title }}</div>');
    $this->write($root, 'web/themes/custom/site/components/hero/hero.component.yml', "name: Hero\n");
    $this->write($root, 'web/themes/custom/site/components/hero/hero.twig', '<section>{{ title }}</section>');

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('HR1', $result->summary);
    $files = array_column($result->findings, 'file');
    $this->assertSame(['web/themes/custom/site/templates/card.html.twig'], $files, 'the component\'s own template is not a break');
  }

  /**
   * Editing a template that was already there is not adding one.
   */
  public function testEditingAnExistingTemplateIsNoBreak(): void {
    $root = $this->repo(self::SDC, ['web/themes/custom/site/templates/page.html.twig' => '<main></main>']);
    $this->write($root, 'web/themes/custom/site/templates/page.html.twig', '<main class="x"></main>');

    $this->assertSame(GateStatus::Passed, $this->houseRules($root)->status);
  }

  /**
   * In report mode a break is recorded beside a passing phase.
   */
  public function testReportModeRecordsTheBreak(): void {
    $root = $this->repo(str_replace(', mode: block', '', self::SDC));
    $this->write($root, 'config/sync/canvas.js_component.promo.yml', "machineName: promo\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Reported, $result->status);
    $message = $result->findings[0]['message'] ?? '';
    $this->assertIsString($message);
    $this->assertStringContainsString('code component', $message);
  }

  /**
   * With no rule a gate judges, the gate is off and says why.
   */
  public function testNoGateRuleIsOff(): void {
    $root = $this->repo("rules:\n  house:\n    - { id: HR2, rule: 'Pages are composed in Canvas.', judge: seeker }\n");
    $this->write($root, 'web/themes/custom/site/templates/card.html.twig', '<div></div>');

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Off, $result->status);
    $this->assertStringContainsString('no house rule is judged by a gate', $result->summary);
  }

  /**
   * The rules verb prints each rule with its id and judge.
   */
  public function testTheRulesVerbPrintsThem(): void {
    $root = $this->repo(self::SDC . "    - { id: HR2, rule: 'Pages are composed in Canvas.', judge: seeker }\n");
    $lines = [];
    $sink = function (string $line) use (&$lines): void {
      $lines[] = $line;
    };
    $code = (new ArgvDispatcher($sink, $sink, static fn (): string => '2026-10-15T00:00:00+00:00', static fn (): string => 'run-hr'))->dispatch(['rules'], $root);

    $this->assertSame(ArgvDispatcher::EXIT_OK, $code);
    $out = implode("\n", $lines);
    $this->assertStringContainsString('HR1 [gate sdc_markup, block] New markup lives in single-directory components.', $out);
    $this->assertStringContainsString('HR2 [seeker] Pages are composed in Canvas.', $out);
  }

  /**
   * The house rules gate's result after the run's changes.
   *
   * @param string $root
   *   The project, its changes in the working tree.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The gate's result at code.
   */
  private function houseRules(string $root): GateResult {
    $base = trim((string) shell_exec(sprintf('git -C %s rev-parse HEAD', escapeshellarg($root))));
    $state = RunState::begin('run-hr', '2026-10-15T00:00:00+00:00', WorkflowConfig::load($root), $base);
    $runner = new GateRunner($this->passing(), new NullSiteDriver(), new CliVcs(CliProcess::run(...)));
    foreach ($runner->run($state, Phase::Code, $root)->results as $result) {
      if ($result->gate === 'house_rules') {
        return $result;
      }
    }
    $this->fail('house_rules did not run at code');
  }

  /**
   * A git repository with the lever file and the given files committed.
   *
   * @param string $yaml
   *   The lever file.
   * @param array<string, string> $files
   *   Files committed in the base, by path.
   *
   * @return string
   *   The root.
   */
  private function repo(string $yaml, array $files = []): string {
    $root = $this->makeRootWithConfig("preset: custom\n" . $yaml);
    foreach ($files as $path => $content) {
      $this->write($root, $path, $content);
    }
    exec(sprintf(
      'cd %s && git init -q . && git add -A && git -c user.name=droost-test -c user.email=test@example.invalid commit -q -m base 2>&1',
      escapeshellarg($root),
    ), $output, $exit);
    $this->assertSame(0, $exit, implode("\n", $output));
    return $root;
  }

  /**
   * Writes a file, making its directory.
   *
   * @param string $root
   *   The project.
   * @param string $path
   *   The relative path.
   * @param string $content
   *   The content.
   */
  private function write(string $root, string $path, string $content): void {
    @mkdir(dirname($root . '/' . $path), 0755, TRUE);
    file_put_contents($root . '/' . $path, $content);
  }

  /**
   * An executor that passes every gate it is asked to run.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The executor.
   */
  private function passing(): GateExecutorInterface {
    return new class() implements GateExecutorInterface {

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
      }

    };
  }

}
