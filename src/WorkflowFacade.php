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
use Droost\Workflow\Evidence\DeclarationAudit;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Evidence\SpecFreeze;
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
use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\Spec\SpecError;
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
      'seeker' => $state->seeker,
      // The arc, not just the verdict: a clean re-inspection replaces the
      // record but must not erase what the earlier ones caught.
      'seeker_history' => $state->seekerHistory,
      'spec' => $state->specPath,
      // Promise against proof: which criteria name a test, which are
      // verified by hand (printed as manual, never as passed), which are
      // still empty — NULL when the spec carries no criteria table.
      'criteria' => $state->specPath === NULL
        ? NULL
        : SpecContract::criteriaVerification($projectRoot, $state->specPath),
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
      // The run reached its terminal gate. Re-running does not restart it;
      // saying so is more useful than silently beginning a second run.
      return new RunOutcome(Outcome::Completed, $state);
    }

    if ($state->statusOf($phase) === PhaseStatus::Failed) {
      if (!$this->waiversCoverTheFailure($state, $phase)) {
        // The phase spent its retry budget. Re-running would silently restart
        // a run the engine already declared over — so nothing executes, and
        // the envelope's retries block says why. Recovery is deliberate:
        // reset() archives the record, then a fresh run begins — or the
        // operator WAIVES every gate that killed the phase (below).
        return new RunOutcome(Outcome::Failed, $state);
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
    if ($specPath !== NULL && $phase !== Phase::Plan) {
      $this->requireFrozenSpecIntact($projectRoot, $specPath, $state->runId);
    }
    if ($specPath !== NULL && $phase === Phase::Plan) {
      SpecContract::requireSection(
        $projectRoot,
        $specPath,
        SpecContract::TOOLING_HEADING,
        'the plan phase ends by mapping every deliverable to the surface '
        . 'that builds it (a droost blueprint, drush generate, a composer '
        . 'tool, or hand-written with the reason stated). Add the section, '
        . 'then re-run.',
      );
    }
    // Grounding is a contract at plan and at code, and advisory after. The
    // two phases that DECIDE things are the two that must look first: plan
    // proposes a shape, code commits it to disk. Test and complete verify
    // what those two chose, so a lookup there is welcome and not required.
    //
    // This exists because grounding was advice while routing was a contract,
    // and the numbers followed the contract rather than the advice. The plan
    // brief asks for both in one breath; only one produced a row anybody
    // checked.
    if ($specPath !== NULL && in_array($phase, [Phase::Plan, Phase::Code], TRUE)) {
      $grounding = SpecContract::grounding($projectRoot, $specPath);
      $name = strtolower($phase->value);
      if ($grounding === NULL) {
        throw SpecError::groundingMissing($specPath, $name, SpecContract::TIERS, TRUE, []);
      }
      if ($grounding['unanswered'] !== []) {
        throw SpecError::groundingMissing($specPath, $name, [], FALSE, $grounding['unanswered']);
      }
      $reached = $grounding['phases'][$name] ?? [];
      $missing = array_values(array_diff(SpecContract::TIERS, $reached));
      if ($missing !== []) {
        throw SpecError::groundingMissing($specPath, $name, $missing, FALSE, []);
      }
    }
    if ($specPath !== NULL && $phase === Phase::Complete
      && !SpecContract::hasRealizedCapture($projectRoot, $specPath)) {
      throw SpecError::sectionMissing(
        $specPath,
        SpecContract::REALIZED_HEADING,
        'complete opens by capturing what was actually built, in the spec '
        . 'itself, for whoever arrives next with none of this context. '
        . 'Write the section, then re-run.',
      );
    }
    // And every acceptance criterion names the test that proves it (or an
    // honest `manual — <reason>`). The pipeline this workflow descends from
    // failed completion on an empty "Verified By" cell; the first real site
    // on droost shipped three criteria of nine with no test and passed every
    // phase, because the link was advice. It is a contract again here —
    // only where the spec carries a criteria table at all, so a quasi-spec
    // at medium or low is not held to a table it never had.
    if ($specPath !== NULL && $phase === Phase::Complete) {
      $criteria = SpecContract::criteriaVerification($projectRoot, $specPath);
      if ($criteria !== NULL && $criteria['unverified'] !== []) {
        throw SpecError::criteriaUnverified($specPath, $criteria['unverified'], $criteria['column_missing'], $criteria['unnamed']);
      }
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
      // INTERACTIVE MODE ADVANCES HERE, NOT THROUGH run(). Both new contracts
      // hung off run()'s non-paused path, so an interactive run froze no spec
      // and audited no declaration: measured, spec_hash NULL, zero declaration
      // rows, and a criterion rewritten mid-run went unnoticed while the same
      // rewrite under agentic mode was refused. A discipline that switches
      // itself off in one of the two supported modes is not a discipline.
      if ($phase === Phase::Code || $phase === Phase::Test) {
        $this->auditDeclarationsFor($answered, $phase, $projectRoot);
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
   *
   * @return array{files: list<string>, tests: list<string>, type: string|null}
   *   What was recorded.
   *
   * @throws \InvalidArgumentException
   *   When nothing was declared, or there is no run to declare against.
   */
  public function declareChanges(string $projectRoot, array $files, array $tests, ?string $type = NULL): array {
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
    // not silently wipe the file declaration.
    foreach (['file' => $files, 'test' => $tests] as $kind => $values) {
      if ($values === []) {
        continue;
      }
      $store->clearDeclarations($state->runId, $kind);
      foreach ($values as $value) {
        $store->declare($state->runId, $phase, $kind, $value, $now);
      }
    }

    return ['files' => $files, 'tests' => $tests, 'type' => $workType?->value];
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
    if (!in_array($phase, [Phase::Code, Phase::Test], TRUE) || $outcome->outcome !== Outcome::Advanced) {
      return $outcome;
    }

    return $this->auditDeclarationsFor($outcome->state, $phase, $projectRoot)
      ? new RunOutcome(Outcome::Failed, $outcome->state, $outcome->report)
      : $outcome;
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
      if ($files === [] && $tests === [] && $store->workType($state->runId) === NULL) {
        return FALSE;
      }
      $audit = new DeclarationAudit(
        $files,
        $tests,
        $this->vcs->changedFiles($projectRoot, $state->baseCommit),
        $store->ranTests($state->runId),
        $store->workType($state->runId),
        $store->measuredGates($state->runId),
      );
      $blocked = FALSE;
      foreach ($audit->checks($phase->value) as $check) {
        $store->record($state->runId, $phase->value, $check, $this->now());
        $blocked = $blocked || $check->state->blocksAdvance();
      }
    }
    catch (\Throwable $e) {
      // An audit that cannot read its own record must not fail a green phase.
      return FALSE;
    }

    return $blocked;
  }

  /**
   * Refuses a phase whose spec lost the contract it was frozen with.
   *
   * The hole this closes: every phase re-read the spec from disk, so a
   * grounding table that satisfied the plan gate could be rewritten before the
   * code gate looked at it, and nothing would know. The agent both wrote the
   * contract and was graded against it, with an edit button in between.
   *
   * Silent when nothing was frozen — a run begun before this existed, or one
   * whose store could not be written — because a run cannot be retroactively
   * in breach of a contract nobody recorded.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param string $spec
   *   The spec, project-relative.
   * @param string $runId
   *   The run.
   *
   * @throws \Droost\Workflow\Spec\SpecError
   *   When a frozen section has changed since the plan.
   */
  private function requireFrozenSpecIntact(string $projectRoot, string $spec, string $runId): void {
    try {
      $store = new EvidenceStore($projectRoot);
      $statement = $store->connection()->prepare('SELECT spec_hash, spec_text FROM run WHERE run_id = ?');
      $statement->execute([$runId]);
      $row = $statement->fetch();
    }
    catch (\Throwable $e) {
      return;
    }
    if (!is_array($row) || !is_string($row['spec_hash'] ?? NULL) || $row['spec_hash'] === '') {
      return;
    }
    // The recorded TEXT is what this is checked against, not the digest: two of
    // the three sections are append-only, and "every row that was there is
    // still there" is not a question a hash can answer.
    $frozenText = is_string($row['spec_text'] ?? NULL) ? $row['spec_text'] : NULL;
    $text = @file_get_contents(rtrim($projectRoot, '/') . '/' . $spec);
    if ($text === FALSE || $frozenText === NULL) {
      return;
    }
    $breaches = SpecFreeze::breaches($text, $frozenText);
    if ($breaches === []) {
      return;
    }

    throw SpecError::contractChanged($spec, $breaches);
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
