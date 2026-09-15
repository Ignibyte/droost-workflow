<?php

declare(strict_types=1);

namespace Droost\Workflow\Cli;

use Droost\Workflow\Evidence\UnreachableChecks;
use Droost\Workflow\Baseline\BaselineError;
use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\DrushCatalogResolver;
use Droost\Workflow\Config\EffortSwitch;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Evidence\EvidenceError;
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\RunStateOnlySink;
use Droost\Workflow\Pack\PackError;
use Droost\Workflow\Seeker\SeekerError;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Spec\SpecError;
use Droost\Workflow\State\StateError;
use Droost\Workflow\Support\DataError;
use Droost\Workflow\WorkflowFacade;

/**
 * The standalone surface: a handful of verbs, no Drupal, no booted site.
 *
 * Plain argv rather than a console framework. A few verbs for a machine
 * audience do not justify the dependency, and a typed error rendered to
 * stderr with a non-zero exit is the whole UX contract.
 *
 * This surface always uses NullSiteDriver, so every site-dependent gate
 * comes back "skipped, no site" with its reason attached. That is the point:
 * the CLI is not a degraded live run pretending otherwise, it is a run that
 * says exactly which checks it could not perform.
 */
final class ArgvDispatcher {

  /**
   * Everything went well.
   */
  public const EXIT_OK = 0;

  /**
   * The run itself failed — a gate blocked, or the pipeline stopped.
   */
  public const EXIT_RUN_FAILED = 1;

  /**
   * The invocation or the configuration was wrong.
   */
  public const EXIT_USAGE = 2;

  /**
   * Constructs an ArgvDispatcher.
   *
   * @param callable(string): void $out
   *   Writes a line to standard output.
   * @param callable(string): void $err
   *   Writes a line to standard error.
   * @param callable(): string $clock
   *   Returns an ISO-8601 timestamp.
   * @param callable(): string $ids
   *   Returns a fresh run identifier.
   * @param callable(): string|null $in
   *   Reads standard input in full. NULL falls back to the real stream;
   *   injected so tests can feed a ledger without a process.
   */
  public function __construct(
    private readonly mixed $out,
    private readonly mixed $err,
    private readonly mixed $clock,
    private readonly mixed $ids,
    private readonly mixed $in = NULL,
  ) {}

