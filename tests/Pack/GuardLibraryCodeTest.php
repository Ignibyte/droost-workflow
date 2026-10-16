<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Code that drives droost/workflow's own classes is the operator's (S5-1).
 *
 * The path rule refuses code that names a protected file and writes. One line
 * of `php -r` that loads the autoloader and calls the library names neither,
 * and three of them were allowed in a live run: a gate waiver written through
 * RunStateStore, the dial moved through EffortSwitch, and a knowledge call
 * forged through ToolCallLog. The library's methods are the operator verbs.
 */
final class GuardLibraryCodeTest extends WorkflowTestCase {

  /**
   * Code a live run must not run.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function refused(): array {
    return [
      'a gate waiver through RunStateStore' => ['php -r \'require "vendor/autoload.php"; $s=new Droost\Workflow\State\RunStateStore(getcwd()); $s->save($s->load()->withGateWaiver("phpstan","x",date("c")));\''],
      'the dial moved through EffortSwitch' => ['php -r \'require "vendor/autoload.php"; Droost\Workflow\Config\EffortSwitch::apply(getcwd(),"low");\''],
      'a knowledge call forged through ToolCallLog' => ['php -r \'require "vendor/autoload.php"; Droost\Workflow\Knowledge\ToolCallLog::append(getcwd(),"droost_search","ok");\''],
      'a class name built from strings' => ['php -r \'require "vendor/autoload.php"; $c="Droost"."\\\\Workflow\\\\Config\\\\EffortSwitch"; $c::apply(".","low");\''],
      'the autoloader alone, then a call' => ['php -r \'require "vendor/autoload.php"; $c = base64_decode("RHJvb3N0XFdvcmtmbG93"); var_dump($c);\''],
      'lowercase namespace' => ['php -r \'require "vendor/autoload.php"; droost\workflow\knowledge\toolcalllog::append(".","droost_wiki","ok");\''],
      'a heredoc program' => ["php <<'PHP'\n<?php\nrequire 'vendor/autoload.php';\nuse Droost\\Workflow\\Knowledge\\ToolCallLog;\nToolCallLog::append(getcwd(), 'droost_search', 'ok');\nPHP"],
      'through ddev exec' => ['ddev exec php -r \'require "vendor/autoload.php"; Droost\Workflow\Config\EffortSwitch::apply(getcwd(),"low");\''],
      'through drush php:eval' => ['drush php:eval \'Droost\Workflow\Knowledge\ToolCallLog::append(getcwd(),"droost_search","ok");\''],
      'a script that drives the library' => ['php forge.php'],
    ];
  }

  /**
   * Each is refused while a run is live.
   */
  #[DataProvider('refused')]
  public function testLibraryCodeIsRefused(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(2, $exit, $command . ' was allowed: ' . $stderr);
    $this->assertStringContainsString('operator verbs', $stderr);
  }

  /**
   * What the agent is given stays open.
   *
   * @return array<string, array{string}>
   *   Label => command.
   */
  public static function allowed(): array {
    return [
      'the code door' => ['vendor/bin/droost droost_symbol \'{"name":"Camp"}\''],
      'the site door' => ['ddev drush droost:tool droost_entities \'{}\''],
      'the workflow binary' => ['vendor/bin/droost-workflow status'],
      'a lint' => ['php -l web/modules/custom/camps/src/CampList.php'],
      'the test suite' => ['php vendor/bin/phpunit --filter Camp'],
      'composer' => ['composer dump-autoload'],
      'php code that is the site\'s own' => ['php -r \'echo json_encode(["a" => 1]);\''],
      'drush eval of site code' => ['drush php:eval \'echo \Drupal::VERSION;\''],
      'prose that mentions the library' => ['git commit -m "Use droost/workflow 0.16"'],
    ];
  }

  /**
   * Each is allowed.
   */
  #[DataProvider('allowed')]
  public function testWhatTheAgentIsGivenIsAllowed(string $command): void {
    [$exit, , $stderr] = $this->guard($this->liveRoot(), 'operator-commands', ['tool_input' => ['command' => $command]]);

    $this->assertSame(0, $exit, $command . ' was refused: ' . $stderr);
  }

  /**
   * With no live run, the library is anyone's to call.
   */
  public function testNoLiveRunNoWall(): void {
    $root = $this->liveRoot();
    $finished = (string) json_encode(['run_id' => 'r', 'current_phase' => NULL]);
    file_put_contents($root . '/droost/droost-workflow/run.json', $finished);
    [$exit, , $stderr] = $this->guard($root, 'operator-commands', ['tool_input' => ['command' => 'php -r \'require "vendor/autoload.php"; var_dump(class_exists("Droost\\\\Workflow\\\\State\\\\RunState"));\'']]);

    $this->assertSame(0, $exit, $stderr);
  }

  /**
   * A project with a run in its code phase.
   *
   * @return string
   *   The root.
   */
  private function liveRoot(): string {
    $root = $this->makeRoot();
    mkdir($root . '/droost/droost-workflow/history', 0755, TRUE);
    mkdir($root . '/.claude/hooks', 0755, TRUE);
    file_put_contents($root . '/.claude/hooks/droost-workflow-guard.php', "<?php\n");
    file_put_contents($root . '/droost/droost-workflow/tool-calls.jsonl', "\n");
    file_put_contents($root . '/forge.php', "<?php\nrequire 'vendor/autoload.php';\n\\Droost\\Workflow\\Knowledge\\ToolCallLog::append(getcwd(), 'droost_search', 'ok');\n");
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-library',
      'current_phase' => 'code',
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
    return $root;
  }

}
