<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Contract;

use Droost\Workflow\Tests\Levers;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The guard holds the project's rules, walls and operator-only scripts.
 */
final class GuardContractTest extends WorkflowTestCase {

  /**
   * During a run the rules are frozen; before one they are ordinary files.
   */
  public function testRulesAreRefusedOnlyDuringRun(): void {
    $root = $this->project();
    $rules = [
      'droost/droost.yml',
      'droost/gates/scan.php',
      'droost/hooks/start.php',
      'droost/walls/w.php',
      'droost/panel/ticket.ts',
      'droost/agents.md',
    ];
    foreach ($rules as $file) {
      [$exit] = $this->write($root, $file);
      $this->assertSame(0, $exit, $file . ' is an ordinary file before a run');
    }

    $this->openRun($root);
    foreach ($rules as $file) {
      [$exit, , $stderr] = $this->write($root, $file);
      $this->assertSame(2, $exit, $file . ' is refused during a run');
      $this->assertNotSame('', $stderr);
    }
    [$exit] = $this->write($root, 'droost/wiki/page.md');
    $this->assertSame(0, $exit, 'the wiki is not a rule');
    [$exit] = $this->shell($root, 'echo x > droost/gates/scan.php');
    $this->assertSame(2, $exit, 'and not through the shell either');
  }

