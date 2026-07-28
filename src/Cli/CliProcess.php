<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Cli;

/**
 * The only place this package spawns a subprocess.
 *
 * Kept as one small named class rather than a closure inside the dispatcher
 * so that "what can this package execute, and how" is answerable by opening
 * a single file.
 *
 * argv is always an array — never a shell string, and never passed through a
 * shell. Combined with GateSettings constraining tool arguments to characters
 * no shell would interpret, that makes command injection structurally absent
 * rather than merely unlikely.
 */
final class CliProcess {

  /**
   * How often to check a running process, in microseconds.
   */
  private const POLL_INTERVAL = 20_000;

  /**
   * Runs a command and waits for it.
   *
   * @param list<string> $argv
   *   The command and its arguments. Not a shell string.
   * @param string $cwd
   *   The working directory.
   * @param int $timeout
   *   Seconds before the process is killed.
   *
   * @return array{int, string, string}
   *   Exit code, standard output, standard error. A timeout returns exit
   *   code 124, matching the convention `timeout(1)` uses, with a note on
   *   stderr — a killed gate is a failed gate, and the report should say why
   *   rather than showing a bare non-zero.
   */
  public static function run(array $argv, string $cwd, int $timeout): array {
    $descriptors = [
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open($argv, $descriptors, $pipes, $cwd);

    if (!is_resource($process)) {
      return [127, '', 'could not start ' . ($argv[0] ?? '(no command)')];
    }

    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(TRUE) + $timeout;
    $timedOut = FALSE;

    while (TRUE) {
      $stdout .= (string) stream_get_contents($pipes[1]);
      $stderr .= (string) stream_get_contents($pipes[2]);

      $status = proc_get_status($process);
      if ($status['running'] !== TRUE) {
        break;
      }
      if (microtime(TRUE) > $deadline) {
        proc_terminate($process, 9);
        $timedOut = TRUE;
        break;
      }
      usleep(self::POLL_INTERVAL);
    }

    // Drain whatever landed between the last read and the process ending.
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    if ($timedOut) {
      return [
        124,
        $stdout,
        trim($stderr . "\nkilled after {$timeout}s"),
      ];
    }
    return [$exit, $stdout, $stderr];
  }

}
