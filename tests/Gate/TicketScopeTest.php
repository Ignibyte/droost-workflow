<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A gate at `scope: ticket` measures what the run changed (0.11).
 *
 * The owner, 2026-09-29: a playwright run is the ticket's own specs through
 * the CLI, not a full regression, unless one is configured. The runner hands
 * each scoped gate the files the run changed (`ticket_files`); the executor
 * narrows the gate to its own kind of file, says it did, and fails a required
 * suite the ticket gave nothing to run.
 */
class TicketScopeTest extends WorkflowTestCase {

  /**
   * A project with the tools, two specs, a unit test and PHP.
   *
   * @return string
   *   The project root.
   */
  private function project(): string {
    $root = $this->makeRoot();
    $dirs = [
      'vendor/bin',
      'node_modules/.bin',
      'tests/e2e',
      'web/modules/custom/x/src',
      'web/modules/custom/x/tests/src/Unit',
    ];
    foreach ($dirs as $dir) {
      mkdir($root . '/' . $dir, 0755, TRUE);
    }
    foreach (['vendor/bin/phpcs', 'vendor/bin/phpstan', 'vendor/bin/phpunit', 'node_modules/.bin/playwright'] as $tool) {
      file_put_contents($root . '/' . $tool, "#!/bin/sh\n");
      chmod($root . '/' . $tool, 0755);
    }
    file_put_contents($root . '/phpunit.xml', "<phpunit/>\n");
    file_put_contents($root . '/tests/e2e/mine.spec.ts', "\n");
    file_put_contents($root . '/tests/e2e/theirs.spec.ts', "\n");
    file_put_contents($root . '/web/modules/custom/x/src/Thing.php', "<?php\n");
    file_put_contents($root . '/web/modules/custom/x/tests/src/Unit/ThingTest.php', "<?php\n");
    file_put_contents($root . '/README.md', "\n");

    return $root;
  }

  /**
   * Each call's argv.
   *
   * @param list<list<string>> $calls
   *   Filled with each call's argv.
   *
   * @return \Droost\Workflow\Gate\ShellGateExecutor
   *   The executor.
   */
  private function recording(array &$calls): ShellGateExecutor {
    return new ShellGateExecutor(
      function (array $argv) use (&$calls): array {
        $calls[] = $argv;
        return [0, "1 passed (1.0s)\n", ''];
      },
      static fn (): int => 0,
    );
  }

  /**
   * The browser suite runs the specs the run changed, and says so.
   */
  public function testPlaywrightRunsTheTicketsSpecsOnly(): void {
    $root = $this->project();
    $calls = [];
    $gate = new GateSettings('playwright', TRUE, [
      'required' => TRUE,
      'scope' => 'ticket',
      'ticket_files' => "tests/e2e/mine.spec.ts\nweb/modules/custom/x/src/Thing.php",
    ]);

    $result = $this->recording($calls)->execute($gate, $root);

    $this->assertSame(['test', 'tests/e2e/mine.spec.ts'], array_slice($calls[0], 1));
    $this->assertNotContains('tests/e2e/theirs.spec.ts', $calls[0]);
    $this->assertStringContainsString('ticket scope: 1 file(s) this run changed, not the full suite', $result->summary);
  }

