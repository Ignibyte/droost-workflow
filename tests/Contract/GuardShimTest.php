<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Contract;

use Droost\Workflow\Pack\PackManifest;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Each harness's hooks call the guard in the package, never a copy.
 */
final class GuardShimTest extends WorkflowTestCase {

  /**
   * The entry point runs the guard, as `droost-guard <verb>` or `guard <verb>`.
   */
  public function testTheEntryPointRunsTheGuard(): void {
    $root = $this->makeRoot();
    $entry = dirname(__DIR__, 2) . '/bin/droost-guard';
    $payload = (string) json_encode([
      'tool_name' => 'Write',
      'tool_input' => ['file_path' => $root . '/.claude/settings.json'],
    ]);
    foreach ([['pre-tool-use', '--harness=claude'], ['guard', 'pre-tool-use', '--harness=claude']] as $args) {
      [$exit, $stderr] = $this->process([PHP_BINARY, $entry, ...$args], $payload, $root);
      $this->assertSame(2, $exit, implode(' ', $args));
      $this->assertStringContainsString('wires the enforcement in', $stderr);
    }
  }

  /**
   * Init wires the entry point, anchored to the project when inside it.
   */
  public function testInitWiresThePackagesEntryPoint(): void {
    $root = $this->makeRoot();
    $package = $root . '/tools/droost/vendor/droost/workflow';
    mkdir($package . '/bin', 0755, TRUE);
    $source = dirname(__DIR__, 2) . '/pack';
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
      if (!$file instanceof \SplFileInfo || !$file->isFile()) {
        continue;
      }
      $to = $package . '/pack/' . substr($file->getPathname(), strlen($source) + 1);
      if (!is_dir(dirname($to))) {
        mkdir(dirname($to), 0755, TRUE);
      }
      copy($file->getPathname(), $to);
    }
    mkdir($root . '/.claude/hooks', 0755, TRUE);
    file_put_contents($root . '/' . PackManifest::LEGACY_GUARD, "<?php // an earlier init's copy\n");

    (new PackMaterializer($root . '/tools/droost/vendor/droost/workflow'))->init($root);

    $settings = (string) file_get_contents($root . '/.claude/settings.json');
    $this->assertStringContainsString('php \"$CLAUDE_PROJECT_DIR/tools/droost/vendor/droost/workflow/bin/droost-guard\" pre-tool-use --harness=claude', $settings);
    $this->assertSame(9, substr_count($settings, 'droost-guard'), 'nine entries, all calling the entry point');
    $this->assertFileDoesNotExist($root . '/' . PackManifest::LEGACY_GUARD, 'the copy nothing calls is gone');
  }

  /**
   * Hook commands of every vintage are recognised by mode.
   */
  public function testGuardModeReadsEveryVintage(): void {
    $this->assertSame('stop', PackManifest::guardMode('php .claude/hooks/droost-workflow-guard.php stop'));
    $this->assertSame('pre-tool-use', PackManifest::guardMode('php "$CLAUDE_PROJECT_DIR/.claude/hooks/droost-workflow-guard.php" pre-tool-use'));
    $this->assertSame('record', PackManifest::guardMode('php "/x/bin/droost-guard" record --harness=claude'));
    $this->assertSame('nudge', PackManifest::guardMode('php "/x/bin/droost-guard" guard nudge --harness=claude'));
    $this->assertNull(PackManifest::guardMode('php my-own-linter.php stop'));
  }

  /**
   * Runs a process with stdin.
   *
   * @param list<string> $argv
   *   The command.
   * @param string $stdin
   *   Its input.
   * @param string $root
   *   The project root, as CLAUDE_PROJECT_DIR.
   *
   * @return array{int, string}
   *   Exit code and stderr.
   */
  private function process(array $argv, string $stdin, string $root): array {
    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $root;
    $pipes = [];
    $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, $env);
    $this->assertIsResource($process);
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($process), $stderr];
  }

}