  /**
   * Runs one invocation.
   *
   * @param list<string> $argv
   *   The arguments, without the script name.
   * @param string $projectRoot
   *   The repository to act on.
   *
   * @return int
   *   The process exit code.
   */
  public function dispatch(array $argv, string $projectRoot): int {
    // CONSUMED FIRST, AND REMOVED. `--project=` was read out of the tail and
    // left in it, so each verb had to know to skip it and two of them did not:
    // `swap --project=<path> agentic` read the FLAG as the mode and failed
    // with 'swap needs a mode … got "--project=/…"', while
    // `swap agentic --project=<path>` worked — and the usage text says
    // "--project=<path> works on every verb". `answer` was fixed for its own
    // version of this and `swap` was not, which is the shape of a rule applied
    // by hand rather than in one place.
    //
    // Reading it BEFORE the verb also answers `droost-workflow --project=<path>
    // init`, which is how a person writes it and which came back "unknown
    // command --project=/…" with the full usage block.
    $named = FALSE;
    $argv = array_values(array_filter($argv, static function (mixed $argument) use (&$projectRoot, &$named): bool {
      if (is_string($argument) && str_starts_with($argument, '--project=')) {
        $projectRoot = substr($argument, strlen('--project='));
        $named = TRUE;

        return FALSE;
      }

      return TRUE;
    }));

    $verb = $argv[0] ?? '';
    if ($verb === '' || $verb === 'help' || $verb === '--help') {
      $this->usage();
      return $verb === '' ? self::EXIT_USAGE : self::EXIT_OK;
    }

    // `--project` on EVERY verb, because the other two surfaces have it and
    // this one did not. The MCP tools take a `project` argument and the drush
    // commands a `--project` option; `bin/droost-workflow` took the working
    // directory and nothing else, so the same instruction written once for an
    // agent worked on two surfaces and was an unknown flag on the third.
    //
    // It also answers the moved-cwd case. An agent's shell can `cd`, and the
    // binary resolving its root from wherever the shell happens to be means the
    // state directory it reads is not necessarily the one the guard is
    // enforcing — two components disagreeing about which repository this is.
    // Naming the root ends the argument.
    // WITHOUT `--project`, walk up to the project that is already here.
    //
    // The working directory was taken as the repository, full stop. Run from a
    // subdirectory, the binary reported built-in defaults as though the project
    // had no levers — and `run` went further: it CREATED a second state root at
    // `lib/sub/droost/droost-workflow/`, whose run.json then blocked the real
    // run as undeclared scope, with no waiver. A second ticket in that repo was
    // stopped by a directory the product itself had made in the wrong place.
    //
    // Only when nothing was named and here is not a project: an explicit
    // `--project` always wins, and a cwd that HAS a lever file or a state
    // directory is the project, which keeps `init` in an empty directory
    // working exactly as before.
    //
    // NOT for `init`, which is how a project comes into existence: climbing
    // there would make `init` in an empty subdirectory silently adopt the
    // parent, and the operator would be told "kept your existing
    // droost.workflow.yml" about a file they have never seen. Caught by running
    // it — the walk is for finding a project, not for creating one.
    // `CLAUDE_PROJECT_DIR` is the HOST's answer to this question, and the guard
    // reads it. The binary ignored it entirely and walked from the working
    // directory — so the two could resolve different projects, which is the
    // worst outcome available here: the engine advances a run the guard is not
    // watching.
    $host = getenv('CLAUDE_PROJECT_DIR');
    if (!$named && $verb !== 'init' && is_string($host) && is_dir($host)) {
      $projectRoot = $host;
    }
    elseif (!$named && $verb !== 'init' && is_dir($projectRoot)) {
      $projectRoot = self::projectAbove($projectRoot);
    }
    if (!is_dir($projectRoot)) {
      // The same refusal the MCP tools give, in the same words, so an agent
      // that learns the phrasing on one surface reads the other correctly.
      $this->fail(sprintf(
        'Not a directory: "%s". Pass --project as an absolute path to the '
        . 'repository, or omit it to use the working directory.',
        $projectRoot,
      ));

      return self::EXIT_USAGE;
    }

    try {
      return match ($verb) {
        'init' => $this->init($projectRoot, $argv),
        'status' => $this->status($projectRoot),
        'run' => $this->run($projectRoot, $argv),
        'answer' => $this->answer($projectRoot, $argv),
        'swap' => $this->swap($projectRoot, $argv),
        'seeker-report' => $this->seekerReport($projectRoot),
        'declare-browser' => $this->declareBrowser($projectRoot, $argv),
        'declare-tasks' => $this->declareTasks($projectRoot, $argv),
        'declare-changes' => $this->declareChanges($projectRoot, $argv),
        'reset' => $this->reset($projectRoot, $argv),
        'baseline' => $this->baseline($projectRoot, $argv),
        'bypass' => $this->bypass($projectRoot, $argv),
        'gate-waive' => $this->gateWaive($projectRoot, $argv),
        'effort' => $this->effort($projectRoot, $argv),
        'evidence' => $this->evidence($projectRoot, $argv),
        default => $this->unknown($verb),
      };
    }
    // Every failure this package raises is typed, and each one is already
    // phrased for a human — so the handler prints rather than re-explains.
    //
    // ALL of them, which took three goes to get right. SpecError, EvidenceError
    // and DataError are siblings of the five that were listed, thrown from the
    // same facade this dispatcher calls, and were missing — so the standalone
    // binary answered a failed spec contract with an uncaught exception and a
    // stack trace, while the drush surface printed the sentence the error was
    // written to carry. Same failure, same library, two different products.
    // `CliErrorCoverageTest` now enumerates them so a ninth cannot be added in
    // silence.
    catch (
      ConfigError | StateError | PackError | SeekerError | BaselineError
      | SpecError | EvidenceError | DataError $e
    ) {
      $this->fail($e->getMessage());
      return self::EXIT_USAGE;
    }
    catch (\InvalidArgumentException $e) {
      $this->fail($e->getMessage());
      return self::EXIT_USAGE;
    }
  }

  /**
   * The nearest ancestor that is already a droost project, else the input.
   *
   * A repository is where its lever file is, the way git's is where `.git` is
   * and composer's is where `composer.json` is. Taking the working directory
   * literally meant a subdirectory was a different project with no levers —
   * and, on `run`, a NEW one, complete with its own state directory in the
   * wrong place.
   *
   * Stops at the first match, so a nested project stays its own. Bounded, and
   * never climbs past a `.git`: crossing a repository boundary would silently
   * attach a run to somebody else's repo.
   *
   * @param string $from
   *   The working directory.
   *
   * @return string
   *   The project root.
   */
  private static function projectAbove(string $from): string {
    $here = rtrim($from, '/');
    $levers = NULL;
    $boundary = NULL;
    for ($depth = 0; $depth < 32; $depth++) {
      // An ACTIVE RUN wins outright, and wins over anything nearer. An agent
      // that creates `lib/droost/droost-workflow/` — one empty directory, no
      // file content — made the real project's levers and its live run vanish
      // from this resolver, and then `run` wrote a SECOND record there. The
      // run somebody is actually in is not something a mkdir gets to hide.
      if (is_file($here . '/droost/droost-workflow/run.json')
        || is_file($here . '/.droost-workflow/run.json')) {
        return $here;
      }
      if ($levers === NULL && is_file($here . '/droost.workflow.yml')) {
        $levers = $here;
      }
      // A repository boundary is a hard stop, checked AFTER the directory
      // itself so a project root that is also a repo root still matches.
      //
      // A WORKTREE and a SUBMODULE carry `.git` as a FILE, so `is_dir` alone
      // walked straight out of them: a run started in a worktree of repo B
      // picked up repo A's levers and wrote its record into repo A. But
      // `file_exists` alone made any file named `.git` a boundary, and one
      // `echo` then moved the project root — the guard has the same walk and
      // the same hole, where moving the root turns the protected paths off.
      // A real `.git` file names a gitdir that exists; a planted one does not.
      //
      // REMEMBERED, NOT OBEYED YET — the same rule the guard learned, because
      // the two have to agree and the guard learned it alone. `git init
      // lib/sub` then made this resolver stop at `lib/sub` while the guard
      // went on enforcing against the real root: the levers a run is HELD to
      // and the levers the guard ENFORCES became two different sets, and a
      // `run` from there wrote a second record.
      //
      // So the walk continues, and an active run above wins. With no run
      // above, the boundary answers and a worktree behaves exactly as it did
      // — which is the case the stop was written for.
      if ($boundary === NULL && self::isRepositoryBoundary($here)) {
        $boundary = ['root' => $here, 'levers' => $levers];
      }
      $parent = dirname($here);
      if ($parent === $here) {
        break;
      }
      $here = $parent;
    }
    if ($boundary !== NULL) {
      return $boundary['levers'] ?? $boundary['root'];
    }

    return $levers ?? $from;
  }

