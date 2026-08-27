<?php

declare(strict_types=1);

namespace Droost\Workflow;

use Droost\Workflow\State\StateError;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Gate\SiteDriverInterface;
use Droost\Workflow\Mode\ModeEngine;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\QuestionSinkInterface;
use Droost\Workflow\Mode\RunOutcome;
use Droost\Workflow\Seeker\SeekerLedger;
use Droost\Workflow\Pack\InitReport;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunState;
use Droost\Workflow\State\RunStateStore;

/**
 * The one place a workflow run is orchestrated.
 *
 * Four fronts call this: the standalone bin, the drush commands, and two MCP
 * tools. Each of them parses input, calls a method here, and renders the
 * result — and does nothing else. That is what makes surface parity
 * architectural rather than aspirational: there is no second implementation
 * to drift, because the only difference between the surfaces is which
 * SiteDriver they inject.
 *
 * This class is also where run state finally gets a production writer. Until
 * now `RunState::begin()` and `RunStateStore` were exercised only by tests,
 * which is why the shipped pack had to be corrected for telling agents to
 * read a file nothing produced.
 */
final class WorkflowFacade {

  /**
   * Constructs a WorkflowFacade.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   Runs the gates that need only a checkout.
   * @param \Droost\Workflow\Gate\SiteDriverInterface $driver
   *   Runs the gates that need a site. The ONLY thing that differs between
   *   the CLI surface and the live one.
   * @param \Droost\Workflow\Mode\QuestionSinkInterface $sink
   *   Delivers a paused run's question.
   * @param callable(): string $clock
   *   Returns an ISO-8601 timestamp. Injected so a run's recorded times come
   *   from the surface rather than from a value object.
   * @param callable(): string $ids
   *   Returns a fresh run identifier.
   */
  public function __construct(
    private readonly GateExecutorInterface $executor,
    private readonly SiteDriverInterface $driver,
    private readonly QuestionSinkInterface $sink,
    private readonly mixed $clock,
    private readonly mixed $ids,
  ) {}

  /**
   * Installs the pack and the default lever file into a project.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return \Droost\Workflow\Pack\InitReport
   *   What was written and what was left alone.
   */
  public function init(string $projectRoot): InitReport {
    return (new PackMaterializer())->init($projectRoot);
  }

  /**
   * What a run here is held to, and where it has got to.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, mixed>
   *   The status document. Always reports whether the levers came from a
   *   committed file or the built-in defaults, because a reader must not have
   *   to guess which.
   */
  public function status(string $projectRoot): array {
    $config = WorkflowConfig::load($projectRoot);
    $state = (new RunStateStore($projectRoot))->load();

    $status = [
      'levers' => [
        'provenance' => $config->provenance->value,
        'preset' => $config->preset,
        'mode' => $config->mode->value,
        'enforcement' => $config->enforcement->value,
        'require_run' => $config->requireRun->value,
        'phases' => $config->phaseNames(),
        'gates' => $config->resolvedGates(),
        // WHEN each enabled gate runs — so "why did plan run nothing" is
        // answerable from status alone.
        'phase_gates' => PhaseGateMap::forPhases($config->phaseNames()),
        'max_gate_retries' => $config->maxGateRetries,
      ],
      // Whether each named gate's tool could actually run here, probed via
      // the executor's own path mapping — the reported row and the executed
      // path are the same fact. Without this, armed-and-working was
      // indistinguishable from armed-and-broken until a run hit it.
      'toolchain' => $this->toolchain($config, $projectRoot),
      // Deprecations are part of the resolved result: a lever file using a
      // retired key should say so everywhere the levers are read.
      'deprecations' => $config->deprecations,
      'run' => NULL,
    ];

    if ($state === NULL) {
      return $status;
    }

    $engine = $this->engine();
    $question = $engine->pendingQuestion($state);
    $status['run'] = [
      'run_id' => $state->runId,
      'started_at' => $state->startedAt,
      'effective_mode' => $state->effectiveMode()->value,
      'current_phase' => $state->currentPhase?->value,
      // The judgment half of the record: whether the checkpoint is armed,
      // the latest parsed inspection, which browser tier the agent declared
      // and whether it can show the phases as host tasks — so "what was this
      // run actually verified by, and could anyone watch it" is answerable
      // from status alone.
      'seekers' => $state->seekers,
      'seeker' => $state->seeker,
      'browser' => $state->browser,
      'tasks' => $state->tasks,
      'phases' => array_map(
        static fn ($s): string => $s->value,
        $state->phases,
      ),
      // The run's own frozen map, which may lawfully differ from the
      // levers' current one above.
      'phase_gates' => $state->phaseGates,
      'gate_reports' => $state->gateResults,
      'awaiting' => $question?->toArray(),
      'answered' => count($state->qaHistory),
    ];
    return $status;
  }

