<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Config\GateSettings;

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
   * What counts as analysable, per gate that accepts a `paths` lever.
   *
   * Used only to tell "this path holds nothing for the tool" from "the tool
   * found problems": phpstan errors out on a path set with no PHP in it, and
   * that exit code would otherwise read as a failing gate on every repo whose
   * custom-code directories are still empty. phpcs's set is wider because
   * the Drupal standard genuinely sniffs css and js.
   */
  private const ANALYSABLE = [
    'phpcs' => [
      'php', 'module', 'install', 'inc', 'theme', 'profile', 'engine',
      'css', 'js',
    ],
    'phpstan' => [
      'php', 'module', 'install', 'inc', 'theme', 'profile', 'engine',
    ],
  ];

  /**
   * Directory names that hold vendored code, never the project's own.
   *
   * A theme with a build step keeps `node_modules/` on disk; a module with
   * its own composer.json keeps `vendor/`. Neither is committed, neither is
   * the work under review, and the Drupal standard sniffs the JavaScript in
   * the first (R24-F3, round 24). Skipped when deciding whether a path holds
   * anything analysable, and passed to phpcs as an ignore pattern.
   */
  private const VENDORED_DIRS = ['node_modules', 'vendor'];

  /**
   * The same exclusion as a phpcs `--ignore` pattern list.
   */
  private const VENDORED_IGNORE = '*/node_modules/*,*/vendor/*';

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
    if (GateSettings::isCustom($gate->name)) {
      return $this->executeCustom($gate, $root);
    }
    $binary = $this->binaryFor($gate->name);
    $argv = $this->argvFor($gate, $root . '/' . $binary);
    // NULL when the gate carries no paths lever (the tool discovers the
    // repo's own config); a list otherwise — possibly empty, see below.
    $scoped = $this->scopedPaths($gate, $root);
    if (is_array($scoped)) {
      $argv = array_merge($argv, $scoped);
    }
    $invocation = implode(' ', $argv);

    if (!is_file($root . '/' . $binary)) {
      // A missing tool outranks an empty scope: the environment being broken
      // is true whether or not there is anything to analyse yet.
      return GateResult::toolMissing($gate->name, $invocation);
    }

    if (in_array($gate->name, ['phpunit', 'coverage'], TRUE)
      && !is_file($root . '/phpunit.xml')
      && !is_file($root . '/phpunit.xml.dist')) {
      // A test run is defined by its config file (bootstrap, env, suites);
      // running bare phpunit against a root with neither would let the tool
      // error and the error read as a failing suite. Config-missing is the
      // same honesty class as tool-missing: the environment cannot run the
      // gate it was told to run. On a Drupal site,
      // `drush droost:workflow:install` writes the file this looks for.
      return GateResult::toolMissing(
        $gate->name,
        $invocation
        . ' (no phpunit.xml or phpunit.xml.dist at the project root)',
      );
    }

    if ($scoped === []) {
      // Every configured path is absent or holds nothing the tool reads.
      // Running anyway would make phpstan's "no files found" error read as a
      // failing gate on a repo whose custom-code directories are still
      // empty. A pass that SAYS it analysed nothing is the honest verdict —
      // and it is labeled, so it can never be mistaken for a clean scan.
      return GateResult::ran(
        $gate->name,
        GateStatus::Passed,
        0,
        0,
        sprintf(
          '%s passed — the configured paths (%s) contain nothing to analyse',
          $gate->name,
          (string) $gate->option('paths'),
        ),
        [],
        $invocation,
      );
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

    if ($gate->name === 'phpunit'
      && $exit === 0
      && str_contains($stdout, 'No tests executed')) {
      // The config exists and the runner worked; there is simply nothing to
      // run yet. A labeled pass, so it can never be mistaken for a clean
      // suite — and the first test the test phase writes hardens this gate
      // with no lever touched.
      return GateResult::ran(
        $gate->name,
        GateStatus::Passed,
        0,
        $elapsed,
        'phpunit passed — NO TESTS RAN. Either this project has no tests yet, or its suite stopped being discovered; the gate cannot tell those apart, so read this as unverified rather than as a pass.',
        [],
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
   * Runs a custom gate: the repo's own command, exit zero passes.
   *
   * The command is a single line from the repo's own lever file — the same
   * trust boundary as a composer script, reviewed in the same diff as every
   * other lever. It runs through the shell because that is the contract a
   * "cmd" key advertises; there is no binary path to pre-check, so the
   * shell's own 127 ("command not found") maps to tool-missing — an enabled
   * gate whose tool is absent is a broken environment, never a pass.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $root
   *   The project root (already trimmed).
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function executeCustom(GateSettings $gate, string $root): GateResult {
    $cmd = $gate->option('cmd');
    $cmd = is_string($cmd) ? $cmd : '';
    $started = $this->tick();
    /** @var array{int, string, string} $outcome */
    $outcome = ($this->runner)(['/bin/sh', '-c', $cmd], $root, $this->timeout);
    [$exit, $stdout, $stderr] = $outcome;
    $elapsed = $this->tick() - $started;

    if ($exit === 127) {
      return GateResult::toolMissing($gate->name, $cmd);
    }

    return GateResult::ran(
      $gate->name,
      $exit === 0 ? GateStatus::Passed : GateStatus::Failed,
      $exit,
      $elapsed,
      $this->summarise($gate->name, $exit, $stdout, $stderr),
      $this->findings($stdout),
      $cmd,
    );
  }

  /**
   * The gate's analysis paths, resolved against what actually exists.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $root
   *   The project root (already trimmed).
   *
   * @return list<string>|null
   *   NULL when the gate carries no paths lever; otherwise the configured
   *   paths that exist and hold at least one file the tool reads — possibly
   *   an empty list, which the caller reports as a labeled pass.
   */
  private function scopedPaths(GateSettings $gate, string $root): ?array {
    $extensions = self::ANALYSABLE[$gate->name] ?? NULL;
    $paths = $gate->option('paths');
    if ($extensions === NULL || !is_string($paths) || $paths === '') {
      return NULL;
    }
    $scoped = [];
    foreach (explode(',', $paths) as $path) {
      $path = trim($path);
      if ($path !== '' && $this->hasAnalysable($root . '/' . $path, $extensions)) {
        $scoped[] = $path;
      }
    }
    return $scoped;
  }

  /**
   * Whether a path holds at least one file with one of these extensions.
   *
   * @param string $path
   *   An absolute file or directory path.
   * @param list<string> $extensions
   *   Lower-case extensions without the dot.
   *
   * @return bool
   *   TRUE when something analysable is there.
   */
  private function hasAnalysable(string $path, array $extensions): bool {
    if (is_file($path)) {
      return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), $extensions, TRUE);
    }
    if (!is_dir($path)) {
      return FALSE;
    }
    try {
      // Vendored trees (node_modules, vendor) are not the project's code: a
      // directory holding nothing else is "nothing to analyse" (R24-F3).
      $files = new \RecursiveIteratorIterator(
        new \RecursiveCallbackFilterIterator(
          new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
          static fn (\SplFileInfo $current): bool => !($current->isDir() && in_array($current->getFilename(), self::VENDORED_DIRS, TRUE)),
        ),
      );
    }
    catch (\UnexpectedValueException) {
      return FALSE;
    }
    foreach ($files as $file) {
      if ($file instanceof \SplFileInfo
        && $file->isFile()
        && in_array(strtolower($file->getExtension()), $extensions, TRUE)) {
        return TRUE;
      }
    }
    return FALSE;
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
   * @param \Droost\Workflow\Config\GateSettings $gate
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
   * @return \Droost\Workflow\Gate\GateResult
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
    return self::binaryPathFor($gate);
  }

  /**
   * The binary a named gate runs, relative to the project root.
   *
   * Public and static because status surfaces render toolchain rows from
   * this exact mapping — the reported probe and the executed path must be
   * the same fact, never two implementations.
   *
   * @param string $gate
   *   The gate name (a named gate; custom gates run their own cmd).
   *
   * @return string
   *   The relative path.
   */
  public static function binaryPathFor(string $gate): string {
    // Playwright is an npm tool: it never appears under vendor/bin, and
    // until 0.4 this mapping pointed there — a gate that could only ever
    // report tool-missing on every repo in existence.
    if ($gate === 'playwright') {
      return 'node_modules/.bin/playwright';
    }
    return 'vendor/bin/' . match ($gate) {
      'coverage' => 'phpunit',
      'mutation' => 'infection',
      // The wiki gate asks the SITE whether its own documentation is current,
      // so it runs through drush. On a checkout with no drush the executor
      // reports toolMissing rather than a pass — which is the honest answer:
      // nothing was checked.
      'wiki_fresh' => 'drush',
      default => $gate,
    };
  }

  /**
   * The command a gate runs.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
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
        // A theme with a build step keeps node_modules/ on disk (never
        // committed), and the Drupal standard sniffs JS and CSS — so the gate
        // walked vendored JavaScript and reported on it (round 24, R24-F3).
        // Vendored trees are never the project's code; exclude them always.
        '--ignore=' . self::VENDORED_IGNORE,
      ],
      'phpstan' => [
        $binary,
        'analyse',
        '--no-progress',
        '--error-format=json',
        '--level=' . (string) ($level ?? 'max'),
        // Not a lever: phpstan inherits php.ini's memory_limit (routinely
        // 128M), and level max over a real module crashes its workers there.
        // Found dogfooding against droost — 336 files OOMed the gate while
        // the repo's own lint script passed the same flag all along.
        '--memory-limit=1G',
      ],
      // Drush exits non-zero when any page is stale, orphaned or invalid, so
      // the gate needs no parsing — the command IS the verdict.
      'wiki_fresh' => [$binary, 'droost:wiki:status'],
      // The empty-suite flag (PHPUnit >= 10; core-dev ships 11.5) turns "no
      // tests yet" into exit zero, which execute() then LABELS rather than
      // reporting as a clean suite — the same honesty shape as the static
      // pair's nothing-to-analyse pass. The flag stays: failing a fresh site
      // that has not written a test yet would be wrong. But the LABEL used to
      // presume the benign cause ("no tests yet"), and a suite that stopped
      // being discovered is indistinguishable from one that never existed —
      // raised by a live run's own seeker as "a suite that stopped being
      // discovered would report green". The gate cannot tell the two apart,
      // so it now says so instead of guessing which one you are.
      'phpunit' => [
        $binary,
        '--no-progress',
        '--do-not-fail-on-empty-test-suite',
      ],
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
      // `playwright test` is the suite runner; bare `playwright` prints
      // usage and exits zero, which would read as a pass with no tests run.
      'playwright' => [$binary, 'test'],
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
