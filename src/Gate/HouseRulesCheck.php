<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * The site's own rules that a machine can check on the diff.
 *
 * A house rule (`rules.house`) judged by a gate names a check this class
 * knows. The run's added files are read against each; a break fails the gate
 * when its rule's mode is `block`, and is reported beside a passing phase
 * when it is `report` (the default). Rules judged by the seeker are not read
 * here: the seeker holds them.
 */
final class HouseRulesCheck {

  /**
   * The gate's name.
   */
  public const GATE = 'house_rules';

  /**
   * Runs the gate-judged house rules over what the run added.
   *
   * @param list<array<mixed>> $house
   *   The run's frozen house rules.
   * @param list<string>|null $added
   *   The files the run added, untracked ones included; NULL when version
   *   control cannot tell an added file from a changed one.
   * @param list<string>|null $changed
   *   Every file the run changed, read in its place when $added is NULL;
   *   NULL when the run's diff cannot be read at all.
   * @param string $projectRoot
   *   The repository.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  public static function run(array $house, ?array $added, ?array $changed, string $projectRoot): GateResult {
    $rules = array_values(array_filter($house, static fn (array $rule): bool => ($rule['judge'] ?? NULL) === 'gate'));
    if ($rules === []) {
      return new GateResult(self::GATE, GateStatus::Off, summary: 'house_rules off — no house rule is judged by a gate (rules.house, judge: gate)');
    }
    $files = $added ?? $changed;
    if ($files === NULL) {
      return GateResult::toolFailed(
        self::GATE,
        1,
        'the run\'s diff could not be read, so what it added is unknown',
        'The house rules read the files a run adds since its base commit; a run with no base commit, or no repository, gives them nothing to read.',
        'git diff --diff-filter=A',
      );
    }
    $findings = [];
    $blocking = FALSE;
    foreach ($rules as $rule) {
      $id = self::text($rule['id'] ?? NULL, '?');
      $mode = self::text($rule['mode'] ?? NULL, 'report');
      if ($mode === 'off') {
        continue;
      }
      foreach (self::breaks(self::text($rule['check'] ?? NULL, ''), $files, $projectRoot) as $file => $why) {
        $findings[] = [
          'file' => $file,
          'line' => 0,
          'rule' => $id,
          'message' => sprintf('%s: %s', $id, $why),
          'detail' => $mode === 'block' ? 'error' : 'warning',
        ];
        $blocking = $blocking || $mode === 'block';
      }
    }
    $ids = implode(', ', array_map(static fn (array $r): string => self::text($r['id'] ?? NULL, '?'), $rules));
    $scope = $added === NULL ? ' (added and changed could not be told apart, so every changed file was read)' : '';
    if ($findings === []) {
      return GateResult::ran(self::GATE, GateStatus::Passed, 0, 0, sprintf('house_rules passed — %s held over %d added file(s)%s', $ids, count($files), $scope), [], 'house rules: ' . $ids);
    }
    return GateResult::ran(
      self::GATE,
      $blocking ? GateStatus::Failed : GateStatus::Reported,
      1,
      0,
      sprintf(
        '%s — %d break(s) of the house rules (%s)%s: %s',
        $blocking ? 'house_rules FAILED' : 'report — house_rules',
        count($findings),
        $ids,
        $scope,
        implode('; ', array_map(static fn (array $f): string => $f['file'] . ' (' . $f['rule'] . ')', array_slice($findings, 0, 5))),
      ),
      $findings,
      'house rules: ' . $ids,
    );
  }

  /**
   * A scalar as text, or the default.
   *
   * @param mixed $value
   *   The value.
   * @param string $default
   *   What to say when it is not a scalar.
   *
   * @return string
   *   The text.
   */
  private static function text(mixed $value, string $default): string {
    return is_scalar($value) ? (string) $value : $default;
  }

  /**
   * The files that break one check, each with why.
   *
   * @param string $check
   *   One of BuildRules::HOUSE_CHECKS.
   * @param list<string> $files
   *   The files to read.
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, string>
   *   Path to the reason.
   */
  private static function breaks(string $check, array $files, string $projectRoot): array {
    return match ($check) {
      'sdc_markup' => self::markupOutsideComponents($files, $projectRoot),
      default => [],
    };
  }

  /**
   * New markup outside a single-directory component, and code components.
   *
   * A Twig file in custom code that sits in no directory holding a
   * `*.component.yml` is markup outside a component; a Canvas code component
   * (`canvas.js_component.*` config) is a component written outside SDC.
   *
   * @param list<string> $files
   *   The files to read.
   * @param string $projectRoot
   *   The repository.
   *
   * @return array<string, string>
   *   Path to the reason.
   */
  private static function markupOutsideComponents(array $files, string $projectRoot): array {
    $root = rtrim($projectRoot, '/');
    $out = [];
    foreach ($files as $file) {
      $path = ltrim(str_replace('\\', '/', $file), '/');
      if (preg_match('#(^|/)canvas\.js_component\.[^/]+\.ya?ml$#', $path) === 1) {
        $out[$path] = 'a Canvas code component; components are single-directory components here';
        continue;
      }
      if (!str_ends_with($path, '.twig') || preg_match('#(^|/)(modules|themes|profiles)/custom/#', $path) !== 1) {
        continue;
      }
      if (glob($root . '/' . dirname($path) . '/*.component.yml') !== []) {
        continue;
      }
      $out[$path] = 'markup outside a single-directory component (no *.component.yml beside it)';
    }
    return $out;
  }

}