  /**
   * Whether a directory is a real repository boundary.
   *
   * A `.git` DIRECTORY always is. A `.git` FILE is one only when it points at
   * a git directory that exists, which is what git requires of a worktree or a
   * submodule and what a file somebody echoed there does not have.
   *
   * @param string $directory
   *   The directory being tested.
   *
   * @return bool
   *   TRUE when the walk should stop here.
   */
  private static function isRepositoryBoundary(string $directory): bool {
    $dot = rtrim($directory, '/') . '/.git';
    // NOT A SYMLINK. `ln -s /tmp lib/sub/.git` made `is_dir` true and stopped
    // the walk; git does not accept a symlinked repository either.
    if (is_link($dot)) {
      return FALSE;
    }
    if (is_dir($dot)) {
      return self::isGitDirectory($dot);
    }
    if (!is_file($dot)) {
      return FALSE;
    }
    $head = (string) @file_get_contents($dot, FALSE, NULL, 0, 4096);
    if (preg_match('/^gitdir:\s*(\S.*?)\s*$/m', $head, $match) !== 1) {
      return FALSE;
    }
    $target = str_starts_with($match[1], '/')
      ? $match[1]
      : rtrim($directory, '/') . '/' . $match[1];

    return self::isGitDirectory($target);
  }

  /**
   * Whether a directory is actually a git directory.
   *
   * THE GUARD AND THIS HAVE TO AGREE, and they stopped agreeing the moment one
   * of them was hardened alone. The guard learned that `mkdir .git`, a `.git`
   * symlinked anywhere, and `gitdir:` naming any directory that exists are not
   * repositories; this did not. So one `mkdir lib/sub/.git` stopped the engine
   * two levels below the project and it read built-in defaults while the
   * operator's `droost.workflow.yml` sat unread in the directory above — the
   * guard, meanwhile, found it. A divergence in the opposite direction from
   * the last one, created by the fix for the last one.
   *
   * `PackGuardParityTest` now drives both resolvers over the same shapes,
   * because two implementations of one rule is the defect and a test that
   * compares them is the only thing that keeps it from recurring.
   *
   * @param string $directory
   *   The candidate.
   *
   * @return bool
   *   TRUE when it looks like a git directory.
   */
  private static function isGitDirectory(string $directory): bool {
    $path = rtrim($directory, '/');

    return is_file($path . '/HEAD')
      && (is_dir($path . '/objects') || is_dir($path . '/refs') || is_file($path . '/config'));
  }

  /**
   * Installs the pack.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The command arguments; `--take-upstream=all` or
   *   `--take-upstream=<path>[,<path>...]` discards those files' drift and
   *   takes the shipped version instead of keeping the local edit.
   *
   * @return int
   *   The exit code.
   */
  private function init(string $projectRoot, array $argv): int {
    $takeUpstream = [];
    foreach ($argv as $arg) {
      if (!is_string($arg) || !str_starts_with($arg, '--take-upstream=')) {
        continue;
      }
      foreach (explode(',', substr($arg, strlen('--take-upstream='))) as $value) {
        $value = trim($value);
        if ($value !== '') {
          $takeUpstream[] = $value;
        }
      }
    }
    $report = $this->facade($projectRoot)->init($projectRoot, $takeUpstream);
    $this->say($report->summary());
    // And SAY WHAT IT JUST SET YOU TO. `init` printed "wrote 21 file(s)" and
    // nothing else, while the lever file it writes moves a repo from the
    // built-in defaults (preset max, enforcement hard, every gate on) to
    // preset custom, enforcement soft, and six optional tiers off. Running the
    // documented first command opted you out of six things in silence — under
    // a README line that says a repo which has said nothing has not opted out
    // of anything.
    //
    // Read back from the file that was just written rather than from what was
    // intended, so this reports the levers a run will actually be held to.
    $this->say('');
    $this->say($this->leverSummary($projectRoot));

    return self::EXIT_OK;
  }

