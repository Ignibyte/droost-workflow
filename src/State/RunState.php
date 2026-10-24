<?php

declare(strict_types=1);

namespace Droost\Workflow\State;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Enforcement;
use Droost\Workflow\Config\Mode;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PhaseGateMap;
use Droost\Workflow\Config\PresetResolver;
use Droost\Workflow\Config\Provenance;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Support\TypedArray;

/**
 * Everything a run remembers between one gate and the next.
 *
 * Immutable: every change returns a new instance, so a caller cannot half-
 * apply an advance and persist the wreckage.
 *
 * Four fields — gate_results, awaiting, qa_history and feedback_attempts — are
 * written by later parts of the workflow and are round-tripped verbatim here.
 * Reserving them now keeps the schema at v1 across the whole build-out: a
 * state file written today stays readable by the gate runner and the mode
 * engine without a migration.
 *
 * Nothing in this class reads a clock or generates an identifier. The run id
 * and the start timestamp are supplied by whichever surface began the run,
 * which is what makes every test of it deterministic.
 */
final class RunState {

  /**
   * The complete hold is answered from the operator's terminal.
   */
  public const LEAD_HUMAN = 'human-led';

  /**
   * The agent may answer every hold, complete's included.
   */
  public const LEAD_AUTONOMOUS = 'autonomous';

  /**
   * Every lead a run may have.
   */
  public const LEADS = [self::LEAD_HUMAN, self::LEAD_AUTONOMOUS];


  /**
   * The schema version this build reads and writes.
   */
  public const SCHEMA_VERSION = 1;

  /**
   * The host task surfaces an agent may declare.
   *
   * A closed set, like the browser tier's, so a typo is a refusal rather
   * than a silently-recorded fiction. `other` is deliberate: a host with a
   * task list we have not named should be able to say it has one, because
   * the useful distinction is "a human can see the phases" versus "they
   * cannot", not which vendor provides it.
   *
   * @var list<string>
   */
  public const TASK_SURFACES = ['claude-code', 'codex', 'other', 'none'];

  /**
   * Constructs a RunState.
   *
   * @param string $runId
   *   The run's identifier, supplied by the surface that began it.
   * @param string $startedAt
   *   When the run began, as an ISO-8601 string supplied by the caller.
   * @param \Droost\Workflow\Config\Mode $mode
   *   The mode the lever file asked for.
   * @param \Droost\Workflow\Config\Mode|null $modeOverride
   *   A mid-run swap, or NULL when none has happened.
   * @param string $preset
   *   The preset the levers were resolved from.
   * @param int $maxGateRetries
   *   The bound the feedback_attempts counters are measured against. Recorded
   *   because a count without its limit does not tell a reader whether a run
   *   gave up early or exhausted its budget.
   * @param \Droost\Workflow\Config\Provenance $provenance
   *   Whether these levers came from a committed file or the built-in
   *   defaults — the difference between "the repo asked for this" and "the
   *   repo said nothing", which a report must not blur.
   * @param array<string, array<string, int|string|bool>> $resolvedGates
   *   The gate levers this run is held to, recorded so a report can be read
   *   without also reading the config file as it stands later. The boolean in
   *   the union is each gate's own "on" flag and nothing else — every option
   *   value is int|string, which is why the reader accepts a bool for "on"
   *   alone.
   * @param array<string, \Droost\Workflow\State\PhaseStatus> $phases
   *   Status per configured phase, keyed by phase name.
   * @param \Droost\Workflow\Config\Phase|null $currentPhase
   *   The phase in progress, or NULL once the run has ended.
   * @param array<array-key, mixed> $gateResults
   *   Reserved for the gate runner; round-tripped verbatim.
   * @param array<array-key, mixed>|null $awaiting
   *   Reserved for pair mode's pending question; round-tripped verbatim.
   * @param list<mixed> $qaHistory
   *   Reserved for pair mode's answered questions; round-tripped verbatim.
   * @param array<string, int> $feedbackAttempts
   *   How many times each gate has driven the feedback loop.
   * @param array<string, list<string>> $phaseGates
   *   Which gates are due at which configured phase — the PhaseGateMap as it
   *   stood when the run began (custom gates woven in at their configured
   *   phase), frozen for the same reason the resolved levers are: a run is
   *   held to the map it started under.
   * @param \Droost\Workflow\Config\Enforcement $enforcement
   *   The enforcement level frozen at begin. Defaults to Off so a document
   *   written before the lever existed reads as what it was: unenforced.
   * @param bool $seekers
   *   Whether the adversarial-review checkpoint is armed, frozen at begin.
   *   Defaults to FALSE for the same reason enforcement defaults to Off: a
   *   document written before the lever existed was not held to it.
   * @param array<string, bool|int|string>|null $seeker
   *   The latest recorded inspection — status, per-severity counts,
   *   observation count, reported_at — or NULL when none has been recorded.
   *   Written only by withSeekerReport(), whose counts come from parsing
   *   the ledger text, never from a self-report.
   * @param string|null $browser
   *   The browser capability the running agent declared at run start
   *   (playwright-mcp, native, or none), or NULL when undeclared. Session-
   *   scoped truth only the agent can know; recorded so the test phase and
   *   the report can say which verification tier actually ran.
   * @param string|null $tasks
   *   The host task surface the running agent declared (claude-code, codex,
   *   other, or none), or NULL when undeclared. Recorded for the same reason
   *   as the browser tier and by the same route: whether this session can
   *   show a human where the run is, as one task per phase, is something only
   *   the agent can see, and a report that claimed phase visibility the host
   *   never had would be worse than one that says "none".
   * @param list<array<string, bool|int|string>> $seekerHistory
   *   Every inspection this run recorded, oldest first, appended by
   *   withSeekerReport(). `$seeker` is the CURRENT verdict and is what the
   *   checkpoint tests; this is the arc that produced it.
   *
   *   They are separate fields because they answer different questions and
   *   one had been silently answering both. A run whose seeker caught a
   *   latent correctness bug, saw it fixed, and then passed a clean
   *   re-inspection reported `clean (critical 0, medium 0, low 0)` —
   *   indistinguishable from a run whose seeker found nothing, because each
   *   inspection REPLACED its predecessor. The verdict was right; the record
   *   of how it was reached did not survive. Measured across four live eval
   *   rounds: 6, 25, 12 and 20 findings caught, recorded as 0, 0, 6 and 2.
   *
   *   Kept per inspection rather than summed, because a re-inspection
   *   restates earlier findings with a resolved status — summing would
   *   double-count them, and the arc is the honest thing to keep anyway.
   * @param string|null $specPath
   *   The governing spec, project-relative, or NULL on a record that
   *   predates the contract. Recorded at begin (or adopted on the first
   *   advance that resolves it) so every later phase checks THE SAME
   *   document the run started under — a run whose spec can silently swap
   *   is a run whose criteria can too.
   * @param array<string, array{reason: string, granted_at: string}> $gateWaivers
   *   Gates the OPERATOR waived for this run, keyed by gate name, each with
   *   its reason and grant time. Run-scoped by construction — the record
   *   dies with the run — and written only by withGateWaiver(), whose sole
   *   caller is the CLI command: an agent has no surface that can reach it.
   * @param string|null $baseCommit
   *   The commit the working tree was at when the run began, or NULL when no
   *   repository answered. What "the run's changes" means: the seeker's
   *   cumulative diff has a stated base, and the gates whose inherited
   *   entries are whole files know which files this run touched.
   * @param string|null $baselineHash
   *   The hash of the adoption baseline (`droost/baseline/`) as it stood at
   *   begin, or NULL when the run is held to none — no baseline, or the lever
   *   turned it off. Frozen so a baseline that changes under a run is caught
   *   by every gate that consults it: the file is the operator's, written at
   *   adoption, and a mid-run edit is a defeat, never a tuning.
   * @param array<string, string|null> $ruleFiles
   *   The project's own rule files this run is held to, project-relative,
   *   each with its sha256 at begin (NULL for a file the levers name that
   *   was not there): the gate, hook and wall files `droost/droost.yml`
   *   points at, and `droost/agents.md`. Frozen so a gate whose file changed
   *   under the run names the change rather than judging by a rule the run
   *   never agreed to.
   * @param int $seekerRounds
   *   How many seeker inspections the code phase requires, frozen at start.
   * @param array<string, mixed>|null $workItem
   *   The ticket this run is bound to (`run --ticket`): its id, source,
   *   number, title, status, type and path, as the work-item source reported
   *   it, or NULL when the run answers no ticket.
   * @param \Droost\Workflow\State\LoopState $loop
   *   The fast flow's loop: its budget, every return and the follow-ups.
   * @param array<array-key, mixed> $rules
   *   The owner's rules for what builds a page, as BuildRules::toArray()
   *   froze them at begin; empty for none.
   * @param string $seekerHolds
   *   Which open seeker findings hold the run, frozen at begin.
   * @param bool $seekerAtComplete
   *   Whether complete needs an inspection of its own, frozen at begin.
   * @param array<string, string> $preexisting
   *   What the tree held changed when the run began, path to sha256.
   * @param string $lead
   *   Who may finish the run (LEADS).
   */
  public function __construct(
    public readonly string $runId,
    public readonly string $startedAt,
    public readonly Mode $mode,
    public readonly ?Mode $modeOverride,
    public readonly string $preset,
    public readonly int $maxGateRetries,
    public readonly Provenance $provenance,
    public readonly array $resolvedGates,
    public readonly array $phases,
    public readonly ?Phase $currentPhase,
    public readonly array $gateResults = [],
    public readonly ?array $awaiting = NULL,
    public readonly array $qaHistory = [],
    public readonly array $feedbackAttempts = [],
    public readonly array $phaseGates = [],
    public readonly Enforcement $enforcement = Enforcement::Off,
    public readonly bool $seekers = FALSE,
    public readonly ?array $seeker = NULL,
    public readonly ?string $browser = NULL,
    public readonly ?string $tasks = NULL,
    public readonly array $seekerHistory = [],
    public readonly ?string $specPath = NULL,
    public readonly array $gateWaivers = [],
    public readonly ?string $baseCommit = NULL,
    public readonly ?string $baselineHash = NULL,
    public readonly array $ruleFiles = [],
    /**
     * How many seeker inspections the code phase requires before it advances.
     *
     * A FLOOR, not a cap. The engine has always required one; this makes the
     * number an operator lever instead of a constant, because the right
     * answer is not the same for a docs rung and a rung that writes Twig.
     * One is the default and the fast path.
     */
    public readonly int $seekerRounds = 1,
    /**
     * The ticket this run answers, or NULL.
     *
     * Bound when the run begins and never swapped: one run, one ticket.
     * Absent on every run.json written before tickets existed, and NULL is
     * what those runs were bound to.
     */
    public readonly ?array $workItem = NULL,
    /**
     * The fast flow's loop: its budget, every return, and the follow-ups.
     *
     * Absent on every run.json written before 0.11, and a strict loop that
     * never returns is what those runs were.
     */
    public readonly LoopState $loop = new LoopState(),
    /**
     * The owner's rules for what builds a page, frozen at begin.
     *
     * As the lever file wrote them when the run began (owner, 2026-10-02).
     * Frozen like the levers: an edit mid-run changes no rule this run is
     * held to. Absent on every run.json written before rules existed, and
     * no rules, every case's defaults, is what those runs had.
     */
    public readonly array $rules = [],
    /**
     * Which open seeker findings hold the run (seekers.holds), frozen.
     */
    public readonly string $seekerHolds = 'none',
    /**
     * Whether complete needs an inspection of its own (seekers.at_complete).
     */
    public readonly bool $seekerAtComplete = FALSE,
    /**
     * What the working tree already held changed when the run began.
     *
     * Path to its sha256 then. A file still identical to how the run found it
     * is not the run's change (0.14.18: an untracked file the developer had
     * left blocked code as undeclared). Absent before 0.14.18.
     *
     * @var array<string, string>
     */
    public readonly array $preexisting = [],
    /**
     * Who may finish the run.
     *
     * `human-led` (the complete hold is answered from the operator's
     * terminal) or `autonomous` (the agent may answer every hold). Frozen at
     * begin; `workflow:lead` changes it, and only the
     * operator's terminal may make it autonomous (owner, 2026-10-20).
     * Absent before then, which is how those runs behaved: autonomous.
     */
    public readonly string $lead = self::LEAD_AUTONOMOUS,
  ) {}

