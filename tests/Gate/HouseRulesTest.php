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
   * A command that prints the files it was handed and fails.
   */
  private const CMD_ECHO = "rules:\n  house:\n    - { id: HR4, rule: 'Custom PHP is checked.', judge: gate, cmd: 'sh bin/echo-fail.sh', files: [ '*.php' ], mode: block }\n";

  /**
   * The script CMD_ECHO runs.
   */
  private const ECHO_FAIL = "printf '%s\\n' \"\$@\"\nexit 3\n";

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
   * A built-in check that read files records that it ran (F-347).
   *
   * `sdc_markup` is judged in-process, in well under a millisecond, and only
   * a rule's `cmd` was timed, so the verdict carried `duration_ms: 0` and the
   * evaluation read it as "the tool never spawned" beside the break it found
   * (KCH-A run 2, HR1 on the page template).
   */
  public function testBuiltInCheckThatReadFilesRecordsItRan(): void {
    $root = $this->repo(self::SDC);
    $this->write($root, 'web/themes/custom/site/templates/card.html.twig', '<div>{{ title }}</div>');

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertGreaterThan(0, $result->durationMs, 'a check that read a file ran');
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
    $this->assertStringContainsString('no house rule is judged by a gate', (string) $result->skipReason, 'the reason rides as the skip reason, which the report prints (F-330)');
  }

  /**
   * A rule's own command is handed the files the run changed, and only those.
   */
  public function testCommandReadsTheChangedFiles(): void {
    $root = $this->repo(self::CMD_ECHO, [
      'bin/echo-fail.sh' => self::ECHO_FAIL,
      'web/modules/custom/camps/src/Old.php' => "<?php\n",
      'web/modules/custom/camps/src/Kept.php' => "<?php\n",
    ]);
    $this->write($root, 'web/modules/custom/camps/src/Old.php', "<?php\n// changed\n");
    $this->write($root, 'web/modules/custom/camps/src/New.php', "<?php\n");
    $this->write($root, 'web/modules/custom/camps/camps.info.yml', "name: Camps\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $message = $result->findings[0]['message'] ?? '';
    $this->assertIsString($message);
    $this->assertStringContainsString('HR4: its command exited 3 over 2 changed file(s)', $message);
    $this->assertStringContainsString('web/modules/custom/camps/src/New.php', $message);
    $this->assertStringContainsString('web/modules/custom/camps/src/Old.php', $message);
    $this->assertStringNotContainsString('Kept.php', $message, 'an unchanged file is the code the run inherited');
    $this->assertStringNotContainsString('camps.info.yml', $message, 'files narrows what the command reads');
  }

  /**
   * A command that exits zero holds its rule, and says how much it read.
   */
  public function testCommandThatExitsZeroHolds(): void {
    $root = $this->repo("rules:\n  house:\n    - { id: HR3, rule: 'No direct database queries.', judge: gate, cmd: \"! grep -n 'Drupal::database'\", files: '*.php', mode: block }\n");
    $this->write($root, 'web/modules/custom/camps/src/List.php', "<?php\n\$ids = \$query->execute();\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Passed, $result->status, $result->summary);
    $this->assertStringContainsString('HR3 read 1 changed file(s)', $result->summary);

    $this->write($root, 'web/modules/custom/camps/src/List.php', "<?php\n\$ids = \\Drupal::database()->select('node');\n");
    $result = $this->houseRules($root);
    $this->assertSame(GateStatus::Failed, $result->status);
    $message = $result->findings[0]['message'] ?? '';
    $this->assertIsString($message);
    $this->assertStringContainsString("2:\$ids = \\Drupal::database()", $message);
  }

  /**
   * A rule whose files the run did not touch read nothing, and says so.
   */
  public function testCommandWithNothingToReadSaysSo(): void {
    $root = $this->repo(self::CMD_ECHO, ['bin/echo-fail.sh' => self::ECHO_FAIL]);
    $this->write($root, 'README.md', "changed\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertTrue($result->labelledPass, 'a pass that read nothing is labelled (S4-1)');
    $this->assertStringContainsString('house_rules read nothing', $result->summary);
    $this->assertStringContainsString('HR4 read 0 changed file(s)', $result->summary);
  }

  /**
   * Every gate rule off is the gate off, never a pass (S4-1).
   */
  public function testEveryRuleOffIsOff(): void {
    $root = $this->repo(str_replace('mode: block', 'mode: off', self::SDC));
    $this->write($root, 'web/themes/custom/site/templates/card.html.twig', '<div></div>');

    $this->assertSame(GateStatus::Off, $this->houseRules($root)->status);
  }

  /**
   * A file named like an option is handed as a file (S4-4).
   */
  public function testOptionShapedFileNameIsPassedAsFile(): void {
    $flagAware = "case \"\$1\" in --version) exit 0;; esac\nprintf '%s\\n' \"\$@\"\nexit 3\n";
    $root = $this->repo(str_replace("files: [ '*.php' ], ", '', self::CMD_ECHO), ['bin/echo-fail.sh' => $flagAware]);
    $this->write($root, '--version', 'x');

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Failed, $result->status, 'a `--version` file must not make the tool print its version and pass');
    $this->assertStringContainsString('./--version', $this->firstMessage($result));
  }

  /**
   * A command that cannot execute, or a signal, is the environment's (S4-5).
   */
  public function testUnrunnableCommandIsTheEnvironments(): void {
    foreach ([126 => "exit 126\n", 137 => "exit 137\n"] as $code => $script) {
      $root = $this->repo(self::CMD_ECHO, ['bin/echo-fail.sh' => $script]);
      $this->write($root, 'web/modules/custom/camps/src/List.php', "<?php\n");

      $result = $this->houseRules($root);

      $this->assertSame(GateStatus::ErrorToolFailed, $result->status, (string) $code);
      $this->assertSame($code, $result->exitCode);
    }
  }

  /**
   * A break records the command's own exit code (S4-5).
   */
  public function testBreakRecordsTheExitCode(): void {
    $root = $this->repo(self::CMD_ECHO, ['bin/echo-fail.sh' => self::ECHO_FAIL]);
    $this->write($root, 'web/modules/custom/camps/src/List.php', "<?php\n");

    $this->assertSame(3, $this->houseRules($root)->exitCode);
  }

  /**
   * A rule's timeout is its own (S4-12).
   */
  public function testRuleTimeoutIsHonoured(): void {
    $root = $this->repo(str_replace('mode: block', 'timeout: 1, mode: block', self::CMD_ECHO), ['bin/echo-fail.sh' => "sleep 5\n"]);
    $this->write($root, 'web/modules/custom/camps/src/List.php', "<?php\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertStringContainsString('did not finish in 1s', $result->summary);
  }

  /**
   * Braces in a files glob name either word.
   */
  public function testBracesInGlobs(): void {
    $root = $this->repo(str_replace("files: [ '*.php' ]", "files: '*.{php,module}'", self::CMD_ECHO), ['bin/echo-fail.sh' => self::ECHO_FAIL]);
    $this->write($root, 'web/modules/custom/camps/camps.module', "<?php\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Failed, $result->status, $result->summary);
    $this->assertStringContainsString('camps.module', $this->firstMessage($result));
  }

  /**
   * A rule whose own script the run rewrote says so (S5-6).
   */
  public function testRewrittenRuleScriptIsSaid(): void {
    $root = $this->repo(self::CMD_ECHO, ['bin/echo-fail.sh' => self::ECHO_FAIL]);
    $this->write($root, 'bin/echo-fail.sh', "exit 0\n");
    $this->write($root, 'web/modules/custom/camps/src/List.php', "<?php\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::Failed, $result->status, 'the rewritten script passes, and its verdict is not a measurement');
    $this->assertStringContainsString('bin/echo-fail.sh was changed by this run', $this->firstMessage($result));
  }

  /**
   * A command that is not there fails the gate: nobody checked the rule.
   */
  public function testMissingCommandIsNoPass(): void {
    $root = $this->repo("rules:\n  house:\n    - { id: HR5, rule: 'Lint.', judge: gate, cmd: 'droost-no-such-linter', mode: report }\n");
    $this->write($root, 'web/modules/custom/camps/src/List.php', "<?php\n");

    $result = $this->houseRules($root);

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertStringContainsString('HR5', (string) $result->remedy);
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
    $runner = new GateRunner($this->passing(), new CliVcs(CliProcess::run(...)));
    foreach ($runner->run($state, Phase::Code, $root)->results as $result) {
      if ($result->gate === 'house_rules') {
        return $result;
      }
    }
    $this->fail('house_rules did not run at code');
  }

  /**
   * The first finding's message.
   *
   * @param \Droost\Workflow\Gate\GateResult $result
   *   The gate's result.
   *
   * @return string
   *   The message, or ''.
   */
  private function firstMessage(GateResult $result): string {
    $message = $result->findings[0]['message'] ?? '';

    return is_string($message) ? $message : '';
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