  /**
   * One block naming the levers a run in this repo would be held to.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return string
   *   The summary.
   */
  private function leverSummary(string $projectRoot): string {
    $status = $this->facade($projectRoot)->status($projectRoot);
    $levers = is_array($status['levers'] ?? NULL) ? $status['levers'] : [];
    $gates = is_array($levers['gates'] ?? NULL) ? $levers['gates'] : [];
    $off = [];
    foreach ($gates as $name => $gate) {
      if (is_array($gate) && ($gate['on'] ?? TRUE) === FALSE) {
        $off[] = (string) $name;
      }
    }

    return sprintf(
      "This repo now resolves to:
"
      . "  preset       %s
"
      . "  enforcement  %s
"
      . "  mode         %s
"
      . "  gates off    %s
"
      . "
"
      . "Those come from droost.workflow.yml, which is yours to edit — it is
"
      . "version-controlled intent and no re-install overwrites it. `%s status`
"
      . "prints this at any time.",
      is_scalar($levers['preset'] ?? NULL) ? (string) $levers['preset'] : '?',
      is_scalar($levers['enforcement'] ?? NULL) ? (string) $levers['enforcement'] : '?',
      is_scalar($levers['mode'] ?? NULL) ? (string) $levers['mode'] : '?',
      $off === [] ? 'none — every gate runs' : implode(', ', $off),
      'droost-workflow',
    );
  }

  /**
   * Reports the levers and the run.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return int
   *   The exit code.
   */
  private function status(string $projectRoot): int {
    $status = $this->facade($projectRoot)->status($projectRoot);
    $this->say($this->encode($status));
    return self::EXIT_OK;
  }

  /**
   * Advances the run by one phase.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments; `--spec=<path>` or `--spec <path>` declares the governing
   *   spec at begin.
   *
   * @return int
   *   The exit code.
   */
  private function run(string $projectRoot, array $argv): int {
    $spec = NULL;
    $count = count($argv);
    for ($i = 0; $i < $count; $i++) {
      $arg = $argv[$i];
      if (str_starts_with($arg, '--spec=')) {
        $spec = substr($arg, 7);
        continue;
      }
      // The SPACE form too. It was dropped in silence, and the failure that
      // followed said "…and no --spec declared" — which is false, and sends the
      // reader to look for a spec they had just named. Every other CLI in a
      // developer's day takes both spellings; a pipeline that takes one and
      // says nothing about the other is teaching a lesson about itself.
      if ($arg === '--spec') {
        if ($i + 1 >= $count || str_starts_with($argv[$i + 1], '-')) {
          $this->fail('--spec needs a path: `--spec=<path>`, or `--spec <path>`.');

          return self::EXIT_USAGE;
        }
        $spec = $argv[++$i];
      }
    }
    $outcome = $this->facade($projectRoot)->run($projectRoot, $spec);
    $this->say($this->encode($outcome->toArray()));

    // A paused run has not failed; it is waiting. Only a genuine failure
    // gets a non-zero exit, so a pair-mode pause does not break a script
    // that treats non-zero as broken. Retryable and terminal failures share
    // the exit code — the difference lives in the envelope's
    // retries.exhausted, where a caller can actually act on it.
    // Blocked is non-zero too — the run did not advance — but the envelope's
    // own word is what tells a reader whether to fix and re-run or to reset.
    return $outcome->outcome === Outcome::Failed || $outcome->outcome === Outcome::Blocked
      ? self::EXIT_RUN_FAILED
      : self::EXIT_OK;
  }

