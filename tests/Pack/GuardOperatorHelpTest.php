<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Asking how an operator's command works is not running it (F-79).
 *
 * P6 run 6's agent, stopped by inherited debt, read `gate-waive --help`
 * before proposing a waiver to the operator, and the guard refused it as the
 * waiver itself. So the agent proposed a command whose syntax it had not been
 * allowed to read. drush prints a command's help and runs nothing when
 * `--help` is given, and droost-workflow prints its usage line.
 */
final class GuardOperatorHelpTest extends WorkflowTestCase {

  /**
   * A help-only invocation of each operator verb is allowed.
   */
  public function testHelpForOperatorVerbsIsAllowed(): void {
    foreach ([
      'drush droost:workflow:gate-waive --help',
      'ddev drush droost:workflow:gate-waive --help 2>&1 | head -30',
      'vendor/bin/drush droost:workflow:bypass -h',
      'drush droost:workflow:effort --help',
      'drush droost:workflow:baseline --help',
      'vendor/bin/droost-workflow gate-waive --help',
    ] as $command) {
      [$exit, , $stderr] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(0, $exit, $command . ' only reads the help: ' . $stderr);
    }
  }

  /**
   * Anything more than help still takes the operator.
   */
  public function testTheVerbWithArgumentsStillTakesTheOperator(): void {
    foreach ([
      'drush droost:workflow:gate-waive phpstan "inherited" --help',
      'drush droost:workflow:gate-waive --help; drush droost:workflow:gate-waive phpstan "x"',
      'drush droost:workflow:bypass "hotfix" -h',
      'drush droost:workflow:effort max --help',
      'drush droost:gate allow_entity_write on --help',
      'drush droost:workflow:gate-waive phpstan "x"',
    ] as $command) {
      [$exit] = $this->guard($this->makeRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);
      $this->assertSame(2, $exit, $command . ' is the operator\'s');
    }
  }

}
