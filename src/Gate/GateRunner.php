<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Baseline\Baseline;
use Droost\Workflow\Baseline\BaselineAwareExecutorInterface;
use Droost\Workflow\Baseline\BaselineAwareSiteDriverInterface;
use Droost\Workflow\Baseline\BaselineContext;
use Droost\Workflow\Baseline\BaselineError;
use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\PresetResolver;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Vcs\VcsInterface;

/**
 * Executes a phase's gates and says whether the run may continue.
 *
 * WHICH gates run, and WHEN, come from the RUN: the resolved on/off set and
 * the phase map are frozen at begin, so an edit made mid-run cannot switch a
 * gate off under a half-finished run or make two surfaces disagree about it.
 *
 * HOW a gate runs — its tuning options (standard, level, paths, thresholds,
 * routes) — is re-read from the lever file at gate time, when the file
 * exists, and any difference from the frozen record is named in the gate's
 * own summary. Round 24 (R24-F4): a subject's phpcs gate walked node_modules,
 * the subject pointed `phpcs.standard` at a ruleset that excluded it — the
 * feedback loop's intended move — and the gate silently re-ran with the old
 * standard until the retries were spent. Fixing a ruleset IS the loop;
 * refusing to see the fix while counting down the budget was the defect.
 */
final class GateRunner {

  /**
   * The gates that cannot run without a booted site.
   *
   * Note that phpunit is deliberately NOT here: unit and kernel suites run well
   * against a checkout, and treating the whole gate as site-bound would skip
   * checks that could have run. Functional suites do need a site, but selecting
   * them is an argument to phpunit rather than a fact about the gate — a
   * distinction this package cannot make yet, and recorded rather than guessed
   * at.
   *
   * wiki_fresh is NOT here, and putting it here was a mistake worth recording.
   * It genuinely needs a site — it asks one, through `drush
   * droost:wiki:status` — so the classification looks right, and moving it here
   * did fix the standalone surface. But every gate in this list is dispatched
   * to the SITE DRIVER, and `DrupalSiteDriver::supports()` names three gates.
   * A site gate the driver does not implement is `toolMissing` by design (a
   * misconfiguration, not an environmental skip), which BLOCKS — so the fix
   * moved the unpassable phase from the standalone surface onto the Drupal one,
   * which is the surface a real run uses. Strictly worse, and with a more
   * confusing message.
   *
   * The list is about HOW a gate runs, not about what it needs: these three
   * need a booted kernel in-process. wiki_fresh is a shell command like phpcs,
   * and its real problem was never the classification — it was that a missing
   * drush read as a broken environment. That is answered where it happens, in
   * ShellGateExecutor.
   *
   * @var list<string>
   */
  public const SITE_GATES = ['rendered_check', 'config_clean', 'grounding_check'];

  /**
   * Constructs a GateRunner.
   *
   * @param \Droost\Workflow\Gate\GateExecutorInterface $executor
   *   Runs the gates that need only a checkout.
   * @param \Droost\Workflow\Gate\SiteDriverInterface $driver
   *   Runs the gates that need a site.
   * @param \Droost\Workflow\Vcs\VcsInterface|null $vcs
   *   Answers which files the run has changed since its base commit, for the
   *   baseline context. NULL means "unknown", which the gates treat as
   *   nothing changed — the conservative reading for inherited whole files.
   * @param list<\Droost\Workflow\Config\ContributedGate> $contributed
   *   The gates enabled modules declare, so re-reading the lever file at gate
   *   time resolves the same set the run froze.
   */
  public function __construct(
    private readonly GateExecutorInterface $executor,
    private readonly SiteDriverInterface $driver,
    private readonly ?VcsInterface $vcs = NULL,
    private readonly array $contributed = [],
  ) {}

