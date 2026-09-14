<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Shared scaffolding for tests that need a project directory on disk.
 *
 * The package's whole contract is about files — a lever file at a repo root
 * and run state beside it — so most tests need a real directory rather than a
 * mock filesystem.
 */
abstract class WorkflowTestCase extends TestCase {

  /**
   * Temporary roots created by this test, removed on teardown.
   *
   * @var list<string>
   */
  private array $roots = [];

  /**
   * Creates an empty project root.
   *
   * @return string
   *   The absolute path.
   */
  protected function makeRoot(): string {
    $root = sys_get_temp_dir() . '/dwf-test-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0755, TRUE) && !is_dir($root)) {
      $this->fail('Could not create a temporary project root.');
    }
    $this->roots[] = $root;
    return $root;
  }

  /**
   * Creates a project root holding a lever file.
   *
   * @param string $yaml
   *   The file's contents.
   *
   * @return string
   *   The absolute path to the root.
   */
  protected function makeRootWithConfig(string $yaml): string {
    $root = $this->makeRoot();
    file_put_contents($root . '/droost.workflow.yml', $yaml);
    $this->writeSpec($root);
    return $root;
  }

  /**
   * Writes a minimal governing spec that satisfies the phase contract.
   *
   * Every facade test that advances a run needs one, because the contract is
   * real: leaving plan requires the tooling plan, and gating complete
   * requires the realized capture. A test that must exercise the REFUSALS
   * removes or rewrites this file rather than the other way round — the
   * satisfied state is the common case, the violation is the special one.
   *
   * @param string $root
   *   The project root.
   * @param bool $realized
   *   Whether the capture section is present (TRUE for full walks).
   *
   * @return string
   *   The spec path, project-relative.
   */
  protected function writeSpec(string $root, bool $realized = TRUE): string {
    $dir = $root . '/droost/droost-workflow';
    if (!is_dir($dir)) {
      mkdir($dir, 0755, TRUE);
    }
    $body = "# Spec: test run\n\n## Tooling plan\n\n- everything: hand-written (fixture)\n";
    // A well-formed spec grounds before it proposes, at plan and at code, in
    // all three tiers. The fixture carries it because the contract is not
    // optional — a test that advanced without it would be asserting against a
    // spec no real run may use.
    $body .= "\n## Grounding\n\n"
      . "| Phase | Tier | Asked | Found |\n"
      . "|---|---|---|---|\n"
      . "| plan | custom | fixture lookup | fixture answer |\n"
      . "| plan | contrib | fixture lookup | fixture answer |\n"
      . "| plan | core | fixture lookup | fixture answer |\n"
      . "| code | custom | fixture lookup | fixture answer |\n"
      . "| code | contrib | fixture lookup | fixture answer |\n"
      . "| code | core | fixture lookup | fixture answer |\n";
    if ($realized) {
      $body .= "\n## Realized\n\nFixture capture.\n";
    }
    file_put_contents($dir . '/spec-test-run.md', $body);
    return 'droost/droost-workflow/spec-test-run.md';
  }

  /**
   * The names of any temporary files left in a run's state directory.
   *
   * @param string $root
   *   The project root.
   *
   * @return list<string>
   *   Basenames of anything matching *.tmp.
   */
  protected function tempResidue(string $root): array {
    $found = glob($root . '/droost/droost-workflow/*.tmp');
    return array_map(basename(...), $found === FALSE ? [] : $found);
  }

  /**
   * Removes everything the test created.
   */
  protected function tearDown(): void {
    foreach ($this->roots as $root) {
      $this->removeTree($root);
    }
    $this->roots = [];
    parent::tearDown();
  }

  /**
   * Recursively deletes a directory.
   *
   * @param string $path
   *   The path to remove.
   */
  private function removeTree(string $path): void {
    if (is_link($path)) {
      unlink($path);
      return;
    }
    if (!is_dir($path)) {
      if (is_file($path)) {
        unlink($path);
      }
      return;
    }
    // A test may have made a directory read-only on purpose.
    @chmod($path, 0755);
    $entries = scandir($path);
    foreach ($entries === FALSE ? [] : $entries as $entry) {
      if ($entry !== '.' && $entry !== '..') {
        $this->removeTree($path . '/' . $entry);
      }
    }
    @rmdir($path);
  }

  /**
   * Executes the packed guard exactly as Claude Code would.
   *
   * @param string $root
   *   The project root, delivered as CLAUDE_PROJECT_DIR — the way Claude Code
   *   runs the hook, and what the guard resolves its run state against.
   * @param string $mode
   *   The guard mode: pre-tool-use or stop.
   * @param array<string, mixed> $payload
   *   The hook payload delivered on stdin.
   * @param string|null $cwd
   *   The working directory to run from, when it must differ from the project
   *   root (the agent's Bash tool can move it — R27-F1). Defaults to $root.
   *
   * @return array{int, string, string}
   *   Exit code, stdout, stderr.
   */
  protected function guard(string $root, string $mode, array $payload, ?string $cwd = NULL): array {
    $script = dirname(__DIR__) . '/pack/hooks/droost-workflow-guard.php';
    // Set CLAUDE_PROJECT_DIR explicitly so the fixture root wins over any value
    // in the environment that runs the suite, and so cwd and the project root
    // can be driven apart to exercise the moved-cwd case.
    $env = getenv();
    $env['CLAUDE_PROJECT_DIR'] = $root;
    $process = proc_open(
      [PHP_BINARY, $script, $mode],
      [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
      $pipes,
      $cwd ?? $root,
      $env,
    );
    $this->assertIsResource($process);
    fwrite($pipes[0], (string) json_encode($payload));
    fclose($pipes[0]);

    // BOTH PIPES, INTERLEAVED. Draining stdout to EOF and only then reading
    // stderr deadlocks the moment the guard writes more to stderr than the
    // OS pipe buffer holds — 64KB on this platform. The guard blocks in
    // `fwrite(STDERR, ...)`, this process blocks in `stream_get_contents()`
    // on stdout, and neither moves again: the suite HANGS rather than failing,
    // which is the one outcome a test harness must never produce.
    //
    // Found by mutating the guard's own summary cap away — a change that
    // should have failed one assertion and instead wedged phpunit at 0% CPU
    // until it was killed. The same shape as the recursive-CTE hang this file
    // already guards against, arriving through the harness instead of the
    // store. A host that reads the hook's streams in this order hangs the
    // same way, so the guard's cap is the real fix; this is what makes the
    // NEXT one visible.
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);
    $buffers = ['' , ''];
    $open = [1 => $pipes[1], 2 => $pipes[2]];
    while ($open !== []) {
      $read = array_values($open);
      $write = NULL;
      $except = NULL;
      if (stream_select($read, $write, $except, 10) === FALSE) {
        break;
      }
      if ($read === []) {
        // Ten seconds with neither stream saying anything: the guard is not
        // coming back. Kill it and let the exit-code assertion below report a
        // crash, rather than waiting for a timeout nobody can attribute.
        proc_terminate($process);
        break;
      }
      foreach ($read as $stream) {
        $chunk = fread($stream, 65536);
        $slot = $stream === $pipes[1] ? 0 : 1;
        if ($chunk === FALSE || $chunk === '') {
          if (feof($stream)) {
            unset($open[$slot === 0 ? 1 : 2]);
          }
          continue;
        }
        $buffers[$slot] .= $chunk;
      }
    }
    [$stdout, $stderr] = $buffers;
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    // A CRASH IS NOT AN ANSWER. The guard speaks in two exit codes — 0 allows,
    // 2 blocks — and anything else is a PHP error, which a host reads as "not a
    // block". A top-level `const` added to this file was not hoisted the way a
    // function declaration is, so every operator-command check died with an
    // uncaught Error and exited 255; the shell probe used to find it asked "is
    // the code 2?" and reported that as ALLOWED. Enforcement was off for an
    // hour and the probe said it was working.
    //
    // Asserted here rather than in each test, so a test cannot pass by reading
    // a crash as permission.
    $this->assertContains(
      $code,
      [0, 2],
      sprintf(
        "The guard exited %d, which is neither allow (0) nor block (2) — it "
        . "crashed. stderr:\n%s",
        $code,
        $stderr,
      ),
    );

    return [$code, $stdout, $stderr];
  }

}