  /**
   * The toolchain rows: per named gate, the binary it runs and its presence.
   *
   * Custom gates are absent by design — their cmd runs through the shell,
   * whose own 127 is the probe. Site gates are absent too: rendered_check
   * runs through the injected site driver, never a binary, and probing
   * binaryPathFor() for it invented a vendor/bin/rendered_check that cannot
   * exist on any repo — a row that read "missing" forever while every
   * remedy pointed at packages that do not provide it. The phpunit row also
   * reports whether a suite config exists, because the gate refuses to run
   * without one.
   *
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The resolved levers.
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, array<string, bool|string>>
   *   Gate name to its binary path, presence, and armed flag.
   */
  private function toolchain(
    WorkflowConfig $config,
    string $projectRoot,
  ): array {
    $root = rtrim($projectRoot, '/');
    $rows = [];
    foreach ($config->gates as $name => $gate) {
      if (GateSettings::isCustom($name)
        || in_array($name, GateRunner::SITE_GATES, TRUE)) {
        continue;
      }
      $binary = ShellGateExecutor::binaryPathFor($name);
      $row = [
        'on' => $gate->on,
        'binary' => $binary,
        'present' => is_file($root . '/' . $binary),
      ];
      if ($name === 'phpunit' || $name === 'coverage') {
        $row['suite_config'] = is_file($root . '/phpunit.xml')
          || is_file($root . '/phpunit.xml.dist');
      }
      $rows[$name] = $row;
    }
    return $rows;
  }

  /**
   * Starts a run, or advances the one in progress, by one phase.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
   *   What happened. The state is persisted before this returns.
   */
  public function run(string $projectRoot): RunOutcome {
    $store = new RunStateStore($projectRoot);
    $state = $store->load();

    if ($state === NULL) {
      $config = WorkflowConfig::load($projectRoot);
      $state = RunState::begin($this->newId(), $this->now(), $config);
      $store->save($state);
    }

    $phase = $state->currentPhase;
    if ($phase === NULL) {
      // The run reached its terminal gate. Re-running does not restart it;
      // saying so is more useful than silently beginning a second run.
      return new RunOutcome(Outcome::Completed, $state);
    }

    if ($state->statusOf($phase) === PhaseStatus::Failed) {
      // The phase spent its retry budget. Re-running would silently restart
      // a run the engine already declared over — so nothing executes, and
      // the envelope's retries block says why. Recovery is deliberate:
      // reset() archives the record, then a fresh run begins.
      return new RunOutcome(Outcome::Failed, $state);
    }

    $outcome = $this->engine()->runPhase(
      $state,
      $phase,
      $projectRoot,
      $this->now(),
    );
    $advanced = $this->advanceIfDue($outcome, $phase);
    $store->save($advanced->state);

    return $advanced;
  }

  /**
   * Answers the question a paused run is waiting on.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $answer
   *   What the human said.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run, no longer awaiting, already persisted.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When there is no run to answer.
   */
  public function answer(string $projectRoot, string $answer): RunState {
    $store = new RunStateStore($projectRoot);
    $state = $this->requireRun($store);
    $answered = $this->engine()->answer($state, $answer, $this->now());
    // A pause exists for exactly one reason: the current phase passed its
    // gates and pair mode asked its check-in question. The answer IS that
    // check-in, so answering moves the run on — to the next phase, or, at the
    // final gate, to its terminal state. Without this, the next invocation
    // re-ran the same gates and re-asked the same question, forever; the only
    // exits a pair run had were swap and reset. The exchange itself is
    // already in the history — a "no" is recorded, and its remedy is reset
    // (abandon) or swap (finish unattended), both said in the question's own
    // phrasing everywhere it is rendered.
    $phase = $answered->currentPhase;
    if ($phase !== NULL) {
      $next = $this->nextPhase($answered, $phase);
      $answered = $next === NULL
        ? $answered->complete()
        : $answered->advanceTo($next);
    }
    $store->save($answered);
    return $answered;
  }

