<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The parity gate reads its runner's verdict; no reference is no pass.
 *
 * `bin/droost-parity judge` ends its output with one JSON line. Each status
 * it can print has one reading here: pass, fail (with every route that
 * differs as a finding), nothing to compare (a labelled pass, or a failure
 * when a reference is required), no browser (reported), and anything else
 * the tool failing. The last test runs the real runner, so the shape read
 * here is the shape it prints.
 */
#[CoversClass(ShellGateExecutor::class)]
final class ParityGateTest extends TestCase {

  /**
   * No reference captured: a labelled pass, and the runner is never started.
   */
  public function testNoReferenceIsLabelledPassWithoutRunningAnything(): void {
    $ran = [];
    $result = $this->verdict(0, '', [], FALSE, $ran);

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertTrue($result->labelledPass, 'a gate that compared no page must say it measured nothing');
    $this->assertStringContainsString('no reference in droost/parity', $result->summary);
    $this->assertSame([], $ran, 'with nothing to compare, Node must not be spawned to say so');
  }

  /**
   * Every route as its reference is a measured pass.
   */
  public function testPassIsMeasured(): void {
    $result = $this->verdict(0, $this->line([
      'status' => 'pass',
      'summary' => '2 route(s) as the reference',
      'routes' => [
        ['route' => '/', 'verdict' => 'PASS', 'failing' => []],
        ['route' => '/camps', 'verdict' => 'PASS', 'failing' => []],
      ],
    ]));

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertFalse($result->labelledPass);
    $this->assertSame('parity passed — 2 route(s) as the reference', $result->summary);
  }

  /**
   * A route that differs fails, and names what differed on which route.
   */
  public function testFailureNamesEachRouteThatDiffers(): void {
    $failing = [['id' => 'D3', 'verdict' => 'FAIL', 'said' => '41 of 60 pairs share the type']];
    $result = $this->verdict(1, "/: as the reference\n" . $this->line([
      'status' => 'fail',
      'summary' => '1 of 2 route(s) differ from the reference: /camps D3',
      'routes' => [
        ['route' => '/', 'verdict' => 'PASS', 'failing' => []],
        ['route' => '/camps', 'verdict' => 'FAIL', 'failing' => $failing],
      ],
    ]));

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('/camps D3', $result->summary);
    $this->assertCount(1, $result->findings);
    $this->assertSame('/camps', $result->findings[0]['key'] ?? NULL);
    $this->assertSame($failing, $result->findings[0]['detail'] ?? NULL);
  }

  /**
   * A required reference that is missing fails the gate.
   */
  public function testRequiredReferenceMissingFails(): void {
    $ran = [];
    $nothing = $this->line([
      'status' => 'nothing',
      'summary' => 'no reference to compare with in droost/parity',
    ]);
    $result = $this->verdict(3, $nothing, [], TRUE, $ran);

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('required', $result->summary);
    $this->assertCount(1, $ran, 'required: the runner is asked, and its answer decides');
  }

  /**
   * No Playwright in the project is reported, as the browser suite's is.
   */
  public function testNoBrowserIsReported(): void {
    $result = $this->verdict(127, $this->line([
      'status' => 'no-browser',
      'summary' => 'no Playwright at node_modules/playwright',
    ]));

    $this->assertSame(GateStatus::Reported, $result->status);
    $this->assertStringContainsString('no Playwright', $result->summary);
  }

  /**
   * No Node: the shell's 127 with no verdict is reported, not a broken tool.
   */
  public function testNoNodeIsReported(): void {
    $result = $this->verdict(127, '');

    $this->assertSame(GateStatus::Reported, $result->status);
    $this->assertStringContainsString('no Node', $result->summary);
  }

  /**
   * A page that could not be judged is the tool failing, never a pass.
   */
  public function testInvalidRouteIsToolFailure(): void {
    $result = $this->verdict(2, $this->line([
      'status' => 'invalid',
      'summary' => '1 of 1 route(s) could not be judged: /contact',
      'routes' => [
        [
          'route' => '/contact',
          'verdict' => 'INVALID',
          'failing' => [['id' => 'D1', 'verdict' => 'INVALID', 'said' => 'status 0']],
        ],
      ],
    ]));

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertStringContainsString('/contact', $result->summary);
  }