  /**
   * Begins a run from a resolved configuration.
   *
   * @param string $runId
   *   The run's identifier.
   * @param string $startedAt
   *   When the run began, as an ISO-8601 string.
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The resolved levers.
   * @param string|null $baseCommit
   *   The commit the tree is at, or NULL when unknown.
   * @param string|null $baselineHash
   *   The adoption baseline's hash to hold the run to, or NULL for none.
   * @param string|null $parityDigest
   *   The parity reference's digest as the run begins, frozen with the gate's
   *   levers as `reference_digest` so the gate can refuse a changed one
   *   (F-144); NULL when the gate is off.
   * @param array<string, string|null> $ruleFiles
   *   The project's rule files and their hashes at begin (RuleFiles::hash()).
   * @param string|null $lead
   *   The lead chosen for this run, or NULL for the lever's.
   *
   * @return self
   *   A run positioned at its first configured phase.
   */
  public static function begin(
    string $runId,
    string $startedAt,
    WorkflowConfig $config,
    ?string $baseCommit = NULL,
    ?string $baselineHash = NULL,
    ?string $parityDigest = NULL,
    array $ruleFiles = [],
    ?string $lead = NULL,
  ): self {
    $phases = [];
    foreach ($config->phases as $index => $phase) {
      $phases[$phase->value] = $index === 0
        ? PhaseStatus::Active
        : PhaseStatus::Pending;
    }

    $gates = $config->resolvedGates();
    if ($parityDigest !== NULL && isset($gates['parity'])) {
      $gates['parity']['reference_digest'] = $parityDigest;
    }

    return new self(
      $runId,
      $startedAt,
      $config->mode,
      NULL,
      $config->preset,
      $config->maxGateRetries,
      $config->provenance,
      $gates,
      $phases,
      $config->phases[0] ?? NULL,
      phaseGates: self::phaseGatesFor($config),
      enforcement: $config->enforcement,
      seekers: $config->seekers,
      seekerRounds: $config->seekerRounds,
      baseCommit: $baseCommit,
      baselineHash: $baselineHash,
      ruleFiles: $ruleFiles,
      loop: new LoopState(
        $config->flow,
        $config->maxLoops,
        // At max a spent budget fails the run (owner, 2026-09-29), and so
        // does a run with nowhere to write a follow-up.
        $config->preset === 'max' || $config->followUps === 'none' ? 'fail' : 'follow-up',
        $config->followUps,
      ),
      rules: $config->rules()->toArray(),
      seekerHolds: $config->seekerHolds,
      seekerAtComplete: $config->seekerAtComplete,
      lead: $lead ?? $config->lead(),
    );
  }

  /**
   * The phase-gate table a run begun on these levers would freeze.
   *
   * One answer for `begin` and for `status` (F-178): status asked the engine
   * map alone, so a project's custom and contributed gates were missing from
   * the table it showed before a run, and present in the one the run froze.
   *
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The resolved levers.
   *
   * @return array<string, list<string>>
   *   Phase to the gates due at it.
   */
  public static function phaseGatesFor(WorkflowConfig $config): array {
    return self::weaveCustomGates(
      PhaseGateMap::forPhases($config->phaseNames(), $config->flow),
      $config->gates,
      $config->flow,
    );
  }

  /**
   * This run, knowing what the tree already held changed when it began.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $changed
   *   The working tree's changes at begin (modified, added, untracked).
   *
   * @return self
   *   A new instance.
   */
  public function withPreexisting(string $projectRoot, array $changed): self {
    $found = [];
    foreach (array_slice($changed, 0, 2000) as $path) {
      $file = rtrim($projectRoot, '/') . '/' . $path;
      $hash = is_file($file) ? @hash_file('sha256', $file) : FALSE;
      if (is_string($hash)) {
        $found[$path] = $hash;
      }
    }
    return $this->with(preexisting: $found);
  }

  /**
   * The files the run found already changed, by path.
   *
   * A file changed before the run opened is left out of the run's diff, so
   * gates judge only what the run did. An adopter's second run opened on the
   * first run's uncommitted edits to the ticket, every ticket-scoped gate read
   * "the run changed no file", and nothing was analysed (F-373). A file the
   * run declares (`declare-changes --files`) is its work, however it found
   * it: see withClaimed().
   *
   * @return list<string>
   *   Project-relative paths.
   */
  public function preexistingPaths(): array {
    return array_map('strval', array_keys($this->preexisting));
  }

  /**
   * This run, claiming files it found already changed as its own.
   *
   * @param list<string> $paths
   *   Project-relative paths.
   *
   * @return self
   *   A new instance.
   */
  public function withClaimed(array $paths): self {
    return $this->with(preexisting: array_diff_key($this->preexisting, array_flip($paths)));
  }

  /**
   * The changed files that are this run's: not as the run found them.
   *
   * @param list<string>|null $changed
   *   The files changed since the base commit, or NULL when unknown.
   * @param string $projectRoot
   *   The repository.
   *
   * @return list<string>|null
   *   The same list, less every file still identical to how it was when the
   *   run began.
   */
  public function ownChanges(?array $changed, string $projectRoot): ?array {
    if ($changed === NULL || $this->preexisting === []) {
      return $changed;
    }
    return array_values(array_filter($changed, function (string $path) use ($projectRoot): bool {
      $was = $this->preexisting[$path] ?? NULL;
      if ($was === NULL) {
        return TRUE;
      }
      $file = rtrim($projectRoot, '/') . '/' . $path;
      return !is_file($file) || @hash_file('sha256', $file) !== $was;
    }));
  }

