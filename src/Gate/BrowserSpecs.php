<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;

/**
 * What a project's Playwright browser specs are: one answer, read everywhere.
 *
 * A name is not enough (F-402). `*.test.jsx` is a Playwright name and a
 * Vitest one: a project that unit-tests its React with Vitest had a module's
 * `js/src/*.test.jsx` held by `spec_chosen` after the human declined a saved
 * spec, and the browser gate at ticket scope would have handed it to
 * `playwright test`. The project's Vitest gate owns that
 * file. Playwright discovers specs under its `testDir`, so a spec is a file
 * with a spec's name inside the directories that hold the project's specs:
 *
 *   1. `gates.playwright.test_dir`, when the lever file sets it;
 *   2. else `testDir` in the project's `playwright.config.*` (the gate runs
 *      `playwright test` at the project root, where Playwright finds it), as
 *      a string or `path.join(__dirname, '…')`;
 *   3. else, or when that directory is the project itself (`testDir: '.'`),
 *      the name alone, as before.
 *
 * Read by `spec_chosen` (TestsChoiceCheck), by the subject code's gates
 * measure (RunSubject), and by the browser gate's ticket scope and its count
 * (ShellGateExecutor), so the four cannot disagree about a file.
 */
final class BrowserSpecs {

  /**
   * A spec's file name, by the names Playwright discovers by default.
   */
  public const NAME = '/\.(spec|test)\.[cm]?[jt]sx?$/';

  /**
   * The config files Playwright looks for, in its own order.
   */
  public const CONFIGS = [
    'playwright.config.ts',
    'playwright.config.js',
    'playwright.config.mts',
    'playwright.config.mjs',
    'playwright.config.cts',
    'playwright.config.cjs',
  ];

  /**
   * Constructs a BrowserSpecs.
   *
   * @param list<string> $dirs
   *   Project-relative directories that hold the specs, or none for the name
   *   alone.
   * @param string $from
   *   Where the directories were read, for a reader.
   */
  private function __construct(
    public readonly array $dirs,
    public readonly string $from,
  ) {}

  /**
   * The project's specs.
   *
   * @param string $projectRoot
   *   The project root.
   * @param \Droost\Workflow\Config\GateSettings|null $playwright
   *   The browser gate's resolved levers; NULL reads them from the lever
   *   file.
   *
   * @return self
   *   The definition.
   */
  public static function of(string $projectRoot, ?GateSettings $playwright = NULL): self {
    $root = rtrim($projectRoot, '/');
    $lever = ($playwright ?? self::levers($root))?->option('test_dir');
    if (is_string($lever) && trim($lever) !== '') {
      return self::within(explode(',', $lever), 'gates.playwright.test_dir');
    }
    foreach (self::CONFIGS as $config) {
      if (is_file($root . '/' . $config)) {
        $dirs = self::testDirs((string) @file_get_contents($root . '/' . $config));
        return $dirs === NULL ? self::nameOnly() : self::within($dirs, $config . ' testDir');
      }
    }

    return self::nameOnly();
  }