  /**
   * Output with no verdict line is the tool failing, whatever the exit code.
   */
  public function testNoVerdictLineIsToolFailure(): void {
    $result = $this->verdict(0, "Error: Cannot find module\n");

    $this->assertSame(GateStatus::ErrorToolFailed, $result->status);
    $this->assertStringContainsString('no verdict', $result->summary);
  }

  /**
   * The reference and scope levers reach the command the runner is given.
   */
  public function testLeversReachTheCommand(): void {
    $ran = [];
    $pass = $this->line(['status' => 'pass', 'summary' => '1 route(s)']);
    $this->verdict(0, $pass, ['reference' => 'design/refs', 'scope' => 'frame'], FALSE, $ran);

    $this->assertSame(
      ['vendor/bin/droost-parity', 'judge', '--reference', 'design/refs', '--scope', 'frame', '--json'],
      array_map(fn (string $arg): string => $this->relative($arg), $ran[0] ?? []),
    );
  }

  /**
   * The real runner, with nothing to compare, prints the shape read above.
   */
  public function testTheRunnerPrintsTheVerdictLineTheGateReads(): void {
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));
    if ($node === '') {
      $this->markTestSkipped('no Node on this machine to run bin/droost-parity with');
    }
    $runner = dirname(__DIR__, 2) . '/bin/droost-parity';
    $this->assertFileExists($runner);
    $this->assertTrue(is_executable($runner), 'composer installs the runner as a bin, so it must be executable');
    $empty = $this->root();
    $output = [];
    $exit = -1;
    exec('cd ' . escapeshellarg($empty) . ' && ' . escapeshellarg($runner) . ' judge --reference droost/parity --json 2>&1', $output, $exit);

    $this->assertSame(3, $exit, implode("\n", $output));
    $last = json_decode((string) end($output), TRUE);
    $this->assertIsArray($last);
    $this->assertIsArray($last['parity'] ?? NULL);
    $this->assertSame('nothing', $last['parity']['status'] ?? NULL);
  }

  /**
   * Runs the gate over a project whose runner prints the given output.
   *
   * @param int $exit
   *   The exit code the runner returns.
   * @param string $stdout
   *   What it prints.
   * @param array<string, bool|int|string> $levers
   *   The gate's levers besides `required`.
   * @param bool $required
   *   The gate's `required` lever.
   * @param list<list<string>> $ran
   *   Receives each argv the runner was given.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function verdict(int $exit, string $stdout, array $levers = [], bool $required = FALSE, array &$ran = []): GateResult {
    $root = $this->root();
    mkdir($root . '/vendor/bin', 0755, TRUE);
    file_put_contents($root . '/vendor/bin/droost-parity', "#!/bin/sh\nexit 0\n");
    chmod($root . '/vendor/bin/droost-parity', 0755);
    // The pre-check looks for a manifest; a test of the runner's verdicts
    // needs one, and the no-reference test needs none.
    if ($stdout !== '' || $exit !== 0) {
      $reference = is_string($levers['reference'] ?? NULL) ? $levers['reference'] : 'droost/parity';
      mkdir($root . '/' . $reference, 0755, TRUE);
      file_put_contents($root . '/' . $reference . '/manifest.json', '{"routes": ["/"]}');
    }
    $executor = new ShellGateExecutor(
      static function (array $argv) use ($exit, $stdout, &$ran): array {
        $ran[] = $argv;
        return [$exit, $stdout, ''];
      },
      static fn (): int => 0,
    );

    return $executor->execute(new GateSettings('parity', TRUE, ['required' => $required] + $levers), $root);
  }

  /**
   * The runner's last line, as it prints it.
   *
   * @param array<string, mixed> $summary
   *   The verdict.
   *
   * @return string
   *   One line of JSON.
   */
  private function line(array $summary): string {
    return json_encode(['parity' => $summary], JSON_THROW_ON_ERROR) . "\n";
  }

  /**
   * A fresh project directory.
   *
   * @return string
   *   Its path.
   */
  private function root(): string {
    $root = sys_get_temp_dir() . '/parity-gate-' . bin2hex(random_bytes(4));
    mkdir($root, 0755, TRUE);
    return $root;
  }

  /**
   * An argv element with any temporary project prefix taken off.
   *
   * @param string $arg
   *   The element.
   *
   * @return string
   *   The element relative to its project.
   */
  private function relative(string $arg): string {
    return preg_replace('#^.*/parity-gate-[0-9a-f]+/#', '', $arg) ?? $arg;
  }

}