  /**
   * Runs every gate due at this phase.
   *
   * Iterates the run's own frozen phase map rather than the whole resolved
   * set, for the same reason it reads the run's levers: what a half-finished
   * run is measured against must not change under it. A gate that is not due
   * at this phase is omitted from the report entirely — complete re-runs the
   * full set, so nothing enabled is ever omitted from the run.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run, carrying the resolved levers and the frozen phase map.
   * @param \Droost\Workflow\Config\Phase $phase
   *   The phase being gated.
   * @param string $projectRoot
   *   The repository to run in.
   * @param callable(\Droost\Workflow\Gate\GateResult): void|null $onResult
   *   Called after each gate. The attach point for pair mode, which needs to
   *   act at a gate boundary without this class knowing anything about modes.
   *
   * @return \Droost\Workflow\Gate\PhaseReport
   *   Every due gate's outcome.
   */
  public function run(
    RunState $state,
    Phase $phase,
    string $projectRoot,
    ?callable $onResult = NULL,
  ): PhaseReport {
    $report = new PhaseReport($phase);
    $live = $this->liveTuning($projectRoot);
    [$context, $tamper] = $this->baselineFor($state, $projectRoot);
    // Read once per phase and only when a gate ran: no gate here writes a
    // file, so the diff does not move between them.
    $changed = NULL;

    foreach ($state->gatesDueFor($phase) as $name => $levers) {
      $waiver = $state->gateWaivers[$name] ?? NULL;
      if ($waiver !== NULL) {
        // Waived by the OPERATOR for this run (never an agent's move — the
        // waiver enters only through the CLI). The gate does not execute,
        // the status is visibly distinct from every kind of pass, and the
        // reason rides the result into the report.
        $result = new GateResult(
          $name,
          GateStatus::Waived,
          summary: sprintf('waived by the operator: %s', $waiver['reason']),
          skipReason: $waiver['reason'],
        );
      }
      elseif ($tamper !== NULL && in_array($name, Baseline::CONSULTING, TRUE)) {
        // The baseline on disk is not the one this run froze at begin. Every
        // gate that would have consulted it fails instead of running: a
        // baseline is the operator's adoption record, and one that moves
        // under a run is the defeat the whole design exists to refuse. The
        // gates that never consult it (phpunit, the browser check) run as
        // usual — the tree is still the tree.
        $result = GateResult::ran(
          $name,
          GateStatus::Failed,
          1,
          0,
          sprintf('%s FAILED — %s', $name, $tamper),
          [],
          'baseline integrity check',
        );
      }
      else {
        [$levers, $drift] = $this->withLiveTuning($name, $levers, $live[$name] ?? NULL);
        $result = $this->inReportMode($levers, $this->coveringTheDiff(
          $name,
          $levers,
          $state,
          $projectRoot,
          $this->runOne($name, $levers, $projectRoot, $state->preset, $context),
        ));
        $result = $this->steeredByTheRun($name, $levers, $state, $projectRoot, $result, $changed);
        $result = $this->outsideTheDiff($state, $projectRoot, $result, $changed);
        if ($drift !== []) {
          // Through the one helper that carries every field: the positional
          // rebuild this replaced turned a labelled pass into a plain one
          // and dropped the remedy, subjects and output with it (F-64).
          $result = $result->withSummary(
            rtrim($result->summary, '. ') . sprintf(' [levers re-read at gate time: %s]', implode('; ', $drift)),
          );
        }
      }
      $report = $report->with($result);
      if ($onResult !== NULL) {
        $onResult($result);
      }
    }

    return $report;
  }