  /**
   * Whether a project-relative path is one of the project's specs.
   *
   * @param string $path
   *   The path, relative to the project.
   *
   * @return bool
   *   TRUE for a spec.
   */
  public function isSpec(string $path): bool {
    $path = self::normal($path);
    if (preg_match(self::NAME, $path) !== 1) {
      return FALSE;
    }
    if ($this->dirs === []) {
      return TRUE;
    }
    foreach ($this->dirs as $dir) {
      if (str_starts_with($path, $dir . '/')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * The specs among some paths.
   *
   * @param list<string> $paths
   *   Project-relative paths.
   *
   * @return list<string>
   *   Those that are specs, in their order.
   */
  public function among(array $paths): array {
    return array_values(array_filter($paths, $this->isSpec(...)));
  }

  /**
   * Where the specs are, for a summary.
   *
   * @return string
   *   For example "under tests/e2e (playwright.config.ts testDir)".
   */
  public function describe(): string {
    return $this->dirs === []
      ? 'by file name (' . $this->from . ')'
      : 'under ' . implode(', ', $this->dirs) . ' (' . $this->from . ')';
  }

  /**
   * The definition that reads the name alone.
   *
   * @return self
   *   The definition.
   */
  private static function nameOnly(): self {
    return new self([], 'no Playwright test directory known');
  }

  /**
   * The definition for some directories.
   *
   * @param list<string> $dirs
   *   Directories relative to the project, as written.
   * @param string $from
   *   Where they were read.
   *
   * @return self
   *   The definition: the name alone when one of them is the project itself.
   */
  private static function within(array $dirs, string $from): self {
    $kept = [];
    foreach ($dirs as $dir) {
      $dir = self::normal($dir);
      if ($dir === '') {
        return new self([], $from . ' is the project root');
      }
      $kept[] = $dir;
    }

    return new self(array_values(array_unique($kept)), $from);
  }

  /**
   * Every `testDir` a Playwright config sets, or NULL when one is unreadable.
   *
   * Read as text, never run: a literal string, or `path.join(__dirname, …)`
   * / `path.resolve(__dirname, …)` with literal parts. A config with no
   * `testDir` searches its own directory, the project root. A `testDir` in
   * another shape (a variable, an absolute or traversing path) leaves the
   * answer unknown, and unknown keeps every spec-named file a spec: a
   * declined spec must never pass because droost could not read the config.
   *
   * @param string $config
   *   The config's source.
   *
   * @return list<string>|null
   *   The directories, relative to the project.
   */
  private static function testDirs(string $config): ?array {
    // Comments are not config: a commented-out testDir names nothing. Only a
    // `//` that opens its line is taken as a comment, so a URL in a string
    // survives.
    $code = (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', $config);
    $keys = preg_match_all('/\btestDir\s*:/', $code);
    if ($keys === 0) {
      return ['.'];
    }
    $string = '([\'"`])([^\'"`$]*)\1';
    $parts = '\s*(?:,\s*([\'"`])([^\'"`$]*)\3\s*)*';
    $found = preg_match_all(
      '/\btestDir\s*:\s*(?:' . $string . '|path\.(?:join|resolve)\(\s*__dirname' . $parts . '\))/',
      $code,
      $matches,
      PREG_SET_ORDER,
    );
    if ($found !== $keys) {
      return NULL;
    }
    $dirs = [];
    foreach ($matches as $match) {
      // A literal: its quote is the first group.
      if (($match[1] ?? '') !== '') {
        $dirs[] = $match[2] ?? '';
        continue;
      }
      // path.join(__dirname, 'a', 'b'): every literal part, in order.
      preg_match_all('/([\'"`])([^\'"`$]*)\1/', $match[0], $literals);
      $dirs[] = implode('/', $literals[2]);
    }
    foreach ($dirs as $dir) {
      if (str_starts_with($dir, '/') || preg_match('#(^|/)\.\.(/|$)#', $dir) === 1) {
        return NULL;
      }
    }

    return $dirs;
  }

  /**
   * The browser gate's levers from the lever file, or none.
   *
   * A lever file that cannot be read is the gate runner's to report; here it
   * only means no `test_dir`.
   *
   * @param string $root
   *   The project root.
   *
   * @return \Droost\Workflow\Config\GateSettings|null
   *   The gate's levers.
   */
  private static function levers(string $root): ?GateSettings {
    try {
      return WorkflowConfig::load($root)->gate('playwright');
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * A path without `./`, `.` segments, doubled or edge slashes.
   *
   * @param string $path
   *   A project-relative path.
   *
   * @return string
   *   The path, '' for the project itself.
   */
  private static function normal(string $path): string {
    $parts = array_filter(
      explode('/', str_replace('\\', '/', trim($path))),
      static fn (string $part): bool => $part !== '' && $part !== '.',
    );

    return implode('/', $parts);
  }

}