  /**
   * Answers a paused run.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function answer(string $projectRoot, array $argv): int {
    // `--project=…` is consumed by dispatch() for every verb, and this one
    // joined the whole tail into the answer — so a run answered with
    // `answer "stop here" --project=/path` recorded
    // `stop here --project=/private/tmp/…` as what the human said. The record
    // of a human's decision is the one string in a run that has to be theirs.
    $words = array_values(array_filter(
      array_slice($argv, 1),
      static fn (string $word): bool => !str_starts_with($word, '--project='),
    ));
    $text = trim(implode(' ', $words));
    if ($text === '') {
      $this->fail('answer needs the answer: droost-workflow answer "yes"');
      return self::EXIT_USAGE;
    }
    $outcome = $this->facade($projectRoot)->answer($projectRoot, $text);
    $phase = $outcome->state->currentPhase;
    // Answering IS the check-in the pause was for, so the run moved on;
    // say where it now stands rather than a bare acknowledgment.
    //
    // Unless the answer ENDED it. A human told the ceiling "stop here — this is
    // stuck", the phase was failed, and this line said `answered — now at
    // code`, which reads as "carry on". The one place the product asks somebody
    // for a judgement then reported their judgement back as though it had not
    // been made.
    if ($outcome->outcome === Outcome::Failed && $phase !== NULL) {
      $this->say(sprintf(
        'answered — the run is STOPPED at %s on your word, not on a gate. '
        . 'Nothing further will advance it. `droost-workflow evidence` renders '
        . 'what it had measured; `droost-workflow reset --force` archives it '
        . 'once you know what made it stick.',
        $phase->value,
      ));

      return self::EXIT_RUN_FAILED;
    }
    // OR DID NOT MOVE IT. The facade declined to advance because a check is
    // still blocked, and this line was chosen from the phase alone — so it
    // said `answered — now at plan`, exit 0, exactly what an advance says. An
    // agent cannot tell "advanced" from "still here" from that, and one ran
    // forty-three identical cycles trying. A held phase is a non-zero exit
    // with the reason on stderr, which is this surface's whole contract.
    if ($outcome->outcome === Outcome::Blocked && $phase !== NULL) {
      $held = count($outcome->blocked);
      $this->fail(sprintf(
        'answered — still at %s. %d check%s hold%s the phase, and an answer '
        . 'does not change what a check found:',
        $phase->value,
        $held,
        $held === 1 ? '' : 's',
        $held === 1 ? 's' : '',
      ));
      foreach ($outcome->blocked as $row) {
        $this->fail(sprintf(
          '  %s [%s]: %s%s',
          $row['check'],
          $row['fault'],
          $row['why'],
          $row['remedy'] !== '' ? ' — ' . $row['remedy'] : '',
        ));
      }
      $this->fail(
        'Clear what they name and answer again, or `answer "stop here"` to end '
        . 'the run on your word. `droost-workflow evidence` renders every row.',
      );

      return self::EXIT_RUN_FAILED;
    }
    $this->say($phase === NULL
      ? 'answered — the run completed'
      : 'answered — now at ' . $phase->value);
    return self::EXIT_OK;
  }

  /**
   * Swaps the run's mode.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function swap(string $projectRoot, array $argv): int {
    $name = $argv[1] ?? '';
    $mode = Mode::resolve($name);
    if ($mode === NULL) {
      $this->fail(sprintf(
        'swap needs a mode (%s), got "%s"',
        implode(' or ', Mode::names()),
        $name,
      ));
      return self::EXIT_USAGE;
    }
    $this->facade($projectRoot)->swap($projectRoot, $mode);
    $this->say('swapped to ' . $mode->value);
    return self::EXIT_OK;
  }

  /**
   * Records a seeker inspection from the ledger on standard input.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return int
   *   The exit code.
   */
  private function seekerReport(string $projectRoot): int {
    $text = $this->in !== NULL
      ? ($this->in)()
      : (string) stream_get_contents(\STDIN);
    if (trim($text) === '') {
      $this->fail(
        'seeker-report reads the ledger from stdin: '
        . 'droost-workflow seeker-report < section.md',
      );
      return self::EXIT_USAGE;
    }
    $record = $this->facade($projectRoot)->recordSeeker($projectRoot, $text);
    $this->say($this->encode($record));
    // Recording findings is a SUCCESSFUL report — the run's advance is
    // where a dirty inspection bites, not here.
    return self::EXIT_OK;
  }

  /**
   * Records the agent's declared browser capability.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function declareBrowser(string $projectRoot, array $argv): int {
    $word = $argv[1] ?? '';
    if ($word === '') {
      $this->fail(
        'declare-browser needs the capability: playwright-mcp, native or '
        . 'none',
      );
      return self::EXIT_USAGE;
    }
    $this->facade($projectRoot)->declareBrowser($projectRoot, $word);
    $this->say('browser: ' . $word);
    return self::EXIT_OK;
  }

  /**
   * Records what this phase will change, and what will cover it.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments: --files=a,b --tests=c,d --type=<work type>; the first
   *   two are repeatable.
   *
   * @return int
   *   The exit code.
   */
  private function declareChanges(string $projectRoot, array $argv): int {
    $files = [];
    $tests = [];
    $type = NULL;
    $workItem = NULL;
    foreach (array_slice($argv, 1) as $argument) {
      if (str_starts_with($argument, '--files=')) {
        $files[] = substr($argument, 8);
      }
      elseif (str_starts_with($argument, '--tests=')) {
        $tests[] = substr($argument, 8);
      }
      elseif (str_starts_with($argument, '--type=')) {
        $type = substr($argument, 7);
      }
      elseif (str_starts_with($argument, '--work-item=')) {
        $workItem = substr($argument, 12);
      }
    }
    try {
      $declared = $this->facade($projectRoot)->declareChanges($projectRoot, $files, $tests, $type, $workItem);
    }
    catch (\InvalidArgumentException $e) {
      $this->fail($e->getMessage());
      return self::EXIT_USAGE;
    }
    $this->say(sprintf(
      'declared: %d file(s), %d test(s)%s',
      count($declared['files']),
      count($declared['tests']),
      $declared['type'] === NULL ? '' : ', type ' . $declared['type'],
    ));

    return self::EXIT_OK;
  }

