<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\Evidence\CheckAdjudicatorInterface;
use Droost\Workflow\Evidence\CheckRecord;
use Droost\Workflow\Evidence\CheckState;
use Droost\Workflow\Evidence\EvaluationReport;
use Droost\Workflow\Evidence\Fault;
use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\State\RunStateStore;

/**
 * Plan does not close until it has looked.
 *
 * The one thing about a plan the workflow forces (owner, 2026-10-15): that
 * the agent asked this site before it wrote down what to build. Both must
 * hold, and each is evidence the agent cannot type:
 *
 *   - the spec's `## Grounding` cites what resolves here (CitationResolver:
 *     the code index and the files), one citation for each tier the table
 *     claims, and at least one that names something that EXISTS: a `none:`
 *     claim alone resolves on any site, so it cannot be the whole of a
 *     plan's looking (S4-2);
 *   - the tool-call ledger shows this run called `code:wiki` and `code:tree`
 *     since it began (owner, 2026-10-19), counted from
 *     EvaluationReport::KNOWLEDGE_TOOLS so the record and this check count
 *     the same calls; `brief` alone is not looking anything up.
 *
 * It says nothing about how to build, and nothing it reads can.
 *
 * It lives in the library and needs no Drupal: the ledger and the index are
 * files, and `droost` answers the code questions with no site. (It once
 * lived in drupal/droost, where a broken site let the standalone binary
 * close plan with the check recorded as skipped: F-276.)
 *
 * Where it applies: every run with a spec. droost off Drupal (2026-10-19)
 * ships the knowledge commands in this package, so wherever this check runs,
 * `droost code:wiki` and `droost code:tree` exist to be asked: there is no
 * project with the workflow and nothing to look with, and the old Skipped
 * verdict for "no droost here" let a plan close unconsulted wherever droost
 * was installed somewhere other than the two paths it knew (F-303). A
 * project whose droost sits one directory down (a site in `site/`) keeps its
 * ledger there, where this run cannot attribute it: that is said, as the
 * environment's to settle, rather than read as an agent that never looked
 * (S4-11). With no spec it records nothing: the engine already holds plan for
 * that.
 */
final class PlanGroundedCheck implements CheckAdjudicatorInterface {

  /**
   * The check's name in the record.
   */
  public const NAME = 'plan_grounded';

  /**
   * Who adjudicated it, in the record.
   */
  public const PROVIDER = 'droost/workflow';

  /**
   * The knowledge commands plan must consult, every run (owner, 2026-10-19).
   *
   * The wiki (what the site's code and its contrib are) and the tree (how
   * the code is laid out): the two looks every plan rests on, forced here
   * and taught by the skills that ship with them.
   */
  public const REQUIRED = ['code:wiki', 'code:tree'];

  /**
   * The calls that read the site's summary rather than look.
   */
  private const SUMMARY = ['brief'];

  /**
   * Constructs a PlanGroundedCheck.
   *
   * @param \Droost\Workflow\Knowledge\CitationResolver|null $resolver
   *   How citations resolve; NULL resolves from files and the code index.
   */
  public function __construct(private readonly ?CitationResolver $resolver = NULL) {}

