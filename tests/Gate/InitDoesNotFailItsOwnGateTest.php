<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Installing droost does not make a project fail droost's own gates.
 *
 * A reviewer ran a real first ticket from `init` defaults and lost it here.
 * On a pristine project holding one clean PHP class:
 *
 *   BEFORE init   phpcs -q --standard=Drupal,DrupalPractice .   rc=0
 *   AFTER  init   .claude/hooks/droost-workflow-guard.php   1 error, 8 warnings
 *
 * With no `phpcs.xml` in a new repository the gate scans `.`, which now holds
 * a 2,000-line procedural hook that carries no autoloader by design and whose
 * comments run past eighty columns on purpose. phpcs is mandatory, it cannot
 * be turned off, and the standalone CLI has no `gate-waive`, `bypass` or
 * `effort` verb. Three attempts, then terminal:
 *
 *   {"outcome":"failed","report":null,"retries":{"exhausted":true}}
 *
 * recoverable only by `reset`. Neither `init`'s output nor the README's
 * Install section names a ruleset or `gates.phpcs.paths` as a prerequisite.
 *
 * So a first-time user lost their first run on a repository where they had
 * written nothing wrong, and every word of the failure was about droost's own
 * file. A tool judging the tool's own installed files is a category error
 * however clean those files are.
 */
final class InitDoesNotFailItsOwnGateTest extends WorkflowTestCase {

  /**
   * What the pack installs is never handed to a gate.
   *
   * Asserted against a REAL `init` rather than a fixture, because the defect
   * was a file the pack installs and a fixture would be a guess about which
   * files those are.
   */
  public function testWhatInitInstallsIsNeverHandedToGates(): void {
    $root = $this->makeRoot();
    mkdir($root . '/src', 0755, TRUE);
    file_put_contents($root . '/src/Money.php', "<?php\n\ndeclare(strict_types=1);\n");
    mkdir($root . '/vendor/bin', 0755, TRUE);
    file_put_contents($root . '/vendor/bin/phpcs', "#!/bin/sh\nexit 0\n");
    chmod($root . '/vendor/bin/phpcs', 0755);

    (new PackMaterializer())->init($root);
    $this->assertFileExists(
      $root . '/.claude/hooks/droost-workflow-guard.php',
      'init really did install the file this is about',
    );

    $seen = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$seen): array {
        $seen = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );
    $executor->execute(new GateSettings('phpcs', TRUE, ['standard' => 'Drupal']), $root);

    $ignore = '';
    foreach ($seen as $argument) {
      if (str_starts_with((string) $argument, '--ignore=')) {
        $ignore = substr((string) $argument, strlen('--ignore='));
      }
    }
    $this->assertNotSame('', $ignore, 'the gate still passes an ignore list');
    foreach (['.claude', 'droost/droost-workflow', 'droost/baseline'] as $own) {
      $this->assertStringContainsString(
        $own,
        $ignore,
        sprintf('%s is droost\'s own, and is not the project\'s code to review', $own),
      );
    }
  }

  /**
   * And the walk that decides "is there anything to analyse" skips them too.
   *
   * Phpstan has no `--ignore` flag, so the only way to keep the pack's hook
   * out of a project's static analysis is not to hand it over — which makes
   * this walk, not the flag, the load-bearing half for that gate.
   *
   * A directory whose only PHP is what `init` wrote must read as EMPTY. The
   * scan that reaches it is the ordinary one: a gate pointed at a parent,
   * descending. An operator who points `paths` straight at `.claude` has said
   * something deliberate and is not second-guessed here.
   */
  public function testTheWalkDoesNotDescendIntoWhatInitWrote(): void {
    $root = $this->makeRoot();
    mkdir($root . '/app', 0755, TRUE);
    (new PackMaterializer())->init($root . '/app');
    $this->assertFileExists(
      $root . '/app/.claude/hooks/droost-workflow-guard.php',
      'the pack really is the only PHP under app/',
    );
    mkdir($root . '/vendor/bin', 0755, TRUE);
    file_put_contents($root . '/vendor/bin/phpcs', "#!/bin/sh\nexit 0\n");
    chmod($root . '/vendor/bin/phpcs', 0755);

    // A tally rather than a flag: static analysis narrows a bool assigned
    // FALSE and then never sees the closure write to it, which turns the
    // second assertion below into a tautology it can prove.
    $runs = [];
    $executor = new ShellGateExecutor(
      function (array $argv) use (&$runs): array {
        $runs[] = $argv;
        return [0, '', ''];
      },
      static fn (): int => 0,
    );
    $result = $executor->execute(
      new GateSettings('phpcs', TRUE, ['standard' => 'Drupal', 'paths' => 'app']),
      $root,
    );

    $this->assertCount(0, $runs, 'the tool is not run over droost\'s own installed files');
    $this->assertTrue(
      $result->labelledPass,
      'and the result says it measured nothing, rather than claiming a pass',
    );

    // The counterweight, in a SUBDIRECTORY so the descent is what finds it:
    // a walk that skipped every directory would also report "nothing to
    // analyse" here, and that is not the property being asserted.
    mkdir($root . '/app/lib', 0755, TRUE);
    file_put_contents($root . '/app/lib/Money.php', "<?php\n\ndeclare(strict_types=1);\n");
    $executor->execute(
      new GateSettings('phpcs', TRUE, ['standard' => 'Drupal', 'paths' => 'app']),
      $root,
    );
    $this->assertCount(1, $runs, 'and the project\'s own code is still analysed');
  }

}
