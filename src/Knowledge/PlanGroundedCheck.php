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
 *   - the tool-call ledger shows this run called one of droost's knowledge
 *     tools during plan (EvaluationReport::KNOWLEDGE_TOOLS, so the record and
 *     this check count the same calls), and one other than `droost_brief`:
 *     reading the site's summary is not looking anything up.
 *
 * It says nothing about how to build, and nothing it reads can.
 *
 * It lives in the library, not in droost, so both doors ask it: drush and
 * the standalone binary. Held only where Drupal booted, a broken site let
 * `vendor/bin/droost-workflow advance` close plan with the check recorded as
 * skipped (F-276). Nothing here needs Drupal: the ledger and the index are
 * files, and `vendor/bin/droost` answers the code questions with no site.
 *
 * Where it applies: a run with a spec, on a project where droost is
 * installed (`vendor/bin/droost`, which drupal/droost ships) or has written
 * its code index. drush alone is not droost: a Drupal site that uses this
 * library without drupal/droost has nothing to look with, and a check it
 * could never satisfy would wedge every run (S4-3). There it is recorded as
 * Skipped, with that reason, which no reader can mistake for a pass. A
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
    $where = self::droostAt($root);
    if ($where === NULL) {
      return [
        $this->record(
          CheckState::Skipped,
          Fault::None,
          'not asked: this project has no droost to look with (no vendor/bin/droost, no code index), so a plan cannot be held to having looked. Install drupal/droost to have it asked',
        ),
      ];
    }
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
    $looked = array_values(array_filter($asked, static fn (array $call): bool => $call['tool'] !== 'droost_brief'));
    $citations = ($this->resolver ?? new CitationResolver())->citations($root, $spec);

    // Every reason at once (F-128): a run told one, fixing it and only then
    // meeting the next, spends an attempt per reason.
    $why = [];
    if ($asked !== [] && $looked === []) {
      $why[] = 'the run read `drush droost:brief` and asked droost nothing else. The brief is the site\'s summary; looking is asking about what this plan touches: `vendor/bin/droost droost_search`, `droost_symbol`, `droost_graph`, `droost_wiki` or `droost_module_docs` for the code, `drush droost:tool droost_entities` or `droost_routes` for the live site';
    }
    if ($asked === []) {
      $why[] = sprintf(
        'the run has not asked droost anything during plan. Read `drush droost:brief` for the site\'s facts, then ask droost about what this plan touches: the code with `vendor/bin/droost <tool> \'<json>\'` (on DDEV, `ddev exec vendor/bin/droost`), which needs no running site, and the live site with `drush droost:tool <tool> \'<json>\'`. Its knowledge tools are %s; `vendor/bin/droost` and `drush droost:tool` each list theirs. Each call is recorded, and that record is what this check reads',
        implode(', ', array_map(static fn (string $tool): string => '`' . $tool . '`', EvaluationReport::KNOWLEDGE_TOOLS)),
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
   * `vendor/bin/droost` is what drupal/droost ships; drush is every Drupal
   * site's, droost or not, and is no evidence of droost (S4-3).
   *
   * @param string $root
   *   The repository root.
   *
   * @return string|null
   *   '' at the root (or a code index there), the directory one level down
   *   that holds it, or NULL when droost is not installed.
   */
  public static function droostAt(string $root): ?string {
    if (is_file(CodeIndex::path($root)) || is_file($root . '/vendor/bin/droost') || is_dir($root . '/vendor/drupal/droost')) {
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
   * A row with no phase was made before the run opened, which is when a plan
   * grounds. A call droost refused or failed is no answer, so it is not
   * looking.
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
        && ($call['phase'] === NULL || $call['phase'] === 'plan'),
    ));
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