  /**
   * Records the host task surface the session can drive.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function declareTasks(string $projectRoot, array $argv): int {
    $word = $argv[1] ?? '';
    if ($word === '') {
      $this->fail(sprintf(
        'declare-tasks needs the surface: %s',
        implode(', ', RunState::TASK_SURFACES),
      ));
      return self::EXIT_USAGE;
    }
    $this->facade($projectRoot)->declareTasks($projectRoot, $word);
    $this->say('tasks: ' . $word);
    return self::EXIT_OK;
  }

  /**
   * Renders the run's evaluation from the evidence store.
   *
   * Filling one of these by hand took six files and several hours, and what it
   * produced was a person's reading of a record they could not query. This is
   * the record reading itself out.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments: --run=<id>, --write[=<path>].
   *
   * @return int
   *   The exit code.
   */
  private function evidence(string $projectRoot, array $argv): int {
    $runId = NULL;
    $writeTo = NULL;
    foreach ($argv as $arg) {
      if (str_starts_with($arg, '--run=')) {
        $runId = substr($arg, 6);
      }
      if ($arg === '--write') {
        $writeTo = '';
      }
      if (str_starts_with($arg, '--write=')) {
        $writeTo = substr($arg, 8);
      }
    }

    // An empty --write means "you name it": the facade knows the resolved run
    // id and this does not. The default lands under droost/evidence/ because
    // that path is deliberately NOT covered by the ignore rule — the SQLite
    // file stays out of git (binary, unresolvable conflicts on concurrent
    // runs) and the rendered digest is the review artefact.
    $result = $this->facade($projectRoot)->evidence($projectRoot, $runId, $writeTo);

    if ($result['written_to'] !== NULL) {
      $this->say(sprintf('Evaluation for %s written to %s', $result['run_id'], $result['written_to']));
      return self::EXIT_OK;
    }
    $this->say($result['markdown']);

    return self::EXIT_OK;
  }

  /**
   * Clears a finished run so the next one can start.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function reset(string $projectRoot, array $argv): int {
    $force = in_array('--force', $argv, TRUE);
    $archived = $this->facade($projectRoot)->reset($projectRoot, $force);
    $this->say('run cleared — record archived to ' . $archived);
    return self::EXIT_OK;
  }

  /**
   * The adoption baseline: the bill, the record, or a write.
   *
   * `--status` and `--measure` are read-only and anyone's to ask. A write
   * (first, or `--refresh`, or `--refresh --grow --reason=…`) is the
   * operator's act; this surface has no terminal check of its own because it
   * IS the operator's terminal — the pack guard is what refuses the agent's
   * shell. There is no site here, so config_clean is never measured.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function baseline(string $projectRoot, array $argv): int {
    $flags = array_slice($argv, 1);
    if (in_array('--status', $flags, TRUE)) {
      $this->say($this->encode($this->facade($projectRoot)->baselineStatus($projectRoot)));
      return self::EXIT_OK;
    }
    if (in_array('--measure', $flags, TRUE)) {
      $this->say($this->encode($this->facade($projectRoot)->baselineMeasure($projectRoot)));
      return self::EXIT_OK;
    }
    $reason = NULL;
    foreach ($flags as $flag) {
      if (str_starts_with($flag, '--reason=')) {
        $reason = substr($flag, strlen('--reason='));
      }
    }
    $written = $this->facade($projectRoot)->baselineWrite(
      $projectRoot,
      in_array('--refresh', $flags, TRUE),
      in_array('--grow', $flags, TRUE),
      $reason,
    );
    $this->say($this->encode($written));
    return self::EXIT_OK;
  }

  /**
   * Grants or clears the require_run bypass. The operator's, not the agent's.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments; `--off` clears instead of granting.
   *
   * @return int
   *   The exit code.
   */
  private function bypass(string $projectRoot, array $argv): int {
    $flags = array_slice($argv, 1);
    $facade = $this->facade($projectRoot);
    // Clearing re-arms the wall. A tightening needs no operator terminal,
    // for the same reason disarming a write gate does not: nobody has to be
    // protected from more enforcement.
    if (in_array('--off', $flags, TRUE)) {
      $this->say($facade->clearBypass($projectRoot)
        ? 'require_run bypass cleared — the wall is armed again.'
        : 'no bypass was granted — the wall was already armed.');
      return self::EXIT_OK;
    }
    $reason = '';
    foreach ($flags as $flag) {
      if (!str_starts_with($flag, '--')) {
        $reason = $flag;
        break;
      }
    }
    if (trim($reason) === '') {
      $this->fail('Usage: droost-workflow bypass "<why>" — the reason goes on the record. `--off` re-arms the wall.');
      return self::EXIT_USAGE;
    }
    if (!$this->operatorTerminal()) {
      $this->fail($this->noTerminalRefusal('bypass'));
      return self::EXIT_USAGE;
    }
    $written = $facade->grantBypass($projectRoot, $reason);
    $this->say(sprintf(
      'require_run bypass GRANTED (%s): %s. Ungoverned custom-code edits are '
      . 'allowed until you run: droost-workflow bypass --off',
      $written,
      trim($reason),
    ));
    $this->say(
      'NOTE: this drops the WHOLE require_run wall. To waive one blocking gate '
      . 'for the current run instead — the usual intent — use: '
      . 'droost-workflow gate-waive <gate> "<reason>".',
    );
    return self::EXIT_OK;
  }

