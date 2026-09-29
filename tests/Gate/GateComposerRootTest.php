<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\WorkflowFacade;

/**
 * A gate's tools come from the Composer project it names (F-153).
 *
 * Druplit keeps its Drupal site in `site/`, a Composer project one level
 * down, with `app/` and `contract/` beside it and its modules at the repo
 * root. The executor looked only at `<project>/vendor/bin/<tool>`, found
 * nothing, and the mandatory phpcs and phpstan gates blocked every advance;
 * phpstan, run from the repo root, never loaded `site/phpstan.neon` either.
 * A gate's `root` names the Composer project it runs in: the tool is that
 * root's, the working directory is that root, and so are the config files
 * each tool discovers.
 */
class GateComposerRootTest extends WorkflowTestCase {

  /**
   * A project shaped like druplit: the site one level down, code beside it.
   *
   * @return string
   *   The project root.
   */
  private function druplit(): string {
    $root = $this->makeRoot();
    foreach (['site/vendor/bin', 'site/web', 'app/vendor/bin', 'modules/droost_cockpit/src'] as $dir) {
      mkdir($root . '/' . $dir, 0755, TRUE);
    }
    foreach (['phpcs', 'phpstan', 'phpunit'] as $tool) {
      file_put_contents($root . '/site/vendor/bin/' . $tool, "#!/bin/sh\n");
      chmod($root . '/site/vendor/bin/' . $tool, 0755);
    }
    file_put_contents($root . '/site/phpcs.xml.dist', "<ruleset><rule ref=\"Drupal\"/><file>../modules</file></ruleset>\n");
    file_put_contents($root . '/site/phpstan.neon', "parameters:\n  paths: [../modules]\n");
    file_put_contents($root . '/modules/droost_cockpit/src/Cockpit.php', "<?php\n");

    return $root;
  }

  /**
   * Each call's argv and working directory.
   *
   * @param list<array{list<string>, string}> $calls
   *   Filled with each call.
   * @param array{int, string, string} $answer
   *   What the tool returns.
   *
   * @return \Droost\Workflow\Gate\ShellGateExecutor
   *   The executor.
   */
  private function recording(array &$calls, array $answer = [0, '', '']): ShellGateExecutor {
    return new ShellGateExecutor(
      function (array $argv, string $dir) use (&$calls, $answer): array {
        $calls[] = [$argv, $dir];
        return $answer;
      },
      static fn (): int => 0,
    );
  }

  /**
   * With no root the tool is missing, which is what druplit met.
   */
  public function testWithoutRootTheToolIsMissing(): void {
    $root = $this->druplit();
    $calls = [];

    $result = $this->recording($calls)->execute(new GateSettings('phpcs', TRUE, []), $root);

    $this->assertSame(GateStatus::ErrorToolMissing, $result->status);
    $this->assertSame([], $calls);
  }

  /**
   * The root's tool, run in the root, with the root's own configs.
   */
  public function testTheRootsToolRunsInTheRootWithItsConfigs(): void {
    $root = $this->druplit();
    $calls = [];
    $executor = $this->recording($calls);

    $phpcs = $executor->execute(new GateSettings('phpcs', TRUE, ['standard' => 'Drupal', 'root' => 'site']), $root);
    $phpstan = $executor->execute(new GateSettings('phpstan', TRUE, ['level' => 1, 'root' => 'site']), $root);

    $this->assertSame(GateStatus::Passed, $phpcs->status, $phpcs->summary);
    $this->assertSame(GateStatus::Passed, $phpstan->status, $phpstan->summary);
    [[$phpcsArgv, $phpcsDir], [$phpstanArgv, $phpstanDir]] = $calls;
    $this->assertSame($root . '/site/vendor/bin/phpcs', $phpcsArgv[0]);
    $this->assertSame($root . '/site', $phpcsDir);
    // The root's ruleset decides the standard and the files (`../modules`).
    $this->assertNotContains('--standard=Drupal', $phpcsArgv);
    $this->assertSame($root . '/site/vendor/bin/phpstan', $phpstanArgv[0]);
    $this->assertSame($root . '/site', $phpstanDir);
    // No path handed over: phpstan loads site/phpstan.neon from its working
    // directory, and the dial still sets the level.
    $this->assertContains('--level=1', $phpstanArgv);
    $this->assertSame([], array_values(array_filter(array_slice($phpstanArgv, 1), static fn (string $a): bool => !str_starts_with($a, '-') && $a !== 'analyse')));
  }