  /**
   * The diary names what a call touched, and never a command's arguments.
   *
   * The run panel's Activity tab shows it beside what the agent says.
   */
  public function testTheDiaryNamesWhatEachCallTouched(): void {
    $root = $this->project();
    $this->openRun($root);
    $this->write($root, 'web/themes/custom/x.css');
    $this->shell($root, 'composer require drupal/webform --with-all-dependencies');
    $this->shell($root, 'curl -H "Authorization: Bearer s3cr3t" https://example.com');
    $this->shell($root, 'TOKEN=abc123 deploy secret-sk-000000000000000000000000000000000000000000');

    $subjects = [];
    foreach (file($root . '/droost/droost-workflow/guard-calls.jsonl', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
      $row = json_decode($line, TRUE);
      if (is_array($row) && is_string($row['subject'] ?? NULL)) {
        $subjects[] = $row['subject'];
      }
    }
    $this->assertContains('web/themes/custom/x.css', $subjects);
    $this->assertContains('composer require drupal/webform', $subjects);
    $this->assertContains('curl', $subjects, 'a flag stops the subject');
    $this->assertContains('deploy', $subjects, 'an assignment is skipped, a long word stops it');
    $this->assertStringNotContainsString('s3cr3t', implode(' ', $subjects));
    $this->assertStringNotContainsString('abc123', implode(' ', $subjects));
  }

  /**
   * The installed package is droost itself, run or no run.
   */
  public function testThePackageIsAlwaysProtected(): void {
    $root = $this->project();
    [$exit, , $stderr] = $this->write($root, 'tools/droost/vendor/droost/workflow/pack/hooks/droost-workflow-guard.php');
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('droost itself', $stderr);
    [$exit] = $this->shell($root, 'rm -rf tools/droost');
    $this->assertSame(2, $exit);
    [$exit] = $this->write($root, '.claude/settings.json');
    $this->assertSame(2, $exit, 'the wiring stays protected');
  }

  /**
   * A wall is asked before a matching call, and its answer stands.
   */
  public function testWallRefusesAndAllows(): void {
    $root = $this->project("walls:\n  tracker: { tools: '^mcp__tracker__(create|edit)', run: droost/walls/tracker.php }\n");
    mkdir($root . '/droost/walls', 0755, TRUE);
    file_put_contents($root . '/droost/walls/tracker.php', '<?php
      $call = json_decode((string) stream_get_contents(STDIN), TRUE);
      file_put_contents(getenv("DROOST_PROJECT_ROOT") . "/asked.json", json_encode($call));
      if (!is_file(getenv("DROOST_PROJECT_ROOT") . "/armed")) {
        fwrite(STDERR, "tracker writes are not armed; the OPERATOR arms them");
        exit(2);
      }
      exit(0);');

    [$exit, , $stderr] = $this->tool($root, 'mcp__tracker__create_issue', ['summary' => 'x']);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('tracker writes are not armed', $stderr);
    $asked = json_decode((string) file_get_contents($root . '/asked.json'), TRUE);
    $this->assertIsArray($asked);
    $this->assertSame('mcp__tracker__create_issue', $asked['tool'] ?? NULL);
    $this->assertSame('mcp__tracker__create_issue', $asked['tool_name'] ?? NULL);
    $this->assertSame(['summary' => 'x'], $asked['input'] ?? NULL);
    $this->assertSame($root, $asked['project_root'] ?? NULL);

    touch($root . '/armed');
    [$exit] = $this->tool($root, 'mcp__tracker__create_issue', ['summary' => 'x']);
    $this->assertSame(0, $exit, 'armed, the wall allows');
    unlink($root . '/armed');
    [$exit] = $this->tool($root, 'mcp__tracker__get_issue', ['id' => 1]);
    $this->assertSame(0, $exit, 'a read the regex does not name is not asked');
  }

  /**
   * A wall that cannot answer refuses: its silence is never consent.
   */
  public function testBrokenWallRefuses(): void {
    $root = $this->project("walls:\n  w: { tools: '^mcp__x__', run: droost/walls/w.php }\n  gone: { tools: '^mcp__y__', run: droost/walls/missing.php }\n");
    mkdir($root . '/droost/walls', 0755, TRUE);
    file_put_contents($root . '/droost/walls/w.php', '<?php throw new \RuntimeException("boom");');

    [$exit, , $stderr] = $this->tool($root, 'mcp__x__do', []);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('could not answer', $stderr);
    [$exit, , $stderr] = $this->tool($root, 'mcp__y__do', []);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('not there', $stderr);
  }

  /**
   * An operator-only script is refused from the agent's shell, however run.
   */
  public function testOperatorOnlyScriptsAreTheOperators(): void {
    $root = $this->project("operator_only: [droost/bin/jira-writes]\n");
    $commands = [
      'droost/bin/jira-writes on',
      './droost/bin/jira-writes on',
      'php droost/bin/jira-writes on',
      'timeout 5 bash droost/bin/jira-writes off',
      $root . '/droost/bin/jira-writes on',
    ];
    foreach ($commands as $command) {
      [$exit, , $stderr] = $this->shell($root, $command);
      $this->assertSame(2, $exit, $command);
      $this->assertStringContainsString('operator-only', $stderr);
    }
    [$exit] = $this->shell($root, 'cat droost/bin/jira-writes');
    $this->assertSame(0, $exit, 'reading it is not running it');
  }

  /**
   * A project with droost/droost.yml and a guard-protected baseline.
   *
   * @param string $levers
   *   Extra lever lines.
   *
   * @return string
   *   The root.
   */
  private function project(string $levers = ''): string {
    $root = $this->makeRoot();
    file_put_contents(Levers::path($root), "preset: custom\n" . $levers);
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    return $root;
  }

  /**
   * Opens a run in its code phase.
   *
   * @param string $root
   *   The root.
   */
  private function openRun(string $root): void {
    file_put_contents($root . '/droost/droost-workflow/run.json', (string) json_encode([
      'run_id' => 'run-guard',
      'current_phase' => 'code',
      'phases' => ['plan' => 'passed', 'code' => 'active'],
      'enforcement' => 'hard',
      'mode' => 'agentic',
    ]));
  }

  /**
   * A Write to a project path.
   *
   * @param string $root
   *   The root.
   * @param string $file
   *   The project-relative path.
   *
   * @return array{int, string, string}
   *   Exit, stdout, stderr.
   */
  private function write(string $root, string $file): array {
    return $this->guard($root, 'pre-tool-use', [
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $root . '/' . $file, 'content' => 'x'],
    ]);
  }

  /**
   * A shell command.
   *
   * @param string $root
   *   The root.
   * @param string $command
   *   The command.
   *
   * @return array{int, string, string}
   *   Exit, stdout, stderr.
   */
  private function shell(string $root, string $command): array {
    return $this->guard($root, 'operator-commands', ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]]);
  }

  /**
   * A call to another tool, through the recording registration.
   *
   * @param string $root
   *   The root.
   * @param string $tool
   *   The tool's name.
   * @param array<string, mixed> $input
   *   Its input.
   *
   * @return array{int, string, string}
   *   Exit, stdout, stderr.
   */
  private function tool(string $root, string $tool, array $input): array {
    return $this->guard($root, 'record', ['tool_name' => $tool, 'tool_input' => $input]);
  }

}