  /**
   * A required suite with nothing of the ticket's to run fails.
   */
  public function testRequiredSuiteWithNoTicketTestFails(): void {
    $root = $this->project();
    $calls = [];
    $gate = new GateSettings('playwright', TRUE, [
      'required' => TRUE,
      'scope' => 'ticket',
      'ticket_files' => 'web/modules/custom/x/src/Thing.php',
    ]);

    $result = $this->recording($calls)->execute($gate, $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('changed no browser spec', $result->summary);
    $this->assertSame([], $calls, 'nothing ran');

    $empty = new GateSettings('playwright', TRUE, ['scope' => 'ticket', 'ticket_files' => '']);
    $optional = $this->recording($calls)->execute($empty, $root);
    $this->assertTrue($optional->labelledPass, 'not required: a labelled pass, never a plain one');
  }

  /**
   * The unit suite runs the test files the run changed.
   */
  public function testPhpunitRunsTheTicketsTestFiles(): void {
    $root = $this->project();
    $calls = [];
    $gate = new GateSettings('phpunit', TRUE, [
      'scope' => 'ticket',
      'ticket_files' => "web/modules/custom/x/src/Thing.php\nweb/modules/custom/x/tests/src/Unit/ThingTest.php",
    ]);

    $this->recording($calls)->execute($gate, $root);

    $this->assertContains('web/modules/custom/x/tests/src/Unit/ThingTest.php', $calls[0]);
    $this->assertNotContains('web/modules/custom/x/src/Thing.php', $calls[0]);
  }

  /**
   * The analysers read the changed files they read, and nothing else.
   */
  public function testTheAnalysersReadTheChangedFilesOnly(): void {
    $root = $this->project();
    $calls = [];
    $gate = new GateSettings('phpstan', TRUE, [
      'level' => 1,
      'scope' => 'ticket',
      'ticket_files' => "web/modules/custom/x/src/Thing.php\nREADME.md\ntests/e2e/mine.spec.ts",
    ]);

    $result = $this->recording($calls)->execute($gate, $root);

    $this->assertContains('web/modules/custom/x/src/Thing.php', $calls[0]);
    $this->assertNotContains('README.md', $calls[0]);
    $this->assertNotContains('web/modules/custom', $calls[0], 'not the whole custom tree');
    $this->assertStringContainsString('not the whole configured set', $result->summary);

    $readme = new GateSettings('phpcs', TRUE, ['scope' => 'ticket', 'ticket_files' => 'README.md']);
    $none = $this->recording($calls)->execute($readme, $root);
    $this->assertTrue($none->labelledPass);
    $this->assertStringContainsString('changed no file phpcs reads', $none->summary);
  }

  /**
   * A repo's own ruleset decides the standard, and the ticket still the files.
   *
   * With a phpcs.xml.dist the gate passes no --standard, so the ruleset's
   * rules apply; its file list is replaced by the ticket's changed files.
   */
  public function testOwnRulesetStillReadsOnlyTheTicketsFiles(): void {
    $root = $this->project();
    file_put_contents($root . '/phpcs.xml.dist', "<ruleset name=\"own\"><file>.</file></ruleset>\n");
    $calls = [];
    $gate = new GateSettings('phpcs', TRUE, [
      'standard' => 'Drupal',
      'scope' => 'ticket',
      'ticket_files' => "web/modules/custom/x/src/Thing.php\nREADME.md",
    ]);

    $this->recording($calls)->execute($gate, $root);

    $this->assertContains('web/modules/custom/x/src/Thing.php', $calls[0]);
    $this->assertNotContains('.', $calls[0], 'not the ruleset\'s whole file list');
    $this->assertEmpty(array_filter($calls[0], static fn (string $a): bool => str_starts_with($a, '--standard=')), 'the ruleset decides the standard');
  }

  /**
   * Full scope, or none named, runs what it always ran.
   */
  public function testFullScopeRunsTheWholeSuite(): void {
    $root = $this->project();
    $calls = [];

    $full = new GateSettings('playwright', TRUE, ['scope' => 'full', 'ticket_files' => 'tests/e2e/mine.spec.ts']);
    $this->recording($calls)->execute($full, $root);
    $this->recording($calls)->execute(new GateSettings('playwright', TRUE, []), $root);

    $this->assertSame(['test'], array_slice($calls[0], 1));
    $this->assertSame(['test'], array_slice($calls[1], 1));
  }

  /**
   * A run that cannot read its changes runs the full set, and says why.
   */
  public function testUnknownChangesRunTheFullSetAndSaySo(): void {
    $root = $this->project();
    $calls = [];

    $blind = new GateSettings('playwright', TRUE, ['scope' => 'ticket', 'ticket_unknown' => TRUE]);
    $result = $this->recording($calls)->execute($blind, $root);

    $this->assertSame(['test'], array_slice($calls[0], 1));
    $this->assertStringContainsString('ticket scope could not be read', $result->summary);
  }

  /**
   * A failing browser run names each failure's spec as a finding.
   *
   * The fast flow's loop reads them to tell the ticket's failure from a
   * lower rung's (0.11).
   */
  public function testBrowserFailureNamesItsSpecs(): void {
    $root = $this->project();
    $out = "  1) [chromium] › tests/e2e/theirs.spec.ts:9:5 › an older page\n\n"
      . "  1 failed\n    [chromium] › tests/e2e/theirs.spec.ts:9:5 › an older page\n  3 passed (4.2s)\n";
    $executor = new ShellGateExecutor(static fn (array $argv): array => [1, $out, ''], static fn (): int => 0);

    $result = $executor->execute(new GateSettings('playwright', TRUE, []), $root);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertContains(
      ['file' => 'tests/e2e/theirs.spec.ts', 'line' => 9, 'detail' => 'failed'],
      $result->findings,
    );
  }

  /**
   * A scope that is neither is refused by name.
   */
  public function testAnUnknownScopeIsRefused(): void {
    $root = $this->project();
    $calls = [];

    $result = $this->recording($calls)->execute(new GateSettings('phpunit', TRUE, ['scope' => 'changed']), $root);

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertStringContainsString('gates.phpunit.scope', $result->summary . ' ' . ($result->remedy ?? ''));
    $this->assertSame([], $calls);
  }

}