  /**
   * Waives one gate for the rest of the current run. The operator's.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments: the gate, then the reason.
   *
   * @return int
   *   The exit code.
   */
  private function gateWaive(string $projectRoot, array $argv): int {
    $operands = array_values(array_filter(
      array_slice($argv, 1),
      static fn (string $word): bool => !str_starts_with($word, '--'),
    ));
    $gate = $operands[0] ?? '';
    $reason = $operands[1] ?? '';
    if (trim($gate) === '' || trim($reason) === '') {
      $this->fail('Usage: droost-workflow gate-waive <gate> "<reason>" — both are required; the reason goes on the record.');
      return self::EXIT_USAGE;
    }
    if (!$this->operatorTerminal()) {
      $this->fail($this->noTerminalRefusal('gate-waive'));
      return self::EXIT_USAGE;
    }
    $state = $this->facade($projectRoot)->waiveGate($projectRoot, $gate, $reason);
    $this->say(sprintf(
      'Gate "%s" WAIVED for run %s: %s. It renders as "waived" (never a pass) '
      . 'in every remaining phase and in the report; the waiver dies with the run.',
      $gate,
      $state->runId,
      trim($reason),
    ));
    return self::EXIT_OK;
  }

  /**
   * Reports the effort level, previews a move, or writes one.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $argv
   *   The arguments: an optional level, and `--preview`.
   *
   * @return int
   *   The exit code.
   */
  private function effort(string $projectRoot, array $argv): int {
    $flags = array_slice($argv, 1);
    $level = '';
    foreach ($flags as $flag) {
      if (!str_starts_with($flag, '--')) {
        $level = $flag;
        break;
      }
    }
    // Reading is nobody's privilege, and neither is pricing a move: a bare
    // `effort` and `effort <level> --preview` write nothing, so the agent is
    // meant to run them — grounding the level it proposes is the point.
    if ($level === '') {
      $this->say($this->leverSummary($projectRoot));
      return self::EXIT_OK;
    }
    if (in_array('--preview', $flags, TRUE)) {
      $change = EffortSwitch::preview($projectRoot, $level);
      $this->say($change->moved()
        ? sprintf('preview: %s → %s would change, for the next run:', $change->previous, $change->level)
        : sprintf('preview: already %s — a move changes nothing', $change->level));
      foreach ($change->delta() as $line) {
        $this->say('  ' . $line);
      }
      return self::EXIT_OK;
    }
    if (!$this->operatorTerminal()) {
      $this->fail($this->noTerminalRefusal('effort'));
      return self::EXIT_USAGE;
    }
    $change = EffortSwitch::apply($projectRoot, $level);
    $this->say($change->moved()
      ? sprintf('effort: %s → %s written to %s', $change->previous, $change->level, WorkflowConfig::FILENAME)
      : sprintf('effort: already %s — nothing to write', $change->level));
    if ($change->alias !== NULL) {
      $this->say(sprintf('  ("%s" is an alias of %s; the file teaches the canonical name)', $change->alias, $change->level));
    }
    $this->say('');
    $this->say($this->leverSummary($projectRoot));
    return self::EXIT_OK;
  }

  /**
   * Whether a person is typing this into a terminal.
   *
   * The same second line of defence the drush surface has, in the same words,
   * because the two surfaces now offer the same three operator verbs and a
   * rule that holds on one and not the other is not a rule. The guard hook is
   * the first line: it refuses these verbs from the agent's Bash tool. Not
   * tamper-proof — a PTY can be faked — but it turns "the agent ran it in
   * passing" into "the agent went out of its way", which the seeker's
   * discipline lens then catches.
   *
   * @return bool
   *   TRUE when STDIN is an interactive terminal.
   */
  private function operatorTerminal(): bool {
    return defined('STDIN') && stream_isatty(STDIN);
  }

  /**
   * The refusal for an operator command issued without a terminal.
   *
   * @param string $command
   *   The verb.
   *
   * @return string
   *   The message.
   */
  private function noTerminalRefusal(string $command): string {
    return sprintf(
      'droost-workflow %1$s is the operator\'s command and this shell has no '
      . 'terminal (an agent\'s tool shell, a pipe, a script). It records a '
      . 'HUMAN\'s decision, so a human types it: run `droost-workflow %1$s …` '
      . 'in your own terminal (`! droost-workflow %1$s …` from inside Claude '
      . 'Code).',
      $command,
    );
  }

  /**
   * Reports an unknown verb.
   *
   * @param string $verb
   *   What was asked for.
   *
   * @return int
   *   The exit code.
   */
  private function unknown(string $verb): int {
    $this->fail(sprintf('unknown command "%s"', $verb));
    $this->usage();
    return self::EXIT_USAGE;
  }

