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
   * The exit code a runner reports when it killed the tool at the timeout.
   *
   * GNU timeout's convention, which the drush and CLI runners follow.
   */
  public const EXIT_KILLED = 124;

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
   * DROOST'S OWN INSTALLED FILES ARE NOT THE PROJECT'S CODE.
   *
   * `init` writes twenty-one files, among them a long procedural hook
   * that carries no autoloader by design, and comments that run past eighty
   * columns on purpose. With no `phpcs.xml` in a new repository the gate scans
   * `.` — so a reviewer ran `init` on a pristine project holding one clean PHP
   * class and watched the mandatory phpcs gate fail, three attempts, terminal,
   * on 1 error and 8 warnings in a file droost had just installed:
   *
   *   BEFORE init   rc=0
   *   AFTER  init   .claude/hooks/droost-workflow-guard.php  1 error, 8 warn
   *
   * phpcs cannot be turned off, the standalone CLI has no `gate-waive`, and
   * neither `init`'s output nor the README names a ruleset as a prerequisite.
   * So a first-time user lost their first run on a repository where they had
   * written nothing wrong — which is the worst possible first impression and
   * entirely droost's doing.
   *
   * A tool judging the tool's own installed files is a category error however
   * clean those files are. The run record and the baseline go with them: they
   * are data droost writes, not code anybody reviews.
   */
  private const DROOST_OWN_DIRS = [
    '.claude',
    'droost/droost-workflow',
    'droost/baseline',
    '.droost-workflow',
  ];

  /**
   * The same exclusion as a phpcs `--ignore` pattern list.
   */
  private const VENDORED_IGNORE = '*/node_modules/*,*/vendor/*,*/.claude/*,'
    . '*/droost/droost-workflow/*,*/droost/baseline/*,*/.droost-workflow/*';

  /**
   * Shell gates whose tool being absent means "no site", not "broken setup".
   *
   * `GateRunner::SITE_GATES` is about gates that need a booted kernel
   * IN-PROCESS, and it dispatches them to the site driver. This is the other
   * kind: an ordinary shell command that happens to ask a site. No drush on a
   * bare checkout is not a misconfigured environment — it is the same absence
   * `rendered_check` reports, and it must give the same answer, because
   * `skipped-no-site` lets a phase advance and `error-tool-missing` does not.
   *
   * @var list<string>
   */
  private const array SITE_SHELL_GATES = ['wiki_fresh'];

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
   * How long this gate's tool may run: its `timeout` lever, else the default.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate.
   *
   * @return int
   *   Seconds.
   */
  private function timeoutFor(GateSettings $gate): int {
    $timeout = $gate->option('timeout');
    return is_int($timeout) && $timeout > 0 ? $timeout : $this->timeout;
  }

  /**
   * {@inheritdoc}
   */
  /**
   * What the last spawned tool wrote, for attaching to whichever result won.
   *
   * A property rather than a return value because `run()` has twenty-two exit
   * points — every one a legitimate early verdict — and threading two more
   * arguments through all of them to carry the same two strings would be a
   * worse trade than one field written once where the process is unpacked.
   */
  private string $lastStdout = '';

  /**
   * Companion to $lastStdout.
   */
  private string $lastStderr = '';

  /**
   * A result carrying the output of the run that produced it.
   *
   * Cleared after attaching so a gate that never spawned anything — off,
   * tool-missing — cannot inherit the previous gate's transcript and make a
   * reader believe something ran.
   *
   * @param \Droost\Workflow\Gate\GateResult $result
   *   The verdict.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict, with its output.
   */
  private function withLastOutput(GateResult $result): GateResult {
    $stdout = $this->lastStdout;
    $stderr = $this->lastStderr;
    $this->lastStdout = '';
    $this->lastStderr = '';

    return $stdout === '' && $stderr === '' ? $result : $result->withOutput($stdout, $stderr);
  }

  /**
   * {@inheritdoc}
   */
  public function execute(GateSettings $gate, string $projectRoot): GateResult {
    return $this->withLastOutput($this->run($gate, $projectRoot, NULL));
  }

  /**
   * {@inheritdoc}
   */
  public function executeWithBaseline(GateSettings $gate, string $projectRoot, BaselineContext $context): GateResult {
    return $this->withLastOutput($this->run($gate, $projectRoot, $context));
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
    if ($gate->runsOwnCommand()) {
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
    $outcome = ($this->runner)($argv, $root, $this->timeoutFor($gate));
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
    $argv = $this->argvFor($gate, $root . '/' . $binary, $root);
    // NULL when the gate carries no paths lever (the tool discovers the
    // repo's own config); a list otherwise — possibly empty, see below.
    $scoped = $this->scopedPaths($gate, $root);
    if (is_array($scoped) && $scoped !== []) {
      // REPLACES the default subject, which is what the comment beside the
      // `.` in `argvFor()` has always claimed and what `array_merge` never
      // did. The observed command was:
      //
      //   phpcs -q --report=json . --standard=PSR12 … src tests
      //
      // — the whole project AND the scope, so the lever an operator sets to
      // narrow a gate widened it instead. 935 violations came back, 876 of
      // them in `.claude/hooks/droost-workflow-guard.php`, a file `init` had
      // installed; the run failed terminally, and the obvious next step
      // (phpcbf on what was reported) would have rewritten droost's own hook.
      $argv = array_values(array_filter(
        $argv,
        static fn (string $argument): bool => $argument !== '.',
      ));
    }
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
    if ($gate->runsOwnCommand()) {
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
      // A gate whose tool IS a site, asked on a checkout that has none.
      // `wiki_fresh` runs `drush droost:wiki:status`, so no drush here means no
      // site to ask — which is the same answer `rendered_check` gives, and it
      // must not be a different one. As `toolMissing` it BLOCKED, at complete,
      // which is the last phase; levers freeze at begin so turning it off then
      // does not help; and the only way out was `reset --force` and redoing
      // three phases. No standalone run could finish at the levers `init`
      // itself writes.
      //
      // The first attempt at this moved the gate into `GateRunner::SITE_GATES`,
      // which dispatches to the site driver — and `DrupalSiteDriver` names
      // three gates, so on a real site it became `toolMissing` there instead.
      // Same wall, worse surface. The question belongs here, where the binary
      // is looked for.
      if (in_array($gate->name, self::SITE_SHELL_GATES, TRUE)) {
        return GateResult::skippedNoSite($gate->name);
      }
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
        sprintf(
          'phpunit IS installed — what is missing is its configuration, so '
          . '`composer require --dev phpunit/phpunit` will change nothing. '
          . 'Write a phpunit.xml (or phpunit.xml.dist) at the project root '
          . 'naming the bootstrap and the test suites; on a Drupal site '
          . '`drush droost:workflow:install` writes one for you. If this '
          . 'project has no suite yet, that is the OPERATOR\'s call: '
          . '`gates.%s.on: false` in droost.workflow.yml.',
          $gate->name,
        ),
      );
    }

    if ($scoped === []) {
      // Every configured path is absent or holds nothing the tool reads.
      // Running anyway would make phpstan's "no files found" error read as a
      // failing gate on a repo whose custom-code directories are still
      // empty. A pass that SAYS it analysed nothing is the honest verdict —
      // and it is labeled, so it can never be mistaken for a clean scan.
      return GateResult::labelledPass(
        $gate->name,
        0,
        0,
        sprintf(
          '%s passed — the configured paths (%s) contain nothing to analyse',
          $gate->name,
          (string) $gate->option('paths'),
        ),
        $invocation,
      );
    }

    $started = $this->tick();
    /** @var array{int, string, string} $outcome */
    $outcome = ($this->runner)($argv, $root, $this->timeoutFor($gate));
    [$exit, $stdout, $stderr] = $outcome;
    $this->lastStdout = $stdout;
    $this->lastStderr = $stderr;
    $elapsed = $this->tick() - $started;

    if ($exit === self::EXIT_KILLED) {
      // The runner killed the tool at the gate's timeout. Not a verdict on
      // the code either: nothing was judged. The line names the lever that
      // raises it, because the number is the whole finding (F-EMT-23: a
      // fixed ten minutes lost the race against infection every time).
      return GateResult::toolFailed(
        $gate->name,
        $exit,
        sprintf('killed after %ds — the gate\'s timeout', $this->timeoutFor($gate)),
        sprintf('raise gates.%s.timeout (seconds) in droost.workflow.yml, or narrow what the tool scans; the tool produced no verdict.', $gate->name),
        $invocation,
      );
    }

    if (self::toolFailedToRun($gate->name, $exit, $stdout, $stderr)) {
      // The tool itself broke — a config it could not load, a crash before
      // it read a file. Not a verdict on the code: a failing lint names
      // files, a crash names the environment. Reported as such, with the
      // tool's own words and what to do about it, and it still fails closed.
      return GateResult::toolFailed(
        $gate->name,
        $exit,
        self::causeLine($stderr, $stdout),
        self::toolFailedHint($gate->name),
        $invocation,
      );
    }

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

    // PHP_CodeSniffer 4 exits 16 for "No files were checked". Nothing was
    // judged, so nothing failed — the same labeled pass this executor gives
    // every other empty path set.
    //
    // This MUST precede the baseline partition. againstBaseline() bails out
    // only on non-empty stdout, so on a project carrying a phpcs baseline an
    // exit 16 was partitioned into "passed — 0 new, 0 inherited": a confident
    // verdict over a run that checked no files at all, which is the exact
    // failure this branch exists to prevent.
    // phpstan, handed nothing to analyse. A repo with no phpstan config and no
    // `paths` lever gives it no path at all, and it exits 1 with "At least one
    // path must be specified to analyse" — recorded as `failed`, which reads as
    // THE CODE being broken in the first code phase of an ordinary project.
    //
    // The first fix appended `.` as phpcs does. phpcs can do that because it
    // takes `--ignore`; phpstan has no such flag, so `.` walked `vendor/` and
    // `node_modules/` and a mandatory blocking gate reported 404 errors, 401 of
    // them in third-party code, on a project whose own two files were clean.
    // Swapping one wrong verdict for a louder one.
    //
    // So it is neither: a gate pointed at nothing has not measured, and says so
    // and names the lever that points it, and the declaration audit records a
    // `mandatory_measured` row that the EVALUATION carries. Not the stop
    // hook's checklist — that lists what blocks, and this deliberately does
    // not.
    //
    // RECORDED, not blocked — and I wrote "type_coverage then holds the run"
    // here, which was wrong twice over: that check only exists when a work TYPE
    // was declared, so an agent declaring nothing got no check at all; and the
    // remedy is a lever, which freezes at begin, so a block could not be
    // cleared from inside the run it stopped.
    if ($gate->name === 'phpstan'
      && $exit !== 0
      && preg_match('/At least one path must be specified/i', $stdout . $stderr) === 1) {
      return GateResult::labelledPass(
        $gate->name,
        $exit,
        $elapsed,
        'phpstan was given no path to analyse — a labeled pass, not a '
        . 'measurement. Point it with `gates.phpstan.paths` in '
        . 'droost.workflow.yml, or add a phpstan.neon naming its own paths.',
        $invocation,
      );
    }

    // A site gate whose tool is PRESENT but has no site to ask. `wiki_fresh`
    // runs `drush droost:wiki:status`, and a checkout with drush in vendor/ and
    // no bootable Drupal gives:
    //
    //     PHP Fatal error: Uncaught AssertionError:
    //     assert($this->bootstrap instanceof DrupalBoot8)
    //
    // which the gate recorded as `failed` — the last phase, blocking, on a
    // surface that has no site to give it. The missing-binary branch above only
    // answers the case where drush is absent entirely, and vendor/bin/drush is
    // present in most Drupal checkouts whether or not a site is bootable. Same
    // absence, two spellings, and only one was answered.
    if (in_array($gate->name, self::SITE_SHELL_GATES, TRUE)
      && $exit !== 0
      && preg_match(
        '/DrupalBoot|bootstrap|Could not find a Drupal settings\.php|'
        . 'Unable to .{0,20}bootstrap|no Drupal (site|root)|DRUSH_BOOTSTRAP/i',
        $stdout . $stderr,
      ) === 1) {
      return GateResult::skippedNoSite($gate->name);
    }

    // Phpcs handed nothing to check. BEFORE the baseline partition, because
    // `againstBaseline()` renders it as "passed — 0 new, 0 inherited", the most
    // reassuring sentence in the report, about a scan that read no files.
    if ($gate->name === 'phpcs' && $exit === 16 && self::foundNothingToCheck($stdout . $stderr)) {
      return GateResult::labelledPass(
        $gate->name,
        $exit,
        $elapsed,
        'phpcs found nothing to check under the configured paths — a labeled pass, not a measurement.',
        $invocation,
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
      return GateResult::labelledPass(
        $gate->name,
        0,
        $elapsed,
        'phpunit passed — NO TESTS RAN. Either this project has no tests yet, or its suite stopped being discovered; the gate cannot tell those apart, so read this as unverified rather than as a pass.',
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

    if ($gate->name === 'phpunit') {
      // The counts go in the FINDINGS, the shape phpcs and phpstan already
      // use, and in the summary so a reader who never opens the findings
      // still sees the size of what passed.
      $counts = $this->phpunitTotals($stdout);
      if ($counts !== NULL) {
        return GateResult::ran(
          $gate->name,
          $exit === 0 ? GateStatus::Passed : GateStatus::Failed,
          $exit,
          $elapsed,
          sprintf(
            'phpunit %s — %d test(s), %d assertion(s)',
            $exit === 0 ? 'passed' : 'FAILED',
            $counts['tests'],
            $counts['assertions'],
          ),
          [['key' => 'totals', 'detail' => $counts]],
          $invocation,
        );
      }
    }

    return GateResult::ran(
      $gate->name,
      $exit === 0 ? GateStatus::Passed : GateStatus::Failed,
      $exit,
      $elapsed,
      $this->summarise($gate->name, $exit, $stdout, $stderr),
      $this->findings($stdout, $gate->name),
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
   * The phpunit run counts, or NULL when the tail cannot be read.
   *
   * The gate used to record `exit: 0` and nothing else, so a run whose suite
   * was twenty tests and one whose suite was a single assertion left
   * byte-identical records — while phpcs beside it stored per-file totals and
   * phpstan stored an error count. In a dogfood round the one gate that
   * caught a live XSS bypass was the one whose record said the least about
   * what it had checked.
   *
   * Two shapes, because phpunit prints one or the other and never both: the
   * clean tail `OK (20 tests, 32 assertions)`, and the counted tail
   * `Tests: 14, Assertions: 18, Failures: 2.` used whenever anything is not a
   * plain pass.
   *
   * @param string $stdout
   *   The runner's output.
   *
   * @return array{tests: int, assertions: int}|null
   *   The counts, or NULL when neither tail is present.
   */
  private function phpunitTotals(string $stdout): ?array {
    if (preg_match('/^OK \((\d+) tests?, (\d+) assertions?\)/m', $stdout, $m) === 1) {
      return ['tests' => (int) $m[1], 'assertions' => (int) $m[2]];
    }
    if (preg_match('/^Tests: (\d+), Assertions: (\d+)/m', $stdout, $m) === 1) {
      return ['tests' => (int) $m[1], 'assertions' => (int) $m[2]];
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
    $outcome = ($this->runner)(['/bin/sh', '-c', $cmd], $root, $this->timeoutFor($gate));
    [$exit, $stdout, $stderr] = $outcome;
    $this->lastStdout = $stdout;
    $this->lastStderr = $stderr;
    $elapsed = $this->tick() - $started;

    if ($exit === 127) {
      // The program the gate's own `cmd` names, not a program called
      // <name>: the default remedy would send an operator to
      // `composer require --dev custom:acme_audit`, which fetches nothing and
      // is not even a package name.
      return GateResult::toolMissing($gate->name, $cmd, $this->ownCommandRemedy($gate));
    }

    // A contributed gate declared what its verdict means; a failure repeats
    // it, so the report never shows an exit code nobody can read (D72).
    $summary = $this->summarise($gate->name, $exit, $stdout, $stderr);
    $verdict = $gate->option('verdict');
    if ($exit !== 0 && is_string($verdict) && $verdict !== '') {
      $summary .= ' — what this means: ' . $verdict;
    }

    return GateResult::ran(
      $gate->name,
      $exit === 0 ? GateStatus::Passed : GateStatus::Failed,
      $exit,
      $elapsed,
      $summary,
      $this->findings($stdout),
      $cmd,
    );
  }

  /**
   * What to do when a gate's own command names a program the shell cannot find.
   *
   * Custom and contributed gates both run a `cmd`, and the lever that chose it
   * sits in a different place for each — the operator's own file for a custom
   * gate, the declaring module's code for a contributed one. Sending an
   * operator to edit a key their lever file does not have is the same failure
   * as sending them to install a package that does not exist.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate whose command exited 127.
   *
   * @return string
   *   The remedy.
   */
  private function ownCommandRemedy(GateSettings $gate): string {
    if (GateSettings::isContributed($gate->name)) {
      return sprintf(
        'The %1$s gate runs a command the module that declares it chose, and '
        . 'the shell could not find the program that command names (exit '
        . '127). That command is not in droost.workflow.yml and cannot be '
        . 'corrected there — install the program the module needs (the '
        . 'summary above shows the command as it ran), or ask the OPERATOR to '
        . 'turn the gate off with `gates.contributed.%2$s.on: false`.',
        $gate->name,
        substr($gate->name, strlen(GateSettings::MODULE_PREFIX)),
      );
    }

    $key = GateSettings::isCustom($gate->name)
      ? 'gates.custom.' . substr($gate->name, strlen(GateSettings::CUSTOM_PREFIX))
      : 'gates.' . $gate->name;

    return sprintf(
      'The %1$s gate runs a command this project declared, and the shell '
      . 'could not find the program that command names (exit 127). Nothing '
      . 'called "%1$s" is installable, so `composer require --dev` on it '
      . 'fetches nothing — read `%2$s.cmd` in droost.workflow.yml, install '
      . 'the program it actually names, or ask the OPERATOR to correct that '
      . 'lever or turn the gate off with `%2$s.on: false`.',
      $gate->name,
      $key,
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
          static fn (\SplFileInfo $current): bool => !($current->isDir() && self::notTheProjectsCode($current->getPathname())),
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
            static fn (\SplFileInfo $current): bool => !($current->isDir() && self::notTheProjectsCode($current->getPathname())),
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
        'A coverage driver is a PHP EXTENSION, so no composer or npm install '
        . 'reaches it: ask the OPERATOR to enable xdebug or pcov for the PHP '
        . 'binary that runs the suite (`pecl install pcov`, then '
        . '`extension=pcov` and `pcov.enabled=1` in php.ini; `php -m | grep '
        . '-iE "xdebug|pcov"` confirms it). If this project does not measure '
        . 'coverage, `gates.coverage.on: false` in droost.workflow.yml. The '
        . 'suite itself is fine — re-running it reports the same thing.',
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
   * @param string $root
   *   The project root (the installed eslint's version lives under it).
   *
   * @return list<string>
   *   The argv array.
   */
  private function argvFor(GateSettings $gate, string $binary, string $root): array {
    $standard = $gate->option('standard');
    $level = $gate->option('level');
    $msi = $gate->option('msi_min');

    // A repository with its own phpcs ruleset is told to use it, which is what
    // the lever file has always promised — "omitted, each tool discovers the
    // repo's own config" — and what phpcs never did, because `--standard` was
    // injected unconditionally and `--standard` makes phpcs DISCARD the
    // ruleset file, including the `<file>` paths that tell it what to check.
    //
    // With no `paths` lever either, the result was `phpcs -q --report=json
    // --standard=… --extensions=…` and no path at all: exit 16, "You must
    // supply at least one file or directory to process", recorded as a labeled
    // pass. A reviewer put eleven real violations in a file, ran their own
    // phpcs (eleven errors), and watched the mandatory gate report `passed`.
    //
    // It also caused a wall: nothing measured means `type_coverage` blocks at
    // test, and phpcs does not re-run there, so the run could not be recovered
    // from its own levers.
    $ownRuleset = NULL;
    foreach (['phpcs.xml.dist', 'phpcs.xml'] as $candidate) {
      if (is_file(rtrim($root, '/') . '/' . $candidate)) {
        $ownRuleset = $candidate;
        break;
      }
    }

    return match ($gate->name) {
      // The repo's ruleset decides the standard, the extensions and the files.
      // Overriding any of them is how the gate stopped agreeing with the
      // command the developer runs by hand.
      'phpcs' => $ownRuleset !== NULL ? [
        $binary,
        '-q',
        '--report=json',
        '--ignore=' . self::VENDORED_IGNORE,
      ] : [
        $binary,
        '-q',
        '--report=json',
        // The project root, because a `--standard` with no path is not a scan:
        // phpcs exits 16 and the gate records a labeled pass over nothing. A
        // `paths` lever replaces this below; the vendored ignore keeps it from
        // walking dependencies.
        '.',
        '--standard=' . (is_string($standard) ? $standard : 'Drupal'),
        // PHP_CodeSniffer 4 dropped the JS/CSS tokenizers and with them the
        // wider default extension set: left alone it now checks `php` only.
        // Neither the Drupal nor the DrupalPractice ruleset declares an
        // `extensions` arg, so a Drupal project whose custom code lives in
        // .module/.theme/.install files — which is most of them — got
        // "ERROR: No files were checked" (exit 16) and a HARD GATE FAILURE
        // on code that is fine. The gate exposes no `extensions` lever, and
        // `standard` is frozen into run.json at begin, so a run that hit this
        // could not be fixed from its own levers OR waived (phpcs is
        // mandatory): reset was the only exit. Measured on a first real site,
        // 2026-09-12. ANALYSABLE already holds the right list — the same one
        // used to decide whether a path holds anything worth running on — so
        // the tool is now told it rather than left to guess.
        '--extensions=' . implode(',', self::ANALYSABLE['phpcs']),
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
        // A SUBJECT, the way phpcs gets one. phpcs is handed `.` when the
        // repo has no ruleset; the branch above it passed phpstan NO PATH at
        // all, phpstan exited 1 with "At least one path must be specified",
        // and that became a labelled pass. `init` writes `phpstan: { level: 6
        // }` with no `paths`, so on every project without a `phpstan.neon` —
        // which is what init creates — the mandatory analyser reported
        // `passed` over zero files, for the life of the project. A reviewer
        // planted a real return-type defect, watched phpstan find it in 0.8s
        // by hand, and watched the gate say passed.
        //
        // Not `.`: phpstan has no `--ignore`, so the only way to keep
        // `vendor/`, `node_modules/` and droost's own installed files out of
        // it is not to hand them over. The project's own top-level source
        // directories are what is left.
        ...$this->defaultPhpPaths($root, $gate),
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
      // A `config` lever pins the project's own file and turns discovery
      // off (see frontEndConfigArgs()) — without it, on a Drupal site, the
      // cascade reaches core's scaffolded .eslintrc.json and eslint crashes
      // before it reads a file (F-EMT-9).
      'eslint' => [$binary, '--format=json', ...$this->frontEndConfigArgs($gate, $root)],
      'stylelint' => [$binary, '--formatter=json', ...$this->frontEndConfigArgs($gate, $root)],
      'prettier' => [$binary, '--check', ...$this->frontEndConfigArgs($gate, $root)],
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
   * The project's own top-level directories that hold analysable PHP.
   *
   * Only used when nothing else names a subject: no `paths` lever and no
   * `phpstan.neon`. With either of those, phpstan is already pointed
   * somewhere and this stays out of the way.
   *
   * Vendored trees and droost's own installed files are excluded for the
   * reason `notTheProjectsCode()` gives — a tool judging the tool's own files
   * is a category error, and phpstan has no ignore flag to undo it with.
   *
   * @param string $root
   *   The project root.
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate, for its `paths` lever.
   *
   * @return list<string>
   *   Project-relative directories, or empty when something else names the
   *   subject or the project has no PHP outside its dependencies.
   */
  private function defaultPhpPaths(string $root, GateSettings $gate): array {
    if (trim((string) ($gate->options['paths'] ?? '')) !== '') {
      return [];
    }
    foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $config) {
      if (is_file(rtrim($root, '/') . '/' . $config)) {
        return [];
      }
    }
    $found = [];
    foreach ((array) @scandir(rtrim($root, '/')) as $entry) {
      if (!is_string($entry) || $entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
        continue;
      }
      $path = rtrim($root, '/') . '/' . $entry;
      if (!is_dir($path) || self::notTheProjectsCode($path)) {
        continue;
      }
      if ($this->hasAnalysable($path, self::ANALYSABLE[$gate->name] ?? ['php'])) {
        $found[] = $entry;
      }
    }
    sort($found);

    return $found;
  }

  /**
   * Whether a directory holds something other than the project's own code.
   *
   * Vendored trees, and droost's own installed files. The second half matters
   * to phpstan in particular, which has no `--ignore` flag — so the only way
   * to keep the pack's 2,000-line procedural hook out of a project's static
   * analysis is not to hand it over in the first place.
   *
   * Matched on the tail of the path rather than the basename alone, because
   * `droost/droost-workflow` is two segments and `baseline` on its own is a
   * word a project may legitimately use.
   *
   * @param string $directory
   *   The absolute directory.
   *
   * @return bool
   *   TRUE when the gate should not descend into it.
   */
  private static function notTheProjectsCode(string $directory): bool {
    $path = str_replace('\\', '/', rtrim($directory, '/'));
    if (in_array(basename($path), self::VENDORED_DIRS, TRUE)) {
      return TRUE;
    }
    foreach (self::DROOST_OWN_DIRS as $own) {
      if ($path === $own || str_ends_with($path, '/' . $own)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether a named tool's exit code says "I could not run".
   *
   * As opposed to "I ran and found problems": eslint exits 2 on a fatal or
   * a config it cannot load (1 is findings); stylelint exits 1 on a fatal,
   * 64 on bad usage and 78 on a bad config (its findings are exit 2);
   * prettier exits 2 on a fatal (unformatted files are exit 1); phpcs exits
   * 3 on a processing error (findings are 1 and 2). Public so the baseline
   * measure refuses to record a crash as zero findings.
   *
   * @param string $gate
   *   The gate name.
   * @param int $exit
   *   The tool's exit code.
   * @param string $stdout
   *   What the tool wrote to stdout, for a tool whose exit codes moved between
   *   majors and whose output says what it actually did.
   * @param string $stderr
   *   What it wrote to stderr.
   *
   * @return bool
   *   TRUE when the tool did not get as far as judging anything.
   */
  public static function toolFailedToRun(
    string $gate,
    int $exit,
    string $stdout = '',
    string $stderr = '',
  ): bool {
    return match ($gate) {
      'eslint', 'prettier' => $exit === 2,
      'stylelint' => in_array($exit, [1, 64, 78], TRUE),
      // PHP_CodeSniffer 4 RENUMBERED THESE. Its ExitCode class is OKAY 0,
      // FIXABLE 1, NON_FIXABLE 2, FAILED_TO_FIX 4, PROCESS_ERROR 16 — so 3 is
      // `1|2`, the commonest possible result: ordinary violations, some of
      // them fixable. Under phpcs 3, 3 was the processing error.
      //
      // Reading 3 as "could not run" inverted the gate on phpcs 4 in both
      // directions at once. A run with 935 real violations was reported as a
      // broken environment and skipped the feedback loop entirely; exit 16 —
      // the real config error, `the "Drupal" coding standard is not installed`
      // — was recorded as a labelled PASS on a mandatory gate.
      //
      // Rather than sniff the version, ask what the tool produced: a phpcs
      // that judged anything emits a JSON report, on either major, and a
      // processing error emits a message. 16 is a process error on both.
      // Exit 16 is PHP_CodeSniffer 4's PROCESS_ERROR, and it covers two very
      // different things: "you gave me nothing to check" and "the Drupal
      // coding standard is not installed". The whole of 16 was read as the
      // first — a labelled pass — so a broken ruleset came back as a PASS on a
      // mandatory gate. phpcs says which it means, so this asks.
      'phpcs' => ($exit === 16 && !self::foundNothingToCheck($stdout . $stderr))
        || ($exit === 3 && !self::reportWasProduced($stdout)),
      default => FALSE,
    };
  }

  /**
   * Whether phpcs is saying it was handed nothing, rather than that it broke.
   *
   * Both are exit 16 on PHP_CodeSniffer 4, and they mean opposite things: one
   * is a scan of nothing (a labelled pass, honestly recorded as measuring
   * nothing), the other is a configuration the tool could not load (a gate
   * that did not run). phpcs distinguishes them in its message and nowhere
   * else.
   *
   * @param string $output
   *   The tool's combined output.
   *
   * @return bool
   *   TRUE when it found nothing to check.
   */
  public static function foundNothingToCheck(string $output): bool {
    return preg_match(
      '/no files were checked|must supply at least one file|nothing to check/i',
      $output,
    ) === 1;
  }

  /**
   * Whether a tool's stdout carries a report it produced.
   *
   * The difference between "I ran and found problems" and "I could not run",
   * for a tool whose exit codes moved between majors. A parseable report means
   * it got as far as judging something, and that is true of every version.
   *
   * @param string $stdout
   *   What the tool wrote.
   *
   * @return bool
   *   TRUE when a report is present.
   */
  public static function reportWasProduced(string $stdout): bool {
    $trimmed = ltrim($stdout);
    if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
      return FALSE;
    }
    $decoded = json_decode($trimmed, TRUE);

    return is_array($decoded) && (isset($decoded['files']) || isset($decoded['totals']));
  }

  /**
   * What to do when a named tool could not run, phrased for the lever file.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return string
   *   The hint.
   */
  public static function toolFailedHint(string $gate): string {
    return match ($gate) {
      'eslint' => 'eslint walked up to a config it cannot load — on a Drupal site that is core\'s scaffolded .eslintrc.json, whose plugins only core\'s own yarn install provides. Point gates.eslint.config at this project\'s own config (its package.json lint script names it); the gate then pins it and turns discovery off.',
      'stylelint' => 'stylelint could not load its config — point gates.stylelint.config at this project\'s own stylelint config.',
      'prettier' => 'prettier could not run — check gates.prettier.config (its own config) and the syntax of the file it names.',
      'phpcs' => 'phpcs hit a processing error — check the ruleset gates.phpcs.standard names and that it resolves from the project root.',
      default => 'the tool could not run; fix its configuration before the gate can judge anything.',
    };
  }

  /**
   * The config flags a front-end lint gate takes from its `config` lever.
   *
   * With no lever the tool discovers config the way it always did. With one,
   * the project's own file is pinned and discovery is off — eslint's flags
   * changed with flat config, so the major installed decides the spelling.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate.
   * @param string $root
   *   The project root.
   *
   * @return list<string>
   *   The extra argv, possibly empty.
   */
  private function frontEndConfigArgs(GateSettings $gate, string $root): array {
    $config = $gate->option('config');
    if (!is_string($config) || $config === '') {
      return [];
    }
    return match ($gate->name) {
      'eslint' => $this->eslintMajor($root) >= 9
        ? ['--no-config-lookup', '--config', $config]
        : ['--no-eslintrc', '--config', $config],
      'stylelint', 'prettier' => ['--config', $config],
      default => [],
    };
  }

  /**
   * The installed eslint's major version.
   *
   * @param string $root
   *   The project root.
   *
   * @return int
   *   The major; 9 when it cannot be read (flat config is the current
   *   default and the eslintrc spelling the legacy one).
   */
  private function eslintMajor(string $root): int {
    $raw = @file_get_contents($root . '/node_modules/eslint/package.json');
    $decoded = is_string($raw) ? json_decode($raw, TRUE) : NULL;
    $version = is_array($decoded) && is_string($decoded['version'] ?? NULL) ? $decoded['version'] : '';
    return preg_match('/^(\d+)\./', $version, $m) === 1 ? (int) $m[1] : 9;
  }

  /**
   * The line in a tool's output that says why it could not run.
   *
   * @param string $stderr
   *   The tool's stderr.
   * @param string $stdout
   *   The tool's stdout.
   *
   * @return string
   *   The first line that is not a banner, capped; '' when there is none.
   */
  private static function causeLine(string $stderr, string $stdout): string {
    $text = trim($stderr) !== '' ? $stderr : $stdout;
    // ESLint prefaces the cause with a banner ("Oops! Something went wrong!
    // :(" and its version); the line after those is the one worth reading.
    foreach (preg_split('/\R/', trim($text)) ?: [] as $line) {
      $line = trim($line);
      if ($line === '' || str_starts_with($line, 'Oops!') || str_starts_with($line, 'ESLint:')) {
        continue;
      }
      return mb_substr($line, 0, 200);
    }
    return '';
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
    // NOT THE FIRST LINE. A tool's first line is its banner, and the summary
    // is what a reader sees first — and what propagates into the evidence
    // document as the account of the failure:
    //
    //   "phpunit failed (exit 1): PHPUnit 13.3.3 by Sebastian Bergmann"
    //   "phpstan failed (exit 1): Instructions for interpreting errors"
    //
    // Neither says anything about what failed. A reviewer driving from the
    // envelope was handed a version number and asked to fix something; the
    // feedback loop's whole premise is that the record names the cause.
    $line = self::failureLine($stderr, $stdout);

    return sprintf(
      '%s failed (exit %d)%s',
      $gate,
      $exit,
      $line === '' ? '' : ': ' . $line,
    );
  }

  /**
   * The failing tests in a phpunit run, as findings.
   *
   * Phpunit emits no machine format the gate asks for, so this reads the
   * report it does print:
   *
   *   1) Acme\Tests\ReorderTest::testReportKeysBySku
   *   Failed asserting that two arrays are identical.
   *   --- Expected
   *   …
   *   /path/tests/ReorderTest.php:25
   *
   * Best-effort, like every other parse here: a format that moves costs the
   * detail and never the verdict, which stays the exit code.
   *
   * @param string $stdout
   *   The tool's output.
   *
   * @return list<array{key: string, detail: array<string, string>}>
   *   One entry per failing test.
   */
  private static function phpunitFailures(string $stdout): array {
    $lines = preg_split('/\R/', $stdout) ?: [];
    $out = [];
    $count = count($lines);
    for ($i = 0; $i < $count; $i++) {
      if (preg_match('/^\d+\)\s+(\S+::\S+|\S+)\s*$/', trim($lines[$i]), $head) !== 1) {
        continue;
      }
      $message = '';
      $where = '';
      for ($j = $i + 1; $j < $count && $j < $i + 40; $j++) {
        $line = trim($lines[$j]);
        if (preg_match('/^\d+\)\s+\S+/', $line) === 1) {
          break;
        }
        if ($message === '' && $line !== '' && !str_starts_with($line, '---') && !str_starts_with($line, '+++')) {
          $message = mb_substr($line, 0, 300);
          continue;
        }
        if (preg_match('#^(/\S+\.php):(\d+)$#', $line, $at) === 1) {
          $where = $at[1] . ':' . $at[2];
        }
      }
      $out[] = [
        'key' => $head[1],
        'detail' => array_filter([
          'test' => $head[1],
          'message' => $message,
          'at' => $where,
        ], static fn (string $one): bool => $one !== ''),
      ];
      if (count($out) >= 50) {
        break;
      }
    }

    return $out;
  }

  /**
   * The line of a tool's output that says what failed.
   *
   * Preferred over the first line, which is a banner in every tool this runs.
   * The patterns are the shapes tools actually print when they have something
   * to report; with none of them present the first line that is not a banner
   * is still better than the banner.
   *
   * @param string $stderr
   *   Standard error.
   * @param string $stdout
   *   Standard output.
   *
   * @return string
   *   One line, bounded, or '' when the tool said nothing.
   */
  private static function failureLine(string $stderr, string $stdout): string {
    $lines = preg_split('/\R/', trim($stderr) !== '' ? $stderr : $stdout) ?: [];
    $banner = '/^(PHPUnit \d|PHP_CodeSniffer|PHPStan|Instructions for|Runtime:'
      . '|Configuration:|Note: Using|Each error has|This page contains|Before fixing'
      . '|The error usually|Do not |^-+$|^\.+$|^\s*$)/i';
    $tells = '/^(There (was|were) \d|Tests: |FAILURES|ERRORS|OK, but|\[ERROR\]'
      . '|\d+\)\s|FOUND \d+ ERROR|Found \d+ error)/i';
    $fallback = '';
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || preg_match($banner, $line) === 1) {
        continue;
      }
      if (preg_match($tells, $line) === 1) {
        return mb_substr($line, 0, 200);
      }
      if ($fallback === '') {
        $fallback = mb_substr($line, 0, 200);
      }
    }

    return $fallback;
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
   * @param string $gate
   *   The gate's name, for the tools whose output is prose rather than a
   *   machine format the gate can ask for.
   *
   * @return list<array<string, mixed>>
   *   The findings, or an empty list.
   */
  private function findings(string $stdout, string $gate = ''): array {
    if (trim($stdout) === '') {
      return [];
    }
    // PHPUNIT PRINTS PROSE, and the gate recorded ZERO findings for it — so
    // the only account of a failing suite was the summary line, and that was
    // the version banner. A reviewer's record held `"findings":[]` beside
    // `"summary":"phpunit failed (exit 1): PHPUnit 13.3.3 by Sebastian
    // Bergmann and contributors."` while the same command by hand named two
    // failing tests and the assertion each broke.
    //
    // The shape is stable and has been for a decade: a numbered heading, the
    // message under it, and the file:line last.
    if ($gate === 'phpunit') {
      $parsed = self::phpunitFailures($stdout);
      if ($parsed !== []) {
        return $parsed;
      }
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
