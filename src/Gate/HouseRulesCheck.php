<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

/**
 * The site's own rules that a machine can check on the diff.
 *
 * A house rule (`rules.house`) judged by a gate names a check this class
 * knows, read over the run's added files, or a command of the site's own,
 * handed the run's changed files (those still on disk, narrowed by the rule's
 * `files` globs) and holding the rule when it exits zero. A break fails the
 * gate when its rule's mode is `block`, and is reported beside a passing
 * phase when it is `report` (the default). A command that cannot run fails
 * the gate as the environment's: a rule nobody checked never reads as held.
 * Rules judged by the seeker are not read here: the seeker holds them.
 */
final class HouseRulesCheck {

  /**
   * The gate's name.
   */
  public const GATE = 'house_rules';

  /**
   * How long one rule's command may take, in seconds.
   */
  public const CMD_TIMEOUT = 600;

  /**
   * How many lines of a command's output a finding carries.
   */
  private const OUTPUT_LINES = 20;

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
   * @param (callable(list<string>, string, int): array{int, string, string})|null $runner
   *   Runs a rule's command (argv, cwd, timeout) and returns its exit code,
   *   output and error output; NULL when no rule names a command.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The verdict.
   */
  public static function run(array $house, ?array $added, ?array $changed, string $projectRoot, ?callable $runner = NULL): GateResult {
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
    $read = [];
    foreach ($rules as $rule) {
      $id = self::text($rule['id'] ?? NULL, '?');
      $mode = self::text($rule['mode'] ?? NULL, 'report');
      if ($mode === 'off') {
        continue;
      }
      if (isset($rule['cmd'])) {
        $outcome = self::command($rule, $changed ?? $files, $projectRoot, $runner);
        if ($outcome instanceof GateResult) {
          return $outcome;
        }
        [$count, $why] = $outcome;
        $read[] = sprintf('%s read %d changed file(s)', $id, $count);
        if ($why !== NULL) {
          $findings[] = [
            'file' => '',
            'line' => 0,
            'rule' => $id,
            'message' => sprintf('%s: %s', $id, $why),
            'detail' => $mode === 'block' ? 'error' : 'warning',
          ];
          $blocking = $blocking || $mode === 'block';
        }
        continue;
      }
      $read[] = sprintf('%s read %d added file(s)', $id, count($files));
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
      return GateResult::ran(self::GATE, GateStatus::Passed, 0, 0, sprintf('house_rules passed — %s held (%s)%s', $ids, implode('; ', $read), $scope), [], 'house rules: ' . $ids);
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
        implode('; ', array_map(static fn (array $f): string => ($f['file'] === '' ? 'its command' : $f['file']) . ' (' . $f['rule'] . ')', array_slice($findings, 0, 5))),
      ),
      $findings,
      'house rules: ' . $ids,
    );
  }

  /**
   * Runs one rule's command over the run's changed files.
   *
   * The files are handed as the command's arguments (`cmd "$@"`), so the
   * site's tool reads what the run changed and never the code it inherited.
   *
   * @param array<mixed> $rule
   *   The rule, with its `cmd` and maybe its `files` globs.
   * @param list<string> $changed
   *   The files the run changed.
   * @param string $projectRoot
   *   The repository, where the command runs.
   * @param (callable(list<string>, string, int): array{int, string, string})|null $runner
   *   Runs the command.
   *
   * @return array{int, string|null}|\Droost\Workflow\Gate\GateResult
   *   How many files it read and why it broke (NULL when it held), or the
   *   gate's result when the command could not run.
   */
  private static function command(array $rule, array $changed, string $projectRoot, ?callable $runner): array|GateResult {
    $id = self::text($rule['id'] ?? NULL, '?');
    $cmd = self::text($rule['cmd'] ?? NULL, '');
    $globs = is_array($rule['files'] ?? NULL) ? array_values(array_filter($rule['files'], 'is_string')) : [];
    $root = rtrim($projectRoot, '/');
    $files = [];
    foreach ($changed as $file) {
      $path = ltrim(str_replace('\\', '/', $file), '/');
      if (!is_file($root . '/' . $path)) {
        continue;
      }
      if ($globs !== [] && !self::matches($path, $globs)) {
        continue;
      }
      $files[] = $path;
    }
    if ($files === []) {
      return [0, NULL];
    }
    $invocation = sprintf('%s (%s, over %d changed file(s))', $cmd, $id, count($files));
    if ($runner === NULL) {
      return GateResult::toolFailed(self::GATE, 1, sprintf('%s names a command and nothing here runs commands', $id), 'Run the gate through the workflow, which runs house-rule commands.', $invocation);
    }
    [$exit, $stdout, $stderr] = $runner(['/bin/sh', '-c', $cmd . ' "$@"', 'house-rule', ...$files], $root, self::CMD_TIMEOUT);
    if ($exit === 0) {
      return [count($files), NULL];
    }
    if ($exit === 127) {
      return GateResult::toolMissing(self::GATE, $invocation, sprintf(
        'The command for house rule %s was not found. Install it, or ask the OPERATOR to change the rule in droost.workflow.yml (rules.house); an agent cannot decide either.',
        $id,
      ), 127);
    }
    if ($exit === 124) {
      return GateResult::toolFailed(self::GATE, 124, sprintf('%s\'s command did not finish in %ds', $id, self::CMD_TIMEOUT), 'Make the command faster, or narrow the files it reads (files: in the rule).', $invocation);
    }
    $output = trim($stdout . "\n" . $stderr);
    $lines = $output === '' ? [] : array_slice(preg_split('/\R/', $output) ?: [], 0, self::OUTPUT_LINES);
    return [count($files), sprintf(
      'its command exited %d over %d changed file(s)%s',
      $exit,
      count($files),
      $lines === [] ? '' : ":\n" . implode("\n", $lines),
      ),
    ];
  }

  /**
   * Whether a path matches one of a rule's globs, by its path or its name.
   *
   * `*` is any run of characters, `/` included, so `web/modules/custom/*`
   * reaches every file below it; `?` is one character.
   *
   * @param string $path
   *   The path, from the repository.
   * @param list<string> $globs
   *   The globs.
   *
   * @return bool
   *   TRUE when one matches.
   */
  private static function matches(string $path, array $globs): bool {
    foreach ($globs as $glob) {
      $pattern = '#^' . strtr(preg_quote($glob, '#'), ['\\*' => '.*', '\\?' => '.']) . '$#';
      if (preg_match($pattern, $path) === 1 || preg_match($pattern, basename($path)) === 1) {
        return TRUE;
      }
    }
    return FALSE;
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