  /**
   * Adds each custom gate to the frozen map at its configured phase.
   *
   * The engine-owned map cannot know a repo's custom gates, so they join at
   * freeze time: once at their declared phase, and once at complete, where
   * everything enabled re-runs. Weaving them into the FROZEN map (rather
   * than special-casing the due-gate lookup) keeps one invariant: a run is
   * measured against exactly what its own document says.
   *
   * @param array<string, list<string>> $phaseGates
   *   The engine map for the configured phases.
   * @param array<string, \Droost\Workflow\Config\GateSettings> $gates
   *   The resolved gate set.
   * @param string $flow
   *   The run's flow. Only the strict one re-runs everything at complete; the
   *   fast flow's complete runs no gate, a custom one included (F-159).
   *
   * @return array<string, list<string>>
   *   The map with custom gates placed.
   */
  private static function weaveCustomGates(array $phaseGates, array $gates, string $flow = 'strict'): array {
    foreach ($gates as $name => $gate) {
      // A project's own gates declare their own phases; the engine map
      // knows none of them.
      if (!GateSettings::isCustom($name)) {
        continue;
      }
      // `phase` is one phase or a comma-separated list (GateSettings validates
      // it), so a custom gate can attach to code AND test. It is woven into
      // each named phase the run configures, and always into complete.
      $at = $gate->option('phase');
      if (is_string($at)) {
        foreach (explode(',', $at) as $phase) {
          if (isset($phaseGates[$phase])) {
            $phaseGates[$phase][] = $name;
          }
        }
      }
      if ($flow === 'strict' && isset($phaseGates['complete'])) {
        $phaseGates['complete'][] = $name;
      }
    }
    return $phaseGates;
  }

  /**
   * The resolved gates due at one phase, in resolved order.
   *
   * The intersection of the two frozen records: the levers say whether each
   * gate runs at all, this run's phase map says when. The runner iterates
   * this instead of the whole resolved set, which is what stops a plan phase
   * from running a browser suite.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase being gated.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its recorded levers, for the gates due at this phase,
   *   preserving resolved-gate order so reports stay stably ordered.
   */
  public function gatesDueFor(Phase $phase): array {
    $due = $this->phaseGates[$phase->value] ?? [];
    $gates = [];
    foreach ($this->resolvedGates as $name => $levers) {
      if (in_array($name, $due, TRUE)) {
        $gates[$name] = $levers;
      }
    }
    return $gates;
  }

  /**
   * The mode actually in force.
   *
   * A mid-run swap outranks the lever file, so that flipping pair to
   * automated does not require editing a version-controlled file while a run
   * is in flight.
   *
   * @return \Droost\Workflow\Config\Mode
   *   The override when one is set, otherwise the configured mode.
   */
  public function effectiveMode(): Mode {
    return $this->modeOverride ?? $this->mode;
  }

  /**
   * One phase's status.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   *
   * @return \Droost\Workflow\State\PhaseStatus|null
   *   The status, or NULL when this run does not execute that phase.
   */
  public function statusOf(Phase $phase): ?PhaseStatus {
    return $this->phases[$phase->value] ?? NULL;
  }

  /**
   * This run with one phase's status changed.
   *
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase.
   * @param \Droost\Workflow\State\PhaseStatus $status
   *   The new status.
   *
   * @return self
   *   A new instance.
   */
  public function withPhaseStatus(Phase $phase, PhaseStatus $status): self {
    $phases = $this->phases;
    $phases[$phase->value] = $status;
    return $this->with(phases: $phases);
  }

  /**
   * This run advanced to a later phase.
   *
   * The phase being left is recorded as passed — advancing past a gate is
   * what passing it means. That is precisely why advancing is refused in
   * three situations rather than being made to work:
   *
   * - The outgoing phase FAILED or was SKIPPED. Stamping it passed on the way
   *   out would launder a failed gate into a pass, and the run's own report
   *   would then say a check succeeded that did not. Retrying a failed phase
   *   is legitimate, but it has to be said out loud with withPhaseStatus()
   *   first, so clearing a failure is a deliberate act and not a side effect
   *   of moving on.
   * - The target is at or before the current phase. Phases run at most once
   *   and in order; a run sitting at plan with complete already passed is not
   *   a state any report can describe honestly.
   * - The run has ended (no current phase). Resuming it would silently
   *   restart something that already reached its terminal gate.
   *
   * @param \Droost\Workflow\Config\Phase $to
   *   The phase to enter.
   *
   * @return self
   *   A new instance.
   *
   * @throws \InvalidArgumentException
   *   When the transition is not one a run may make. Every case is a caller
   *   error.
   */
  public function advanceTo(Phase $to): self {
    if (!array_key_exists($to->value, $this->phases)) {
      throw new \InvalidArgumentException(sprintf(
        'This run does not execute the "%s" phase',
        $to->value,
      ));
    }
    if ($this->currentPhase === NULL) {
      throw new \InvalidArgumentException(sprintf(
        'This run has ended; it cannot advance to "%s"',
        $to->value,
      ));
    }
    if (!$this->isLaterThanCurrent($to)) {
      throw new \InvalidArgumentException(sprintf(
        'Cannot advance from "%s" to "%s": phases run once, in order',
        $this->currentPhase->value,
        $to->value,
      ));
    }

    $leaving = $this->phases[$this->currentPhase->value];
    if ($leaving !== PhaseStatus::Active) {
      throw new \InvalidArgumentException(sprintf(
        'Cannot advance away from "%s" while it is %s: that would record it '
        . 'as passed. Set its status deliberately first if you mean to '
        . 'clear it.',
        $this->currentPhase->value,
        $leaving->value,
      ));
    }

    $phases = $this->phases;
    $phases[$this->currentPhase->value] = PhaseStatus::Passed;
    $phases[$to->value] = PhaseStatus::Active;

    // THE SEEKER'S VERDICT BELONGS TO THE PHASE IT INSPECTED. `$this->seeker`
    // is the LAST ledger, and the checkpoint asks whether it says `clean` —
    // so a clean inspection at CODE set it permanently and the COMPLETE
    // checkpoint could never fire again. The README promises the seeker
    // inspects "after the code phase's gates pass, and again at complete";
    // a reviewer's third ticket got exactly one inspection, and the path that
    // disabled the second was the EASY one — a clean code read. Complete is
    // where the diff is largest, so a run that got a clean read early is
    // precisely the run nobody looks at again.
    //
    // The TRAIL is untouched: `seekerHistory` keeps every ledger, which is
    // what the report and the evaluation count. Only the current verdict is
    // cleared, because the next phase has not been inspected yet and saying
    // otherwise is the claim this clears.
    return $this->with(phases: $phases, currentPhase: $to, clearSeeker: TRUE);
  }

  /**
   * This run sent back to an earlier phase (the fast flow's loop, 0.11).
   *
   * The one way back, and only in the fast flow. `advanceTo()` refuses a
   * backward move because phases run once, in order; the fast flow keeps that
   * for every phase but one edge: a failure at test, or code that moved under
   * code's gates, returns the run to code, whose gates then run again, and
   * test after them. The phases between are set back to pending, never to
   * passed, and the return is recorded with what caused it.
   *
   * @param \Droost\Workflow\Config\Phase $to
   *   The phase to return to. It must have passed.
   * @param string $reason
   *   Either `failed` (spends the budget) or `moved` (spends nothing).
   * @param string $at
   *   When, ISO-8601.
   * @param array<string, string> $gates
   *   What caused it, by gate or subject.
   *
   * @return self
   *   A new instance.
   *
   * @throws \InvalidArgumentException
   *   When the run does not loop, has ended, or the target is not an earlier
   *   phase that passed.
   */
  public function returnTo(Phase $to, string $reason, string $at, array $gates): self {
    if (!$this->loop->loops()) {
      throw new \InvalidArgumentException('Only a run in the fast flow returns to an earlier phase.');
    }
    $from = $this->currentPhase;
    if ($from === NULL
      || ($this->phases[$to->value] ?? NULL) !== PhaseStatus::Passed
      || $this->isLaterThanCurrent($to)
      || $to === $from) {
      throw new \InvalidArgumentException(sprintf(
        'Cannot return from "%s" to "%s": a run returns only to an earlier phase that passed.',
        $from->value ?? 'the end',
        $to->value,
      ));
    }
    $order = Phase::canonical();
    $target = array_search($to, $order, TRUE);
    $current = array_search($from, $order, TRUE);
    $phases = $this->phases;
    foreach ($order as $index => $phase) {
      if (!array_key_exists($phase->value, $phases)) {
        continue;
      }
      if ($index === $target) {
        $phases[$phase->value] = PhaseStatus::Active;
      }
      elseif ($index > $target && $index <= $current) {
        $phases[$phase->value] = PhaseStatus::Pending;
      }
    }

    return $this->with(
      phases: $phases,
      currentPhase: $to,
      clearSeeker: TRUE,
      loop: $this->loop->returned($from->value, $to->value, $reason, $at, $gates),
    );
  }

