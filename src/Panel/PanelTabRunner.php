<?php

declare(strict_types=1);

namespace Droost\Workflow\Panel;

use Droost\Workflow\Cli\CliProcess;

/**
 * Runs a project's panel tab file and cleans what it prints.
 *
 * A tab is a file in the project's `droost/` folder, named in droost.yml
 * (`panel.tabs.<id>.run`), run as its own process like a gate, hook or wall
 * file: `.php` with this PHP, `.ts`, `.mts`, `.js` or `.mjs` with node (which
 * strips TypeScript's types itself), anything else when it is executable. It
 * is handed JSON on stdin (the project root, the tab's id, the open run or
 * none) and prints one JSON object: `{"badge": "3/4", "sections": [...]}`
 * in the shapes PanelSections takes.
 *
 * A tab that cannot run, runs out of time or prints something else says so
 * in its place, in words: a panel that drew nothing would look like a tab
 * with nothing to say.
 */
final class PanelTabRunner {

  /**
   * Constructs a PanelTabRunner.
   *
   * @param string $projectRoot
   *   The project root.
   * @param (callable(list<string>, string, int): array{int, string, string})|null $runner
   *   Runs a process (argv, cwd, timeout); CliProcess::run when NULL.
   */
  public function __construct(
    private readonly string $projectRoot,
    private readonly mixed $runner = NULL,
  ) {}

  /**
   * Runs one tab.
   *
   * @param string $id
   *   The tab's id.
   * @param array{title: string, run: string, timeout: int} $tab
   *   The tab as droost.yml declares it.
   * @param array<string, mixed>|null $run
   *   The open run's summary, or NULL.
   *
   * @return array{badge: string|null, sections: list<array<string, mixed>>, error: string|null}
   *   What the panel draws.
   */
  public function run(string $id, array $tab, ?array $run): array {
    $root = rtrim($this->projectRoot, '/');
    $path = $root . '/' . $tab['run'];
    if (!is_file($path)) {
      return self::failed(sprintf('%s is not there', $tab['run']));
    }
    $argv = self::argv($path);
    if ($argv === NULL) {
      return self::failed(sprintf('%s is not .php, .ts or .js, and not executable', $tab['run']));
    }
    $input = json_encode([
      'schema' => 'droost.panel-tab/1',
      'project_root' => $root,
      'tab' => $id,
      'run' => $run,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $file = tempnam(sys_get_temp_dir(), 'droost-tab-');
    if ($file === FALSE || !is_string($input) || @file_put_contents($file, $input) === FALSE) {
      return self::failed('its input could not be written');
    }
    try {
      [$exit, $stdout, $stderr] = ($this->runner ?? CliProcess::run(...))(
        // $0 is the input file, $1 the root; what follows is the command.
        ['/bin/sh', '-c', 'r="$1"; shift; DROOST_PROJECT_ROOT="$r" exec "$@" < "$0"', $file, $root, ...$argv],
        $root,
        $tab['timeout'],
      );
    }
    finally {
      @unlink($file);
    }
    if ($exit !== 0) {
      $said = trim($stderr !== '' ? $stderr : $stdout);
      return self::failed(sprintf(
        '%s %s%s',
        $tab['run'],
        $exit === 124 ? 'ran out of time' : 'exited ' . $exit,
        $said === '' ? '' : ': ' . mb_substr((string) strtok($said, "\n"), 0, 200),
      ));
    }
    $document = json_decode(trim($stdout), TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return self::failed(sprintf('%s printed something that is not JSON', $tab['run']));
    }
    $cleaned = PanelSections::clean($document);

    return [
      'badge' => $cleaned['badge'],
      'sections' => $cleaned['sections'],
      'error' => $cleaned['dropped'] === [] ? NULL : sprintf('%s: dropped %s', $tab['run'], implode('; ', $cleaned['dropped'])),
    ];
  }

  /**
   * How to run a tab file, or NULL when it cannot be run.
   *
   * @param string $path
   *   The file's absolute path.
   *
   * @return list<string>|null
   *   The argv.
   */
  private static function argv(string $path): ?array {
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($extension === 'php') {
      return [PHP_BINARY, $path];
    }
    if (in_array($extension, ['ts', 'mts', 'js', 'mjs', 'cjs'], TRUE)) {
      $node = getenv('DROOST_NODE');
      return [is_string($node) && $node !== '' ? $node : 'node', $path];
    }

    return is_executable($path) ? [$path] : NULL;
  }

  /**
   * A tab that could not be drawn, with why.
   *
   * @param string $why
   *   The reason, in words.
   *
   * @return array{badge: null, sections: list<array<string, mixed>>, error: string}
   *   The tab.
   */
  private static function failed(string $why): array {
    return ['badge' => NULL, 'sections' => [], 'error' => $why];
  }

}