  /**
   * The wiki verdict, held to the extensions this run changed.
   *
   * F-60 (owner, 2026-09-23). `droost:wiki:status` fails only on a stale,
   * orphaned or invalid page. An extension with no page at all is a note, and
   * `--strict` would fail every run for every earlier rung's module. With
   * `cover_diff`, which medium and up set, the custom modules and themes THIS
   * run changed are held to having a page, and a gap an earlier run left stays
   * the note it was. Contrib is never held: the run did not write it.
   *
   * @param string $name
   *   The gate.
   * @param array<string, mixed> $levers
   *   Its levers.
   * @param \Droost\Workflow\State\RunState $state
   *   The run, for its base commit.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Gate\GateResult $result
   *   What the tool reported.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  private function coveringTheDiff(string $name, array $levers, RunState $state, string $projectRoot, GateResult $result): GateResult {
    if ($name !== 'wiki_fresh' || ($levers['cover_diff'] ?? FALSE) !== TRUE || $result->status !== GateStatus::Passed) {
      return $result;
    }
    if ($this->vcs === NULL || !$this->vcs->isRepository($projectRoot)) {
      return $result->withVerdict(GateStatus::Passed, rtrim($result->summary, '. ') . ' — cover_diff was not applied: '
        . 'the run\'s diff could not be read, so which extensions it changed is unknown.');
    }
    $uncovered = [];
    foreach ($result->findings as $finding) {
      if (($finding['rule'] ?? NULL) === 'wiki.uncovered' && is_string($finding['extension'] ?? NULL)) {
        $uncovered[] = $finding['extension'];
      }
    }
    $own = array_values(array_intersect(
      self::customExtensions($projectRoot, $this->vcs->changedFiles($projectRoot, $state->baseCommit)),
      $uncovered,
    ));
    if ($own === []) {
      return $result;
    }

    return $result->withVerdict(GateStatus::Failed, sprintf(
      'wiki_fresh FAILED — this run changed %s, and no wiki page covers %s (gates.wiki_fresh.cover_diff). '
      . 'Write or extend a page whose droost.modules names %s, then run this phase again. The report: %s',
      implode(', ', $own),
      count($own) === 1 ? 'it' : 'them',
      count($own) === 1 ? 'it' : 'each of them',
      $result->summary,
    ));
  }

  /**
   * The config files this run changed that the gate's tool read (F-102).
   *
   * A verdict reached under rules the run itself set is not the verdict a
   * reader assumes. P6 run 7's agent wrote the stylelint config its CSS was
   * judged by, tuned to that CSS, and the record said "stylelint passed". The
   * files are kept as a field for the record's column and named in the
   * summary, which is the line the agent and the operator read as the phase
   * runs. A gate that did not run read nothing, and a diff that cannot be
   * read names nothing, so neither is marked.
   *
   * @param string $name
   *   The gate.
   * @param array<string, mixed> $levers
   *   Its levers, for a pinned config.
   * @param \Droost\Workflow\State\RunState $state
   *   The run, for its base commit.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Gate\GateResult $result
   *   What the gate reported.
   * @param list<string>|null $changed
   *   The run's changed files, read on first use and kept for the phase.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The result, with the steering configs when there are any.
   */
  private function steeredByTheRun(string $name, array $levers, RunState $state, string $projectRoot, GateResult $result, ?array &$changed): GateResult {
    if (in_array($result->status, [GateStatus::Off, GateStatus::SkippedNoSite, GateStatus::Waived], TRUE)
      || $this->vcs === NULL || !$this->vcs->isRepository($projectRoot)) {
      return $result;
    }
    $changed ??= $this->vcs->changedFiles($projectRoot, $state->baseCommit);
    $steering = SteeringConfigs::changed($name, $levers, $projectRoot, $changed);
    if ($steering === []) {
      return $result;
    }

    return $result->withSteeredBy($steering)->withSummary(
      rtrim($result->summary, '. ') . sprintf(' [steered by config this run changed: %s]', implode(', ', $steering)),
    );
  }

