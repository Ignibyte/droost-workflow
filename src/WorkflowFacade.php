<?php

declare(strict_types=1);

namespace Droost\Workflow;

use Droost\Workflow\Baseline\BaselineError;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Baseline\BaselineWriter;
use Droost\Workflow\Cli\CliProcess;
use Droost\Workflow\State\StateError;
use Droost\Workflow\Vcs\CliVcs;
use Droost\Workflow\Vcs\VcsInterface;
use Droost\Workflow\Config\ContributedGate;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Event\NullWorkflowListener;
use Droost\Workflow\Event\WorkflowListenerInterface;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Evidence\CheckAdjudicatorInterface;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Evidence\SpecFreeze;
use Droost\Workflow\Evidence\SubjectHasher;
use Droost\Workflow\Evidence\WorkType;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Gate\SiteDriverInterface;
use Droost\Workflow\Mode\ModeEngine;
use Droost\Workflow\Mode\Outcome;
use Droost\Workflow\Mode\QuestionSinkInterface;
use Droost\Workflow\Mode\RunOutcome;
use Droost\Workflow\Seeker\SeekerLedger;
use Droost\Workflow\Spec\CriteriaVerification;
use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\Spec\SpecError;
use Droost\Workflow\Pack\InitReport;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Pack\PackRemover;
use Droost\Workflow\Pack\RemoveReport;
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
   * Observes lifecycle transitions. A notification, never the record.
   */
  private readonly WorkflowListenerInterface $listener;

  /**
   * Answers where the tree is (base commit) and what changed since.
   */
  private readonly VcsInterface $vcs;

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
   * @param \Droost\Workflow\Event\WorkflowListenerInterface|null $listener
   *   Observes lifecycle transitions (run start, phase change, completion).
   *   Optional and defaults to a no-op, so the CLI and every existing caller
   *   are unaffected; the Drupal surfaces inject a bridge that re-broadcasts to
   *   hooks. A notification, never the record — see the interface.
   * @param \Droost\Workflow\Vcs\VcsInterface|null $vcs
   *   Version-control facts: the base commit frozen at begin and the files
   *   changed since. Defaults to the git binary; a test injects a fake.
   * @param list<\Droost\Workflow\Config\ContributedGate>|null $contributed
   *   The gates enabled modules declare (D72). The Drupal surfaces pass their
   *   catalog (an empty list when no module contributes); the standalone CLI
   *   passes what drush answered, or NULL when nothing could be resolved —
   *   and NULL leaves a lever file's `gates.contributed` block alone rather
   *   than refusing it as naming a gate no module declares. Every lever load
   *   this facade performs resolves against the same list, so the run's
   *   frozen set and the status document agree.
   * @param string|null $contributedSource
   *   Where that list came from, for status (R31-F3): the booted site's
   *   catalog, drush asked from the standalone binary, or the reason none
   *   could be resolved. NULL lets the facade say "none resolved".
   * @param \Droost\Workflow\Evidence\CheckAdjudicatorInterface|null $checks
   *   The contributed checks this surface can ask, or NULL when it has
   *   none. Asked once per phase, after the gates and before advancing.
   */
  public function __construct(
    private readonly GateExecutorInterface $executor,
    private readonly SiteDriverInterface $driver,
    private readonly QuestionSinkInterface $sink,
    private readonly mixed $clock,
    private readonly mixed $ids,
    ?WorkflowListenerInterface $listener = NULL,
    ?VcsInterface $vcs = NULL,
    private readonly ?array $contributed = NULL,
    private readonly ?string $contributedSource = NULL,
    private readonly ?CheckAdjudicatorInterface $checks = NULL,
  ) {
    $this->listener = $listener ?? new NullWorkflowListener();
    $this->vcs = $vcs ?? new CliVcs(CliProcess::run(...));
  }

  /**
   * Installs the pack and the default lever file into a project.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $takeUpstream
   *   Pack destinations whose local drift is discarded in favour of the
   *   shipped version. The single value 'all' takes every drifted file. Empty
   *   (the default) keeps every user-edited file, as drift-aware init does.
   *
   * @return \Droost\Workflow\Pack\InitReport
   *   What was written and what was left alone.
   */
  public function init(string $projectRoot, array $takeUpstream = []): InitReport {
    return (new PackMaterializer())->init($projectRoot, $takeUpstream);
  }

  /**
   * Takes the pack back out, leaving the project's own work in place.
   *
   * The half `init` never had. Without it, a project that removed droost kept
   * three guard hooks wired in settings.json, running a guard that reads a
   * lever file for a module no longer installed — and the only way out was to
   * hand-edit the JSON, which the guard refuses.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return \Droost\Workflow\Pack\RemoveReport
   *   What went, what stayed, and what was left for the operator.
   */
  public function uninstall(string $projectRoot): RemoveReport {
    return (new PackRemover())->uninstall($projectRoot);
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
    $config = WorkflowConfig::load($projectRoot, $this->contributed);
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
        // Whether the adversarial reviewer inspects the diff after the code
        // gates pass. The single largest difference between `low` and
        // `medium`, frozen into run.json at begin — and until now absent from
        // the one command an operator reads BEFORE there is a run (F-12).
        'seekers' => $config->seekers,
        'seeker_rounds' => $config->seekerRounds,
        // The work-item integration for status: how a run's ticket is fetched
        // and written back. NULL when the repo declares none.
        'work_item' => $config->workItem?->toArray(),
        // Whether a committed adoption baseline is honoured (strict mode when
        // FALSE). What it holds is the `baseline` block below.
        'baseline' => $config->baseline,
        // The gates enabled modules contributed (D72), with their provenance
        // and the sentence each declared for its verdict — "not moved by the
        // dial": a level change never turns these on or off.
        'contributed' => array_map(
          static fn (ContributedGate $gate): array => [
            'provider' => $gate->provider,
            'phases' => $gate->phases,
            'default_mode' => $gate->defaultMode,
            'command' => $gate->command,
            'verdict' => $gate->verdict,
          ],
          $config->contributedGates,
        ),
        // Where this surface got that list (R31-F3): a booted site reads its
        // own catalog; the standalone binary asks drush; a surface that could
        // resolve none says so here rather than presenting a shorter set as
        // the whole truth.
        'contributed_source' => $this->contributedSource(),
      ],
      // The adoption baseline: present or not, honoured or not, what each
      // gate inherits — so "why did phpstan pass over 123 errors" is
      // answerable from status alone.
      'baseline' => $this->baselineSummary($projectRoot, $config),
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
      // The level the run is HELD to — frozen at begin, so it may lawfully
      // differ from levers.preset above after a mid-run lever edit. Without
      // it the drush report's header read "effort ?" (D70 round 1, T25):
      // the renderer asked the status document for a key that was only in
      // run.json.
      'preset' => $state->preset,
      // What the run asked enforcement to be, and what it CAN be on the host
      // the session declared: the wall and the phase guard are Claude Code
      // hooks, and a host without pre-tool hooks holds `hard` with nothing.
      // Said here so a report never claims a discipline the host never had.
      'enforcement' => $this->enforcementOnHost($state),
      'effective_mode' => $state->effectiveMode()->value,
      'current_phase' => $state->currentPhase?->value,
      // The judgment half of the record: whether the checkpoint is armed,
      // the latest parsed inspection, which browser tier the agent declared
      // and whether it can show the phases as host tasks — so "what was this
      // run actually verified by, and could anyone watch it" is answerable
      // from status alone.
      'seekers' => $state->seekers,
      'seeker_rounds' => $state->seekerRounds,
      'seeker' => $state->seeker,
      // The arc, not just the verdict: a clean re-inspection replaces the
      // record but must not erase what the earlier ones caught.
      'seeker_history' => $state->seekerHistory,
      'spec' => $state->specPath,
      // Promise against proof: which criteria name a test, which are
      // verified by hand (printed as manual, never as passed), which are
      // still empty — NULL when the spec carries no criteria table.
      // Declared rows first, the markdown table only where a run has none —
      // and `source` says which answered, so a reader is never guessing
      // whether a count came from a tool call or from a heading's position.
      'criteria' => CriteriaVerification::resolve($projectRoot, $state->runId, $state->specPath),
      'gate_waivers' => $state->gateWaivers,
      'browser' => $state->browser,
      'tasks' => $state->tasks,
      // Where the run started and which baseline it is held to — frozen at
      // begin, so a report can say what "new" was measured against.
      'base_commit' => $state->baseCommit,
      'baseline_hash' => $state->baselineHash,
      // Which door began the run, as far as contributed gates go, and which
      // of them a later, seeing surface had to weave in (R31-F3/F5).
      'contributed_source' => $state->contributedSource,
      'late_woven' => $state->lateWoven,
      'phases' => array_map(
        static fn ($s): string => $s->value,
        $state->phases,
      ),
      // The run's own frozen map, which may lawfully differ from the
      // levers' current one above.
      'phase_gates' => $state->phaseGates,
      'gate_reports' => $state->gateResults,
      // HOW MANY TIMES EACH GATE WAS ASKED, and how many asks are left (F-21).
      // The run envelope has carried this since the budget existed; `status`
      // did not, and `status` is what `droost:workflow:report` reads — the
      // surface an operator looks at AFTER a run ends. So no reader could tell
      // "this gate failed" from "this gate failed, spent its budget, and has
      // not been asked since", and only the second sentence explains a verdict
      // that names spec rows the agent has already corrected.
      //
      // Same method as the envelope, not a second copy of the arithmetic.
      'retries' => $state->retries(),
      'awaiting' => $question?->toArray(),
      'answered' => count($state->qaHistory),
    ];
    return $status;
  }

  /**
   * The enforcement a run requested, and what it amounts to on its host.
   *
   * Enforcement is the harness hooks — the write wall, the plan-phase guard,
   * the mid-phase stop challenge — and those are Claude Code hooks. The
   * declared task surface is the best proxy for the host the session runs
   * in: `claude-code` runs the pack's hooks; `codex`, `other` and `none` have
   * no pre-tool hook to run them, so `hard` there is ADVISORY — the briefs
   * carry the rules as instructions, and the gates still hold the run
   * server-side, but nothing stops an out-of-phase edit. An undeclared
   * surface is reported as requested, with the caveat.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   *
   * @return array{requested: string, effective: string, reason: string}
   *   The requested level, the effective one, and why.
   */
  private function enforcementOnHost(RunState $state): array {
    $requested = $state->enforcement->value;
    if ($requested === 'off') {
      return ['requested' => 'off', 'effective' => 'off', 'reason' => 'the lever turned enforcement off'];
    }
    if ($state->tasks === NULL) {
      return [
        'requested' => $requested,
        'effective' => $requested,
        'reason' => 'the host surface is undeclared (declare-tasks); the pack hooks enforce wherever the host runs them',
      ];
    }
    if ($state->tasks === 'claude-code') {
      return ['requested' => $requested, 'effective' => $requested, 'reason' => 'the declared host runs the pack hooks'];
    }
    return [
      'requested' => $requested,
      'effective' => 'advisory',
      'reason' => sprintf(
        'the declared host surface (%s) runs no pre-tool hooks: the write wall and the phase guard cannot fire there. '
        . 'The gates still hold the run server-side and the briefs carry the rules as instructions, but an '
        . 'out-of-phase edit is not stopped.',
        $state->tasks,
      ),
    ];
  }

  /**
   * The adoption baseline, as a status document block.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, mixed>
   *   `present`, `honoured`, `dir`, and when present: when and at which
   *   commit it was written, the level then in force, per-gate inherited
   *   counts, how many times it grew, and its current hash. An unreadable
   *   manifest reports `error` rather than pretending to be absent.
   */
  public function baselineStatus(string $projectRoot): array {
    return $this->baselineSummary($projectRoot, WorkflowConfig::load($projectRoot, $this->contributed));
  }

  /**
   * The bill: what each consulting gate would inherit if baselined now.
   *
   * Read-only, so anyone may ask — the agent included, which is how it
   * grounds a proposal to baseline. Runs every consulting tool the level
   * turns on, so on a large tree it takes as long as a code-phase gate run.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string>|null $configDrift
   *   The site's current config drift, from the live surface, or NULL.
   *
   * @return array<string, mixed>
   *   The level, the per-gate bill, and the gates that could not be measured.
   */
  public function baselineMeasure(string $projectRoot, ?array $configDrift = NULL): array {
    $config = WorkflowConfig::load($projectRoot, $this->contributed);
    $measured = $this->writer()->measure($config, $projectRoot, $configDrift);
    return [
      'level' => $config->preset,
      'bill' => $measured->bill(),
      'skipped' => $measured->skipped,
    ];
  }

  /**
   * Writes the adoption baseline, or refreshes it under the ratchet.
   *
   * The OPERATOR's act: the surfaces demand a terminal and the pack guard
   * refuses the agent's shell. Refused while a run is active, because a
   * baseline that changes under a run fails every gate that consults it.
   *
   * @param string $projectRoot
   *   The repository.
   * @param bool $refresh
   *   Re-measure an existing baseline (paid-off debt drops).
   * @param bool $grow
   *   Allow the refresh to record more debt than before.
   * @param string|null $reason
   *   Why growth is accepted; required with $grow.
   * @param list<string>|null $configDrift
   *   The site's current config drift, from the live surface, or NULL.
   *
   * @return array<string, mixed>
   *   The manifest written, the bill, the skipped gates, and the new hash.
   *
   * @throws \Droost\Workflow\Baseline\BaselineError
   *   When a run is active, a first write finds a baseline, a refresh finds
   *   none, growth is refused, or --grow lacks its reason.
   */
  public function baselineWrite(
    string $projectRoot,
    bool $refresh = FALSE,
    bool $grow = FALSE,
    ?string $reason = NULL,
    ?array $configDrift = NULL,
  ): array {
    $active = (new RunStateStore($projectRoot))->load();
    if ($active !== NULL && $active->currentPhase !== NULL) {
      throw BaselineError::runActive();
    }
    $config = WorkflowConfig::load($projectRoot, $this->contributed);
    $measured = $this->writer()->measure($config, $projectRoot, $configDrift);
    $manifest = $this->writer()->write(
      $projectRoot,
      $measured,
      $config->preset,
      $this->vcs->head($projectRoot),
      $refresh,
      $grow,
      $reason,
    );
    return [
      'manifest' => $manifest->toArray(),
      'bill' => $measured->bill(),
      'skipped' => $measured->skipped,
      'hash' => BaselineStore::hash($projectRoot),
      'dir' => BaselineStore::DIR,
    ];
  }

  /**
   * The baseline writer for this surface.
   *
   * @return \Droost\Workflow\Baseline\BaselineWriter
   *   The writer.
   *
   * @throws \LogicException
   *   When this facade's executor cannot measure (a test fake).
   */
  private function writer(): BaselineWriter {
    if (!$this->executor instanceof ShellGateExecutor) {
      throw new \LogicException('baseline measurement needs the shell executor — this facade was built with another.');
    }
    return new BaselineWriter($this->executor, $this->clock);
  }

  /**
   * Where this surface's contributed gates came from, for status (R31-F3).
   *
   * @return string
   *   The surface's own sentence when it gave one; otherwise "none resolved"
   *   for an empty list, so a shorter gate set never reads as the whole.
   */
  private function contributedSource(): string {
    if ($this->contributedSource !== NULL) {
      return $this->contributedSource;
    }
    if ($this->contributed === NULL) {
      return 'none resolved by this surface';
    }
    return $this->contributed === [] ? 'declared to this surface (none)' : 'declared to this surface';
  }

  /**
   * The baseline block for a resolved configuration.
   *
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The resolved levers (for the `baseline.on` switch).
   *
   * @return array<string, mixed>
   *   The block.
   */
  private function baselineSummary(string $projectRoot, WorkflowConfig $config): array {
    $block = [
      'present' => BaselineStore::exists($projectRoot),
      'honoured' => FALSE,
      'lever' => $config->baseline,
      'dir' => BaselineStore::DIR,
    ];
    if (!$block['present']) {
      return $block;
    }
    try {
      $baseline = BaselineStore::load($projectRoot);
    }
    catch (BaselineError $e) {
      $block['error'] = $e->getMessage();
      return $block;
    }
    if ($baseline === NULL) {
      return $block;
    }
    $gates = [];
    foreach ($baseline->manifest->gates as $gate => $entry) {
      $gates[$gate] = $entry['count'];
    }
    // Assigned, not unioned: `+` keeps the LEFT operand's keys, so the FALSE
    // placeholder above won every time and a present, lever-on baseline
    // reported "present but OFF (strict mode)" on the first live room.
    $block['honoured'] = $config->baseline;
    return $block + [
      'generated_at' => $baseline->manifest->generatedAt,
      'generated_commit' => $baseline->manifest->generatedCommit,
      'preset' => $baseline->manifest->preset,
      'gates' => $gates,
      'grown' => count($baseline->manifest->grown),
      'hash' => BaselineStore::hash($projectRoot),
    ];
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
        || GateSettings::isContributed($name)
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
   * @param string|null $spec
   *   The governing spec's path (--spec), when the caller declares one.
   *   Resolved against the record: a declaration that contradicts the
   *   recorded spec refuses rather than silently swapping the run's
   *   criteria.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
   *   What happened. The state is persisted before this returns.
   */
  public function run(string $projectRoot, ?string $spec = NULL): RunOutcome {
    $store = new RunStateStore($projectRoot);
    $state = $store->load();

    if ($state === NULL) {
      $config = WorkflowConfig::load($projectRoot, $this->contributed);
      // Frozen with the levers: where the tree is, and which baseline the
      // run is held to (none when the lever refuses one). A baseline that
      // moves under the run is then caught by every gate that consults it.
      $state = RunState::begin(
        $this->newId(),
        $this->now(),
        $config,
        $this->vcs->head($projectRoot),
        $config->baseline ? BaselineStore::hash($projectRoot) : NULL,
        $this->contributedSource(),
      );
      $store->save($state);
      $this->notify(fn () => $this->listener->onRunStart($state));
      // The run's first phase is now active: the first cycle step begins.
      if ($state->currentPhase !== NULL) {
        $begin = $state->currentPhase;
        $this->notify(fn () => $this->listener->onPhaseBegin($state, $begin));
      }
    }

    // A run with no spec yet is a run that has just OPENED, not one that has
    // failed. The plan phase grounds before it writes the spec — dozens of
    // knowledge-tool calls — and until the run exists nothing can attribute
    // them: the ledger wrote `run: null`, the evidence store's tool_call table
    // never saw the plan phase at all, and the per-run grounding filter nearly
    // read the plan's own lookups as nobody's (F-25). So the order is: open
    // the run, THEN ground, THEN declare the spec. A bare `run` here has done
    // the first step; it says so and waits, and the plan phase is never gated
    // without a document to gate it against.
    if ($state->currentPhase === Phase::Plan
      && $state->specPath === NULL
      && ($spec === NULL || $spec === '')
      && SpecContract::candidates($projectRoot) === []) {
      return new RunOutcome(Outcome::Blocked, $state, NULL, NULL, [self::awaitingSpecRow($store)]);
    }

    // The governing spec, resolved once and recorded: declared via --spec,
    // carried from the record, or adopted when exactly one candidate exists.
    // Recorded on the state so every later phase checks THE SAME document —
    // and so a --spec that contradicts the record refuses instead of
    // silently swapping the run's criteria mid-flight.
    if ($state->currentPhase !== NULL) {
      $resolved = SpecContract::resolve($projectRoot, $spec, $state->specPath);
      if ($resolved !== $state->specPath) {
        $state = $state->withSpecPath($resolved);
        $store->save($state);
      }
    }

    $phase = $state->currentPhase;
    if ($phase === NULL) {
      // Naming a spec at a run that is OVER is not a no-op, it is a mistake
      // with no feedback. `run --spec=<the next ticket's spec>` returned
      // `{"outcome":"completed","report":null}` — the spec was never adopted,
      // nothing ran, and the caller's next move was made believing a new
      // ticket had started under a new contract. A reviewer hit it on the
      // second of three tickets.
      //
      // The spec resolution above is skipped in this branch (there is no
      // phase to govern), so this is the only place that can notice.
      if ($spec !== NULL && $spec !== '') {
        throw StateError::runEndedBeforeSpec($store->label(), $spec);
      }
      // The run reached its terminal gate. Re-running does not restart it;
      // saying so is more useful than silently beginning a second run.
      return new RunOutcome(Outcome::Completed, $state);
    }

    if ($state->statusOf($phase) === PhaseStatus::Failed) {
      if (!$this->waiversCoverTheFailure($state, $phase)) {
        // The phase spent its retry budget. Re-running would silently restart
        // a run the engine already declared over — so nothing executes.
        //
        // WITH THE REASON, and with the way out. This returned
        // `{"outcome":"failed","report":null,"blocked":[],"awaiting":null}`
        // on every later `run`, byte-identical, for ever — a reviewer drove
        // nine. The `retries` block names a gate and a COUNT, not what the
        // gate found; the comment here used to claim it "says why" and it
        // does not. The same shape was fixed for the two `Blocked` paths and
        // this one was missed.
        //
        // The last real report is still in run.json, and `status` and
        // `evidence` render it in full — but nothing in the envelope named
        // either, or named `reset`, so an agent driving from the envelope had
        // the word `failed` and nothing else.
        return new RunOutcome(
          Outcome::Failed,
          $state,
          NULL,
          NULL,
          self::terminalRows($state, $phase, $projectRoot),
        );
      }
      // Every gate that killed the phase has since been waived by the
      // operator — a signed, reasoned act through the terminal, refused from
      // the agent's shell. That is the one deliberate recovery short of
      // reset: the phase reopens and runs again with those gates recorded
      // as WAIVED (never passed), so a long governed run is not lost to a
      // gate the operator has answered for. D70 round 2: a rendered_check
      // that failed only inside the gate run, on a page that rendered 200
      // every other way, would otherwise have cost a four-hour run at max.
      $state = $state->withPhaseStatus($phase, PhaseStatus::Active);
      $store->save($state);
    }

    // The spec holds up its end before the phase runs. Leaving plan needs
    // the tooling plan — every deliverable mapped to the surface that
    // builds it, hand-written only with a stated reason — so "exhaust the
    // generators first" is a checked contract, not advice. Gating complete
    // needs the realized capture, so a run cannot close having left its own
    // document behind.
    $specPath = $state->specPath;
    // The spec is the contract, and until the plan ends it is still being
    // written. Afterwards it is the thing the run is HELD to — so every phase
    // past plan verifies that the sections the gates read are the ones the
    // plan froze, before reading them.
    //
    // Sections, not the whole file: `## Realized` is appended at complete by
    // requirement and the seeker appends its ledgers as they happen, so the
    // document must stay writable. What may not move is the tooling plan, the
    // grounding table and the acceptance criteria.
    // THE FREEZE IS RECORDED TOO (F-33, F-35). A rewritten frozen section used
    // to throw SpecError::contractChanged, whose only remedy is destroying the
    // run — and P3-T1-a2 took it, honestly, having refused to manufacture tool
    // calls to satisfy a list it no longer meant. An agent that legitimately
    // needs different tooling than it predicted has no lawful edit, so the
    // freeze punished honesty. Recorded now; Phase B removes the need for it
    // entirely by making the facts rows rather than prose.
    if ($specPath !== NULL && $phase !== Phase::Plan) {
      $this->recordFrozenSpecDrift($state, $projectRoot, $specPath, $phase);
    }
    // SPEC SHAPE IS RECORDED, NOT ENFORCED (F-35, Phase A).
    //
    // Everything this block used to do, it did by THROWING. Grounding tiers
    // unreached, routes undeclared, a `## Realized` section absent, criteria
    // without a `Verified By` cell — each was a SpecError that ended the run.
    //
    // Part 3 measured the cost. Across three runs every code-quality gate
    // passed every time — phpcs, phpstan, config_clean, rendered_check — and
    // all three stoppages were the shape of a markdown file: a route list
    // inside a fence (F-32, F-34), a rewritten frozen section whose only
    // remedy was destroying the run (F-33), and a data table under the wrong
    // heading which left a complete, live, verified build unclosable (F-35).
    //
    // The rule this now obeys: there is no judgement from droost, only "did
    // it do the job or not" in binary. The shape of prose is not a job the
    // agent did or did not do — it is a lint. So each observation becomes a
    // finding on a `spec` / `shape` check in CheckState::Recorded, which
    // renders as "recorded (not blocking)": the same treatment a report-mode
    // gate whose tool is absent already gets. The report names every one of
    // them and none of them stops a run.
    //
    // The parser stays deliberately. Phase B replaces it with rows written by
    // tool calls; until then its observations are still worth reading. They
    // were never wrong about what the document said — only about whether that
    // should end a run.
    $observations = $this->specShapeObservations($projectRoot, $specPath, $phase, $state->runId);
    if ($observations !== []) {
      $this->recordSpecShape($state, $projectRoot, $phase, $observations);
    }
    if ($specPath !== NULL && $phase === Phase::Complete) {
      // Still recorded, and still in the report — but the return value no
      // longer gates anything.
      $this->recordCriteriaContract(
        $state,
        $projectRoot,
        CriteriaVerification::resolve($projectRoot, $state->runId, $specPath) !== NULL,
      );
    }

    // Every door, the same gates (R31-F3/F5): a run begun on a surface that
    // could not see the site's contributed catalog froze a shorter set. A
    // surface that CAN see it weaves the missing gates in here, on record,
    // before this phase's gates run — and complete re-runs everything, so no
    // run finishes without the gates the site declared.
    $state = $this->weaveLateContributed($state, $phase, $projectRoot, $store);

    $outcome = $this->engine()->runPhase(
      $state,
      $phase,
      $projectRoot,
      $this->now(),
    );
    // Scope is audited where it is created. A file touched that nobody declared
    // is the drift the seeker exists to catch by judgement — and the seeker is
    // OFF below `medium`, so on a light run nothing looked at all. This looks
    // by arithmetic, at every level.
    //
    // It runs after the gates and before the advance, so a phase whose tools
    // were all green still does not move while its diff exceeds its plan. The
    // checks land in the evidence store either way, which is what lets the Stop
    // hook name the reason rather than repeat that a phase is open.
    $outcome = $this->auditDeclarations($outcome, $phase, $projectRoot);
    $advanced = $this->advanceIfDue($outcome, $phase);
    // The moment the contract stops being drafted and starts being binding.
    // Frozen on the way OUT of plan, not on the way in, because plan is where
    // the spec is written — and only when plan actually passed, so a refused
    // phase does not pin a contract the run never satisfied.
    if ($phase === Phase::Plan
      && $specPath !== NULL
      && ($advanced->state->phases[Phase::Plan->value] ?? NULL) === PhaseStatus::Passed) {
      $this->freezeSpec($projectRoot, $specPath, $advanced->state->runId, $this->now());
    }
    $store->save($advanced->state);
    $this->announceAdvanceOrComplete($phase, $advanced->state);

    return $advanced;
  }

  /**
   * Weaves contributed gates this surface can see and the run's record lacks.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run about to be gated.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase about to run.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\State\RunStateStore $store
   *   Where the amended record is saved.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run, amended and saved when anything joined; otherwise unchanged.
   */
  private function weaveLateContributed(RunState $state, Phase $phase, string $projectRoot, RunStateStore $store): RunState {
    if ($this->contributed === NULL || $this->contributed === []) {
      return $state;
    }
    $config = WorkflowConfig::load($projectRoot, $this->contributed);
    $missing = [];
    foreach ($config->gates as $name => $settings) {
      if (GateSettings::isContributed($name) && !array_key_exists($name, $state->resolvedGates)) {
        $missing[$name] = $settings;
      }
    }
    if ($missing === []) {
      return $state;
    }
    $woven = $state->withLateContributed($missing, $phase);
    if ($woven !== $state) {
      $store->save($woven);
    }
    return $woven;
  }

  /**
   * Answers the question a paused run is waiting on.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $answer
   *   What the human said.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
   *   What answering did — `Advanced`, `Completed`, `Failed` when the answer
   *   ended the run, or `Blocked` with the rows that hold the phase. The run
   *   inside is no longer awaiting and already persisted.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When there is no run to answer.
   */
  public function answer(string $projectRoot, string $answer): RunOutcome {
    $store = new RunStateStore($projectRoot);
    $state = $this->requireRun($store);
    $answered = $this->engine()->answer($state, $answer, $this->now(), $projectRoot);
    // A "stop here" to the stuck question FAILED the phase in the engine. That
    // is a human's decision about the run and it is returned as one, before
    // the held-check path below can mistake its own `stopped_by_operator` row
    // for an ordinary block.
    $stoppedAt = $answered->currentPhase;
    if ($stoppedAt !== NULL && $answered->statusOf($stoppedAt) === PhaseStatus::Failed) {
      $store->save($answered);

      return new RunOutcome(Outcome::Failed, $answered);
    }
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
      // INTERACTIVE MODE ADVANCES HERE, NOT THROUGH run(). Both new contracts
      // hung off run()'s non-paused path, so an interactive run froze no spec
      // and audited no declaration: measured, spec_hash NULL, zero declaration
      // rows, and a criterion rewritten mid-run went unnoticed while the same
      // rewrite under agentic mode was refused. A discipline that switches
      // itself off in one of the two supported modes is not a discipline.
      // And it must DECIDE here, not merely record. The verdict was computed
      // and thrown away, so the same undeclared file blocked the code phase
      // under `agentic` and advanced under `interactive` — leaving a blocked
      // row behind in a run that carried on regardless. Half a discipline is
      // the exact shape the comment above was already written against.
      // UNRESOLVED CHECKS HOLD THE PHASE AT EVERY PHASE, not just the two the
      // declaration audit speaks for. `answer` re-audited Code and Test and
      // fell straight through to `advanceTo()`/`complete()` everywhere else,
      // so a reviewer drove sixty blocked rows at `complete`, answered the
      // ceiling's question with "keep going", and got:
      //
      //     answered — the run completed
      //     plan passed | code passed | test passed | complete passed
      //     rows left: 60 blocked contributed_checks at complete
      //
      // Sixty unresolved blocks, every phase reported passed. The ceiling's
      // escape is meant to let a human say "I have seen this, carry on" — and
      // it was laundering the blocks into a green instead of surfacing them.
      // Same at `plan`.
      //
      // A human CAN still end a stuck run: `answer "stop here"` fails the
      // phase and records `stopped_by_operator`. What they cannot do is
      // advance past a check that is still blocked, because the answer does
      // not change what the check found.
      $unresolved = in_array($phase, [Phase::Code, Phase::Test, Phase::Complete], TRUE)
        ? $this->auditDeclarationsFor($answered, $phase, $projectRoot)
        : self::blockingRows($answered, $phase, $projectRoot) !== [];
      if ($unresolved) {
        // The phase does not ADVANCE, and it does not FAIL either. Marking it
        // Failed here was terminal and unrecoverable: `run()` then refuses
        // unless `waiversCoverTheFailure()` agrees, and that reads blocking
        // GATE rows — but the phase paused precisely because the gates passed,
        // so the blocking set is empty and no waiver, however many, could
        // reopen it. The only exit was abandoning the run.
        //
        // A declaration block is a correctable condition: declare the file, or
        // stop touching it, and answer again. Leaving the phase ACTIVE is what
        // makes that possible, and the blocked rows are already recorded.
        //
        // AND IT SAYS SO. The rows were computed one line up and thrown away,
        // and the CLI chose its line from the phase alone — so a held answer
        // printed `answered — now at plan`, exit 0, byte for byte what an
        // advance prints. A reviewer drove forty-three `answer`/`run` cycles
        // that way, every one exit 0, every one saying the same thing, and
        // could not tell "advanced" from "still here" from the surface at
        // all. The phase not moving IS the outcome, so it is returned as one,
        // with what holds it — the same envelope `run()` returns for the same
        // condition.
        $store->save($answered);

        return new RunOutcome(
          Outcome::Blocked,
          $answered,
          NULL,
          NULL,
          self::blockingRows($answered, $phase, $projectRoot),
        );
      }
      $next = $this->nextPhase($answered, $phase);
      $answered = $next === NULL
        ? $answered->complete()
        : $answered->advanceTo($next);
      if ($phase === Phase::Plan && $answered->specPath !== NULL) {
        $this->freezeSpec($projectRoot, $answered->specPath, $answered->runId, $this->now());
      }
    }
    $store->save($answered);
    if ($phase !== NULL) {
      $this->announceAdvanceOrComplete($phase, $answered);
    }

    return new RunOutcome(
      $answered->currentPhase === NULL ? Outcome::Completed : Outcome::Advanced,
      $answered,
    );
  }

  /**
   * Clears a finished run: archives its record and removes run.json.
   *
   * A completed (or failed) run.json persists — deliberately, it is the
   * record — and start refuses to clobber it, so multi-ticket work needs a
   * sanctioned way to finish one run and begin the next. The record is
   * archived to <state dir>/history/<run_id>.json, never discarded; a
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
    // Everything below reads the store's RESOLVED state directory, so reset
    // finds the run wherever it lives — the visible droost/droost-workflow or
    // a project still on the legacy hidden dir.
    $stateDir = $store->directory();
    $path = $store->path();
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
    $history = $stateDir . '/history';
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
    // The two append-only ledgers go into history beside the run they belong
    // to. They used to stay put: `tool-calls.jsonl` outlived every reset, its
    // rows carried no run id, and the next run's ingest — a count watermark
    // from zero — took the whole file as its own. Measured: round two of a
    // pair opened holding round one's 51 tool calls, and grounding_check's
    // "the plan named a tool that was never called" drift check was satisfied
    // by the previous round having called it (F-6). Same base name as the
    // record, same collision suffix, so history/<run>.* is one complete run.
    foreach (['tool-calls.jsonl', 'guard-calls.jsonl'] as $ledger) {
      $source = $stateDir . '/' . $ledger;
      if (!is_file($source)) {
        continue;
      }
      $archived = $history . '/' . $base . '.' . $ledger;
      for ($n = 2; is_file($archived); $n++) {
        $archived = $history . '/' . $base . '-' . $n . '.' . $ledger;
      }
      if (@rename($source, $archived) === FALSE) {
        throw StateError::archiveFailed($archived, 'the ' . $ledger . ' ledger could not be moved');
      }
    }
    foreach (glob($stateDir . '/.guard-warned-*') ?: [] as $marker) {
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
   * @throws \InvalidArgumentException
   *   When the target mode is not agentic, or the run is waiting on a STUCK
   *   question — which a swap would dismiss without answering, clearing the
   *   pause and resetting the counter with nothing recorded.
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
   * @return array<string, bool|int|string>
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
    $ledger = SeekerLedger::parse($ledgerText);
    $record = $ledger->toRecord($this->now());
    $state = $state->withSeekerReport($record);
    $store->save($state);

    // The findings as ROWS, not as a count beside prose nobody queries. The
    // table existed and nothing wrote it; `RunState`'s docblock keeps the bill
    // — across four rounds, 6, 25, 12 and 20 findings were caught and recorded
    // as 0, 0, 6 and 2. Never allowed to fail the recording: the inspection
    // itself is already saved above.
    try {
      (new EvidenceStore($projectRoot))->recordSeekerFindings(
        $state->runId,
        $state->currentPhase instanceof Phase ? $state->currentPhase->value : 'code',
        count($state->seekerHistory),
        $ledger->findings,
      );
    }
    catch (\Throwable) {
      // The store is unreachable; the run record still carries the verdict.
    }

    return $record;
  }

  /**
   * The phase a declaration is stamped with.
   *
   * `requireRun()` has already refused a finished run, so in practice this is
   * always set — but the property is nullable and a declaration stamped with
   * an invented phase would be worse than one stamped with nothing. An empty
   * string reads as "the record could not say", which is a fact; `plan` would
   * be a guess wearing the costume of one.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The open run.
   *
   * @return string
   *   The phase, or ''.
   */
  private static function openPhase(RunState $state): string {
    return $state->currentPhase === NULL ? '' : $state->currentPhase->value;
  }

  /**
   * Declares a route this ticket's change serves.
   *
   * The tool-call half of the redesign: `rendered_check` renders rows, not a
   * markdown section. `## Routes` stays in the spec for a human, and nothing
   * mechanical depends on where it sits or whether the writer fenced it.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $path
   *   The route, as the site serves it. Must begin with `/`: a route is a
   *   path, and the one thing worth refusing here is a value that cannot be
   *   requested, because the alternative is a gate rendering it and failing.
   * @param string|null $reason
   *   Why the route is in scope, for the reader. Never read mechanically.
   *
   * @return array<string, mixed>
   *   The routes the run now declares.
   *
   * @throws \InvalidArgumentException
   *   When the path is not a path.
   */
  public function declareRoute(string $projectRoot, string $path, ?string $reason = NULL): array {
    $path = trim($path);
    if ($path === '' || !str_starts_with($path, '/')) {
      throw new \InvalidArgumentException(sprintf(
        'A route is a path the site serves and must begin with "/" — got "%s"',
        $path,
      ));
    }
    $state = $this->requireRun(new RunStateStore($projectRoot));
    $store = new EvidenceStore($projectRoot);
    $store->declareRoute($state->runId, self::openPhase($state), $path, $reason, $this->now());

    return ['run' => $state->runId, 'routes' => $store->specRoutes($state->runId)];
  }

  /**
   * Declares an acceptance criterion, or restates one.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $ref
   *   The criterion's id, e.g. `AC-1`.
   * @param string $statement
   *   What must be true, in the agent's own words.
   *
   * @return array<string, mixed>
   *   The criteria the run now declares.
   *
   * @throws \InvalidArgumentException
   *   When either is empty.
   */
  public function declareCriterion(string $projectRoot, string $ref, string $statement): array {
    $ref = trim($ref);
    $statement = trim($statement);
    if ($ref === '' || $statement === '') {
      throw new \InvalidArgumentException(
        'A criterion needs a ref and a statement: declare-criterion AC-1 "the listing shows every published rink"',
      );
    }
    $state = $this->requireRun(new RunStateStore($projectRoot));
    $store = new EvidenceStore($projectRoot);
    $store->declareCriterion($state->runId, self::openPhase($state), $ref, $statement, $this->now());

    return ['run' => $state->runId, 'criteria' => $store->specCriteria($state->runId)];
  }

  /**
   * Records what proves a criterion.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $ref
   *   The criterion's id.
   * @param string $verifiedBy
   *   The test, route or command that proves it.
   *
   * @return array<string, mixed>
   *   The criteria the run now declares.
   *
   * @throws \Droost\Workflow\Spec\SpecError
   *   When no such criterion was declared. The only refusal on this surface,
   *   and the reason it is one: a run that can verify unstated criteria
   *   proves whatever it happened to do, which is the circularity the
   *   evidence store exists to make impossible.
   */
  public function verifyCriterion(string $projectRoot, string $ref, string $verifiedBy): array {
    $ref = trim($ref);
    $verifiedBy = trim($verifiedBy);
    if ($ref === '' || $verifiedBy === '') {
      throw new \InvalidArgumentException(
        'A verification needs a ref and what proves it: verify-criterion AC-1 "RinkListTest::testEveryPublished"',
      );
    }
    $state = $this->requireRun(new RunStateStore($projectRoot));
    $store = new EvidenceStore($projectRoot);
    if (!$store->verifyCriterion($state->runId, self::openPhase($state), $ref, $verifiedBy, $this->now())) {
      throw SpecError::criterionNotDeclared($ref, array_column($store->specCriteria($state->runId), 'ref'));
    }

    return ['run' => $state->runId, 'criteria' => $store->specCriteria($state->runId)];
  }

  /**
   * Records anything else the spec would have said in prose.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $kind
   *   One of `grounding`, `tooling`, `decision`, or a contributed step's own.
   * @param string $subject
   *   What the note is about.
   * @param string|null $detail
   *   The rest, for a reader.
   *
   * @return array<string, mixed>
   *   The notes the run now declares.
   *
   * @throws \InvalidArgumentException
   *   When either required part is empty.
   */
  public function declareNote(string $projectRoot, string $kind, string $subject, ?string $detail = NULL): array {
    $kind = trim($kind);
    $subject = trim($subject);
    if ($kind === '' || $subject === '') {
      throw new \InvalidArgumentException(
        'A note needs a kind and a subject: declare-note grounding "Drupal\\node\\Entity\\Node"',
      );
    }
    $state = $this->requireRun(new RunStateStore($projectRoot));
    $store = new EvidenceStore($projectRoot);
    $store->declareNote($state->runId, self::openPhase($state), $kind, $subject, $detail, $this->now());

    return ['run' => $state->runId, 'notes' => $store->specNotes($state->runId)];
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
   * Records what this phase intends to change, and what will cover it.
   *
   * Nothing asked for this before. The `## Tooling plan` maps constructs to
   * surfaces and never names a path; the `Verified By` column names a test and
   * is filled at the TEST phase, after the fact. The only statement of scope
   * was a sentence of prose in the code brief that nothing checked.
   *
   * A declaration is not a promise the agent grades itself against: the code
   * phase audits it against the diff. Files touched but never declared block;
   * files declared and not touched are recorded and do not, because plans
   * shrink for good reasons and a run that wedged over one would teach the
   * agent to pad its declarations.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param list<string> $files
   *   Paths this phase will change. A directory covers what is under it.
   * @param list<string> $tests
   *   Tests that will cover the work — classes, methods or paths.
   * @param string|null $type
   *   What KIND of work this is — see WorkType. Distinct from the effort dial:
   *   preset says how hard to try, this says what is being built, and the two
   *   are orthogonal. Never a waiver; it is audited against the diff.
   * @param string|null $workItem
   *   The ticket or request this run answers, when the site's process wants
   *   one. Sites that contribute the `work_item_declared` check require it;
   *   everywhere else it is simply unrecorded.
   *
   * @return array{files: list<string>, tests: list<string>, type: string|null}
   *   What was recorded.
   *
   * @throws \InvalidArgumentException
   *   When nothing was declared, or there is no run to declare against.
   */
  public function declareChanges(string $projectRoot, array $files, array $tests, ?string $type = NULL, ?string $workItem = NULL): array {
    $files = self::cleanList($files);
    $tests = self::cleanList($tests);
    $workType = NULL;
    if ($type !== NULL && $type !== '') {
      $workType = WorkType::parse($type);
      if ($workType === NULL) {
        throw new \InvalidArgumentException(sprintf(
          'Unknown work type "%s". Use one of: %s.',
          $type,
          implode(', ', WorkType::names()),
        ));
      }
    }
    if ($files === [] && $tests === [] && $workType === NULL) {
      throw new \InvalidArgumentException(
        'declare-changes needs at least one file or test. An empty declaration '
        . 'is not a small scope, it is no scope — and the code phase audits '
        . 'the diff against what was declared.'
      );
    }
    $state = $this->requireRun(new RunStateStore($projectRoot));
    $currentPhase = $state->currentPhase;
    $phase = $currentPhase === NULL ? 'plan' : $currentPhase->value;
    $store = new EvidenceStore($projectRoot);
    $now = $this->now();
    if ($workType !== NULL) {
      $store->upsertRun($state->runId, [
        'work_type' => $workType->value,
        'work_type_declared_at' => $now,
      ]);
    }
    // A re-declaration REPLACES. Appending meant an agent correcting a mistake
    // inherited both the old promise and the new one, and with no `undeclare`
    // verb a declaration it could not satisfy left no move but abandoning the
    // run. Only the kinds actually passed are touched, so `--tests=` alone does
    // not silently wipe the file declaration. Replaced, not erased: the
    // superseded rows stay, so the audit can still name the paths the first
    // declaration never predicted (see supersedeDeclarations()).
    // `work_item` is the third kind because a check shipped that READS it and
    // nothing anywhere could write it. Any site adding the documented
    // `work_item:` block got a blocking check with fault `agent`, whose own
    // guidance says "There is no waiver for it" — so every run failed at plan,
    // permanently, told to declare a ticket by a tool with no way to declare
    // one. That is the SpecFreeze deadlock's exact shape, which
    // `DeclarationAudit` names in a comment fifty lines from the bug.
    $kinds = ['file' => $files, 'test' => $tests];
    if ($workItem !== NULL && trim($workItem) !== '') {
      $kinds['work_item'] = [trim($workItem)];
    }
    foreach ($kinds as $kind => $values) {
      if ($values === []) {
        continue;
      }
      $revision = $store->supersedeDeclarations($state->runId, $kind, $now);
      foreach ($values as $value) {
        $store->declare($state->runId, $phase, $kind, $value, $now, $revision);
      }
    }

    return [
      'files' => $files,
      'tests' => $tests,
      'type' => $workType?->value,
      'work_item' => $workItem !== NULL && trim($workItem) !== '' ? trim($workItem) : NULL,
    ];
  }

  /**
   * A comma-or-repeat argument list, trimmed and deduplicated.
   *
   * @param list<string> $values
   *   The raw values.
   *
   * @return list<string>
   *   The cleaned values.
   */
  private static function cleanList(array $values): array {
    $flat = [];
    foreach ($values as $value) {
      foreach (explode(',', $value) as $part) {
        $part = trim($part);
        if ($part !== '') {
          $flat[] = $part;
        }
      }
    }

    return array_values(array_unique($flat));
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
   * The evaluation for a run, generated from the record rather than about it.
   *
   * Filling one of these by hand took six files and several hours, and the
   * hand-filled version is a person's reading of a record they could not query.
   * Every section here that can be derived IS derived; the ones that need a
   * human's judgement say so rather than guessing.
   *
   * The `still green?` column is why `SubjectHasher` exists. A verdict stored
   * alone is a sticker; stored beside a fingerprint of what it examined, it
   * expires by itself when the files move. Recomputing the fingerprints here is
   * what makes that mechanism real rather than merely present — it was built,
   * tested, and called by nothing.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string|null $runId
   *   The run, or NULL for the run recorded in this project's state.
   * @param string|null $writeTo
   *   A project-relative path to write to, or NULL to only return the markdown.
   *
   * @return array{markdown: string, run_id: string, written_to: string|null}
   *   The document, the run it describes, and where it was written.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When no run id is given and none can be resolved from the project.
   */
  public function evidence(string $projectRoot, ?string $runId = NULL, ?string $writeTo = NULL): array {
    if ($runId === NULL || trim($runId) === '') {
      $store = new RunStateStore($projectRoot);
      $state = $store->load();
      if ($state === NULL) {
        throw StateError::noRun($store->label());
      }
      $runId = $state->runId;
    }

    $markdown = (new EvaluationReport(new EvidenceStore($projectRoot)))
      ->render($runId, $this->currentSubjects($projectRoot));

    $written = NULL;
    if ($writeTo !== NULL) {
      // Named from the RESOLVED run id, never from the argument: `--write`
      // without `--run=` used to land on `droost/evidence/run.md` for every
      // round, so each run silently overwrote the last one's artefact — and the
      // artefact is the committed one, the whole point of keeping the SQLite
      // file out of git.
      $writeTo = trim($writeTo) === ''
        ? 'droost/evidence/' . $runId . '.md'
        : trim($writeTo);
      // AN ABSOLUTE PATH IS ABSOLUTE. `ltrim($writeTo, '/')` turned
      // `--write=/tmp/round.md` into `<root>/tmp/round.md` — a file created
      // somewhere nobody asked for, while the result reported the path that
      // had been passed, so the report was not merely wrong about the
      // location, it named a file that did not exist.
      $target = str_starts_with($writeTo, '/')
        ? $writeTo
        : rtrim($projectRoot, '/') . '/' . $writeTo;
      $directory = dirname($target);
      if (!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
        throw StateError::unwritable($target, 'the directory to hold it could not be created');
      }
      if (@file_put_contents($target, $markdown) === FALSE) {
        throw StateError::unwritable($target, 'the file could not be written');
      }
      // WHERE IT WENT, not what was asked for.
      $written = $target;
    }

    return ['markdown' => $markdown, 'run_id' => $runId, 'written_to' => $written];
  }

  /**
   * A fingerprint of what each gate WOULD examine, as the tree stands now.
   *
   * Compared against the fingerprint stored beside each green, this is what
   * turns "the record says phpstan passed" into "phpstan passed, about the code
   * as it stands right now". A gate whose paths lever resolves to nothing has
   * no fingerprint and gets none here — NULL is a real answer and is not the
   * same as "unchanged".
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, string|null>
   *   Gate name to current fingerprint. A gate with no `paths` lever is absent;
   *   a gate whose paths now resolve to nothing maps to NULL, which is not the
   *   same answer and must not render as the same one.
   */
  private function currentSubjects(string $projectRoot): array {
    $state = (new RunStateStore($projectRoot))->load();
    if ($state === NULL) {
      return [];
    }
    $subjects = [];
    foreach ($state->resolvedGates as $gate => $levers) {
      if (!is_string($gate) || !is_array($levers)) {
        continue;
      }
      $paths = SubjectHasher::fromLever($levers['paths'] ?? NULL);
      if ($paths === []) {
        // No `paths` lever at all: this gate has no subject to fingerprint and
        // never had one. ABSENT from the map, which reads as unknown.
        continue;
      }
      // Present, possibly NULL. NULL means the gate HAS a path set and it now
      // resolves to nothing — the subject was deleted. That is the loudest
      // possible expiry, and it used to read `unknown` under a note blaming
      // levers that carry no paths, which was the opposite of what happened.
      $subjects[$gate] = SubjectHasher::hash($projectRoot, $paths);
    }

    return $subjects;
  }

  /**
   * Waives ONE gate for the rest of this run, on the operator's authority.
   *
   * The scoped alternative to dropping the whole wall: two live rounds
   * reached for `droost:workflow:bypass` believing it cleared a gate, and
   * it arms ungoverned edits instead — the wrong hammer. A waiver is
   * per-gate, run-scoped (it dies with the run record), always carries its
   * reason, renders as its own status (never a pass), and enters ONLY
   * through the CLI — there is deliberately no MCP surface for it, so an
   * agent can never waive its own gates.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $gate
   *   The gate to waive.
   * @param string $reason
   *   The operator's reason, non-empty.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run, with the waiver recorded and persisted.
   *
   * @throws \InvalidArgumentException
   *   When the gate is unknown, is one of the mandatory trio, or the reason
   *   is empty.
   * @throws \Droost\Workflow\State\StateError
   *   When there is no run to waive against.
   */
  public function waiveGate(
    string $projectRoot,
    string $gate,
    string $reason,
  ): RunState {
    if (trim($reason) === '') {
      throw new \InvalidArgumentException(
        'a gate waiver requires a reason — an unexplained waiver is indistinguishable from tampering',
      );
    }
    if (in_array($gate, ['phpcs', 'phpstan', 'phpunit'], TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'the mandatory trio carries no switch and no waiver — "%s" failures are fixed at the source',
        $gate,
      ));
    }
    if (!in_array($gate, GateSettings::KNOWN_GATES, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        'unknown gate "%s" (known: %s)',
        $gate,
        implode(', ', GateSettings::KNOWN_GATES),
      ));
    }
    $store = new RunStateStore($projectRoot);
    $waived = $this->requireRun($store)
      ->withGateWaiver($gate, trim($reason), $this->now());
    $store->save($waived);
    return $waived;
  }

  /**
   * Freezes the spec's contract sections into the evidence store.
   *
   * Called when the plan phase passes, and only then. The text is kept whole
   * beside the fingerprint so a later refusal can say WHICH section moved
   * rather than only that the digest differs — an accusation nobody can check
   * is not much better than no check.
   *
   * Never fails the run. A store that cannot be written costs the run its
   * tamper-detection, which is worth saying out loud and is not worth throwing
   * away a passed phase for.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param string $spec
   *   The spec, project-relative.
   * @param string $runId
   *   The run.
   * @param string $now
   *   The current time, ISO-8601.
   */
  private function freezeSpec(string $projectRoot, string $spec, string $runId, string $now): void {
    $text = @file_get_contents(rtrim($projectRoot, '/') . '/' . $spec);
    if ($text === FALSE) {
      return;
    }
    try {
      (new EvidenceStore($projectRoot))->upsertRun($runId, [
        'spec_path' => $spec,
        'spec_text' => $text,
        'spec_hash' => SpecFreeze::fingerprint($text),
        'spec_frozen_at' => $now,
      ]);
    }
    catch (\Throwable $e) {
      // Deliberately swallowed: see the docblock.
    }
  }

  /**
   * Holds the code phase to the scope its plan declared.
   *
   * Silent unless something was declared: a run that never used
   * `declare-changes` is not retroactively in breach, and the verb is new.
   *
   * @param \Droost\Workflow\Mode\RunOutcome $outcome
   *   What the phase produced.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that just ran.
   * @param string $projectRoot
   *   The repository root.
   *
   * @return \Droost\Workflow\Mode\RunOutcome
   *   The outcome, downgraded to Failed when the audit blocks.
   */
  private function auditDeclarations(RunOutcome $outcome, Phase $phase, string $projectRoot): RunOutcome {
    // Code AND test, because scope and coverage are different promises asked at
    // different moments. Auditing coverage at code made it unanswerable: no
    // test-shaped gate runs there, so every promised test read as never run and
    // the phase could never pass — while the plan brief instructed the very
    // declaration that caused it.
    // InspectionDue as well as Advanced, because SCOPE COMES BEFORE
    // JUDGEMENT. The seeker checkpoint sits inside the engine and stops the
    // phase short of Advanced, so the audit — which only ran on Advanced —
    // happened AFTER the seeker had already reviewed. An agent that touched
    // files it never declared got a human-grade review of a diff nobody had
    // checked the shape of, and the seeker's clean verdict was recorded about
    // it. Then the scope block fired, and the run carried a "clean" inspection
    // of work that was out of bounds.
    //
    // Asking the cheap machine question first is also the kind thing to do: a
    // scope block costs one `declare-changes`, and a seeker round costs a
    // review.
    // Complete too, and on Completed as well as Advanced: the final phase
    // passes as Completed, and scope is asked wherever the diff can still grow
    // (see DeclarationAudit::checks()). This runs before advanceIfDue() writes
    // the terminal state, so a block holds the phase open.
    if (!in_array($phase, [Phase::Code, Phase::Test, Phase::Complete], TRUE)
      || !in_array($outcome->outcome, [Outcome::Advanced, Outcome::InspectionDue, Outcome::Completed], TRUE)) {
      return $outcome;
    }

    if (!$this->auditDeclarationsFor($outcome->state, $phase, $projectRoot)) {
      return $outcome;
    }

    // A declaration block costs nothing to retry, which is right — the agent is
    // meant to go away, change something real and come back, and a legitimate
    // correction cycle can be long. But nothing was counting, so a phase could
    // be re-entered forever at zero price, and the blocks come from the agent
    // itself: it will keep trying. Past the ceiling the engine stops and ASKS,
    // because it can see the count and nothing else, while the person watching
    // can tell a slow run from a stuck one at a glance.
    $stuck = $this->engine()->stuckOutcome(
      $outcome->state,
      $phase,
      $projectRoot,
      $outcome->report,
      $this->now(),
    );

    // BLOCKED, not failed. A declaration block spends nothing — `attempts` is
    // empty and `exhausted` is false — and one `declare-changes` clears it. It
    // reported `failed`, which the README answers with `reset`, so an agent
    // reading its own envelope destroyed a run it could have saved.
    return $stuck ?? new RunOutcome(
      Outcome::Blocked,
      $outcome->state,
      $outcome->report,
      NULL,
      self::blockingRows($outcome->state, $phase, $projectRoot),
    );
  }

  /**
   * The one row a freshly opened run shows while its spec is unwritten.
   *
   * Synthetic rather than read from the store: no check has run, nothing has
   * failed, and there is nothing to record except that the run is open and
   * waiting. The shape matches blockingRows() so every surface prints it the
   * same way.
   *
   * @param \Droost\Workflow\State\RunStateStore $store
   *   The run's store, for the directory the spec belongs in.
   *
   * @return array<string, string>
   *   The row.
   */
  private static function awaitingSpecRow(RunStateStore $store): array {
    $dir = $store->label();

    return [
      'check' => 'spec',
      'fault' => Fault::None->value,
      'why' => 'the run is open and its plan phase is waiting for a spec. Ground '
      . 'now — every knowledge-tool call from here belongs to this run — then '
      . 'write the document.',
      'remedy' => sprintf(
        'Write %s/spec-<slug>.md (tmp-spec-<slug>.md at medium/low) with its '
        . '`## Tooling plan`, `## Grounding` and `## Routes` sections, then '
        . '`run --spec=<that path>` to gate the plan phase against it.',
        rtrim($dir, '/'),
      ),
      'guidance' => '',
    ];
  }

  /**
   * Why a phase died, and what to do now, for a terminal envelope.
   *
   * The gates that spent the budget, each with what it found — read from the
   * store, which still holds the last real verdict — plus one row naming the
   * two commands that exist here. An agent driving from the envelope
   * otherwise has the word `failed` and nothing else.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that died.
   * @param string $projectRoot
   *   The repository.
   *
   * @return list<array{check: string, fault: string, why: string, remedy: string, guidance: string}>
   *   The rows.
   */
  private static function terminalRows(RunState $state, Phase $phase, string $projectRoot): array {
    $rows = [];
    try {
      foreach ((new EvidenceStore($projectRoot))->checklist($state->runId, $phase->value) as $row) {
        $state_ = CheckState::tryFrom(is_string($row['state'] ?? NULL) ? $row['state'] : '');
        if ($state_ !== CheckState::Blocked) {
          continue;
        }
        $fault = is_string($row['fault'] ?? NULL) ? $row['fault'] : '';
        $rows[] = [
          'check' => is_string($row['name'] ?? NULL) ? $row['name'] : '',
          'fault' => $fault,
          'why' => is_string($row['summary'] ?? NULL) ? $row['summary'] : '',
          'remedy' => is_string($row['remedy'] ?? NULL) ? $row['remedy'] : '',
          'guidance' => (Fault::tryFrom($fault) ?? Fault::None)->guidance(),
        ];
      }
    }
    catch (\Throwable) {
      // A record that cannot be read is not a reason to say nothing at all —
      // the row below still names the way out.
    }
    $rows[] = [
      'check' => 'run',
      'fault' => Fault::None->value,
      'why' => sprintf('the %s phase spent its retry budget; this run is over', $phase->value),
      // THE VERB IS NAMED, AND SO IS THE SURFACE IT LIVES ON. This said "the
      // OPERATOR can waive them" beside a gate whose own guidance said "there
      // is no waiver" — one envelope, two answers — and a reviewer then ran
      // `droost-workflow gate-waive phpstan` and got `unknown command`. The
      // waiver exists, on the drush surface; the standalone binary has none,
      // and a remedy that offers one there is a remedy nobody can follow.
      'remedy' => 'Read the full report with `droost-workflow status` or '
      . '`droost-workflow evidence`, then clear the run with '
      . '`droost-workflow reset` and begin the next one. If every gate that '
      . 'killed the phase has been answered for, the OPERATOR can waive them '
      . 'with `drush droost:workflow:gate-waive <gate> "<reason>"` and the '
      . 'phase reopens — that verb exists on the drush surface only; the '
      . 'standalone `droost-workflow` binary has no waiver, and there `reset` '
      . 'is the exit.',
      'guidance' => '',
    ];

    return $rows;
  }

  /**
   * The gates this run's level turned off, from the levers frozen at begin.
   *
   * Read from the run's own frozen levers rather than re-resolving, for the
   * reason the levers are frozen at all: a run is held to the configuration it
   * started under, and an operator editing the dial mid-run must not change
   * what a half-finished run is being judged against.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   *
   * @return list<string>
   *   Gate names that are off.
   */
  private static function gatesOff(RunState $state): array {
    $off = [];
    foreach ($state->resolvedGates as $gate => $levers) {
      if (is_string($gate) && is_array($levers) && ($levers['on'] ?? TRUE) === FALSE) {
        $off[] = $gate;
      }
    }

    return $off;
  }

  /**
   * Records that the declaration audit could not run, and why.
   *
   * Best-effort by necessity: the usual reason the audit failed is that the
   * store is unreachable, so writing this row may fail for the same reason. A
   * row that cannot be written still leaves the phase stopped, which is the
   * part that matters.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param string $projectRoot
   *   The repository.
   * @param \Throwable $error
   *   What stopped the audit.
   */
  private function recordAuditFailure(
    RunState $state,
    Phase $phase,
    string $projectRoot,
    \Throwable $error,
  ): void {
    try {
      (new EvidenceStore($projectRoot))->record(
        $state->runId,
        $phase->value,
        new CheckRecord(
          'declaration',
          'declaration_audit',
          CheckState::Blocked,
          Fault::Environment,
          'The declaration audit could not read the run\'s own record: ' . $error::class,
          'Re-run the phase. If it persists, check that nothing else is holding '
          . 'the evidence store open, then inspect it with `droost-workflow evidence`.',
        ),
        $this->now(),
      );
    }
    catch (\Throwable) {
      // The store is what failed. Nothing to record it with.
    }
  }

  /**
   * Records whether the spec carried an acceptance-criteria table at all.
   *
   * Blocking above `medium`, recorded at or below it, and the dial is what
   * decides: a `high` run asked for verification and a spec with no criteria
   * table cannot supply it, while a `low` run's ten-line quasi-spec was never
   * told to write one and must not be punished for obeying its own brief.
   *
   * Either way the fact lands in the record, because what this closes was not a
   * wrong verdict — it was no verdict at all.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $projectRoot
   *   The repository.
   * @param bool $present
   *   Whether a criteria table was found.
   */

  /**
   * What the spec's SHAPE looks like, as observations rather than refusals.
   *
   * One entry per thing the old contract would have thrown on. Each is a lint
   * about a markdown document, and the engine records lints — it does not end
   * runs over them (F-35). Order is the order a reader meets them.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string|null $specPath
   *   The governing spec, or NULL when the run has none yet.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase being adjudicated; different phases look at different sections.
   * @param string $runId
   *   The run, so the criteria observation reads the DECLARED rows where
   *   there are any and the table only where there are none. Observing the
   *   document about criteria a run declared through the tool would report a
   *   gap in prose nobody is relying on.
   *
   * @return list<array{check: string, why: string}>
   *   Observations, empty when the document says everything it should.
   */
  private function specShapeObservations(string $projectRoot, ?string $specPath, Phase $phase, string $runId): array {
    if ($specPath === NULL) {
      return [];
    }
    $out = [];
    $name = strtolower($phase->value);

    if ($phase === Phase::Plan
      && !SpecContract::hasSection($projectRoot, $specPath, SpecContract::TOOLING_HEADING)) {
      $out[] = [
        'check' => 'tooling_plan',
        'why' => sprintf(
          'no "%s" section. Which surface built each deliverable is answerable '
          . 'from the tool-call ledger, which the agent cannot write — so this '
          . 'is a readability lint, not a missing fact.',
          SpecContract::TOOLING_HEADING,
        ),
      ];
    }

    if (in_array($phase, [Phase::Plan, Phase::Code], TRUE)) {
      $grounding = SpecContract::grounding($projectRoot, $specPath);
      if ($grounding === NULL) {
        $out[] = [
          'check' => 'grounding',
          'why' => sprintf(
            'no grounding table. Whether the codebase was actually consulted is '
            . 'answerable from the ledger (%s), which is the mechanical half; '
            . 'the table is the agent showing its working.',
            implode(', ', array_slice(EvaluationReport::KNOWLEDGE_TOOLS, 0, 3)) . ', …',
          ),
        ];
      }
      elseif ($grounding['unanswered'] !== []) {
        $out[] = [
          'check' => 'grounding',
          'why' => sprintf(
            '%d grounding question(s) left unanswered in the %s phase: %s',
            count($grounding['unanswered']),
            $name,
            implode('; ', array_slice($grounding['unanswered'], 0, 5)),
          ),
        ];
      }
      else {
        $missing = array_values(array_diff(SpecContract::TIERS, $grounding['phases'][$name] ?? []));
        if ($missing !== []) {
          $out[] = [
            'check' => 'grounding',
            'why' => sprintf('the %s phase reached no %s tier row(s)', $name, implode('/', $missing)),
          ];
        }
      }
    }

    if ($phase === Phase::Plan) {
      // A run that DECLARED its routes has nothing to observe here. The
      // document is the fallback, and reporting a gap in a section nothing
      // is reading would be a lint about prose the engine has stopped
      // consulting.
      $declaredRoutes = [];
      try {
        $declaredRoutes = (new EvidenceStore($projectRoot))->specRoutes($runId);
      }
      catch (\Throwable) {
        // Unreadable store: fall through to the document, and the phase that
        // could not write the store has already said so.
      }
      $routes = $declaredRoutes !== [] ? NULL : SpecContract::routes($projectRoot, $specPath);
      if ($declaredRoutes === [] && ($routes === NULL || ($routes['routes'] === [] && !$routes['none']))) {
        $out[] = [
          'check' => 'routes',
          'why' => $routes === NULL
            ? sprintf(
              'no route was declared with `declare-route`, and no "%s" section '
              . 'to fall back on — so rendered_check has only the lever to go on',
              SpecContract::ROUTES_HEADING,
          )
            : sprintf(
              'no route was declared with `declare-route`, and the "%s" '
              . 'section names neither a path nor `none`. Declaring is the '
              . 'route out of this: a row has no shape to get wrong, which is '
              . 'what cost two of Part 3\'s three runs their routes (F-34).',
              SpecContract::ROUTES_HEADING,
          ),
        ];
      }
    }

    if ($phase === Phase::Complete) {
      if (!SpecContract::hasRealizedCapture($projectRoot, $specPath)) {
        $out[] = [
          'check' => 'realized',
          'why' => sprintf('no "%s" capture of what was actually built', SpecContract::REALIZED_HEADING),
        ];
      }
      $criteria = CriteriaVerification::resolve($projectRoot, $runId, $specPath);
      if ($criteria !== NULL && $criteria['unverified'] !== []) {
        $out[] = [
          'check' => 'criteria_verified',
          'why' => sprintf(
            '%d criterion/criteria carry no `Verified By`: %s. This is the one '
            . 'observation worth making mechanical, and Phase B does: '
            . '`verify_criterion` names a test the phpunit run executed, or '
            . 'records `manual` honestly.',
            count($criteria['unverified']),
            implode(', ', array_slice($criteria['unverified'], 0, 8)),
          ),
        ];
      }
    }

    return $out;
  }

  /**
   * Records spec-shape observations as one non-blocking check.
   *
   * `CheckState::Recorded` renders as "recorded (not blocking)" and
   * `blocksAdvance()` is FALSE for it, so the phase proceeds. The findings ride
   * the row, so §8a names each one and a reader can see what the document did
   * not say without the run having died over it.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase these observations belong to.
   * @param list<array{check: string, why: string}> $observations
   *   What specShapeObservations() found.
   */
  private function recordSpecShape(RunState $state, string $projectRoot, Phase $phase, array $observations): void {
    $findings = [];
    foreach ($observations as $o) {
      $findings[] = [
        'rule' => 'spec_shape.' . $o['check'],
        'message' => $o['why'],
      ];
    }
    try {
      (new EvidenceStore($projectRoot))->record(
        $state->runId,
        $phase->value,
        new CheckRecord(
          'spec',
          'shape',
          CheckState::Recorded,
          Fault::None,
          sprintf(
            '%d spec-shape observation(s), recorded and not blocking: %s. The '
            . 'document\'s shape is a lint; what the run DID is in the gates and '
            . 'the ledger.',
            count($observations),
            implode(', ', array_column($observations, 'check')),
          ),
          findings: $findings,
        ),
        $this->now(),
      );
    }
    catch (\Throwable) {
      // The store is unreachable; the phase's own audit already says so.
    }
  }

  /**
   * Records whether the spec carries a criteria table at all.
   *
   * Presence and absence are both recorded, because silence is the cheapest
   * cheat in the system: write the criteria as prose and the whole `Verified
   * By` contract never fires. The return value used to gate the complete
   * phase at high+; nothing reads it now (F-35), and Phase B replaces the
   * question with a COUNT of `spec_criterion` rows.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $projectRoot
   *   The repository.
   * @param bool $present
   *   Whether the spec has a criteria table.
   *
   * @return bool
   *   Whether the level would once have refused. Kept so the signature does
   *   not churn while Phase B is built; no caller acts on it.
   */
  private function recordCriteriaContract(RunState $state, string $projectRoot, bool $present): bool {
    $demanding = in_array($state->preset, ['high', 'xhigh', 'max', 'factory'], TRUE);
    if ($present) {
      // A SATISFIED row, not silence. The store is append-only and the
      // checklist reads the latest attempt per check, so returning early here
      // left an earlier `blocked` row standing as the current verdict — which
      // meant an agent that did exactly what the block asked could never clear
      // it. A blocking check whose remedy does not work is a deadlock, and
      // driving the loop is the only thing that finds one: the rule was right
      // and the run still wedged.
      try {
        (new EvidenceStore($projectRoot))->record(
          $state->runId,
          Phase::Complete->value,
          new CheckRecord(
            'spec',
            'criteria_table',
            CheckState::Satisfied,
            Fault::None,
            'The spec carries an `## Acceptance criteria` table, so every criterion in it was '
            . 'held to its `Verified By` cell.',
          ),
          $this->now(),
        );
      }
      catch (\Throwable) {
        // The store is unreachable; the phase's own audit already says so.
      }

      return FALSE;
    }
    try {
      (new EvidenceStore($projectRoot))->record(
        $state->runId,
        Phase::Complete->value,
        new CheckRecord(
          'spec',
          'criteria_table',
          // RECORDED AT EVERY LEVEL IN PHASE A. This wrote `Blocked` at high+,
          // and `Blocked->blocksAdvance()` is TRUE — so leaving it while the
          // phase now advances would put a row in the store that contradicts
          // what the run did. A record that disagrees with the run is the
          // defect class this whole repo exists to find (F-21, F-31), and it
          // would be self-inflicted.
          //
          // The level still matters and is still said, in the summary: at high+
          // the absent table is called out as something that level expects.
          // Phase B makes it mechanical — criteria are rows, and "are there
          // any" is a COUNT.
          CheckState::Recorded,
          Fault::None,
          $demanding
            ? 'The spec carries no `## Acceptance criteria` table, so nothing in this run was '
            . 'checked against a stated criterion. At this level that is the contract rather than '
            . 'a formality: write the table, one observable behaviour per row, and fill '
            . '`Verified By` with the test that proves it.'
            : 'No `## Acceptance criteria` table, which this level does not require — so nothing '
            . 'here was verified against a stated criterion. Recorded, not verified.',
        ),
        $this->now(),
      );
    }
    catch (\Throwable) {
      // The store is unreachable, and the phase's own audit already says so.
    }

    return $demanding;
  }

  /**
   * The non-gate checks holding a phase, for the envelope.
   *
   * A gate failure is already in the report, with its summary and its exit
   * code. A declaration block, a contributed check and a spec condition were
   * nowhere: the run returned `outcome: failed` with `failed: 0`, every gate
   * green, and no reason a caller could read. A reviewer driving a real run
   * found the cause only by opening the SQLite store with a third-party tool.
   * An agent has no such option, and loops.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param string $projectRoot
   *   The repository.
   *
   * @return list<array<string, string>>
   *   Name, fault, summary and remedy for each blocking non-gate check.
   */
  private static function blockingRows(RunState $state, Phase $phase, string $projectRoot): array {
    try {
      return (new EvidenceStore($projectRoot))->blockingChecks($state->runId, $phase->value);
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Adjudicates and records this phase's declaration checks.
   *
   * Split out so the INTERACTIVE path can call it. Interactive runs advance
   * through answer() rather than run(), and hanging the audit off run()'s
   * outcome meant it never fired there at all — measured on a real interactive
   * run: no spec frozen, no declaration rows, and a criterion rewritten mid-run
   * went unnoticed. A discipline that switches itself off in one of the two
   * supported modes is not a discipline.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase that just ran.
   * @param string $projectRoot
   *   The repository root.
   *
   * @return bool
   *   TRUE when something the audit found blocks the phase.
   */
  private function auditDeclarationsFor(RunState $state, Phase $phase, string $projectRoot): bool {
    try {
      $store = new EvidenceStore($projectRoot);
      $files = $store->declared($state->runId, 'file');
      $tests = $store->declared($state->runId, 'test');
      $first = $store->firstDeclaration($state->runId, 'file');
      $gatesOff = array_values(array_unique(array_merge(
        self::gatesOff($state),
        $store->unmeasurableGates($state->runId),
      )));
      // BEFORE the early return, because this is not a declaration check. "Did
      // phpcs actually look at anything" is a question about the gates, and the
      // answer does not depend on what anybody promised — while the return
      // below fires for a run that declared nothing, which is precisely the
      // agent this check was written for. It was unreachable in its own stated
      // case until a reviewer drove a run that declared nothing and found no
      // declaration rows at all.
      $hollow = DeclarationAudit::mandatoryMeasured(
        $store->measuredGates($state->runId),
        $gatesOff,
        $phase->value,
        $store->blockedGates($state->runId),
        $store->workType($state->runId),
      );
      if ($hollow !== NULL) {
        $store->record($state->runId, $phase->value, $hollow, $this->now());
      }
      if ($files === [] && $tests === [] && $store->workType($state->runId) === NULL) {
        // Nothing was declared, so there is nothing to hold the diff to — and
        // holding it to nothing would block every changed file as undeclared.
        return FALSE;
      }
      $audit = new DeclarationAudit(
        $files,
        $tests,
        $this->vcs->changedFiles($projectRoot, $state->baseCommit),
        $store->workType($state->runId),
        $store->measuredGates($state->runId),
        // Off by level, unreachable on this surface, or waived by the
        // operator: three ways a gate cannot show a measurement, none of
        // them anything the agent chose.
        $gatesOff,
        // So the audit can READ a gate binary rather than infer from the diff
        // whether a package manager wrote it.
        $projectRoot,
        // An empty diff is "nothing changed" only when there is a repository
        // to ask (F-36). Asked directly, not inferred from head(): a
        // repository with no commits has no HEAD and a fully visible diff.
        diffVisible: $this->vcs->isRepository($projectRoot),
        // What the plan predicted, which a re-declaration made from the
        // finished diff must not be allowed to stand in for (F-54).
        firstDeclaredFiles: $first['values'],
        firstDeclaredAt: $first['at'],
        // The level's demand that the run test what it wrote (F-61), read
        // from the levers frozen when the run began.
        testsInDiff: ($state->resolvedGates['phpunit']['in_diff'] ?? FALSE) === TRUE,
      );
      $blocked = FALSE;
      $emitted = [];
      foreach ($audit->checks($phase->value) as $check) {
        $store->record($state->runId, $phase->value, $check, $this->now());
        $emitted[] = $check->name;
        $blocked = $blocked || $check->state->blocksAdvance();
      }
      // A check this pass no longer asks is retired rather than left standing.
      // The audit decides from its own fresh result, so a check that stopped
      // being emitted kept its last blocked row as the record's current
      // verdict — the engine advancing while the store said blocked, and the
      // stop hook refusing on a row nothing could clear.
      if ($hollow !== NULL) {
        $emitted[] = $hollow->name;
      }
      $store->retireUnemitted($state->runId, $phase->value, 'declaration', $emitted, $this->now());
    }
    catch (\Throwable $e) {
      // An audit that cannot read its own record must not fail a green phase —
      // but it must not pass one in silence either. A locked database made the
      // scope audit, the work-type check and type_coverage all quietly succeed,
      // which is a free pass handed out by a transient error nobody sees.
      //
      // So the failure becomes a row: blocked, environment fault, with a
      // remedy. The phase stops, because an audit that did not run has not
      // passed; the fault is environment because a contended database is not
      // the agent's doing and there is nothing for it to fix.
      $this->recordAuditFailure($state, $phase, $projectRoot, $e);

      return TRUE;
    }

    return $blocked;
  }

  /**
   * Records frozen-spec drift instead of refusing over it (F-33, F-35).
   *
   * `requireFrozenSpecIntact()` stays for callers that genuinely want the
   * refusal, and for Phase B to delete. This one records the same breaches as
   * findings on a non-blocking check, so a reader still learns that a frozen
   * section moved — and the run still finishes.
   *
   * The distinction the freeze could never draw is why this had to change: an
   * agent quietly deleting a criterion it failed and an agent correcting a
   * tooling plan it no longer means produce the same breach. Only the second
   * happened in three live rounds, and the remedy for it was destroying the
   * run.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $projectRoot
   *   The repository.
   * @param string $spec
   *   The spec, project-relative.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase being adjudicated.
   */
  private function recordFrozenSpecDrift(RunState $state, string $projectRoot, string $spec, Phase $phase): void {
    try {
      $store = new EvidenceStore($projectRoot);
      $statement = $store->connection()->prepare('SELECT spec_hash, spec_text FROM run WHERE run_id = ?');
      $statement->execute([$state->runId]);
      $row = $statement->fetch();
      if (!is_array($row) || !is_string($row['spec_text'] ?? NULL)) {
        return;
      }
      $text = @file_get_contents(rtrim($projectRoot, '/') . '/' . $spec);
      if ($text === FALSE) {
        return;
      }
      $breaches = SpecFreeze::breaches($text, (string) $row['spec_text']);
      if ($breaches === []) {
        return;
      }
      $store->record(
        $state->runId,
        $phase->value,
        new CheckRecord(
          'spec',
          'frozen_sections',
          CheckState::Recorded,
          Fault::None,
          sprintf(
            '%d frozen-section change(s) since the plan froze the spec, recorded '
            . 'and not blocking. What the run DID is in the gates and the ledger; '
            . 'the ledger in particular cannot be rewritten by the agent, which '
            . 'is why the tooling plan no longer needs freezing.',
            count($breaches),
          ),
          findings: array_map(
            static fn (string $b): array => ['rule' => 'spec_freeze.drift', 'message' => $b],
            $breaches,
          ),
        ),
        $this->now(),
      );
    }
    catch (\Throwable) {
      // The store is unreachable; the phase's own audit already says so.
    }
  }

  /**
   * Whether every gate that terminally failed a phase has since been waived.
   *
   * Read from the phase's recorded report: the gates whose status blocked
   * the advance (failed, tool missing) must each carry an operator waiver.
   * A phase with no recorded report, or no blocking row, was not failed by
   * a gate and is not reopened by this route.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The terminally failed phase.
   *
   * @return bool
   *   TRUE when a waiver covers every blocking gate of the last report.
   */
  private function waiversCoverTheFailure(RunState $state, Phase $phase): bool {
    $report = $state->gateResults[$phase->value] ?? NULL;
    if (!is_array($report) || !is_array($report['gates'] ?? NULL)) {
      return FALSE;
    }
    $blocking = [];
    foreach ($report['gates'] as $row) {
      if (!is_array($row)) {
        continue;
      }
      $gate = $row['gate'] ?? NULL;
      $status = $row['status'] ?? NULL;
      if (is_string($gate) && is_string($status)
        && in_array($status, [
          GateStatus::Failed->value,
          GateStatus::ErrorToolMissing->value,
          GateStatus::ErrorToolFailed->value,
        ], TRUE)) {
        $blocking[] = $gate;
      }
    }
    if ($blocking === []) {
      return FALSE;
    }
    foreach ($blocking as $gate) {
      if (!isset($state->gateWaivers[$gate])) {
        return FALSE;
      }
    }
    return TRUE;
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
   * Announces a run's advance or completion to the listener, if it moved.
   *
   * Derived purely from the resulting state so both run() and answer() share
   * it: a NULL current phase is completion, a changed current phase is an
   * advance, and an unchanged one (paused, failed, inspection-due) is neither.
   *
   * @param \Droost\Workflow\Config\Phase $from
   *   The phase current before this step.
   * @param \Droost\Workflow\State\RunState $after
   *   The run after the step, already persisted.
   */
  private function announceAdvanceOrComplete(Phase $from, RunState $after): void {
    if ($after->currentPhase === NULL) {
      // The final phase ended; then the run completed.
      $this->notify(fn () => $this->listener->onPhaseEnd($after, $from));
      $this->notify(fn () => $this->listener->onRunComplete($after));
      return;
    }
    if ($after->currentPhase !== $from) {
      $to = $after->currentPhase;
      // The left phase ended, the run changed phase, the entered phase began.
      $this->notify(fn () => $this->listener->onPhaseEnd($after, $from));
      $this->notify(fn () => $this->listener->onPhaseChange($after, $from, $to));
      $this->notify(fn () => $this->listener->onPhaseBegin($after, $to));
    }
  }

  /**
   * Delivers a lifecycle callback, swallowing any failure it raises.
   *
   * A listener is a notification, never the record: the transition is already
   * persisted, so a listener that throws must not turn a saved, correct run
   * into a failed one — the state-first/listener-second contract the pause
   * path keeps, applied to lifecycle events.
   *
   * @param callable(): void $emit
   *   The callback to run.
   */
  private function notify(callable $emit): void {
    try {
      $emit();
    }
    catch (\Throwable) {
      // Intentionally swallowed: a broken listener cannot break a run.
    }
  }

  /**
   * The mode engine for this surface.
   *
   * @return \Droost\Workflow\Mode\ModeEngine
   *   The engine.
   */
  private function engine(): ModeEngine {
    return new ModeEngine(
      new GateRunner($this->executor, $this->driver, $this->vcs, $this->contributed ?? []),
      $this->sink,
      $this->checks,
    );
  }

  /**
   * Grants the require_run bypass: the operator's escape from the wall.
   *
   * THE GUARD'S READ CONTRACT LIVES HERE, once. It honours a grant only when
   * `reason` and `granted_at` are both non-empty strings — "a hand-rolled or
   * corrupt marker is not a grant" — and that shape was written by the drush
   * command alone until 2026-09-15, so a project without Drupal had no way to
   * produce it. An exhausted gate then left `reset` as the only exit, on a
   * package whose whole claim is that it needs no Drupal.
   *
   * Writes the RESOLVED state directory, which is the same rule the guard
   * resolves by. Getting that wrong has bitten twice: a grant written to the
   * legacy hidden directory on a project whose run state had moved was simply
   * never seen, and the wall turned back on over a decision a human had made.
   *
   * @param string $projectRoot
   *   The repository.
   * @param string $reason
   *   Why the bypass is granted. Recorded, and required.
   *
   * @return string
   *   The path written, project-relative.
   *
   * @throws \InvalidArgumentException
   *   When the reason is empty.
   * @throws \Droost\Workflow\State\StateError
   *   When the grant cannot be written.
   */
  public function grantBypass(string $projectRoot, string $reason): string {
    if (trim($reason) === '') {
      throw new \InvalidArgumentException(
        'a bypass requires a reason — an unexplained bypass is indistinguishable from tampering',
      );
    }
    $relative = RunStateStore::resolveStateDir($projectRoot);
    $directory = rtrim($projectRoot, '/') . '/' . $relative;
    if (!is_dir($directory) && !@mkdir($directory, 0777, TRUE) && !is_dir($directory)) {
      throw StateError::unwritable($relative, 'the directory could not be created');
    }
    $encoded = json_encode([
      'reason' => trim($reason),
      'granted_at' => $this->now(),
      'granted_by' => 'terminal',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    if (@file_put_contents($directory . '/bypass.json', $encoded) !== strlen($encoded)) {
      throw StateError::unwritable($relative . '/bypass.json', 'the write did not complete');
    }
    return $relative . '/bypass.json';
  }

  /**
   * Clears the bypass, re-arming the wall.
   *
   * Not an operator-only act the way granting is: removing a bypass tightens,
   * and a tightening needs nobody's permission.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return bool
   *   TRUE when a grant was removed, FALSE when there was none.
   */
  public function clearBypass(string $projectRoot): bool {
    $path = rtrim($projectRoot, '/') . '/'
      . RunStateStore::resolveStateDir($projectRoot) . '/bypass.json';
    if (!is_file($path)) {
      return FALSE;
    }
    return @unlink($path);
  }

  /**
   * Whether a bypass is active, and on what recorded grounds.
   *
   * Judged by the GUARD'S rule, not by the file's existence: a marker missing
   * either required field is not a grant, and reporting it as one would tell
   * an operator the wall is down when it is up.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array{active: bool, reason?: string, granted_at?: string}
   *   The state.
   */
  public function bypassState(string $projectRoot): array {
    $path = rtrim($projectRoot, '/') . '/'
      . RunStateStore::resolveStateDir($projectRoot) . '/bypass.json';
    if (!is_file($path)) {
      return ['active' => FALSE];
    }
    $grant = json_decode((string) file_get_contents($path), TRUE);
    $reason = is_array($grant) && is_string($grant['reason'] ?? NULL) ? $grant['reason'] : '';
    $at = is_array($grant) && is_string($grant['granted_at'] ?? NULL) ? $grant['granted_at'] : '';
    if ($reason === '' || $at === '') {
      return ['active' => FALSE];
    }
    return ['active' => TRUE, 'reason' => $reason, 'granted_at' => $at];
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
