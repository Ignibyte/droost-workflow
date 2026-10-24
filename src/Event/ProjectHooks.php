<?php

declare(strict_types=1);

namespace Droost\Workflow\Event;

use Droost\Workflow\Cli\CliProcess;
use Droost\Workflow\Config\RuleFiles;

/**
 * Tells the project's hook files about the run's lifecycle events.
 *
 * `droost/droost.yml` names a file per event (`hooks: { run_start: … }`).
 * Each runs as its own process from the project root — `php <file>` for a
 * .php file, the file itself otherwise — with the event as JSON on stdin and
 * DROOST_PROJECT_ROOT and DROOST_HOOK in its environment. A hook NOTIFIES:
 * whatever it does, the run goes on. Its outcome is recorded, one row per
 * call, in `hook-calls.jsonl` beside the run, so a hook that failed is on the
 * record rather than lost; anything that must hold a phase is a gate.
 */
final class ProjectHooks {

  /**
   * The record of hook calls, in the state directory.
   */
  public const FILENAME = 'hook-calls.jsonl';

  /**
   * How long a hook may run, in seconds.
   */
  public const TIMEOUT = 30;

  /**
   * The engine's event types, mapped to the hook events a project names.
   */
  public const EVENTS = [
    'run.started' => 'run_start',
    'phase.began' => 'phase_begin',
    'phase.ended' => 'phase_end',
    'run.completed' => 'run_complete',
  ];

  /**
   * Constructs the hooks for one project.
   *
   * @param string $projectRoot
   *   The project root.
   * @param string $stateDir
   *   The run's state directory, where the record is written.
   * @param array<string, string> $hooks
   *   Hook event => project-relative file (WorkflowConfig::$hooks).
   * @param (\Closure(list<string>, string, int): array{int, string, string})|null $runner
   *   Runs a process; NULL runs it for real.
   */
  public function __construct(
    private readonly string $projectRoot,
    private readonly string $stateDir,
    private readonly array $hooks,
    private readonly ?\Closure $runner = NULL,
  ) {}

  /**
   * Runs the hook for one engine event, if the project names one.
   *
   * Never throws and never blocks: a hook is told, not asked.
   *
   * @param string $type
   *   The engine's event type (`run.started`, …).
   * @param array<string, mixed> $event
   *   What the hook is told: the run, the phase, the event's payload.
   * @param array<string, string|null> $frozen
   *   The run's frozen rule-file hashes, so a hook changed since the run
   *   began is named in its record.
   * @param string $at
   *   When, ISO-8601.
   *
   * @return array<string, mixed>|null
   *   The recorded row, or NULL when no hook is named for the event.
   */
  public function fire(string $type, array $event, array $frozen, string $at): ?array {
    $name = self::EVENTS[$type] ?? NULL;
    $file = $name === NULL ? NULL : ($this->hooks[$name] ?? NULL);
    if ($name === NULL || $file === NULL) {
      return NULL;
    }
    $row = ['event' => $name, 'file' => $file, 'run' => $event['run_id'] ?? NULL, 'at' => $at];
    try {
      $row += $this->run($name, $file, $event);
    }
    catch (\Throwable $e) {
      $row += ['ok' => FALSE, 'exit' => NULL, 'note' => 'the hook could not be started: ' . $e->getMessage()];
    }
    if (RuleFiles::changed($this->projectRoot, $frozen, $file)) {
      $row['changed_since_begin'] = TRUE;
    }
    $line = json_encode($row, JSON_UNESCAPED_SLASHES);
    if ($line !== FALSE && is_dir($this->stateDir)) {
      @file_put_contents(rtrim($this->stateDir, '/') . '/' . self::FILENAME, $line . "\n", FILE_APPEND | LOCK_EX);
    }
    return $row;
  }

  /**
   * Runs one hook file.
   *
   * @param string $name
   *   The hook event.
   * @param string $file
   *   The project-relative file.
   * @param array<string, mixed> $event
   *   The JSON it is handed.
   *
   * @return array{ok: bool, exit: int|null, note: string, said?: bool}
   *   The outcome.
   */
  private function run(string $name, string $file, array $event): array {
    $root = rtrim($this->projectRoot, '/');
    $path = $root . '/' . $file;
    $php = str_ends_with($file, '.php');
    if (!is_file($path)) {
      return ['ok' => FALSE, 'exit' => NULL, 'note' => 'the file is not there; nothing was told'];
    }
    if (!$php && !is_executable($path)) {
      return ['ok' => FALSE, 'exit' => NULL, 'note' => 'the file is neither .php nor executable; nothing was told'];
    }
    $input = tempnam(sys_get_temp_dir(), 'droost-hook-');
    if ($input === FALSE || @file_put_contents($input, (string) json_encode(['event' => $name, 'project_root' => $root] + $event, JSON_UNESCAPED_SLASHES)) === FALSE) {
      return ['ok' => FALSE, 'exit' => NULL, 'note' => 'its input could not be written'];
    }
    $env = 'DROOST_PROJECT_ROOT="$2" DROOST_HOOK="$3" exec';
    $argv = $php
      ? ['/bin/sh', '-c', $env . ' "$0" "$4" < "$1"', PHP_BINARY, $input, $root, $name, $path]
      : ['/bin/sh', '-c', $env . ' "$0" < "$1"', $path, $input, $root, $name];
    try {
      [$exit, $stdout, $stderr] = $this->runner !== NULL
        ? ($this->runner)($argv, $root, self::TIMEOUT)
        : CliProcess::run($argv, $root, self::TIMEOUT);
    }
    finally {
      @unlink($input);
    }
    $said = trim($stderr !== '' ? $stderr : $stdout);
    return [
      'ok' => $exit === 0,
      'exit' => $exit,
      'note' => mb_substr($said === '' ? ($exit === 0 ? 'told' : 'exited ' . $exit) : strtok($said, "\n"), 0, 300),
      // Whether the hook said anything: the agent is shown only what it said,
      // or that it failed (a live run's read "told" at every phase).
      'said' => $said !== '',
    ];
  }

}
