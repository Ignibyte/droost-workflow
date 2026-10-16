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
 *     the code index and the files), one citation at least and one for each
 *     tier the table claims;
 *   - the tool-call ledger shows this run called one of droost's knowledge
 *     tools during plan (EvaluationReport::KNOWLEDGE_TOOLS, so the record and
 *     this check count the same calls).
 *
 * It says nothing about how to build, and nothing it reads can.
 *
 * It lives in the library, not in droost, so both doors ask it: drush and
 * the standalone binary. Held only where Drupal booted, a broken site let
 * `vendor/bin/droost-workflow advance` close plan with the check recorded as
 * skipped (F-276). Nothing here needs Drupal: the ledger and the index are
 * files, and `vendor/bin/droost` answers the code questions with no site.
 *
 * Where it applies: a run with a spec, on a project where droost can be
 * asked at all, which is a `vendor/bin/droost` or `vendor/bin/drush` (at the
 * root or one directory down, a site in `site/`) or a code index already
 * written. A project with none of those has nothing to look with, and a
 * check it could never satisfy would wedge every run: there it is recorded
 * as Skipped, with that reason, which no reader can mistake for a pass. With
 * no spec it records nothing: the engine already holds plan for that.
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
    if (!self::reachable($root)) {
      return [
        $this->record(
          CheckState::Skipped,
          Fault::None,
          'not asked: this project has no droost to look with (no vendor/bin/droost, no vendor/bin/drush, no code index), so a plan cannot be held to having looked. Install drupal/droost to have it asked',
        ),
      ];
    }

    $asked = self::knowledgeCalls($root, $runId);
    $citations = ($this->resolver ?? new CitationResolver())->citations($root, $spec);

    // Every reason at once (F-128): a run told one, fixing it and only then
    // meeting the next, spends an attempt per reason.
    $why = [];
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
          'plan looked: %d knowledge call(s) this run (%s), and %d Grounding citation(s) resolve%s',
          count($asked),
          implode(', ', array_map(static fn (string $tool, int $n): string => $tool . ' ×' . $n, array_keys($tools), $tools)),
          count($citations['resolved']),
          $citations['unresolved'] === [] ? '' : sprintf(' (%d more do not, reported by grounding_check)', count($citations['unresolved'])),
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
   * Whether droost can be asked on this project at all.
   *
   * @param string $root
   *   The repository root.
   *
   * @return bool
   *   TRUE when a droost surface or its index is there.
   */
  private static function reachable(string $root): bool {
    if (is_file(CodeIndex::path($root))) {
      return TRUE;
    }
    $surfaces = glob($root . '/{,*/}vendor/bin/{droost,drush}', GLOB_BRACE) ?: [];

    return $surfaces !== [];
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
