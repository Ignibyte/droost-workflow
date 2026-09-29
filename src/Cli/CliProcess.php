<?php

declare(strict_types=1);

namespace Droost\Workflow\Cli;

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
    // STDIN IS A PIPE CLOSED AT ONCE, never the caller's (F-84). With no
    // descriptor 0 the child inherited this process's stdin, and in
    // `drush mcp:server` that is the protocol pipe, open for the session.
    // infection with no config asked its setup questions there, and
    // stylelint and prettier handed no files read their code from it: each
    // blocked until the gate's timeout, and anything the client sent
    // meanwhile was the tool's to read. Nothing a gate runs takes input, so
    // every child reads an immediate end of file instead.
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open($argv, $descriptors, $pipes, $cwd);

    if (!is_resource($process)) {
      return [127, '', 'could not start ' . ($argv[0] ?? '(no command)')];
    }

    fclose($pipes[0]);
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
        // THE WHOLE TREE, deepest first (F-176). `proc_terminate()` signals
        // the direct child, and a gate's direct child is often a shell or a
        // runner: a custom gate is `/bin/sh -c <cmd>`, and playwright hands
        // its browsers to workers. Killing the parent alone left the suite
        // running, and the one in-place retry then started a second beside
        // it. PHP cannot start a child in a group of its own, so the tree is
        // read from `ps` at the moment of the kill.
        foreach (array_reverse(self::descendants($status['pid'])) as $child) {
          self::kill($child);
        }
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

  /**
   * Every process below one, breadth first.
   *
   * @param int $pid
   *   The root of the tree.
   *
   * @return list<int>
   *   Its descendants, parents before their children.
   */
  private static function descendants(int $pid): array {
    if ($pid <= 0) {
      return [];
    }
    $pipes = [];
    $ps = proc_open(['ps', '-Ao', 'pid=,ppid='], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($ps)) {
      return [];
    }
    fclose($pipes[0]);
    $listing = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($ps);
    $children = [];
    foreach (preg_split('/\R/', $listing) ?: [] as $line) {
      if (preg_match('/^\s*(\d+)\s+(\d+)\s*$/', $line, $m) === 1) {
        $children[(int) $m[2]][] = (int) $m[1];
      }
    }
    $found = [];
    $queue = [$pid];
    while ($queue !== []) {
      $parent = array_shift($queue);
      foreach ($children[$parent] ?? [] as $child) {
        if (!in_array($child, $found, TRUE) && $child !== $pid) {
          $found[] = $child;
          $queue[] = $child;
        }
      }
    }

    return $found;
  }

  /**
   * Kills one process, quietly: it may have ended already.
   *
   * @param int $pid
   *   The process.
   */
  private static function kill(int $pid): void {
    if (function_exists('posix_kill')) {
      @posix_kill($pid, 9);
      return;
    }
    $pipes = [];
    $kill = proc_open(['kill', '-9', (string) $pid], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (is_resource($kill)) {
      foreach ($pipes as $pipe) {
        fclose($pipe);
      }
      proc_close($kill);
    }
  }

}