  /**
   * Which of a failing gate's findings lie in files the run did not change.
   *
   * The other half of F-80. The summary names a failure's level and its first
   * lines, and P6 run 6's agent still could not tell that the one phpstan
   * error at `high` was in T1's code, which the run never touched: it asked
   * the operator instead. At `max` every custom file meets phpstan at level
   * max, so most of a first failure is inherited. Errors only: a warning
   * never fails a gate. A diff that cannot be read says nothing, rather than
   * calling everything inherited.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run, for its base commit.
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Gate\GateResult $result
   *   What the gate reported.
   * @param list<string>|null $changed
   *   The run's changed files, read on first use and kept for the phase.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The result, its summary naming the untouched files when there are any.
   */
  private function outsideTheDiff(RunState $state, string $projectRoot, GateResult $result, ?array &$changed): GateResult {
    if (($result->demotedFrom ?? $result->status) !== GateStatus::Failed
      || $this->vcs === NULL || !$this->vcs->isRepository($projectRoot)) {
      return $result;
    }
    $errors = [];
    foreach ($result->findings as $finding) {
      $file = $finding['file'] ?? NULL;
      if (is_string($file) && $file !== '' && ($finding['detail'] ?? 'error') !== 'warning') {
        $errors[] = ltrim(str_replace('\\', '/', $file), '/');
      }
    }
    if ($errors === []) {
      return $result;
    }
    $changed ??= $this->vcs->changedFiles($projectRoot, $state->baseCommit);
    $touched = array_flip(array_map(static fn (string $path): string => ltrim(str_replace('\\', '/', $path), '/'), $changed));
    $outside = array_values(array_filter($errors, static fn (string $file): bool => !isset($touched[$file])));
    if ($outside === []) {
      return $result;
    }
    $files = array_values(array_unique($outside));
    $where = count($files) === 1 ? 'a file' : 'files';
    $named = implode(', ', array_slice($files, 0, 3))
      . (count($files) > 3 ? sprintf(', and %d more', count($files) - 3) : '');
    if (!$result->truncated) {
      $note = sprintf(
        '%d of %d %s in %s this run did not change: %s',
        count($outside),
        count($errors),
        count($errors) === 1 ? 'error is' : 'errors are',
        $where,
        $named,
      );
    }
    else {
      // The findings stop at the cap and the summary's own total does not,
      // so a count of the kept ones read as the whole: "69 errors, …
      // [50 of 50 errors are in files this run did not change …]" (F-105).
      $note = sprintf(
        '%d of the %d %s kept in the record %s in %s this run did not change: %s. The record keeps a gate\'s first %d findings, so the rest are not counted here',
        count($outside),
        count($errors),
        count($errors) === 1 ? 'error' : 'errors',
        count($errors) === 1 ? 'is' : 'are',
        $where,
        $named,
        GateResult::FINDINGS_CAP,
      );
    }

    return $result->withSummary(rtrim($result->summary, '. ') . ' [' . $note . ']');
  }

  /**
   * The custom modules and themes that own the changed files.
   *
   * The owner is the nearest directory up the path that holds a `*.info.yml`,
   * which is how Drupal finds an extension, so a submodule is its own
   * extension rather than its parent's. Only paths under a `custom/` directory
   * are asked: a contrib, core or vendor change is not the run's extension.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string> $changed
   *   Project-relative changed paths.
   *
   * @return list<string>
   *   Machine names, first seen first.
   */
  private static function customExtensions(string $projectRoot, array $changed): array {
    $root = rtrim($projectRoot, '/');
    $names = [];
    foreach ($changed as $file) {
      $path = ltrim(str_replace('\\', '/', $file), '/');
      if (preg_match('#^((?:.*?/)?custom)/.+$#', $path, $match) !== 1) {
        continue;
      }
      $stop = $match[1];
      for ($dir = dirname($path); str_starts_with($dir, $stop . '/'); $dir = dirname($dir)) {
        $infos = glob($root . '/' . $dir . '/*.info.yml') ?: [];
        foreach ($infos as $info) {
          $names[basename($info, '.info.yml')] = TRUE;
        }
        if ($infos !== []) {
          break;
        }
      }
    }

    return array_keys($names);
  }

  /**
   * Whether a failing gate may be retried again.
   *
   * The bound is the run's own recorded `max_gate_retries`, and the count
   * lives in run state — so a process killed mid-loop resumes with its
   * attempts intact rather than starting the budget over.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $gate
   *   The gate that failed.
   *
   * @return bool
   *   TRUE when another attempt is within budget.
   */
  public function mayRetry(RunState $state, string $gate): bool {
    return ($state->feedbackAttempts[$gate] ?? 0) < $state->maxGateRetries;
  }

  /**
   * Records one more attempt against a gate.
   *
   * Returns the new state rather than mutating: the caller decides when to
   * persist, and a caller that forgets has not silently lost a count it
   * believed was saved.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $gate
   *   The gate that failed.
   *
   * @return \Droost\Workflow\State\RunState
   *   The run with the attempt recorded.
   */
  public function recordAttempt(RunState $state, string $gate): RunState {
    return $state->withFeedbackAttempt($gate);
  }