  /**
   * Prints how to use this.
   */
  private function usage(): void {
    $this->say(<<<'TXT'
    droost-workflow — the phased, gated pipeline, standalone.

      init             install the .claude pack and a default lever file
      status           what this repo resolves to, and where a run has got to
      run              start a run, or advance it by one phase
                   (--spec=<path> declares which spec governs the run)
      answer <text>    answer a paused run's question (the run then advances)
      swap agentic     stop holding at phases and finish without stopping
      seeker-report    record an adversarial inspection (ledger on stdin)
      declare-changes  say what this run will touch, before touching it:
                       --files=<a,b> --tests=<X,Y> [--type=<kind>]
                       [--work-item=<id>]. The code
                       phase audits the claim against the real diff, so a file
                       nobody declared blocks and a declared test that never
                       ran blocks. --type is one of code, content_model,
                       theme, content, docs, mixed and never turns a gate off;
                       it says which gates must have MEASURED something.
      declare-browser  record the session's browser tier (playwright-mcp,
                       native, none)
      declare-tasks    record the host task surface this session can drive,
                       one task per phase (claude-code, codex, other, none)
      evidence         render the run's evaluation FROM the record, rather
                       than writing one about it: --run=<id> for an archived
                       run, --write[=<path>] to save it (default
                       droost/evidence/<run>.md). Re-measures the fingerprint
                       of what every green examined, so a verdict that no
                       longer describes the code reads EXPIRED.
      reset [--force]  clear a finished run (archives its record to
                       the state dir's history/); --force abandons a live one

    The OPERATOR's three. Each one loosens what a run is held to, so each
    refuses to run without an interactive terminal, and the pack's guard hook
    refuses them from the agent's shell. Reading is nobody's privilege: a bare
    `effort`, and `effort <level> --preview`, write nothing and are for the
    agent to run when it wants to ground a level it means to propose.

      bypass "<why>"   drop the require_run wall until cleared (--off re-arms
                       it, and needs no terminal — a tightening needs nobody)
      gate-waive <gate> "<why>"
                       waive ONE gate for the rest of this run; it renders as
                       "waived", never as a pass, and dies with the run. The
                       mandatory trio carries no waiver
      effort [<level>] report the level, or write it: low | medium | high |
                       xhigh | max | custom. --preview prices the move without
                       writing. A run in progress keeps the level it froze at
      baseline         write the adoption baseline (droost/baseline/): the
                       debt the tree carries today, inherited from then on.
                       --measure shows the bill without writing; --status
                       shows the recorded baseline; --refresh re-measures
                       (paid-off debt drops, growth is refused unless
                       --grow --reason="…"). Writing is the operator's act.

    --project=<path> works on every verb and names the repository to act on,
    the way the drush commands' --project and the MCP tools' `project`
    argument do. Without it the working directory is the repository — which
    is wrong the moment a shell has moved, and wrong quietly: a subdirectory
    has no lever file, so the run reports built-in defaults as though the
    project had none.

    Every site-dependent gate reports "skipped, no site" here, with its
    reason. That is deliberate: this surface tells you what it could not
    check rather than quietly leaving it out.
    TXT);
  }

  /**
   * A facade wired for the siteless surface.
   *
   * Siteless, not blind: the gates enabled modules contribute (D72) live in
   * the site, so this surface asks drush for them before it resolves a single
   * lever (R31-F3 — a run begun here was held to fewer gates than the same run
   * through drush, and nothing said so). No drush, or a site that cannot
   * answer, resolves to none, with the reason in `levers.contributed_source`.
   *
   * @param string $projectRoot
   *   The repository the verb acts on.
   *
   * @return \Droost\Workflow\WorkflowFacade
   *   The facade.
   */
  private function facade(string $projectRoot): WorkflowFacade {
    $catalog = (new DrushCatalogResolver(
      static function (array $argv, string $cwd, int $timeout): array {
        return CliProcess::run($argv, $cwd, $timeout);
      },
    ))->resolve($projectRoot);
    // A site here means contributed checks may exist that this surface cannot
    // ask — the binary boots no Drupal. Saying so beats recording nothing,
    // which read downstream exactly like a run that had been asked.
    $hasSite = is_file($projectRoot . '/vendor/bin/drush');

    return new WorkflowFacade(
      new ShellGateExecutor(
        static function (array $argv, string $cwd, int $timeout): array {
          return CliProcess::run($argv, $cwd, $timeout);
        },
        static fn (): int => (int) (hrtime(TRUE) / 1_000_000),
      ),
      new NullSiteDriver(),
      new RunStateOnlySink(),
      $this->clock,
      $this->ids,
      NULL,
      NULL,
      $catalog['gates'],
      $catalog['source'],
      new UnreachableChecks($hasSite),
    );
  }

  /**
   * Encodes a document for output.
   *
   * @param array<string, mixed> $document
   *   The document.
   *
   * @return string
   *   Pretty JSON, or a plain error line if it cannot be encoded.
   */
  private function encode(array $document): string {
    try {
      return json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
      );
    }
    catch (\JsonException $e) {
      return '{"error":"could not render the report: '
        . addslashes($e->getMessage()) . '"}';
    }
  }

  /**
   * Writes a line to standard output.
   *
   * @param string $line
   *   The line.
   */
  private function say(string $line): void {
    ($this->out)($line);
  }

  /**
   * Writes a line to standard error.
   *
   * @param string $line
   *   The line.
   */
  private function fail(string $line): void {
    ($this->err)($line);
  }

}
