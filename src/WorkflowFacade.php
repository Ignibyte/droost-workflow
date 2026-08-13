<?php

declare(strict_types=1);

namespace Drupal\droost_workflow;

use Drupal\droost_workflow\State\StateError;
use Drupal\droost_workflow\Config\Mode;
use Drupal\droost_workflow\Config\Phase;
use Drupal\droost_workflow\Config\PhaseGateMap;
use Drupal\droost_workflow\Config\WorkflowConfig;
use Drupal\droost_workflow\Gate\GateExecutorInterface;
use Drupal\droost_workflow\Gate\GateRunner;
use Drupal\droost_workflow\Gate\SiteDriverInterface;
use Drupal\droost_workflow\Mode\ModeEngine;
use Drupal\droost_workflow\Mode\Outcome;
use Drupal\droost_workflow\Mode\QuestionSinkInterface;
use Drupal\droost_workflow\Mode\RunOutcome;
use Drupal\droost_workflow\Pack\InitReport;
use Drupal\droost_workflow\Pack\PackMaterializer;
use Drupal\droost_workflow\State\RunState;
use Drupal\droost_workflow\State\RunStateStore;

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
   * @param \Drupal\droost_workflow\Gate\GateExecutorInterface $executor
   *   Runs the gates that need only a checkout.
   * @param \Drupal\droost_workflow\Gate\SiteDriverInterface $driver
   *   Runs the gates that need a site. The ONLY thing that differs between
   *   the CLI surface and the live one.
   * @param \Drupal\droost_workflow\Mode\QuestionSinkInterface $sink
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
   * @return \Drupal\droost_workflow\Pack\InitReport
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
        'phases' => $config->phaseNames(),
        'gates' => $config->resolvedGates(),
        // WHEN each enabled gate runs — so "why did plan run nothing" is
        // answerable from status alone.
        'phase_gates' => PhaseGateMap::forPhases($config->phaseNames()),
        'max_gate_retries' => $config->maxGateRetries,
      ],
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
   * Starts a run, or advances the one in progress, by one phase.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return \Drupal\droost_workflow\Mode\RunOutcome
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
   * @return \Drupal\droost_workflow\State\RunState
   *   The run, no longer awaiting, already persisted.
   *
   * @throws \Drupal\droost_workflow\State\StateError
   *   When there is no run to answer.
   */
  public function answer(string $projectRoot, string $answer): RunState {
    $store = new RunStateStore($projectRoot);
    $state = $this->requireRun($store);
    $answered = $this->engine()->answer($state, $answer, $this->now());
    $store->save($answered);
    return $answered;
  }

  /**
   * Swaps the run to automated.
   *
   * @param string $projectRoot
   *   The repository.
   * @param \Drupal\droost_workflow\Config\Mode $to
   *   The mode to switch to.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The swapped run, already persisted.
   *
   * @throws \Drupal\droost_workflow\State\StateError
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
   * Moves a passing run on to the next phase.
   *
   * @param \Drupal\droost_workflow\Mode\RunOutcome $outcome
   *   What the phase produced.
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase just worked.
   *
   * @return \Drupal\droost_workflow\Mode\RunOutcome
   *   The outcome, with the state advanced when it should be.
   */
  private function advanceIfDue(
    RunOutcome $outcome,
    Phase $phase,
  ): RunOutcome {
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
   * @param \Drupal\droost_workflow\State\RunState $state
   *   The run.
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The current phase.
   *
   * @return \Drupal\droost_workflow\Config\Phase|null
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
   * @param \Drupal\droost_workflow\State\RunStateStore $store
   *   The store.
   *
   * @return \Drupal\droost_workflow\State\RunState
   *   The run.
   *
   * @throws \Drupal\droost_workflow\State\StateError
   *   When no run is recorded.
   */
  private function requireRun(RunStateStore $store): RunState {
    $state = $store->load();
    if ($state === NULL) {
      throw StateError::corrupt(
        $store->label(),
        'there is no run in progress',
      );
    }
    return $state;
  }

  /**
   * The mode engine for this surface.
   *
   * @return \Drupal\droost_workflow\Mode\ModeEngine
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