  /**
   * {@inheritdoc}
   */
  public function adjudicate(string $projectRoot, string $phase, string $runId): array {
    if ($phase !== 'plan') {
      return [];
    }
    $root = rtrim($projectRoot, '/');
    $spec = self::spec($root);
    if ($spec === NULL) {
      return [];
    }
    // NULL (none of the paths droostAt() knows) is droost at the root: this
    // check runs inside the package that answers code:wiki and code:tree.
    $where = self::droostAt($root) ?? '';
    if ($where !== '') {
      return [
        $this->record(
          CheckState::Blocked,
          Fault::Environment,
          sprintf('droost is installed in `%s/`, not at the repository root where this run is, so the calls it records (in `%s/%s/`) cannot be told apart from another run\'s. Run the workflow from `%s/`, or install droost at the root; the OPERATOR may lift this block for a run that looked by other means', $where, $where, RunStateStore::STATE_DIR, $where),
        ),
      ];
    }

    $asked = self::knowledgeCalls($root, $runId);
    $looked = array_values(array_filter($asked, static fn (array $call): bool => !in_array($call['tool'], self::SUMMARY, TRUE)));
    $missing = array_values(array_diff(self::REQUIRED, array_column($asked, 'tool')));
    $citations = ($this->resolver ?? new CitationResolver())->citations($root, $spec);

    // Every reason at once (F-128): a run told one, fixing it and only then
    // meeting the next, spends an attempt per reason.
    $why = [];
    if ($asked !== [] && $looked === []) {
      $why[] = 'the run read `droost brief` and asked droost nothing else. The brief is the site\'s summary; looking is asking about what this plan touches';
    }
    if ($missing !== []) {
      $why[] = sprintf(
        'plan has not consulted %s this run. Every plan consults the wiki (`droost code:wiki`, the site\'s code and its contrib modules and themes) and the tree (`droost code:tree [module]`, how the code is laid out) before it is written; `droost search:arch <query>` finds the documentation in between. Each call is recorded by droost itself, and that record is what this check reads%s',
        implode(' and ', array_map(static fn (string $command): string => '`droost ' . $command . '`', $missing)),
        self::failedAttempts($root, $runId, $missing),
      );
    }
    if (!$citations['table']) {
      $why[] = sprintf(
        '%s has no `%s` table. Add one row per lookup (Phase | Tier | Asked | Found | Evidence), and in Evidence cite what came back: a class, a docroot-relative file, a wiki page, or `none: <query>` for a lookup that found nothing',
        basename($spec),
        SpecContract::GROUNDING_HEADING,
      );
    }
    elseif ($citations['resolved'] === []) {
      $why[] = sprintf(
        'nothing %s\'s Grounding cites resolves here%s. Cite what droost showed you: a class, a docroot-relative file (`modules/custom/x/x.info.yml`), a wiki page, or `none: <query>` for a lookup that genuinely found nothing%s',
        basename($spec),
        $citations['unresolved'] === [] ? ' (its Evidence column is empty)' : ' (tried: ' . implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $citations['unresolved'])) . ')',
        $citations['index'] === 'ready' ? '' : sprintf(' (droost\'s code index is %s, so only the files were asked)', $citations['index']),
      );
    }
    elseif ($citations['positive'] === []) {
      $why[] = sprintf(
        'every citation %s\'s Grounding resolves is a `none:` claim, and a claim that something is absent resolves on any site. Cite at least one thing droost showed you that exists: a class, a file, a wiki page%s',
        basename($spec),
        $citations['index'] === 'ready' ? '' : sprintf(' (droost\'s code index is %s, so a `none:` claim cannot be checked at all)', $citations['index']),
      );
    }
    elseif ($citations['uncited_tiers'] !== []) {
      $why[] = sprintf(
        'the Grounding table claims the %s tier(s) and cites nothing that resolves for them. Each tier you searched needs one citation this site confirms, or drop the tier you did not search',
        implode(', ', $citations['uncited_tiers']),
      );
    }

    if ($why !== []) {
      return [
        $this->record(
          CheckState::Blocked,
          Fault::Agent,
          'plan has not looked yet: ' . implode('; and ', $why) . '. Then run plan again. How you build is your choice; this asks only that you looked first.',
        ),
      ];
    }

    $tools = array_count_values(array_column($asked, 'tool'));
    ksort($tools);

    return [
      $this->record(
        CheckState::Satisfied,
        Fault::None,
        sprintf(
          'plan looked: %d knowledge call(s) this run (%s), and %d Grounding citation(s) resolve%s; the code index is %s',
          count($asked),
          implode(', ', array_map(static fn (string $tool, int $n): string => $tool . ' ×' . $n, array_keys($tools), $tools)),
          count($citations['resolved']),
          $citations['unresolved'] === [] ? '' : sprintf(' (%d more do not)', count($citations['unresolved'])),
          $citations['index'],
        ),
      ),
    ];
  }

  /**
   * A record of this check.
   *
   * @param \Droost\Workflow\Evidence\CheckState $state
   *   The verdict.
   * @param \Droost\Workflow\Evidence\Fault $fault
   *   Whose it is when blocked.
   * @param string $summary
   *   Why.
   *
   * @return \Droost\Workflow\Evidence\CheckRecord
   *   The record.
   */
  private function record(CheckState $state, Fault $fault, string $summary): CheckRecord {
    return new CheckRecord('check', self::NAME, $state, $fault, $summary, provider: self::PROVIDER);
  }

  /**
   * Where droost is installed, relative to the repository root.
   *
   * `vendor/bin/droost` or `tools/droost/vendor/bin/droost` (droost's own
   * vendor directory), or the code index. NULL is not "no droost" to the
   * check (F-303): it runs inside the package that ships the commands.
   *
   * @param string $root
   *   The repository root.
   *
   * @return string|null
   *   '' at the root (or a code index there), the directory one level down
   *   that holds it, or NULL when droost is not installed.
   */
  public static function droostAt(string $root): ?string {
    if (is_file(CodeIndex::path($root))
      || is_file($root . '/vendor/bin/droost')
      || is_file($root . '/tools/droost/vendor/bin/droost')) {
      return '';
    }
    foreach (glob($root . '/*/vendor/bin/droost') ?: [] as $found) {
      return basename(dirname($found, 3));
    }

    return NULL;
  }

  /**
   * This run's knowledge calls made during plan that answered.
   *
   * Only rows the run made itself (owner, 2026-10-20): a row made with no run
   * open cannot say what it was for, and a lookup made between two runs, for
   * another purpose, read as the next plan's consult (F-331). The plan looks
   * once its run is open; after that, looking is unlimited at any phase. A
   * call droost refused or failed is no answer, so it is not looking.
   *
   * @param string $root
   *   The repository root.
   * @param string $runId
   *   The run.
   *
   * @return list<array{tool: string, outcome: string, at: string, run: string|null, phase: string|null}>
   *   The calls.
   */
  private static function knowledgeCalls(string $root, string $runId): array {
    return array_values(array_filter(
      ToolCallLog::read($root, $runId),
      static fn (array $call): bool => in_array($call['tool'], EvaluationReport::KNOWLEDGE_TOOLS, TRUE)
        && $call['outcome'] === 'ok'
        && $call['run'] === $runId
        && $call['phase'] === 'plan',
    ));
  }

  /**
   * What the run tried of the missing commands, when it tried and failed.
   *
   * A call that failed is no answer, so it does not count; but "has not
   * consulted" alone would send an agent that did call back to call again
   * and fail again. Say that it called, how often, and the usual cause.
   *
   * @param string $root
   *   The repository root.
   * @param string $runId
   *   The run.
   * @param list<string> $missing
   *   The required commands with no answered call.
   *
   * @return string
   *   '' when none was tried, else a sentence starting with '; '.
   */
  private static function failedAttempts(string $root, string $runId, array $missing): string {
    $tried = [];
    $before = [];
    foreach (ToolCallLog::read($root) as $call) {
      if (!in_array($call['tool'], $missing, TRUE)) {
        continue;
      }
      if ($call['run'] === NULL) {
        $before[$call['tool']] = ($before[$call['tool']] ?? 0) + 1;
      }
      elseif ($call['run'] === $runId && $call['outcome'] !== 'ok' && $call['phase'] === 'plan') {
        $tried[$call['tool']] = ($tried[$call['tool']] ?? 0) + 1;
      }
    }
    $said = [];
    foreach ($before as $tool => $count) {
      $said[] = sprintf('`droost %s` was called %d× before this run opened, which is not this run\'s looking: call it again now', $tool, $count);
    }
    if ($tried === []) {
      return $said === [] ? '' : '; ' . implode('; ', $said);
    }
    foreach ($tried as $tool => $count) {
      $said[] = sprintf('`droost %s` was called %d× and failed each time%s', $tool, $count, $tool === 'code:tree' ? ' (it reads the code index: build it with `droost code:index`)' : '');
    }

    return '; ' . implode('; ', $said) . '. A call that fails is no answer: call it until it answers';
  }

  /**
   * The run's spec, project-relative, or NULL when there is none to read.
   *
   * @param string $root
   *   The repository root.
   *
   * @return string|null
   *   The spec.
   */
  private static function spec(string $root): ?string {
    try {
      $state = (new RunStateStore($root))->load();
      if ($state !== NULL && is_string($state->specPath) && $state->specPath !== '' && is_file($root . '/' . $state->specPath)) {
        return $state->specPath;
      }
    }
    catch (\Throwable) {
      // An unreadable run is not this check's to report.
    }
    $candidates = SpecContract::candidates($root);
    if (count($candidates) !== 1) {
      return NULL;
    }

    return ltrim(substr($candidates[0], strlen($root)), '/');
  }

}
