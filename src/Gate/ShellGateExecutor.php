<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Gate;

use Drupal\droost_workflow\Config\GateSettings;

/**
 * Runs a gate by spawning the consuming repo's own tool.
 *
 * The dispatch shape is droost's VerifyRunner: resolve the binary, build an
 * argv array, run it rooted at the project with a timeout, turn the exit code
 * and output into a result. Mirrored rather than imported — droost is a
 * Drupal module, and depending on it would drag a booted site into the
 * surface that by definition has none. The duplication is accepted and
 * recorded; a shared verify library is a later conversation.
 *
 * Argv arrays throughout, never a shell string. Every value that reaches one
 * came through GateSettings, which constrains tool arguments to characters no
 * shell would interpret — but building the command as a list means no future
 * lever can reintroduce that risk by being less careful.
 */
final class ShellGateExecutor implements GateExecutorInterface {

  /**
   * How long a gate may run before it is killed, in seconds.
   */
  public const DEFAULT_TIMEOUT = 600;

  /**
   * Constructs a ShellGateExecutor.
   *
   * @param callable(list<string>, string, int): array{int, string, string} $runner
   *   Runs argv in a directory with a timeout, returning exit code, stdout
   *   and stderr. Injected so tests can drive the argv and parsing logic
   *   without a real subprocess, and so the one place this package spawns
   *   anything is visible.
   * @param callable(): int $clock
   *   Returns milliseconds. Injected for the same reason no value object
   *   reads a clock: a report whose durations move is a report that cannot be
   *   compared.
   * @param int $timeout
   *   Seconds before a gate is killed.
   */
  public function __construct(
    private readonly mixed $runner,
    private readonly mixed $clock,
    private readonly int $timeout = self::DEFAULT_TIMEOUT,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function execute(GateSettings $gate, string $projectRoot): GateResult {
    $root = rtrim($projectRoot, '/');
    $binary = $this->binaryFor($gate->name);
    $argv = $this->argvFor($gate, $root . '/' . $binary);
    $invocation = implode(' ', $argv);

    if (!is_file($root . '/' . $binary)) {
      return GateResult::toolMissing($gate->name, $invocation);
    }

    $started = $this->tick();
    /** @var array{int, string, string} $outcome */
    $outcome = ($this->runner)($argv, $root, $this->timeout);
    [$exit, $stdout, $stderr] = $outcome;
    $elapsed = $this->tick() - $started;

    if ($gate->name === 'coverage') {
      return $this->coverageVerdict(
        $gate,
        $exit,
        $stdout,
        $stderr,
        $elapsed,
        $invocation,
      );
    }

    return GateResult::ran(
      $gate->name,
      $exit === 0 ? GateStatus::Passed : GateStatus::Failed,
      $exit,
      $elapsed,
      $this->summarise($gate->name, $exit, $stdout, $stderr),
      $this->findings($stdout),
      $invocation,
    );
  }

  /**
   * The coverage gate's verdict, which the exit code alone cannot give.
   *
   * PHPUnit has no --min-coverage option — the previous argv invented one,
   * so the gate failed on an unknown-option error whenever it was enabled
   * and the factory preset's coverage gate could never pass. The threshold
   * is enforced HERE instead: run the suite with a text coverage report,
   * parse the Lines percentage, and compare it to the gate's own floor.
   *
   * This is the one deliberate exception to "the exit code decides the
   * verdict". Three cases, three different answers:
   * - a non-zero exit is a failing suite, and fails before coverage is even
   *   a question;
   * - exit zero with a parsable percentage is measured coverage, judged
   *   against the floor;
   * - exit zero with NO percentage means nothing measured anything — no
   *   coverage driver is installed — and an environment that cannot run the
   *   gate it was told to run is broken, not lenient: error-tool-missing,
   *   which blocks.
   *
   * @param \Drupal\droost_workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param int $exit
   *   The exit code.
   * @param string $stdout
   *   Standard output, carrying the coverage summary.
   * @param string $stderr
   *   Standard error.
   * @param int $elapsed
   *   Milliseconds spent.
   * @param string $invocation
   *   The command that ran.
   *
   * @return \Drupal\droost_workflow\Gate\GateResult
   *   The verdict.
   */
  private function coverageVerdict(
    GateSettings $gate,
    int $exit,
    string $stdout,
    string $stderr,
    int $elapsed,
    string $invocation,
  ): GateResult {
    if ($exit !== 0) {
      return GateResult::ran(
        $gate->name,
        GateStatus::Failed,
        $exit,
        $elapsed,
        $this->summarise($gate->name, $exit, $stdout, $stderr),
        $this->findings($stdout),
        $invocation,
      );
    }

    if (preg_match('/^\s*Lines:\s+([0-9.]+)%/m', $stdout, $matches) !== 1) {
      return GateResult::toolMissing(
        $gate->name,
        $invocation . ' — the suite passed but no coverage was measured; '
        . 'a code coverage driver (xdebug or pcov) is not installed',
      );
    }

    $measured = (float) $matches[1];
    $min = $gate->option('min');
    $floor = is_int($min) ? $min : 0;
    $satisfied = $measured >= (float) $floor;

    return GateResult::ran(
      $gate->name,
      $satisfied ? GateStatus::Passed : GateStatus::Failed,
      $exit,
      $elapsed,
      sprintf(
        'coverage %.1f%% %s min %d%%',
        $measured,
        $satisfied ? 'meets' : 'is under',
        $floor,
      ),
      [],
      $invocation,
    );
  }

  /**
   * The binary a gate runs, relative to the project root.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return string
   *   The relative path.
   */
  private function binaryFor(string $gate): string {
    return 'vendor/bin/' . match ($gate) {
      'coverage' => 'phpunit',
      'mutation' => 'infection',
      default => $gate,
    };
  }

  /**
   * The command a gate runs.
   *
   * @param \Drupal\droost_workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $binary
   *   The absolute path to the tool.
   *
   * @return list<string>
   *   The argv array.
   */
  private function argvFor(GateSettings $gate, string $binary): array {
    $standard = $gate->option('standard');
    $level = $gate->option('level');
    $msi = $gate->option('msi_min');

    return match ($gate->name) {
      'phpcs' => [
        $binary,
        '-q',
        '--report=json',
        '--standard=' . (is_string($standard) ? $standard : 'Drupal'),
      ],
      'phpstan' => [
        $binary,
        'analyse',
        '--no-progress',
        '--error-format=json',
        '--level=' . (string) ($level ?? 'max'),
      ],
      'phpunit' => [$binary, '--no-progress'],
      // No threshold flag: phpunit has no --min-coverage option. The floor
      // is enforced by coverageVerdict(), from the parsed summary.
      'coverage' => [
        $binary,
        '--no-progress',
        '--coverage-text',
        '--only-summary-for-coverage-text',
      ],
      'mutation' => [
        $binary,
        '--no-progress',
        '--min-msi=' . (string) ($msi ?? 0),
      ],
      default => [$binary],
    };
  }

  /**
   * A human-readable line for a finished gate.
   *
   * @param string $gate
   *   The gate name.
   * @param int $exit
   *   The exit code.
   * @param string $stdout
   *   Standard output.
   * @param string $stderr
   *   Standard error.
   *
   * @return string
   *   The summary.
   */
  private function summarise(
    string $gate,
    int $exit,
    string $stdout,
    string $stderr,
  ): string {
    if ($exit === 0) {
      return $gate . ' passed';
    }
    // Prefer stderr's first line: a tool that failed to start says why there,
    // while stdout is often a machine format nobody wants in a summary.
    $line = strtok(trim($stderr) !== '' ? $stderr : $stdout, "\n");
    return sprintf(
      '%s failed (exit %d)%s',
      $gate,
      $exit,
      $line === FALSE ? '' : ': ' . $line,
    );
  }

  /**
   * Structured findings, when the tool emitted JSON.
   *
   * Parsing is best-effort by design: a tool that changed its output format
   * should cost the report its detail, not its verdict. The exit code decides
   * pass or fail, always.
   *
   * @param string $stdout
   *   Standard output.
   *
   * @return list<array<string, mixed>>
   *   The findings, or an empty list.
   */
  private function findings(string $stdout): array {
    if (trim($stdout) === '') {
      return [];
    }
    try {
      $decoded = json_decode($stdout, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    if (!is_array($decoded)) {
      return [];
    }

    $out = [];
    foreach ($decoded as $key => $value) {
      if (is_array($value)) {
        $out[] = ['key' => (string) $key, 'detail' => $value];
      }
    }
    return $out;
  }

  /**
   * The current millisecond count, from the injected clock.
   *
   * @return int
   *   Milliseconds.
   */
  private function tick(): int {
    /** @var int $now */
    $now = ($this->clock)();
    return $now;
  }

}