  /**
   * Runs a single gate, choosing where it belongs.
   *
   * @param string $name
   *   The gate name.
   * @param array<string, int|string|bool> $levers
   *   The gate's recorded levers.
   * @param string $projectRoot
   *   The repository to run in.
   * @param string $preset
   *   The run's frozen preset name, for saying what turned an off gate off.
   * @param \Droost\Workflow\Baseline\BaselineContext|null $context
   *   The baseline the run is held to, or NULL when there is none.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   What happened.
   */
  private function runOne(
    string $name,
    array $levers,
    string $projectRoot,
    string $preset,
    ?BaselineContext $context,
  ): GateResult {
    $on = $levers['on'] ?? FALSE;
    if ($on !== TRUE) {
      return GateResult::off($name, $this->offReason($preset, $name));
    }

    $gate = $this->settings($name, $levers);
    $consults = $context !== NULL && in_array($name, Baseline::CONSULTING, TRUE);

    if (in_array($name, self::SITE_GATES, TRUE)) {
      if (!$this->driver->available()) {
        return GateResult::skippedNoSite($name);
      }
      if (!in_array($name, $this->driver->supports(), TRUE)) {
        // A site exists but this driver cannot run the gate. That is a
        // misconfiguration, not an environmental skip — reporting it as
        // "no site" would blame the wrong thing and hide a real gap.
        return GateResult::toolMissing(
          $name,
          sprintf('%s (no site driver implements it)', $name),
          GateRemedy::wrongDriver($name),
        );
      }
      if ($consults && $this->driver instanceof BaselineAwareSiteDriverInterface) {
        return $this->driver->runWithBaseline($gate, $projectRoot, $context);
      }
      return $this->driver->run($gate, $projectRoot);
    }

    if ($consults && $this->executor instanceof BaselineAwareExecutorInterface) {
      return $this->executor->executeWithBaseline($gate, $projectRoot, $context);
    }
    return $this->executor->execute($gate, $projectRoot);
  }

  /**
   * The baseline context for this run, or why none can be built.
   *
   * The run froze a hash at begin (NULL when it is held to no baseline). The
   * disk must still say the same: a baseline added, removed or edited under a
   * run is tampering, and every consulting gate is told so instead of run.
   *
   * @param \Droost\Workflow\State\RunState $state
   *   The run.
   * @param string $projectRoot
   *   The repository.
   *
   * @return array{\Droost\Workflow\Baseline\BaselineContext|null, string|null}
   *   The context (NULL for no baseline), and the tamper reason (NULL when
   *   the disk matches the frozen record).
   */
  private function baselineFor(RunState $state, string $projectRoot): array {
    $now = BaselineStore::hash($projectRoot);
    if ($now !== $state->baselineHash) {
      return [
        NULL,
        sprintf(
          'the baseline changed during the run (frozen %s, now %s). A baseline '
          . 'is written at adoption by the operator and may not move under a '
          . 'run — restore droost/baseline/ to what it was, or reset the run.',
          BaselineStore::short($state->baselineHash),
          BaselineStore::short($now),
        ),
      ];
    }
    if ($state->baselineHash === NULL) {
      return [NULL, NULL];
    }
    try {
      $baseline = BaselineStore::load($projectRoot);
    }
    catch (BaselineError $e) {
      return [NULL, 'the baseline cannot be read: ' . $e->getMessage()];
    }
    if ($baseline === NULL) {
      return [NULL, NULL];
    }
    $changed = $this->vcs?->changedFiles($projectRoot, $state->baseCommit) ?? [];
    return [new BaselineContext($baseline, $changed), NULL];
  }

  /**
   * Turns a blocking result into REPORTED when the gate is in report mode.
   *
   * The mode comes from the run's FROZEN levers, like `on`: whether a gate may
   * block is not something a mid-run lever edit gets to change. Only the two
   * blocking statuses are affected — a pass stays a pass, an off gate stays
   * off, a skip stays a skip — and the original summary is kept so the report
   * still says exactly what the tool found; the prefix says why the phase
   * moved on regardless.
   *
   * @param array<string, int|string|bool> $levers
   *   The gate's frozen levers.
   * @param \Droost\Workflow\Gate\GateResult $result
   *   What the gate reported.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The result, demoted to Reported when the mode says so.
   */
  private function inReportMode(array $levers, GateResult $result): GateResult {
    if (($levers['mode'] ?? GateSettings::DEFAULT_MODE) !== 'report'
      || !$result->status->blocksAdvance()) {
      return $result;
    }
    // demotedFrom is kept, so the record can still say the tool never ran
    // (F-KCH3: snyk absent from the PATH read as "recorded, unproven" for
    // three phases), and so is everything else, the tool's output included
    // (F-64).
    return $result->demotedTo(
      GateStatus::Reported,
      sprintf('report — %s (mode: report; would block in mode: block)', $result->summary),
    );
  }

