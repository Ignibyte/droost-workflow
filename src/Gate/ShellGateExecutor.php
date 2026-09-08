<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Baseline\BaselineAwareExecutorInterface;
use Droost\Workflow\Baseline\BaselineContext;
use Droost\Workflow\Baseline\FindingParsers;
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
 *
 * With a baseline context (D71) the verdict of the consulting gates turns on
 * NEW findings only: the linters' output is partitioned by finding key,
 * phpstan runs through the baseline's wrapper config, prettier's unformatted
 * files are matched against the recorded list and the run's changed files,
 * and the two metric gates pass at or above their recorded floor when they
 * miss the level's target. Every such result carries both counts.
 */
final class ShellGateExecutor implements BaselineAwareExecutorInterface {

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
    // The front-end trio. Each scopes to the files its tool owns, so a path
    // with no JS/CSS reports a labeled "nothing to analyse" pass rather than
    // a tool error — the same honesty as the static PHP pair. prettier is
    // deliberately held to the JS/CSS family (not yml/json/md) so the gate
    // never reformats Drupal's own info.yml or a README.
    'eslint' => ['js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'vue'],
    'stylelint' => ['css', 'scss', 'less', 'pcss'],
    'prettier' => [
      'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'vue', 'css', 'scss', 'less',
    ],
  ];