  /**
   * A finding is named from the project, not from the root.
   */
  public function testFindingsAreNamedFromTheProject(): void {
    $root = $this->druplit();
    $file = $root . '/modules/droost_cockpit/src/Cockpit.php';
    $message = [
      'message' => 'Missing file doc comment',
      'source' => 'Drupal.Commenting.FileComment.Missing',
      'severity' => 5,
      'type' => 'ERROR',
      'line' => 1,
      'column' => 1,
      'fixable' => FALSE,
    ];
    $report = (string) json_encode([
      'totals' => ['errors' => 1, 'warnings' => 0, 'fixable' => 0],
      'files' => [
        $file => ['errors' => 1, 'warnings' => 0, 'messages' => [$message]],
      ],
    ]);
    $calls = [];

    $result = $this->recording($calls, [2, $report, ''])->execute(new GateSettings('phpcs', TRUE, ['root' => 'site']), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertNotEmpty($result->findings);
    $this->assertSame('modules/droost_cockpit/src/Cockpit.php', $result->findings[0]['file'] ?? NULL);
  }

  /**
   * A root that is not a directory inside the project is refused by name.
   */
  public function testRootOutsideTheProjectOrMissingIsRefused(): void {
    $root = $this->druplit();
    $calls = [];
    $executor = $this->recording($calls);

    foreach (['../elsewhere', '/usr', 'nonesuch'] as $lever) {
      $result = $executor->execute(new GateSettings('phpcs', TRUE, ['root' => $lever]), $root);
      $this->assertSame(GateStatus::ErrorToolMissing, $result->status, $lever);
      $this->assertStringContainsString('gates.phpcs.root', $result->summary . ' ' . ($result->remedy ?? ''), $lever);
    }
    $this->assertSame([], $calls, 'nothing ran');
  }

  /**
   * The lever is read from the file like any other gate option.
   */
  public function testTheLeverIsReadAsGateOption(): void {
    $config = WorkflowConfig::fromArray([
      'preset' => 'low',
      'gates' => ['phpstan' => ['root' => 'site'], 'phpcs' => ['root' => 'site/']],
    ], 'test');

    $this->assertSame('site', $config->gate('phpstan')->option('root'));
    $this->assertSame('site/', $config->gate('phpcs')->option('root'));
    foreach (['phpcs', 'phpstan', 'phpunit', 'coverage', 'mutation'] as $gate) {
      $this->assertContains('root', GateSettings::optionNames($gate), $gate);
    }
  }

  /**
   * Status finds the tools and the ruleset where the gates run them (F-161).
   *
   * It probed `<project>/vendor/bin` whatever `root` said, so it reported
   * phpcs and phpstan absent while the gates ran them from site/vendor/bin,
   * and PSR12 as the standard while the site's own Drupal ruleset ran.
   */
  public function testStatusLooksWhereTheGatesRun(): void {
    $root = $this->druplit();
    mkdir($root . '/site/vendor/drupal/coder/coder_sniffer/Drupal', 0755, TRUE);
    file_put_contents($root . '/site/vendor/drupal/coder/coder_sniffer/Drupal/ruleset.xml', "<ruleset/>\n");
    file_put_contents($root . '/droost.workflow.yml', "preset: low\ngates:\n  phpcs:\n    root: site\n  phpstan:\n    root: site\n");
    $facade = new WorkflowFacade(
      new ShellGateExecutor(static fn (): array => [0, '', ''], static fn (): int => 0),
      new NullSiteDriver(),
      new RunStateOnlySink(),
      static fn (): string => '2026-09-29T00:00:00+00:00',
      static fn (): string => 'r',
    );

    $toolchain = $facade->status($root)['toolchain'] ?? NULL;
    $this->assertIsArray($toolchain);
    $phpcs = $toolchain['phpcs'] ?? NULL;
    $phpstan = $toolchain['phpstan'] ?? NULL;
    $this->assertIsArray($phpcs);
    $this->assertIsArray($phpstan);

    $this->assertSame('site/vendor/bin/phpcs', $phpcs['binary'] ?? NULL);
    $this->assertTrue($phpcs['present'] ?? FALSE);
    $this->assertTrue($phpstan['present'] ?? FALSE);
    $this->assertSame('site/phpcs.xml.dist', $phpcs['ruleset'] ?? NULL);
    $this->assertSame('Drupal', WorkflowConfig::load($root)->gate('phpcs')->option('standard'), 'the site\'s coder counts');
  }

}