  /**
   * Why a gate that did not run is off — the level, or the file.
   *
   * An `off` row that says only "off" leaves the reader to guess between two
   * opposite situations: the repo chose a level that drops the gate (`low`
   * has no phpunit — the dial doing its job, in a line a reviewer saw), or a
   * lever-file override switched off something the level turns on — a
   * loosening. The frozen preset name and the built-in base are enough to
   * tell them apart, so nothing new is recorded to say it.
   *
   * @param string $preset
   *   The run's frozen preset name.
   * @param string $name
   *   The gate.
   *
   * @return string
   *   The reason, phrased to follow the word "off".
   */
  private function offReason(string $preset, string $name): string {
    if (GateSettings::isCustom($name)) {
      // A repo's own gate has no built-in base to be compared against.
      return 'by the lever file';
    }
    if (GateSettings::isContributed($name)) {
      // A module declares its gate ON; only gates.contributed turns it off.
      return 'by the lever file (gates.contributed — the declaring module turns it on)';
    }
    if ($preset === 'custom') {
      return 'by the lever file (custom levers)';
    }
    $base = PresetResolver::resolve($preset)->gates[$name] ?? NULL;
    if ($base === NULL) {
      return 'by the lever file';
    }
    return $base->on
      ? sprintf('by the lever file (preset %s turns it on)', $preset)
      : sprintf('by preset %s', $preset);
  }

  /**
   * The lever file's CURRENT tuning options per gate, or [] when unreadable.
   *
   * Only when a lever file exists at the root: a repo with no file resolved
   * its frozen levers from the built-in preset and has nothing to re-read. A
   * file that no longer parses leaves the frozen record in force — the run
   * must not stall on a half-edited YAML — and the gate summary says nothing,
   * because there is nothing coherent to compare against.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, array<string, int|string|bool>>
   *   Gate name to its currently resolved levers.
   */
  private function liveTuning(string $projectRoot): array {
    if (!is_file(rtrim($projectRoot, '/') . '/' . WorkflowConfig::FILENAME)) {
      return [];
    }
    try {
      return WorkflowConfig::load($projectRoot, $this->contributed)->resolvedGates();
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Overlays the file's current tuning onto the frozen levers of one gate.
   *
   * `on` is never taken from the file — that is the frozen half. Custom gates
   * are left alone too: their `cmd` is the gate, not a tuning of it.
   *
   * @param string $name
   *   The gate.
   * @param array<string, int|string|bool> $frozen
   *   The levers recorded at begin.
   * @param array<string, int|string|bool>|null $current
   *   The file's levers for the same gate, or NULL when absent.
   *
   * @return array{array<string, int|string|bool>, list<string>}
   *   The levers to run with, and a human line per option that changed.
   */
  private function withLiveTuning(string $name, array $frozen, ?array $current): array {
    if ($current === NULL || GateSettings::isCustom($name) || GateSettings::isContributed($name)) {
      // Custom and contributed gates: their `cmd` is the gate, not a tuning.
      return [$frozen, []];
    }
    $drift = [];
    $levers = $frozen;
    foreach (GateSettings::optionNames($name) as $option) {
      $was = $frozen[$option] ?? NULL;
      $now = $current[$option] ?? NULL;
      if ($now === $was || !(is_int($now) || is_string($now))) {
        continue;
      }
      $levers[$option] = $now;
      $drift[] = sprintf('%s %s → %s', $option, $was === NULL ? '(unset)' : (string) $was, (string) $now);
    }
    return [$levers, $drift];
  }

  /**
   * Rebuilds a GateSettings from the levers recorded in run state.
   *
   * @param string $name
   *   The gate name.
   * @param array<string, int|string|bool> $levers
   *   The recorded levers.
   *
   * @return \Droost\Workflow\Config\GateSettings
   *   The settings.
   */
  private function settings(
    string $name,
    array $levers,
  ): GateSettings {
    $options = [];
    foreach ($levers as $key => $value) {
      if ($key !== 'on' && (is_int($value) || is_string($value))) {
        $options[$key] = $value;
      }
    }
    return new GateSettings($name, TRUE, $options);
  }

}
