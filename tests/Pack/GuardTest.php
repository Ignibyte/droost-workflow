<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The enforcement guard, executed for real against fixture run documents.
 *
 * The single most important behaviour is the first one: NO RUN, NO OPINION.
 * A guard that polices regular conversation is a defect worse than no guard,
 * so every path in here starts from what run.json says.
 */
final class GuardTest extends WorkflowTestCase {

  /**
   * Without an active run, both modes stay silent and allow.
   */
  public function testNoRunNoOpinion(): void {
    $root = $this->makeRoot();
    foreach (['pre-tool-use', 'stop'] as $mode) {
      [$exit, $stdout, $stderr] = $this->guard($root, $mode, []);
      $this->assertSame(0, $exit, $mode . ' must allow with no run');
      $this->assertSame('', $stdout);
      $this->assertSame('', $stderr);
    }
  }

  /**
   * Hard enforcement blocks project edits during plan; the spec passes.
   */
  public function testHardBlocksEditsDuringPlanExceptTheSpec(): void {
    $root = $this->rootWithRun('plan', 'active', 'hard');

    [$exit, , $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'src/Thing.php'],
    ]);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('PLAN', $stderr);

    [$exit, $stdout, $stderr] = $this->guard($root, 'pre-tool-use', [
      'tool_input' => ['file_path' => '.droost-workflow/spec-thing.md'],
    ]);
    $this->assertSame(0, $exit, 'the spec is plan\'s own artefact');
    $this->assertSame('', $stdout . $stderr);

    // Once the run has advanced past plan, edits are the phase's work.
    $coding = $this->rootWithRun('code', 'active', 'hard');
    [$exit] = $this->guard($coding, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'src/Thing.php'],
    ]);
    $this->assertSame(0, $exit);
  }

  /**
   * Soft enforcement warns exactly once per phase, then stays quiet.
   */
  public function testSoftWarnsOncePerPhase(): void {
    $root = $this->rootWithRun('plan', 'active', 'soft');
    $payload = ['tool_input' => ['file_path' => 'src/Thing.php']];

    [$exit, $stdout] = $this->guard($root, 'pre-tool-use', $payload);
    $this->assertSame(0, $exit, 'soft never blocks');
    $decoded = json_decode($stdout, TRUE);
    $this->assertIsArray($decoded);
    $this->assertArrayHasKey('systemMessage', $decoded);

    [$exit, $stdout] = $this->guard($root, 'pre-tool-use', $payload);
    $this->assertSame(0, $exit);
    $this->assertSame('', $stdout, 'the second warning is silence');
  }

  /**
   * Hard enforcement challenges a mid-phase stop — once.
   */
  public function testStopIsChallengedOnceThenReleased(): void {
    $root = $this->rootWithRun('code', 'active', 'hard');

    [$exit, , $stderr] = $this->guard($root, 'stop', []);
    $this->assertSame(2, $exit);
    $this->assertStringContainsString('advance it or abandon it', $stderr);

    // Claude reports the stop hook already fired: stand down, no deadlock.
    [$exit, $stdout, $stderr] = $this->guard($root, 'stop', [
      'stop_hook_active' => TRUE,
    ]);
    $this->assertSame(0, $exit);
    $this->assertSame('', $stdout . $stderr);
  }

  /**
   * Ended, failed and unenforced runs are all left alone.
   */
  public function testEndedFailedAndOffRunsAreNotPoliced(): void {
    $done = $this->rootWithRun('complete', 'passed', 'hard');
    [$exit] = $this->guard($done, 'stop', []);
    $this->assertSame(0, $exit, 'a finished run is history, not law');

    $failed = $this->rootWithRun('test', 'failed', 'hard');
    [$exit] = $this->guard($failed, 'stop', []);
    $this->assertSame(0, $exit, 'a failed run is a legitimate end');

    $off = $this->rootWithRun('plan', 'active', 'off');
    [$exit, $stdout, $stderr] = $this->guard($off, 'pre-tool-use', [
      'tool_input' => ['file_path' => 'src/Thing.php'],
    ]);
    $this->assertSame(0, $exit);
    $this->assertSame('', $stdout . $stderr, 'off means the hooks stand down');
  }

  /**
   * A project root holding an active run frozen at the given levers.
   *
   * @param string $phase
   *   The current phase.
   * @param string $status
   *   The current phase's status.
   * @param string $enforcement
   *   The frozen enforcement level.
   *
   * @return string
   *   The root path.
   */
  private function rootWithRun(
    string $phase,
    string $status,
    string $enforcement,
  ): string {
    $root = $this->makeRoot();
    mkdir($root . '/.droost-workflow', 0755, TRUE);
    file_put_contents($root . '/.droost-workflow/run.json', json_encode([
      'current_phase' => $phase,
      'phases' => [$phase => $status],
      'enforcement' => $enforcement,
    ]));
    return $root;
  }

  /**
   * Executes the packed guard exactly as Claude Code would.
   *
   * @param string $root
   *   The project root (the hook's cwd).
   * @param string $mode
   *   The guard mode: pre-tool-use or stop.
   * @param array<string, mixed> $payload
   *   The hook payload delivered on stdin.
   *
   * @return array{int, string, string}
   *   Exit code, stdout, stderr.
   */
  private function guard(string $root, string $mode, array $payload): array {
    $script = dirname(__DIR__, 2) . '/pack/hooks/droost-workflow-guard.php';
    $process = proc_open(
      [PHP_BINARY, $script, $mode],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $root,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode($payload));
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
  }

}