  /**
   * This run moved on with its current phase's failures as follow-ups.
   *
   * The phase left is recorded `deferred`, never passed: its gates failed and
   * the run did not fix them. The follow-ups carry them to the next ticket.
   *
   * @param \Droost\Workflow\Config\Phase $to
   *   The phase to enter.
   * @param list<array<string, string|null>> $filed
   *   The follow-ups written for the phase left.
   *
   * @return self
   *   A new instance.
   *
   * @throws \InvalidArgumentException
   *   When advanceTo() would refuse the same move.
   */
  public function deferTo(Phase $to, array $filed): self {
    $from = $this->currentPhase;
    $advanced = $this->advanceTo($to);
    // $from is set: advanceTo() refuses a run that has ended.
    $phases = $advanced->phases;
    $phases[$from->value ?? ''] = PhaseStatus::Deferred;

    return $advanced->with(
      phases: $phases,
      loop: $this->loop->deferring($from->value ?? '', $filed),
    );
  }

  /**
   * This run with its loop record replaced.
   *
   * @param \Droost\Workflow\State\LoopState $loop
   *   The loop.
   *
   * @return self
   *   A new instance.
   */
  public function withLoop(LoopState $loop): self {
    return $this->with(loop: $loop);
  }

  /**
   * This run at its terminal gate: the final phase passed, nothing left to run.
   *
   * The last phase has no phase to advance TO, so advanceTo() cannot record
   * its pass or retire the run — yet a run that reached and passed its final
   * gate is finished, and every reader (status, report, reset, and run()'s own
   * "already completed" short-circuit) tells that from currentPhase being
   * NULL. This records the final phase passed, exactly as advanceTo() records
   * the phase it leaves, and drops currentPhase to NULL so the finished run
   * reads as finished rather than forever "active".
   *
   * Completing an already-terminal run is a no-op, not an error: run() may see
   * a completed run again and re-affirm it.
   *
   * @return self
   *   A new instance at its terminal gate, or this one unchanged when it is
   *   already there.
   */
  public function complete(): self {
    if ($this->currentPhase === NULL) {
      return $this;
    }
    $phases = $this->phases;
    $phases[$this->currentPhase->value] = PhaseStatus::Passed;
    // with() cannot set currentPhase to NULL (it reads NULL as "keep"), so the
    // terminal state is built directly, as rebuild() does for its own field.
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $phases,
      NULL,
      $this->gateResults,
      $this->awaiting,
      $this->qaHistory,
      $this->feedbackAttempts,
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
      $this->seekerHistory,
      $this->specPath,
      $this->gateWaivers,
      $this->baseCommit,
      $this->baselineHash,
      $this->ruleFiles,
      $this->seekerRounds,
      $this->workItem,
      $this->loop,
      rules: $this->rules,
      seekerHolds: $this->seekerHolds,
      seekerAtComplete: $this->seekerAtComplete,
      preexisting: $this->preexisting,
      lead: $this->lead,
    );
  }

  /**
   * Whether a phase comes after the current one in canonical order.
   *
   * @param \Droost\Workflow\Config\Phase $to
   *   The candidate phase.
   *
   * @return bool
   *   TRUE when it is strictly later.
   */
  private function isLaterThanCurrent(Phase $to): bool {
    if ($this->currentPhase === NULL) {
      return FALSE;
    }
    $order = Phase::canonical();
    return array_search($to, $order, TRUE)
      > array_search($this->currentPhase, $order, TRUE);
  }

  /**
   * The incoming report, carrying what each gate said on its failed attempts.
   *
   * A phase that fails a gate, gets fixed, and passes used to leave a record
   * showing only the pass. `feedback_attempts` counted that a retry happened;
   * nothing anywhere said what the gate had objected to. So the run record
   * could prove the feedback loop fired and not what it corrected — and every
   * account of WHY a gate failed had to come from the agent's own narration,
   * which is the class of evidence this package exists to stop trusting.
   *
   * Only blocking attempts are carried: a gate that was `off` or `reported`
   * and then ran has no correction to explain. The kept shape is deliberately
   * small — status, exit code, duration and the summary line — because the
   * findings of a superseded attempt describe code that no longer exists,
   * while its summary is the sentence that caused the work.
   *
   * Bounded at five so a gate stuck in a long loop cannot grow the record
   * without limit; the oldest attempts drop first, and the count in
   * `feedback_attempts` still says how many there really were.
   *
   * @param array<array-key, mixed>|null $previous
   *   The report this one replaces, if any. Keyed as loosely as `gateResults`
   *   itself is: that field round-trips unvalidated, so claiming string keys
   *   here was a claim about data nobody checks. Only `gates` is ever read.
   * @param array<string, mixed> $report
   *   The incoming serialized PhaseReport.
   *
   * @return array<string, mixed>
   *   The incoming report, with prior failures attached per gate.
   */
  private static function carryFailedAttempts(?array $previous, array $report): array {
    $blocking = ['failed', 'error-tool-missing', 'error-tool-failed'];
    $priorGates = is_array($previous['gates'] ?? NULL) ? $previous['gates'] : [];
    $history = [];
    foreach ($priorGates as $gate) {
      if (!is_array($gate) || !is_string($gate['gate'] ?? NULL)) {
        continue;
      }
      $name = $gate['gate'];
      $carried = is_array($gate['previous_attempts'] ?? NULL) ? $gate['previous_attempts'] : [];
      if (in_array($gate['status'] ?? NULL, $blocking, TRUE)) {
        $carried[] = [
          'status' => $gate['status'],
          'exit_code' => $gate['exit_code'] ?? NULL,
          'duration_ms' => $gate['duration_ms'] ?? NULL,
          'summary' => $gate['summary'] ?? NULL,
        ];
      }
      if ($carried !== []) {
        $history[$name] = array_slice($carried, -5);
      }
    }
    if ($history === [] || !is_array($report['gates'] ?? NULL)) {
      return $report;
    }
    foreach ($report['gates'] as $i => $gate) {
      if (!is_array($gate)) {
        continue;
      }
      $name = $gate['gate'] ?? NULL;
      if (is_string($name) && isset($history[$name])) {
        $gate['previous_attempts'] = $history[$name];
        $report['gates'][$i] = $gate;
      }
    }

    return $report;
  }

  /**
   * This run with a phase's gate report recorded.
   *
   * Until this existed, `gate_results` was a field the writer emitted, the
   * reader round-tripped, and nothing ever populated — which meant any
   * instruction to "read the gate results from run state" pointed at a
   * permanently empty array, and anyone following it would fill the gap from
   * memory. Reporting checks that never ran is the one failure this package
   * exists to prevent, so the field needed a writer or it needed removing.
   *
   * @param string $phase
   *   The phase the report belongs to.
   * @param array<string, mixed> $report
   *   The serialized PhaseReport. Kept as plain data so State does not depend
   *   on Gate.
   *
   * @return self
   *   A new instance.
   */
  public function withGateReport(string $phase, array $report): self {
    $results = $this->gateResults;
    $previous = $results[$phase] ?? NULL;
    $results[$phase] = self::carryFailedAttempts(is_array($previous) ? $previous : NULL, $report);
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $this->phases,
      $this->currentPhase,
      $results,
      $this->awaiting,
      $this->qaHistory,
      $this->feedbackAttempts,
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
      $this->seekerHistory,
      $this->specPath,
      $this->gateWaivers,
      $this->baseCommit,
      $this->baselineHash,
      $this->ruleFiles,
      $this->seekerRounds,
      $this->workItem,
      $this->loop,
      rules: $this->rules,
      seekerHolds: $this->seekerHolds,
      seekerAtComplete: $this->seekerAtComplete,
      preexisting: $this->preexisting,
      lead: $this->lead,
    );
  }

  /**
   * This run with one more feedback-loop attempt counted against a gate.
   *
   * @param string $gate
   *   The gate that failed.
   *
   * @return self
   *   A new instance.
   */
  public function withFeedbackAttempt(string $gate): self {
    $attempts = $this->feedbackAttempts;
    $attempts[$gate] = ($attempts[$gate] ?? 0) + 1;
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $this->phases,
      $this->currentPhase,
      $this->gateResults,
      $this->awaiting,
      $this->qaHistory,
      $attempts,
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
      $this->seekerHistory,
      $this->specPath,
      $this->gateWaivers,
      $this->baseCommit,
      $this->baselineHash,
      $this->ruleFiles,
      $this->seekerRounds,
      $this->workItem,
      $this->loop,
      rules: $this->rules,
      seekerHolds: $this->seekerHolds,
      seekerAtComplete: $this->seekerAtComplete,
      preexisting: $this->preexisting,
      lead: $this->lead,
    );
  }

  /**
   * This run paused at a gate, waiting on a question.
   *
   * @param array<string, string|list<string>> $question
   *   The serialized pending question. The lists are the conversation an
   *   interactive hold carries — its detail lines and the options worth
   *   offering — which is why this is not a flat string map.
   *
   * @return self
   *   A new instance.
   */
  public function awaiting(array $question): self {
    return $this->rebuild(awaiting: $question, clearAwaiting: FALSE);
  }

  /**
   * This run with its lead changed (`workflow:lead`).
   *
   * @param string $lead
   *   One of LEADS.
   *
   * @return self
   *   A new instance.
   */
  public function withLead(string $lead): self {
    return $this->with(lead: $lead);
  }

  /**
   * Whether a human must answer the pending hold.
   *
   * The complete hold of a human-led run (owner, 2026-10-20).
   *
   * @return bool
   *   TRUE when the pending question is complete's and the run is human-led.
   */
  public function awaitsHuman(): bool {
    return $this->lead === self::LEAD_HUMAN
      && is_array($this->awaiting)
      && ($this->awaiting['phase'] ?? NULL) === 'complete';
  }

  /**
   * This run with its pending question answered.
   *
   * The exchange is appended to the history, never replacing it: what was
   * asked and what was decided is part of the run's record, and the complete
   * phase presents it. Clearing the question but keeping no trace of it would
   * make a pair-mode run indistinguishable from an unattended one after the
   * fact.
   *
   * @param string $answer
   *   What the human said.
   * @param string $answeredAt
   *   When, as a caller-supplied ISO-8601 string.
   * @param string|null $via
   *   Where the answer came from: `terminal` (the operator's) or `agent` (a
   *   command with no terminal, which is how an agent answers). NULL when
   *   the caller cannot say.
   *
   * @return self
   *   A new instance, no longer awaiting.
   */
  public function answered(string $answer, string $answeredAt, ?string $via = NULL): self {
    $history = $this->qaHistory;
    $history[] = [
      'asked' => $this->awaiting,
      'answer' => $answer,
      'answered_at' => $answeredAt,
    ] + ($via === NULL ? [] : ['via' => $via]);
    return $this->rebuild(
      awaiting: NULL,
      clearAwaiting: TRUE,
      qaHistory: $history,
    );
  }

  /**
   * This run with any pending question dropped, unanswered.
   *
   * Used by a swap to automated: the point of that swap is to finish without
   * asking, so leaving the question outstanding would defeat it. The exchange
   * is still recorded, marked as released rather than answered, because a
   * question that was asked and then bypassed is a fact about the run.
   *
   * @param string $releasedAt
   *   When, as a caller-supplied ISO-8601 string.
   *
   * @return self
   *   A new instance, no longer awaiting.
   */
  public function released(string $releasedAt): self {
    if ($this->awaiting === NULL) {
      return $this;
    }
    $history = $this->qaHistory;
    $history[] = [
      'asked' => $this->awaiting,
      'answer' => NULL,
      'released_at' => $releasedAt,
    ];
    return $this->rebuild(
      awaiting: NULL,
      clearAwaiting: TRUE,
      qaHistory: $history,
    );
  }

  /**
   * A copy with the pause-related fields replaced.
   *
   * @param array<string, string|list<string>>|null $awaiting
   *   The new pending question.
   * @param bool $clearAwaiting
   *   Whether a NULL $awaiting means "clear it" rather than "leave it".
   * @param list<mixed>|null $qaHistory
   *   The new history, or NULL to keep the current one.
   *
   * @return self
   *   A new instance.
   */
  private function rebuild(
    ?array $awaiting,
    bool $clearAwaiting,
    ?array $qaHistory = NULL,
  ): self {
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $this->phases,
      $this->currentPhase,
      $this->gateResults,
      $clearAwaiting ? NULL : ($awaiting ?? $this->awaiting),
      $qaHistory ?? $this->qaHistory,
      $this->feedbackAttempts,
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
      $this->seekerHistory,
      $this->specPath,
      $this->gateWaivers,
      $this->baseCommit,
      $this->baselineHash,
      $this->ruleFiles,
      $this->seekerRounds,
      $this->workItem,
      $this->loop,
      rules: $this->rules,
      seekerHolds: $this->seekerHolds,
      seekerAtComplete: $this->seekerAtComplete,
      preexisting: $this->preexisting,
      lead: $this->lead,
    );
  }

  /**
   * This run with its governing spec recorded.
   *
   * @param string $specPath
   *   The spec, project-relative.
   *
   * @return self
   *   A new instance.
   */
  public function withSpecPath(string $specPath): self {
    return $this->with(specPath: $specPath);
  }

  /**
   * This run with a fresh seeker inspection recorded.
   *
   * @param array<string, bool|int|string> $record
   *   The parsed ledger's record (SeekerLedger::toRecord()).
   *
   * @return self
   *   A new instance.
   */
  public function withSeekerReport(array $record): self {
    // Rebuild the record in the canonical key ORDER hydration produces, not
    // merely with the canonical keys. A missing `self_reviewed` appended at
    // the end still round-tripped into a different document, because the
    // reader inserts it before `reported_at` and an identical-array
    // assertion compares order. Both halves were caught by the round-trip
    // test, which exists because shipping a package that cannot read the
    // state it just wrote is the failure this class guards against.
    $record = [
      'status' => $record['status'] ?? 'clean',
      ...self::openCount($record['open'] ?? NULL),
      'critical' => (int) ($record['critical'] ?? 0),
      'high' => (int) ($record['high'] ?? 0),
      'medium' => (int) ($record['medium'] ?? 0),
      'low' => (int) ($record['low'] ?? 0),
      'observations' => (int) ($record['observations'] ?? 0),
      'self_reviewed' => (bool) ($record['self_reviewed'] ?? FALSE),
      'phase' => (string) ($record['phase'] ?? ''),
      'dispatch' => (string) ($record['dispatch'] ?? 'unverified'),
      'reported_at' => (string) ($record['reported_at'] ?? ''),
    ];
    // The current verdict REPLACES; the trail APPENDS. An inspection that
    // clears a run must not erase the evidence of what the earlier ones
    // caught, which is exactly what a single replaced field did.
    return $this->with(
      seeker: $record,
      seekerHistory: [...$this->seekerHistory, $record],
    );
  }

  /**
   * This run with the agent's declared browser capability recorded.
   *
   * @param string $browser
   *   One of: playwright-mcp, native, none.
   *
   * @return self
   *   A new instance.
   */
  public function withBrowser(string $browser): self {
    return $this->with(browser: $browser);
  }

  /**
   * This run with the agent's declared host task surface recorded.
   *
   * @param string $tasks
   *   The surface: claude-code, codex, other, or none.
   *
   * @return self
   *   The run, with the declaration recorded.
   */
  public function withTasks(string $tasks): self {
    return $this->with(tasks: $tasks);
  }

  /**
   * This run bound to a ticket, or with its binding brought up to date.
   *
   * @param array<string, mixed> $item
   *   The ticket as WorkItem::binding() reports it.
   *
   * @return self
   *   The run, with the ticket recorded.
   */
  public function withWorkItem(array $item): self {
    return $this->with(workItem: $item);
  }

  /**
   * This run held to a baseline the operator recorded before any gate ran.
   *
   * @param string $hash
   *   The baseline's hash.
   *
   * @return self
   *   A new instance.
   */
  public function withBaselineHash(string $hash): self {
    return $this->with(baselineHash: $hash);
  }

  /**
   * This run with a mid-run mode swap applied.
   *
   * @param \Droost\Workflow\Config\Mode $to
   *   The mode to switch to.
   *
   * @return self
   *   A new instance.
   */
  public function withModeOverride(Mode $to): self {
    return $this->with(modeOverride: $to);
  }

  /**
   * This run's state as the data written to disk.
   *
   * @return array<string, mixed>
   *   The v1 document.
   */
  public function toArray(): array {
    $phases = [];
    foreach ($this->phases as $name => $status) {
      $phases[$name] = $status->value;
    }

    return [
      'v' => self::SCHEMA_VERSION,
      'run_id' => $this->runId,
      'started_at' => $this->startedAt,
      'mode' => $this->mode->value,
      'mode_override' => $this->modeOverride?->value,
      'preset' => $this->preset,
      'max_gate_retries' => $this->maxGateRetries,
      'provenance' => $this->provenance->value,
      'enforcement' => $this->enforcement->value,
      'resolved_gates' => $this->resolvedGates,
      'phase_gates' => $this->phaseGates,
      'phases' => $phases,
      'current_phase' => $this->currentPhase?->value,
      'gate_results' => $this->gateResults,
      'awaiting' => $this->awaiting,
      'qa_history' => $this->qaHistory,
      'feedback_attempts' => $this->feedbackAttempts,
      'seekers' => $this->seekers,
      'seeker_rounds' => $this->seekerRounds,
      'seeker' => $this->seeker,
      'seeker_history' => $this->seekerHistory,
      'spec_path' => $this->specPath,
      'gate_waivers' => $this->gateWaivers,
      'browser' => $this->browser,
      'tasks' => $this->tasks,
      'base_commit' => $this->baseCommit,
      'baseline_hash' => $this->baselineHash,
      'rule_files' => $this->ruleFiles,
      'work_item' => $this->workItem,
      'loop' => $this->loop->toArray(),
      'rules' => $this->rules,
      'seeker_holds' => $this->seekerHolds,
      'seeker_at_complete' => $this->seekerAtComplete,
      'preexisting' => $this->preexisting,
      'lead' => $this->lead,
    ];
  }

  /**
   * Rebuilds a run from the data written to disk.
   *
   * The caller has already checked the schema version.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's path as shown to an operator, so that every message
   *   about this document names the same file. Passing it in rather than
   *   hardcoding "run.json" here is what stops one failure reporting
   *   "run.json" and the next ".droost-workflow/run.json".
   *
   * @return self
   *   The run.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When a field is absent or has the wrong type.
   * @throws \Droost\Workflow\State\StateError
   *   When a name is outside its vocabulary.
   */
  public static function fromArray(TypedArray $node, string $label): self {
    $modeName = $node->string('mode');
    // resolve(), not tryFrom(): a run started before the rename recorded
    // "automated" or "pair", and refusing to read it would strand an
    // in-flight run rather than rename a lever.
    $mode = Mode::resolve($modeName);
    if ($mode === NULL) {
      throw StateError::corrupt($label, sprintf(
        'unknown mode "%s"',
        $modeName,
      ));
    }

    $overrideName = $node->optionalString('mode_override');
    $override = NULL;
    if ($overrideName !== NULL) {
      $override = Mode::resolve($overrideName);
      if ($override === NULL) {
        throw StateError::corrupt($label, sprintf(
          'unknown mode_override "%s"',
          $overrideName,
        ));
      }
    }

    $currentName = $node->optionalString('current_phase');
    $current = NULL;
    if ($currentName !== NULL) {
      $current = Phase::tryFrom($currentName);
      if ($current === NULL) {
        throw StateError::corrupt($label, sprintf(
          'unknown current_phase "%s"',
          $currentName,
        ));
      }
    }

    $phases = self::readPhases($node, $label);

    return new self(
      $node->string('run_id'),
      $node->string('started_at'),
      $mode,
      $override,
      self::readPreset($node, $label),
      $node->int('max_gate_retries'),
      self::readProvenance($node, $label),
      self::readResolvedGates($node, $label),
      $phases,
      $current,
      $node->optionalChild('gate_results')?->toArray() ?? [],
      $node->optionalChild('awaiting')?->toArray(),
      self::readQaHistory($node, $label),
      self::readFeedbackAttempts($node),
      self::readPhaseGates($node, $label, $phases),
      Enforcement::tryFrom(
        $node->optionalString('enforcement', Enforcement::Off->value)
          ?? Enforcement::Off->value,
      ) ?? Enforcement::Off,
      $node->optionalBool('seekers', FALSE),
      self::readSeeker($node, $label),
      self::readBrowser($node, $label),
      self::readTasks($node, $label),
      self::readSeekerHistory($node, $label),
      $node->optionalString('spec_path', '') ?: NULL,
      self::readGateWaivers($node, $label),
      // Absent on every run.json written before baselines existed; those
      // runs were held to none, which is exactly what NULL says.
      $node->optionalString('base_commit', '') ?: NULL,
      $node->optionalString('baseline_hash', '') ?: NULL,
      // late_woven and contributed_source, on a record from before droost
      // left Drupal, describe module gates no surface runs any more: read
      // past, never refused.
      self::readRuleFiles($node, $label),
      // Absent on every run.json written before the lever existed. Those
      // runs required one inspection, which is what the default says, so a
      // run mid-flight when the upgrade lands keeps its own semantics.
      max(1, $node->optionalInt('seeker_rounds', 1)),
      // Absent on every run.json written before tickets existed: those runs
      // answered none.
      self::readWorkItem($node),
      // Absent on every run.json written before the fast flow: those runs
      // never looped, which is what the strict default says.
      LoopState::fromArray($node->optionalChild('loop')?->toArray()),
      // Absent on every run.json written before rules existed.
      rules: $node->optionalChild('rules')?->toArray() ?? [],
      // Absent on every run.json written before the levers: those runs held
      // on what their level held on, and inspected at code only.
      seekerHolds: $node->optionalString('seeker_holds', '')
        ?: WorkflowConfig::defaultSeekerHolds(self::readPreset($node, $label)),
      seekerAtComplete: $node->optionalBool('seeker_at_complete', FALSE),
      preexisting: array_filter(
        $node->optionalChild('preexisting')?->toArray() ?? [],
        static fn (mixed $hash, mixed $path): bool => is_string($hash) && is_string($path),
        ARRAY_FILTER_USE_BOTH,
      ),
      lead: in_array($node->optionalString('lead', ''), self::LEADS, TRUE)
        ? (string) $node->optionalString('lead', '')
        : self::LEAD_AUTONOMOUS,
    );
  }

  /**
   * Reads the ticket a run is bound to, or NULL when it is bound to none.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The run node.
   *
   * @return array<string, mixed>|null
   *   The binding, keyed as WorkItem::binding() writes it.
   */
  private static function readWorkItem(TypedArray $node): ?array {
    $child = $node->optionalChild('work_item');
    if ($child === NULL) {
      return NULL;
    }
    $binding = [];
    foreach ($child->toArray() as $key => $value) {
      $binding[(string) $key] = $value;
    }
    return $binding;
  }

  /**
   * Reads the rule files frozen at begin, and their hashes.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The document root.
   * @param string $label
   *   The document label.
   *
   * @return array<string, string|null>
   *   Project-relative path to sha256, or NULL for a file that was absent.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When an entry is not a path mapped to a sha256 or null.
   */
  private static function readRuleFiles(TypedArray $node, string $label): array {
    $files = $node->optionalChild('rule_files');
    if ($files === NULL) {
      return [];
    }
    $out = [];
    foreach ($files->toArray() as $path => $hash) {
      if (!is_string($path) || $path === '' || ($hash !== NULL && (!is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1))) {
        throw StateError::corrupt($label, sprintf(
          'rule_files must map a project path to a sha256 or null; got "%s" => %s',
          (string) $path,
          var_export($hash, TRUE),
        ));
      }
      $out[$path] = $hash;
    }
    return $out;
  }

  /**
   * Reads the operator's per-gate waivers.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The run node.
   * @param string $label
   *   The provenance label for errors.
   *
   * @return array<string, array{reason: string, granted_at: string}>
   *   Waivers keyed by gate name; empty when none were granted.
   */
  private static function readGateWaivers(TypedArray $node, string $label): array {
    $raw = $node->optionalChild('gate_waivers')?->toArray() ?? [];
    $waivers = [];
    foreach ($raw as $gate => $record) {
      if (!is_string($gate) || !is_array($record)
        || !is_string($record['reason'] ?? NULL) || ($record['reason'] ?? '') === ''
        || !is_string($record['granted_at'] ?? NULL) || ($record['granted_at'] ?? '') === '') {
        throw StateError::corrupt(
          $label,
          'gate_waivers must map a gate name to {reason, granted_at}, both non-empty — a waiver without its reason is indistinguishable from tampering',
        );
      }
      $waivers[$gate] = [
        'reason' => $record['reason'],
        'granted_at' => $record['granted_at'],
      ];
    }
    return $waivers;
  }

  /**
   * This run with one gate waived by the operator.
   *
   * The waiver is RUN-SCOPED: it dies with the run record, applies to every
   * remaining phase that lists the gate, and always carries its reason —
   * the report prints it, so the choice is visible where the record is.
   *
   * @param string $gate
   *   The gate name.
   * @param string $reason
   *   The operator's reason, non-empty.
   * @param string $grantedAt
   *   When it was granted, ISO-8601.
   *
   * @return self
   *   A new instance carrying the waiver.
   */
  public function withGateWaiver(string $gate, string $reason, string $grantedAt): self {
    $waivers = $this->gateWaivers;
    $waivers[$gate] = ['reason' => $reason, 'granted_at' => $grantedAt];
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $this->phases,
      $this->currentPhase,
      $this->gateResults,
      $this->awaiting,
      $this->qaHistory,
      $this->feedbackAttempts,
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $this->seeker,
      $this->browser,
      $this->tasks,
      $this->seekerHistory,
      $this->specPath,
      $waivers,
      $this->baseCommit,
      $this->baselineHash,
      $this->ruleFiles,
      $this->seekerRounds,
      $this->workItem,
      $this->loop,
      rules: $this->rules,
      seekerHolds: $this->seekerHolds,
      seekerAtComplete: $this->seekerAtComplete,
      preexisting: $this->preexisting,
      lead: $this->lead,
    );
  }

  /**
   * Reads the trail of inspections this run recorded.
   *
   * Absent on every run.json written before the trail existed, and those
   * runs are not corrupt — they simply predate it. So a missing key yields
   * an empty trail rather than an error, and a run mid-flight when the
   * upgrade lands keeps advancing with the inspections it records from here
   * on. Refusing them would strand exactly the runs the fix is meant to
   * serve.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's path as shown to an operator.
   *
   * @return list<array<string, bool|int|string>>
   *   The inspections, oldest first.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When an entry carries a status outside its vocabulary.
   */
  private static function readSeekerHistory(
    TypedArray $node,
    string $label,
  ): array {
    $raw = $node->optionalChild('seeker_history')?->toArray() ?? [];
    if (!array_is_list($raw)) {
      throw StateError::corrupt(
        $label,
        'seeker_history must be a list, got a mapping',
      );
    }
    $history = [];
    foreach ($raw as $index => $entry) {
      if (!is_array($entry)) {
        throw StateError::corrupt($label, sprintf(
          'seeker_history[%d] must be a mapping, got %s',
          $index,
          get_debug_type($entry),
        ));
      }
      // Read each entry through the same typed reader the rest of this file
      // uses, rather than casting: a cast turns a malformed value into a
      // plausible one (a string "many" becomes 0), which is how a corrupt
      // record reads as a clean inspection.
      $typed = TypedArray::serialized($entry);
      $status = $typed->string('status');
      if (!in_array($status, ['clean', 'findings'], TRUE)) {
        throw StateError::corrupt($label, sprintf(
          'seeker_history[%d] has unknown status "%s" (known: clean, findings)',
          $index,
          $status,
        ));
      }
      $history[] = [
        'status' => $status,
        ...self::openCount($typed->toArray()['open'] ?? NULL),
        'critical' => $typed->int('critical'),
        // Absent before HIGH existed (0.14.18): none was recorded.
        'high' => $typed->optionalInt('high', 0),
        'medium' => $typed->int('medium'),
        'low' => $typed->int('low'),
        'observations' => $typed->int('observations'),
        // Absent on any entry recorded before the label was read back, and
        // an unlabelled inspection is an independent one by default: the
        // pack asks a SELF-review to say so, never the other way round.
        'self_reviewed' => $typed->optionalBool('self_reviewed', FALSE),
        // Absent before 0.14.18: no phase was kept, and no dispatch checked.
        'phase' => $typed->optionalString('phase', '') ?? '',
        'dispatch' => $typed->optionalString('dispatch', 'unverified') ?? 'unverified',
        'reported_at' => $typed->string('reported_at'),
      ];
    }
    return $history;
  }

  /**
   * Reads the recorded seeker inspection, when one exists.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, bool|int|string>|null
   *   The record.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When a field has the wrong type.
   * @throws \Droost\Workflow\State\StateError
   *   When the status word is outside its vocabulary.
   */
  private static function readSeeker(
    TypedArray $node,
    string $label,
  ): ?array {
    $seekerNode = $node->optionalChild('seeker');
    if ($seekerNode === NULL) {
      return NULL;
    }
    $status = $seekerNode->string('status');
    if (!in_array($status, ['clean', 'findings'], TRUE)) {
      throw StateError::corrupt($label, sprintf(
        'unknown seeker status "%s" (known: clean, findings)',
        $status,
      ));
    }
    return [
      'status' => $status,
      ...self::openCount($seekerNode->toArray()['open'] ?? NULL),
      'critical' => $seekerNode->int('critical'),
      'high' => $seekerNode->optionalInt('high', 0),
      'medium' => $seekerNode->int('medium'),
      'low' => $seekerNode->int('low'),
      'observations' => $seekerNode->int('observations'),
      // Absent on every record written before the ledger's own label was
      // read back, and an unlabelled inspection is an independent one: the
      // pack asks a SELF-review to say so, never the other way round.
      'self_reviewed' => $seekerNode->optionalBool('self_reviewed', FALSE),
      'phase' => $seekerNode->optionalString('phase', '') ?? '',
      'dispatch' => $seekerNode->optionalString('dispatch', 'unverified') ?? 'unverified',
      'reported_at' => $seekerNode->string('reported_at'),
    ];
  }

  /**
   * The seeker record's `open` count, or nothing when none was recorded.
   *
   * The severities count every row of an inspection, resolved and carried
   * ones too; `open` is how many still stand (F-327). A record written
   * before it was kept has no count, and stays without one rather than
   * reading as "0 open".
   *
   * @param mixed $open
   *   The recorded value, if any.
   *
   * @return array<string, int>
   *   `['open' => n]`, or empty.
   */
  private static function openCount(mixed $open): array {
    return is_int($open) ? ['open' => $open] : [];
  }

  /**
   * Reads the declared browser capability, when one was declared.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return string|null
   *   The capability.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When the word is outside its vocabulary.
   */
  private static function readBrowser(
    TypedArray $node,
    string $label,
  ): ?string {
    $browser = $node->optionalString('browser');
    if ($browser === NULL) {
      return NULL;
    }
    if (!in_array($browser, ['playwright-mcp', 'native', 'none'], TRUE)) {
      throw StateError::corrupt($label, sprintf(
        'unknown browser capability "%s" (known: playwright-mcp, native, '
        . 'none)',
        $browser,
      ));
    }
    return $browser;
  }

  /**
   * Reads the declared host task surface, when one was declared.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return string|null
   *   The surface.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When the word is outside its vocabulary.
   */
  private static function readTasks(
    TypedArray $node,
    string $label,
  ): ?string {
    $tasks = $node->optionalString('tasks');
    if ($tasks === NULL) {
      return NULL;
    }
    if (!in_array($tasks, self::TASK_SURFACES, TRUE)) {
      throw StateError::corrupt($label, sprintf(
        'unknown task surface "%s" (known: %s)',
        $tasks,
        implode(', ', self::TASK_SURFACES),
      ));
    }
    return $tasks;
  }

  /**
   * Reads the frozen phase-to-gates map.
   *
   * A document written before the map existed has no phase_gates field. The
   * engine default is the only honest reconstruction — those runs WERE
   * executing under "the engine decides" — so absence synthesizes the
   * default for the phases the document configures. A PRESENT field is the
   * run's own frozen record and is never second-guessed, empty or not.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   * @param array<string, \Droost\Workflow\State\PhaseStatus> $phases
   *   The configured phases, already read and validated.
   *
   * @return array<string, list<string>>
   *   Phase name to its due gates.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When a value is not a list of strings.
   * @throws \Droost\Workflow\State\StateError
   *   When a phase or gate name is outside its vocabulary.
   */
  private static function readPhaseGates(
    TypedArray $node,
    string $label,
    array $phases,
  ): array {
    $gatesNode = $node->optionalChild('phase_gates');
    if ($gatesNode === NULL) {
      $due = [];
      foreach (array_keys($phases) as $name) {
        $phase = Phase::tryFrom($name);
        if ($phase !== NULL) {
          $due[$name] = PhaseGateMap::gatesFor($phase);
        }
      }
      return $due;
    }

    $due = [];
    foreach ($gatesNode->keys() as $name) {
      if (Phase::tryFrom($name) === NULL) {
        // The phases reader above has already turned a 0.3 document into
        // the migration message; anything else here is genuine corruption.
        throw StateError::corrupt($label, sprintf(
          'unknown phase "%s" in phase_gates (known: %s)',
          $name,
          implode(', ', Phase::names()),
        ));
      }
      $gates = $gatesNode->stringList($name);
      foreach ($gates as $gate) {
        if (!GateSettings::isKnown($gate)) {
          throw StateError::corrupt($label, sprintf(
            'unknown gate "%s" in phase_gates (known: %s)',
            $gate,
            implode(', ', GateSettings::KNOWN_GATES),
          ));
        }
      }
      $due[$name] = $gates;
    }
    return $due;
  }

  /**
   * Reads the recorded preset name.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return string
   *   The preset name.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the field is absent or not a string.
   * @throws \Droost\Workflow\State\StateError
   *   When the name is outside the vocabulary.
   */
  private static function readPreset(
    TypedArray $node,
    string $label,
  ): string {
    $preset = $node->string('preset');
    if (!PresetResolver::isKnown($preset)) {
      throw StateError::corrupt($label, sprintf(
        'unknown preset "%s" (known: %s)',
        $preset,
        implode(', ', PresetResolver::KNOWN_PRESETS),
      ));
    }
    return $preset;
  }

  /**
   * Reads the recorded provenance.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return \Droost\Workflow\Config\Provenance
   *   Where the levers this run is held to came from.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the field is absent or not a string.
   * @throws \Droost\Workflow\State\StateError
   *   When the word is outside the vocabulary.
   */
  private static function readProvenance(
    TypedArray $node,
    string $label,
  ): Provenance {
    $word = $node->string('provenance');
    $provenance = Provenance::tryFrom($word);
    if ($provenance === NULL) {
      throw StateError::corrupt($label, sprintf(
        'unknown provenance "%s"',
        $word,
      ));
    }
    return $provenance;
  }

  /**
   * Reads the answered-question history.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return list<mixed>
   *   The history, in order.
   *
   * @throws \Droost\Workflow\State\StateError
   *   When the field is present but not a list. Silently running
   *   array_values() over a map would reshape a reserved field the caller
   *   handed us verbatim, which is the one thing round-tripping promises not
   *   to do.
   */
  private static function readQaHistory(
    TypedArray $node,
    string $label,
  ): array {
    $raw = $node->optionalChild('qa_history')?->toArray() ?? [];
    if (!array_is_list($raw)) {
      throw StateError::corrupt(
        $label,
        'qa_history must be a list, got a mapping',
      );
    }
    return $raw;
  }

  /**
   * Reads the recorded gate levers.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its recorded levers.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the shape is wrong.
   * @throws \Droost\Workflow\State\StateError
   *   When a gate name is outside the vocabulary.
   */
  private static function readResolvedGates(
    TypedArray $node,
    string $label,
  ): array {
    $gatesNode = $node->optionalChild('resolved_gates');
    if ($gatesNode === NULL) {
      return [];
    }

    $gates = [];
    foreach ($gatesNode->keys() as $name) {
      // The config side refuses unknown gate names; state has to as well, or
      // a corrupted run.json yields a gate report naming a gate that does not
      // exist — and resolvedGates is what a report renders.
      if (!GateSettings::isKnown($name)) {
        throw StateError::corrupt($label, sprintf(
          'unknown gate "%s" in resolved_gates (known: %s)',
          $name,
          implode(', ', GateSettings::KNOWN_GATES),
        ));
      }
      $levers = $gatesNode->child($name);
      $out = ['on' => $levers->bool('on')];
      foreach ($levers->keys() as $option) {
        if ($option === 'on') {
          continue;
        }
        // Options span int (a level), string (a standard) and bool (a flag
        // such as `required`); read them back as written, never coerced.
        $out[$option] = $levers->scalar($option);
      }
      $gates[$name] = $out;
    }
    return $gates;
  }

  /**
   * Reads the per-phase statuses.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   * @param string $label
   *   The state file's operator-facing path.
   *
   * @return array<string, \Droost\Workflow\State\PhaseStatus>
   *   Phase name to status.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When the shape is wrong.
   * @throws \Droost\Workflow\State\StateError
   *   When a status word is outside the vocabulary.
   */
  private static function readPhases(TypedArray $node, string $label): array {
    $phasesNode = $node->optionalChild('phases');
    if ($phasesNode === NULL) {
      return [];
    }

    $phases = [];
    foreach ($phasesNode->keys() as $name) {
      // Validate the KEY, not just the status word. A typo'd or foreign phase
      // name would otherwise load clean, round-trip its garbage back to disk,
      // and then make every legitimate phase unreachable — failing much later
      // and nowhere near the cause.
      if (Phase::tryFrom($name) === NULL) {
        // "document" is not garbage — it is a run that began under 0.3,
        // whose five-phase vocabulary this build no longer speaks. Runs are
        // short-lived by design, so there is no migration: the message names
        // the two honest exits instead of calling the file corrupt.
        if ($name === 'document') {
          throw StateError::corrupt($label, sprintf(
            'this run began under droost/workflow 0.3, whose five phases '
            . 'included "document" (folded into complete in 0.4.0) — finish '
            . 'the run on 0.3, or remove %s and start a new run',
            $label,
          ));
        }
        throw StateError::corrupt($label, sprintf(
          'unknown phase "%s" (known: %s)',
          $name,
          implode(', ', Phase::names()),
        ));
      }
      $word = $phasesNode->string($name);
      $status = PhaseStatus::tryFrom($word);
      if ($status === NULL) {
        throw StateError::corrupt($label, sprintf(
          'unknown status "%s" for phase "%s"',
          $word,
          $name,
        ));
      }
      $phases[$name] = $status;
    }
    return $phases;
  }

  /**
   * Reads the feedback-loop counters.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The decoded document.
   *
   * @return array<string, int>
   *   Gate name to attempt count.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When a counter is not an integer.
   */
  private static function readFeedbackAttempts(TypedArray $node): array {
    $attemptsNode = $node->optionalChild('feedback_attempts');
    if ($attemptsNode === NULL) {
      return [];
    }

    $attempts = [];
    foreach ($attemptsNode->keys() as $gate) {
      $attempts[$gate] = $attemptsNode->int($gate);
    }
    return $attempts;
  }

  /**
   * A copy with selected fields replaced.
   *
   * @param array<string, \Droost\Workflow\State\PhaseStatus>|null $phases
   *   The new phase statuses, or NULL to keep the current ones.
   * @param \Droost\Workflow\Config\Phase|null $currentPhase
   *   The new current phase; only applied when $moveCurrent is TRUE.
   * @param \Droost\Workflow\Config\Mode|null $modeOverride
   *   The new override, or NULL to keep the current one.
   * @param array<string, bool|int|string>|null $seeker
   *   The new inspection record, or NULL to keep the current one.
   * @param string|null $browser
   *   The new declared capability, or NULL to keep the current one.
   * @param string|null $tasks
   *   The new declared task surface, or NULL to keep the current one.
   * @param list<array<string, bool|int|string>>|null $seekerHistory
   *   The new inspection trail, or NULL to keep the current one. Callers
   *   append rather than replace: see withSeekerReport().
   * @param string|null $specPath
   *   The governing spec, or NULL to keep the current one.
   * @param bool $clearSeeker
   *   Whether to CLEAR the current seeker verdict. NULL means "unchanged" for
   *   every other field here, which is what makes this necessary:
   *   `seeker: NULL` cannot clear the verdict, only fail to set it.
   * @param array<string, mixed>|null $workItem
   *   The ticket binding to record, or NULL to keep the run's own.
   * @param \Droost\Workflow\State\LoopState|null $loop
   *   The loop record, or NULL to keep the run's own.
   * @param array<string, string>|null $preexisting
   *   What the tree held changed at begin, or NULL to keep the run's own.
   * @param string|null $lead
   *   The new lead, or NULL to keep it.
   * @param string|null $baselineHash
   *   The baseline to hold the run to, or NULL to keep the run's own.
   *
   * @return self
   *   A new instance.
   */
  private function with(
    ?array $phases = NULL,
    ?Phase $currentPhase = NULL,
    ?Mode $modeOverride = NULL,
    ?array $seeker = NULL,
    ?string $browser = NULL,
    ?string $tasks = NULL,
    ?array $seekerHistory = NULL,
    ?string $specPath = NULL,
    // NULL means "unchanged" for every other field here, which is what makes
    // this one necessary: `seeker: NULL` cannot CLEAR the verdict, it can only
    // fail to set it. Written out because a silent no-op is exactly what this
    // shape produces, and one shipped.
    bool $clearSeeker = FALSE,
    ?array $workItem = NULL,
    ?LoopState $loop = NULL,
    ?array $preexisting = NULL,
    ?string $lead = NULL,
    ?string $baselineHash = NULL,
  ): self {
    return new self(
      $this->runId,
      $this->startedAt,
      $this->mode,
      $modeOverride ?? $this->modeOverride,
      $this->preset,
      $this->maxGateRetries,
      $this->provenance,
      $this->resolvedGates,
      $phases ?? $this->phases,
      $currentPhase ?? $this->currentPhase,
      $this->gateResults,
      $this->awaiting,
      $this->qaHistory,
      $this->feedbackAttempts,
      $this->phaseGates,
      $this->enforcement,
      $this->seekers,
      $clearSeeker ? NULL : ($seeker ?? $this->seeker),
      $browser ?? $this->browser,
      $tasks ?? $this->tasks,
      $seekerHistory ?? $this->seekerHistory,
      $specPath ?? $this->specPath,
      $this->gateWaivers,
      $this->baseCommit,
      $baselineHash ?? $this->baselineHash,
      $this->ruleFiles,
      $this->seekerRounds,
      $workItem ?? $this->workItem,
      $loop ?? $this->loop,
      rules: $this->rules,
      seekerHolds: $this->seekerHolds,
      seekerAtComplete: $this->seekerAtComplete,
      preexisting: $preexisting ?? $this->preexisting,
      lead: $lead ?? $this->lead,
    );
  }

  /**
   * The retry budget, as every surface must report it (F-21).
   *
   * ONE SOURCE OF TRUTH. `RunOutcome::toArray()` computed this for the run
   * envelope and `status()` did not carry it, so the report — the surface an
   * operator actually reads after a run ends — could not tell
   * "this gate failed" from "this gate failed, spent its budget, and has not
   * been asked since". Only the second sentence explains a verdict naming spec
   * rows the agent has already corrected, which is how a live round read as
   * "your corrections were rejected".
   *
   * Re-deriving it in a second place would be the same defect this repo keeps
   * finding: two paths to one answer, and they disagree in the case nobody
   * tested.
   *
   * @return array{attempts: array<string, int>, remaining: array<string,
   *   int>, max_gate_retries: int, exhausted: bool}
   *   `remaining` is stated rather than left to inference: `mayRetry()` is
   *   `attempts < max`, and `{"attempts":{"phpcs":2},"max_gate_retries":2}`
   *   reads just as naturally as "one more coming" to an agent that does not
   *   know the comparison is `<` and not `<=`.
   */
  public function retries(): array {
    $exhausted = $this->currentPhase !== NULL
      && $this->statusOf($this->currentPhase) === PhaseStatus::Failed;
    $left = [];
    foreach ($this->feedbackAttempts as $gate => $spent) {
      $left[$gate] = $exhausted ? 0 : max(0, $this->maxGateRetries - $spent + 1);
    }

    return [
      'attempts' => $this->feedbackAttempts,
      'remaining' => $left,
      'max_gate_retries' => $this->maxGateRetries,
      'exhausted' => $exhausted,
    ];
  }

}