  /**
   * Gates that must receive concrete files, never a bare directory.
   *
   * The phpcs and phpstan gates take a directory and filter by extension
   * themselves. The front-end trio do NOT: handed a directory, stylelint and
   * prettier treat every file under it as their own language — a module's
   * .yml, .php and .twig all parsed as CSS, each raising a CssSyntaxError
   * (caught live on the EMT dogfood). So for these the scope is expanded to
   * the matching files before invocation, and the tool sees only what it owns.
   */
  private const FILE_SCOPED = ['eslint', 'stylelint', 'prettier'];

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
    return $this->run($gate, $projectRoot, NULL);
  }

  /**
   * {@inheritdoc}
   */
  public function executeWithBaseline(GateSettings $gate, string $projectRoot, BaselineContext $context): GateResult {
    return $this->run($gate, $projectRoot, $context);
  }

  /**
   * Runs a gate's tool and returns its raw output, with no verdict.
   *
   * The baseline writer's entry point: the same binary resolution, argv and
   * path scoping a real gate run uses, so what is measured at adoption is
   * exactly what the gate will judge later. Extra arguments are appended
   * (phpstan's `--generate-baseline=…`, for one).
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $extraArgv
   *   Arguments appended to the gate's own.
   * @param list<string> $withoutPrefixes
   *   Argument prefixes to drop from the gate's own argv first (phpstan
   *   refuses `--error-format` beside `--generate-baseline`).
   *
   * @return array{argv: list<string>, exit: int, stdout: string, stderr: string}|null
   *   The run, or NULL when nothing could run: a custom gate (its cmd is the
   *   gate, not a tool), a missing binary, a missing suite config, or paths
   *   with nothing to analyse.
   */
  public function measure(GateSettings $gate, string $projectRoot, array $extraArgv = [], array $withoutPrefixes = []): ?array {
    $root = rtrim($projectRoot, '/');
    if (GateSettings::isCustom($gate->name)) {
      return NULL;
    }
    $prepared = $this->prepare($gate, $root);
    if (!is_file($prepared['binary']) || $prepared['scoped'] === []) {
      return NULL;
    }
    if (in_array($gate->name, ['phpunit', 'coverage'], TRUE)
      && !is_file($root . '/phpunit.xml')
      && !is_file($root . '/phpunit.xml.dist')) {
      return NULL;
    }
    $kept = array_values(array_filter(
      $prepared['argv'],
      static function (string $arg) use ($withoutPrefixes): bool {
        foreach ($withoutPrefixes as $prefix) {
          if (str_starts_with($arg, $prefix)) {
            return FALSE;
          }
        }
        return TRUE;
      },
    ));
    $argv = [...$kept, ...$extraArgv];
    /** @var array{int, string, string} $outcome */
    $outcome = ($this->runner)($argv, $root, $this->timeout);
    return ['argv' => $argv, 'exit' => $outcome[0], 'stdout' => $outcome[1], 'stderr' => $outcome[2]];
  }

  /**
   * The binary, argv and scope a named gate runs with.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $root
   *   The project root (already trimmed).
   *
   * @return array{binary: string, argv: list<string>, scoped: list<string>|null}
   *   The absolute binary path, the full argv (scope appended), and the
   *   scoped paths — NULL when the gate carries no paths lever, an empty list
   *   when every configured path holds nothing the tool reads.
   */
  private function prepare(GateSettings $gate, string $root): array {
    $binary = $this->binaryFor($gate->name);
    $argv = $this->argvFor($gate, $root . '/' . $binary);
    // NULL when the gate carries no paths lever (the tool discovers the
    // repo's own config); a list otherwise — possibly empty, see below.
    $scoped = $this->scopedPaths($gate, $root);
    if (is_array($scoped)) {
      // The front-end trio lint the files they are handed; a bare directory
      // makes them parse everything under it as their own language. Expand a
      // non-empty scope to the concrete matching files (the empty case is
      // still the labeled "nothing to analyse" pass below).
      if ($scoped !== [] && in_array($gate->name, self::FILE_SCOPED, TRUE)) {
        $scoped = $this->analysableFiles($gate, $root);
      }
      $argv = array_merge($argv, $scoped);
    }
    return ['binary' => $root . '/' . $binary, 'argv' => $argv, 'scoped' => $scoped];
  }

  /**
   * Runs a gate, against the whole tree or against a baseline.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Baseline\BaselineContext|null $context
   *   The baseline the run is held to, or NULL for none.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function run(GateSettings $gate, string $projectRoot, ?BaselineContext $context): GateResult {
    $root = rtrim($projectRoot, '/');
    if (GateSettings::isCustom($gate->name)) {
      return $this->executeCustom($gate, $root);
    }
    $prepared = $this->prepare($gate, $root);
    $binary = substr($prepared['binary'], strlen($root) + 1);
    $argv = $prepared['argv'];
    $scoped = $prepared['scoped'];
    $baseline = $context?->baseline;
    if ($baseline !== NULL && $gate->name === 'phpstan' && $baseline->phpstanWrapper() !== NULL) {
      // The wrapper includes the project's own config AND the recorded
      // baseline, so phpstan reports only what the baseline does not carry.
      // `--level` on argv still wins over the wrapper's, as it does over the
      // project's file: the level is the dial's, never the baseline's.
      $argv[] = '-c';
      $argv[] = $baseline->phpstanWrapper();
    }
    $msiFloor = $baseline?->metric('msi');
    $msiTarget = $gate->option('msi_min');
    $msiTarget = is_int($msiTarget) ? $msiTarget : 0;
    if ($msiFloor !== NULL && $gate->name === 'mutation' && $msiFloor < $msiTarget) {
      // The ratchet: infection fails the run below --min-msi, so it is told
      // the inherited floor and the summary names the level's target.
      $argv = array_map(
        static fn (string $arg): string => str_starts_with($arg, '--min-msi=') ? sprintf('--min-msi=%.2f', $msiFloor) : $arg,
        $argv,
      );
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
        $baseline?->metric('coverage'),
      );
    }

    if ($baseline !== NULL) {
      $partitioned = $this->againstBaseline($gate, $context, $root, $exit, $stdout, $stderr, $elapsed, $invocation, $msiFloor, $msiTarget);
      if ($partitioned !== NULL) {
        return $partitioned;
      }
    }

    if ($gate->name === 'phpunit'
      && $exit === 0
      && str_contains($stdout, 'No tests executed')) {
      if ($gate->option('required') === TRUE) {
        // The top of the dial: a suite that does not exist is the defect, not
        // a fresh start. `required` turns the labelled pass below into a
        // failure, so a max run cannot complete without tests that exist.
        return GateResult::ran(
          $gate->name,
          GateStatus::Failed,
          $exit,
          $elapsed,
          'phpunit FAILED — NO TESTS RAN, and this preset requires a test suite to exist (required: true). Write the tests; a run at this level cannot complete without them.',
          [],
          $invocation,
        );
      }
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

    if ($gate->name === 'phpcs' && $exit !== 0) {
      // PHP_CodeSniffer exits non-zero on WARNINGS too, so a committed
      // minified stylesheet ("file appears to be minified") read exactly like
      // a coding standards violation (round 24, R24-F7). The verdict follows
      // the structured totals: errors fail, warnings alone pass and are
      // counted in the summary so nobody mistakes the pass for a silent one.
      $totals = $this->phpcsTotals($stdout);
      if ($totals !== NULL && $totals['errors'] === 0) {
        return GateResult::ran(
          $gate->name,
          GateStatus::Passed,
          $exit,
          $elapsed,
          sprintf('phpcs passed with %d warning(s) and no errors (warnings never fail this gate; see the findings)', $totals['warnings']),
          $this->findings($stdout),
          $invocation,
        );
      }
    }

    if ($gate->name === 'playwright'
      && $gate->option('required') === TRUE
      && $exit === 0
      && (str_contains($stdout, 'No tests found') || str_contains($stderr, 'No tests found'))) {
      // `playwright test` exits non-zero on an empty suite, so this is
      // defensive — but `required` must hold even if a future runner exits
      // zero on nothing: at the top of the dial no suite is a failure.
      return GateResult::ran(
        $gate->name,
        GateStatus::Failed,
        $exit,
        $elapsed,
        'playwright FAILED — no tests found, and this preset requires a browser suite to exist (required: true).',
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
   * A consulting gate's verdict against the baseline, or NULL to fall back.
   *
   * NULL — the whole-tree verdict — when the baseline records nothing for
   * this gate, or when the tool's output is not the machine format expected
   * (a tool that could not start is judged by its exit code, as always).
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param \Droost\Workflow\Baseline\BaselineContext|null $context
   *   The baseline context (non-NULL here; typed for the caller's shape).
   * @param string $root
   *   The project root.
   * @param int $exit
   *   The tool's exit code.
   * @param string $stdout
   *   Standard output.
   * @param string $stderr
   *   Standard error.
   * @param int $elapsed
   *   Milliseconds spent.
   * @param string $invocation
   *   The command that ran.
   * @param float|null $msiFloor
   *   The inherited MSI floor, when one is recorded.
   * @param int $msiTarget
   *   The level's MSI threshold.
   *
   * @return \Droost\Workflow\Gate\GateResult|null
   *   The partitioned verdict, or NULL.
   */
  private function againstBaseline(
    GateSettings $gate,
    ?BaselineContext $context,
    string $root,
    int $exit,
    string $stdout,
    string $stderr,
    int $elapsed,
    string $invocation,
    ?float $msiFloor,
    int $msiTarget,
  ): ?GateResult {
    if ($context === NULL) {
      return NULL;
    }
    $baseline = $context->baseline;
    $name = $gate->name;

    if (in_array($name, ['phpcs', 'eslint', 'stylelint'], TRUE) && $baseline->has($name)) {
      $findings = match ($name) {
        'phpcs' => FindingParsers::phpcs($stdout, $root),
        'eslint' => FindingParsers::eslint($stdout, $root),
        default => FindingParsers::stylelint($stdout, $root),
      };
      if ($findings === [] && $exit !== 0 && $this->phpcsTotals($stdout) === NULL && trim($stdout) !== '' && !str_starts_with(trim($stdout), '[') && !str_starts_with(trim($stdout), '{')) {
        // Non-zero with no machine output: the tool did not run to a report.
        return NULL;
      }
      $new = [];
      $inherited = 0;
      $warnings = 0;
      foreach ($findings as $finding) {
        if (!$finding['error']) {
          $warnings++;
          continue;
        }
        if ($baseline->inherits($name, $finding['key'])) {
          $inherited++;
          continue;
        }
        $new[] = [
          'file' => $finding['file'],
          'line' => $finding['line'],
          'rule' => $finding['rule'],
          'message' => $finding['message'],
        ];
      }
      $status = $new === [] ? GateStatus::Passed : GateStatus::Failed;
      $summary = $new === []
        ? sprintf('%s passed — 0 new, %d inherited%s', $name, $inherited, $warnings > 0 ? sprintf(' (%d warning(s), never failing)', $warnings) : '')
        : sprintf(
          '%s FAILED — %d new error(s) the baseline does not record (%d inherited): %s:%d %s',
          $name,
          count($new),
          $inherited,
          $new[0]['file'],
          $new[0]['line'],
          $new[0]['message'],
        );
      return GateResult::ran($name, $status, $exit, $elapsed, $summary, $new, $invocation)
        ->withBaselineCounts($inherited, count($new));
    }

    if ($name === 'phpstan' && $baseline->phpstanWrapper() !== NULL) {
      $newCount = FindingParsers::phpstanErrorCount($stdout);
      if ($newCount === NULL && $exit !== 0) {
        // Not the JSON report: phpstan itself failed (config, memory). The
        // exit-code verdict says so without inventing counts.
        return NULL;
      }
      $inherited = $baseline->phpstanInheritedCount();
      $newCount ??= 0;
      $status = $exit === 0 ? GateStatus::Passed : GateStatus::Failed;
      $summary = $status === GateStatus::Passed
        ? sprintf('phpstan passed — 0 new, %d inherited', $inherited)
        : sprintf('phpstan FAILED — %d new error(s) the baseline does not record (%d inherited)', $newCount, $inherited);
      return GateResult::ran('phpstan', $status, $exit, $elapsed, $summary, $this->findings($stdout), $invocation)
        ->withBaselineCounts($inherited, $newCount);
    }

    if ($name === 'prettier' && $baseline->has('prettier')) {
      $unformatted = FindingParsers::prettierUnformatted($stdout, $stderr, $root);
      if ($unformatted === [] && $exit !== 0) {
        // Prettier failed without naming a file (a syntax error, a bad
        // config): the exit code is the verdict.
        return NULL;
      }
      $recorded = $baseline->prettierFiles();
      $inherited = [];
      $new = [];
      foreach ($unformatted as $file) {
        // A recorded file stays inherited until the run touches it: then
        // formatting it is part of the change (prettier --write is mechanical).
        if (in_array($file, $recorded, TRUE) && !$context->changed($file)) {
          $inherited[] = $file;
        }
        else {
          $new[] = $file;
        }
      }
      $status = $new === [] ? GateStatus::Passed : GateStatus::Failed;
      $summary = $new === []
        ? sprintf('prettier passed — 0 new, %d inherited unformatted file(s)', count($inherited))
        : sprintf('prettier FAILED — %d unformatted file(s) the baseline does not cover (%d inherited): %s', count($new), count($inherited), implode(', ', array_slice($new, 0, 5)));
      return GateResult::ran(
        'prettier',
        $status,
        $exit,
        $elapsed,
        $summary,
        array_map(static fn (string $file): array => ['file' => $file], $new),
        $invocation,
      )->withBaselineCounts(count($inherited), count($new));
    }

    if ($name === 'mutation' && $msiFloor !== NULL && $exit === 0) {
      $measured = FindingParsers::msiPercent($stdout);
      if ($measured !== NULL && $measured < $msiTarget) {
        return GateResult::ran(
          'mutation',
          GateStatus::Passed,
          $exit,
          $elapsed,
          sprintf(
            'mutation MSI %.1f%% is under the %d%% target but at or above the inherited floor %.1f%% (ratchet — the floor rises on refresh, never falls)',
            $measured,
            $msiTarget,
            $msiFloor,
          ),
          [],
          $invocation,
        );
      }
    }

    return NULL;
  }

  /**
   * The phpcs JSON report's totals, or NULL when the output is not that report.
   *
   * @param string $stdout
   *   Standard output.
   *
   * @return array{errors: int, warnings: int}|null
   *   The counts.
   */
  private function phpcsTotals(string $stdout): ?array {
    try {
      $decoded = json_decode($stdout, TRUE, 32, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return NULL;
    }
    $totals = is_array($decoded) && is_array($decoded['totals'] ?? NULL) ? $decoded['totals'] : NULL;
    if ($totals === NULL || !is_int($totals['errors'] ?? NULL) || !is_int($totals['warnings'] ?? NULL)) {
      return NULL;
    }
    return ['errors' => $totals['errors'], 'warnings' => $totals['warnings']];
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
   * The concrete files a file-scoped gate should analyse, root-relative.
   *
   * Walks the gate's configured paths and returns every file the tool owns
   * (by extension), skipping vendored trees — so the front-end trio see only
   * the JS/CSS under the custom-code dirs, never the .yml/.php/.twig a bare
   * directory would sweep in. A directly-named file with a matching extension
   * passes through as-is. Deterministically ordered so the invocation string
   * and findings are stable across runs.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate's resolved levers.
   * @param string $root
   *   The project root (already trimmed).
   *
   * @return list<string>
   *   Root-relative file paths, sorted.
   */
  private function analysableFiles(GateSettings $gate, string $root): array {
    $extensions = self::ANALYSABLE[$gate->name] ?? [];
    $paths = $gate->option('paths');
    $files = [];
    foreach (explode(',', is_string($paths) ? $paths : '') as $path) {
      $path = trim($path);
      if ($path === '') {
        continue;
      }
      $abs = $root . '/' . $path;
      if (is_file($abs)) {
        if (in_array(strtolower(pathinfo($abs, PATHINFO_EXTENSION)), $extensions, TRUE)) {
          $files[] = $path;
        }
        continue;
      }
      if (!is_dir($abs)) {
        continue;
      }
      try {
        $walk = new \RecursiveIteratorIterator(
          new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $current): bool => !($current->isDir() && in_array($current->getFilename(), self::VENDORED_DIRS, TRUE)),
          ),
        );
      }
      catch (\UnexpectedValueException) {
        continue;
      }
      foreach ($walk as $file) {
        if ($file instanceof \SplFileInfo
          && $file->isFile()
          && in_array(strtolower($file->getExtension()), $extensions, TRUE)) {
          $files[] = substr($file->getPathname(), strlen($root) + 1);
        }
      }
    }
    sort($files);
    return $files;
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
   * @param float|null $floor
   *   The inherited coverage floor, when a baseline records one. Below the
   *   level's target, measured coverage at or above the floor passes with the
   *   target named (the ratchet); below the floor fails.
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
    ?float $floor = NULL,
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
    $target = is_int($min) ? $min : 0;
    $satisfied = $measured >= (float) $target;

    if (!$satisfied && $floor !== NULL) {
      // The ratchet (D71 §7): a legacy repo cannot meet the level's target on
      // day one, so the inherited floor decides and the target is named.
      $aboveFloor = $measured >= $floor;
      return GateResult::ran(
        $gate->name,
        $aboveFloor ? GateStatus::Passed : GateStatus::Failed,
        $exit,
        $elapsed,
        $aboveFloor
          ? sprintf('coverage %.1f%% is under the %d%% target but at or above the inherited floor %.1f%% (ratchet — the floor rises on refresh, never falls)', $measured, $target, $floor)
          : sprintf('coverage %.1f%% fell BELOW the inherited floor %.1f%% (target %d%%) — the change lost coverage the project already had', $measured, $floor, $target),
        [],
        $invocation,
      );
    }

    return GateResult::ran(
      $gate->name,
      $satisfied ? GateStatus::Passed : GateStatus::Failed,
      $exit,
      $elapsed,
      sprintf(
        'coverage %.1f%% %s min %d%%',
        $measured,
        $satisfied ? 'meets' : 'is under',
        $target,
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
    // The npm tools never appear under vendor/bin — they live in the site's
    // node_modules/.bin. playwright and the front-end lint trio all probe
    // there; on a repo with no node toolchain the executor reports
    // tool-missing (with the message saying how to install it), never a pass.
    if (in_array($gate, ['playwright', 'eslint', 'stylelint', 'prettier'], TRUE)) {
      return 'node_modules/.bin/' . $gate;
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
      // The front-end lint trio. Exit code IS the verdict (nonzero = problems),
      // like the mandatory tools; the json format is emitted for the findings
      // detail the report renders, not for the pass/fail decision. execute()
      // appends the scoped custom-tree paths, and each tool discovers the
      // config Drupal core ships in web/core (a site may extend it).
      // NOTE: live invocation is version-sensitive and is validated on a
      // node-equipped site — eslint 9 flat-config lints a directory directly
      // (eslint 8 wanted --ext); stylelint prefers file globs, so a bare
      // directory in `paths` is refined to a glob on the first real run.
      'eslint' => [$binary, '--format=json'],
      'stylelint' => [$binary, '--formatter=json'],
      'prettier' => [$binary, '--check'],
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