  /**
   * Clears a finished run: archives its record and removes run.json.
   *
   * A completed (or failed) run.json persists — deliberately, it is the
   * record — and start refuses to clobber it, so multi-ticket work needs a
   * sanctioned way to finish one run and begin the next. The record is
   * archived to .droost-workflow/history/<run_id>.json, never discarded; a
   * name collision gets a numeric suffix rather than overwriting an earlier
   * archive. An UNREADABLE run.json is clearable the same way (archived under
   * "run"): a file that cannot be parsed is not a live run, and clearing is
   * exactly the recovery it needs. A run still in progress is refused unless
   * $force — abandoning live work stays a deliberate act.
   *
   * The guard's warn-once markers (.droost-workflow/.guard-warned-*) are
   * cleared too: they are per-run state, and surviving a reset silenced every
   * soft nudge for the checkout's lifetime.
   *
   * @param string $projectRoot
   *   The repository.
   * @param bool $force
   *   Clear even a run still in progress.
   *
   * @return string
   *   The archived record's path.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When there is nothing to reset (noRun), the run is live and $force is
   *   not given (runInProgress), or the record could not be moved
   *   (archiveFailed — nothing is deleted in that case).
   */
  public function reset(string $projectRoot, bool $force = FALSE): string {
    $store = new RunStateStore($projectRoot);
    $path = $projectRoot . '/.droost-workflow/run.json';
    if (!is_file($path)) {
      throw StateError::noRun($store->label());
    }
    // Classify from the RUN STATE alone. Loading the lever file here would
    // couple "may I clear this run" to "does droost.workflow.yml parse" — and
    // a typo in the lever then archived a LIVE run as if it were finished.
    try {
      $state = $store->load();
    }
    catch (StateError) {
      // Present but unreadable: not a live run, clearable without force.
      $state = NULL;
    }
    $live = $state !== NULL
      && $state->currentPhase !== NULL
      && $state->statusOf($state->currentPhase) !== PhaseStatus::Failed;
    if ($live && !$force) {
      throw StateError::runInProgress(
        $store->label(),
        $state->currentPhase->value,
      );
    }
    $history = $projectRoot . '/.droost-workflow/history';
    if (!is_dir($history) && !@mkdir($history, 0777, TRUE) && !is_dir($history)) {
      throw StateError::archiveFailed($history, 'the history directory could not be created');
    }
    $id = $state !== NULL && $state->runId !== '' ? $state->runId : 'run';
    $base = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $id);
    $target = $history . '/' . $base . '.json';
    for ($n = 2; is_file($target); $n++) {
      $target = $history . '/' . $base . '-' . $n . '.json';
    }
    if (@rename($path, $target) === FALSE) {
      throw StateError::archiveFailed($target, 'the record could not be moved');
    }
    foreach (glob($projectRoot . '/.droost-workflow/.guard-warned-*') ?: [] as $marker) {
      @unlink($marker);
    }
    return $target;
  }

  /**
   * Swaps the run to automated.
   *
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Config\Mode $to
   *   The mode to switch to.
   *
   * @return \Droost\Workflow\State\RunState
   *   The swapped run, already persisted.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When there is no run to swap.
   */
  public function swap(string $projectRoot, Mode $to): RunState {
    $store = new RunStateStore($projectRoot);
    $state = $this->requireRun($store);
    $swapped = $this->engine()->swap($state, $to, $this->now());
    $store->save($swapped);
    return $swapped;
  }

  /**
   * Records a seeker inspection from its ledger text.
   *
   * The counts come from PARSING the ledger — never from an agent's summary
   * of it. An unparseable or incomplete section is a typed error, and
   * nothing is recorded: an inspection that cannot be read is an inspection
   * that did not happen.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $ledgerText
   *   The text carrying the "## Seeker Inspection" section — the spec
   *   file's content, or the section alone.
   *
   * @return array<string, int|string>
   *   The recorded inspection, already persisted.
   *
   * @throws \Droost\Workflow\Seeker\SeekerError
   *   When the ledger is missing, incomplete or contradictory.
   * @throws \Droost\Workflow\State\StateError
   *   When there is no run to record against.
   */
  public function recordSeeker(
    string $projectRoot,
    string $ledgerText,
  ): array {
    $store = new RunStateStore($projectRoot);
    $state = $this->requireRun($store);
    $record = SeekerLedger::parse($ledgerText)->toRecord($this->now());
    $store->save($state->withSeekerReport($record));
    return $record;
  }

  /**
   * Records the browser capability the running agent declared.
   *
   * Session-scoped truth only the agent can know: no file on disk says
   * whether the session driving this run has a browser tool. Declared once
   * at run start so the test phase and the final report can say which
   * verification tier actually ran.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $browser
   *   One of: playwright-mcp, native, none.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run, already persisted.
   *
   * @throws \InvalidArgumentException
   *   When the word is outside the vocabulary.
   * @throws \Droost\Workflow\State\StateError
   *   When there is no run to record against.
   */
  public function declareBrowser(
    string $projectRoot,
    string $browser,
  ): RunState {
    if (!in_array($browser, ['playwright-mcp', 'native', 'none'], TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'browser must be playwright-mcp, native or none — got "%s"',
        $browser,
      ));
    }
    $store = new RunStateStore($projectRoot);
    $declared = $this->requireRun($store)->withBrowser($browser);
    $store->save($declared);
    return $declared;
  }

  /**
   * Records the host task surface the running agent can drive.
   *
   * Same shape and same reason as declareBrowser(): whether this session can
   * show a human where the run is — one task per phase, updated as it moves
   * — is session-scoped truth only the agent can see, so the agent declares
   * it and the run records it. A report that claimed phase visibility the
   * host never had would be worse than one that says none.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $tasks
   *   The surface: claude-code, codex, other, or none.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run, with the declaration recorded.
   *
   * @throws \InvalidArgumentException
   *   When the word is outside its vocabulary.
   */
  public function declareTasks(
    string $projectRoot,
    string $tasks,
  ): RunState {
    if (!in_array($tasks, RunState::TASK_SURFACES, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'tasks must be one of %s — got "%s"',
        implode(', ', RunState::TASK_SURFACES),
        $tasks,
      ));
    }
    $store = new RunStateStore($projectRoot);
    $declared = $this->requireRun($store)->withTasks($tasks);
    $store->save($declared);
    return $declared;
  }

  /**
   * Moves a passing run on to the next phase.
   *
   * @param \Droost\Workflow\Mode\RunOutcome $outcome
   *   What the phase produced.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase just worked.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
   *   The outcome, with the state advanced when it should be.
   */
  private function advanceIfDue(
    RunOutcome $outcome,
    Phase $phase,
  ): RunOutcome {
    if ($outcome->outcome === Outcome::Completed) {
      // The final phase passed. This is the ONLY place the terminal state is
      // written: complete() records the phase passed and drops currentPhase to
      // NULL, so run()'s "already completed" short-circuit, status, report and
      // reset all read the finished run as finished — not as forever "active".
      return new RunOutcome(
        Outcome::Completed,
        $outcome->state->complete(),
        $outcome->report,
      );
    }
    if ($outcome->outcome !== Outcome::Advanced) {
      return $outcome;
    }

    $next = $this->nextPhase($outcome->state, $phase);
    if ($next === NULL) {
      return $outcome;
    }

    return new RunOutcome(
      Outcome::Advanced,
      $outcome->state->advanceTo($next),
      $outcome->report,
    );
  }

  /**
   * The phase after this one, among those the run configured.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The current phase.
   *
   * @return \Droost\Workflow\Config\Phase|null
   *   The next configured phase, or NULL when this was the last.
   */
  private function nextPhase(RunState $state, Phase $phase): ?Phase {
    $seen = FALSE;
    foreach (Phase::canonical() as $candidate) {
      if ($candidate === $phase) {
        $seen = TRUE;
        continue;
      }
      if ($seen && $state->statusOf($candidate) !== NULL) {
        return $candidate;
      }
    }
    return NULL;
  }

  /**
   * The run, or a typed error saying there is not one.
   *
   * @param \Droost\Workflow\State\RunStateStore $store
   *   The store.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When no run is recorded.
   */
  private function requireRun(RunStateStore $store): RunState {
    $state = $store->load();
    if ($state === NULL) {
      // Absent, not corrupt: load() returns NULL only when the file does not
      // exist. Say "start a run", not "your unreadable file — move it aside".
      throw StateError::noRun($store->label());
    }
    if ($state->currentPhase === NULL) {
      // Ended, not absent: the terminal record persists until reset, and the
      // mutating verbs must not rewrite a closed record — a browser tier or a
      // "clean" inspection recorded after completion would misstate what the
      // finished work was actually verified by.
      throw StateError::runEnded($store->label());
    }
    return $state;
  }

  /**
   * The mode engine for this surface.
   *
   * @return \Droost\Workflow\Mode\ModeEngine
   *   The engine.
   */
  private function engine(): ModeEngine {
    return new ModeEngine(
      new GateRunner($this->executor, $this->driver),
      $this->sink,
    );
  }

  /**
   * The current time, from the injected clock.
   *
   * @return string
   *   An ISO-8601 timestamp.
   */
  private function now(): string {
    /** @var string $now */
    $now = ($this->clock)();
    return $now;
  }

  /**
   * A fresh run identifier, from the injected generator.
   *
   * @return string
   *   The identifier.
   */
  private function newId(): string {
    /** @var string $id */
    $id = ($this->ids)();
    return $id;
  }

}
