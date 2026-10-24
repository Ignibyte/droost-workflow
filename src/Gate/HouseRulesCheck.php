<?php

declare(strict_types=1);

namespace Droost\Workflow\Gate;

use Droost\Workflow\Knowledge\SiteCalls;
use Droost\Workflow\Knowledge\ToolCallLog;

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
   * How many bytes of file names one invocation is handed.
   *
   * Far under any system's argument limit (macOS allows 1 MB with the
   * environment), so a long list never fails to start.
   */
  private const ARG_BYTES = 65536;

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
      // Through off(), so the reason rides as the skip reason too: built by
      // hand it had none, and the workchain report printed a bare `off`
      // (F-330).
      return GateResult::off(self::GATE, 'no house rule is judged by a gate (rules.house, judge: gate)');
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
    $total = 0;
    $live = 0;
    $exitCode = 0;
    $elapsed = 0;
    foreach ($rules as $rule) {
      $id = self::text($rule['id'] ?? NULL, '?');
      $mode = self::text($rule['mode'] ?? NULL, 'report');
      if ($mode === 'off') {
        continue;
      }
      $live++;
      // THE RUN'S RAW SITE CALLS (F-366): not files but what the guard saw
      // the run do. Each raw drush php or SQL call is a break.
      if (($rule['check'] ?? NULL) === 'site_tools') {
        $runId = ToolCallLog::openRun($projectRoot)['id'];
        [$count, $calls] = $runId === NULL ? [0, []] : SiteCalls::raw($projectRoot, $runId);
        $total += $count;
        $read[] = sprintf('%s read %d guard record(s) of this run', $id, $count);
        foreach ($calls as $n => $verb) {
          $findings[] = [
            'file' => '',
            'line' => 0,
            'rule' => $id,
            'message' => sprintf('%s: the run called drush %s (its call %d). Site state is read and changed through droost\'s tools (drush droost:tool <tool>), which the run records and the guard holds; raw PHP and SQL are neither. drush droost:tool lists them', $id, $verb, $n),
            'detail' => $mode === 'block' ? 'error' : 'warning',
          ];
          $blocking = $blocking || $mode === 'block';
        }
        continue;
      }
      if (isset($rule['cmd'])) {
        $outcome = self::command($rule, $changed ?? $files, $projectRoot, $runner);
        if ($outcome instanceof GateResult) {
          return $outcome;
        }
        [$count, $why, $exit, $ms] = $outcome;
        $total += $count;
        $elapsed += $ms;
        $exitCode = $exit !== 0 ? $exit : $exitCode;
        $read[] = sprintf('%s read %d changed file(s)', $id, $count);
        // THE RULE'S OWN SCRIPT, CHANGED BY THE RUN IT JUDGES (S5-6). A
        // check whose script the agent rewrote says whatever the rewrite
        // says; its verdict is the agent's, and the record says so.
        foreach (self::steeringScripts(self::text($rule['cmd'], ''), $changed ?? $files, $projectRoot) as $script) {
          $findings[] = [
            'file' => $script,
            'line' => 0,
            'rule' => $id,
            'message' => sprintf('%s: its command\'s own script %s was changed by this run, so what it reports is not a measurement; the OPERATOR reviews the change', $id, $script),
            'detail' => $mode === 'block' ? 'error' : 'warning',
          ];
          $blocking = $blocking || $mode === 'block';
        }
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
      $total += count($files);
      $read[] = sprintf('%s read %d added file(s)', $id, count($files));
      // Timed, and never under a millisecond when it read a file (F-347): a
      // built-in check runs in-process in microseconds, and `duration_ms: 0`
      // is what the record reads as "the tool never spawned".
      $started = hrtime(TRUE);
      $breaks = self::breaks(self::text($rule['check'] ?? NULL, ''), $files, $projectRoot);
      if ($files !== []) {
        $elapsed += max(1, (int) ceil((hrtime(TRUE) - $started) / 1_000_000));
      }
      foreach ($breaks as $file => $why) {
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
    if ($live === 0) {
      return GateResult::off(self::GATE, sprintf('every gate-judged house rule (%s) is mode: off', $ids));
    }
    $scope = $added === NULL ? ' (added and changed could not be told apart, so every changed file was read)' : '';
    if ($findings === [] && $total === 0) {
      // A pass that read nothing is said as one (S4-1): no rule had a file to
      // judge, which is not the same claim as every rule holding.
      return GateResult::labelledPass(self::GATE, 0, $elapsed, sprintf('house_rules read nothing — no file this run added or changed is one %s reads (%s)%s', $ids, implode('; ', $read), $scope), 'house rules: ' . $ids);
    }
    if ($findings === []) {
      return GateResult::ran(self::GATE, GateStatus::Passed, 0, $elapsed, sprintf('house_rules passed — %s held (%s)%s', $ids, implode('; ', $read), $scope), [], 'house rules: ' . $ids);
    }
    return GateResult::ran(
      self::GATE,
      $blocking ? GateStatus::Failed : GateStatus::Reported,
      $exitCode !== 0 ? $exitCode : 1,
      $elapsed,
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
   * Each is given as `./path`, so a file named like an option (`--version`)
   * is a file and never a flag that turns the tool into a no-op pass (S4-4).
   * A long list is split so no invocation passes the system's argument
   * limit, and every part must exit zero.
   *
   * @param array<mixed> $rule
   *   The rule, with its `cmd`, maybe its `files` globs and its `timeout`.
   * @param list<string> $changed
   *   The files the run changed.
   * @param string $projectRoot
   *   The repository, where the command runs.
   * @param (callable(list<string>, string, int): array{int, string, string})|null $runner
   *   Runs the command.
   *
   * @return array{int, string|null, int, int}|\Droost\Workflow\Gate\GateResult
   *   How many files it read, why it broke (NULL when it held), the exit code
   *   and the milliseconds it took; or the gate's result when the command
   *   could not run, which is the environment's, never the agent's.
   */
  private static function command(array $rule, array $changed, string $projectRoot, ?callable $runner): array|GateResult {
    $id = self::text($rule['id'] ?? NULL, '?');
    $cmd = self::text($rule['cmd'] ?? NULL, '');
    $timeout = is_int($rule['timeout'] ?? NULL) && $rule['timeout'] > 0 ? $rule['timeout'] : self::CMD_TIMEOUT;
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
      return [0, NULL, 0, 0];
    }
    $invocation = sprintf('%s (%s, over %d changed file(s))', $cmd, $id, count($files));
    if ($runner === NULL) {
      return GateResult::toolFailed(self::GATE, 1, sprintf('%s names a command and nothing here runs commands', $id), 'Run the gate through the workflow, which runs house-rule commands.', $invocation);
    }
    $started = hrtime(TRUE);
    foreach (self::batches($files) as $batch) {
      $operands = array_map(static fn (string $f): string => './' . $f, $batch);
      $argv = ['/bin/sh', '-c', $cmd . ' "$@"', 'house-rule', ...$operands];
      [$exit, $stdout, $stderr] = $runner($argv, $root, $timeout);
      $ms = (int) ((hrtime(TRUE) - $started) / 1_000_000);
      if ($exit === 0) {
        continue;
      }
      if ($exit === 127) {
        return GateResult::toolMissing(self::GATE, $invocation, sprintf(
          'The command for house rule %s was not found. Install it, or ask the OPERATOR to change the rule in droost/droost.yml (rules.house); an agent cannot decide either.',
          $id,
        ), 127, $ms);
      }
      if ($exit === 124) {
        return GateResult::toolFailed(self::GATE, 124, sprintf('%s\'s command did not finish in %ds', $id, $timeout), 'Make the command faster, narrow the files it reads (files: in the rule), or ask the OPERATOR to raise the rule\'s timeout.', $invocation);
      }
      // 126 is a command that cannot execute, and above 128 a signal killed
      // it (out of memory, a kill): the machine's, not a verdict on the
      // code. A negative exit is a process that never started.
      if ($exit === 126 || $exit > 128 || $exit < 0) {
        return GateResult::toolFailed(self::GATE, $exit, sprintf('%s\'s command could not run to a verdict (exit %d)', $id, $exit), 'Make the command executable and runnable on this machine; a signal or an unexecutable script is the environment\'s to fix.', $invocation);
      }
      $output = trim($stdout . "\n" . $stderr);
      $lines = $output === '' ? [] : array_slice(preg_split('/\R/', $output) ?: [], 0, self::OUTPUT_LINES);
      return [count($files), sprintf(
        'its command exited %d over %d changed file(s)%s',
        $exit,
        count($files),
        $lines === [] ? '' : ":\n" . implode("\n", $lines),
        ), $exit, $ms,
      ];
    }

    return [count($files), NULL, 0, (int) ((hrtime(TRUE) - $started) / 1_000_000)];
  }

  /**
   * The files, in parts small enough for one command line each.
   *
   * @param list<string> $files
   *   The files.
   *
   * @return list<list<string>>
   *   The parts.
   */
  private static function batches(array $files): array {
    $parts = [];
    $current = [];
    $bytes = 0;
    foreach ($files as $file) {
      $size = strlen($file) + 3;
      if ($current !== [] && $bytes + $size > self::ARG_BYTES) {
        $parts[] = $current;
        $current = [];
        $bytes = 0;
      }
      $current[] = $file;
      $bytes += $size;
    }
    if ($current !== []) {
      $parts[] = $current;
    }

    return $parts;
  }

  /**
   * The files a rule's command runs that this run changed.
   *
   * Every word of the command that names a file in the repository is a
   * script the rule's verdict rests on (`scripts/check.sh`, `sh bin/lint`).
   *
   * @param string $cmd
   *   The rule's command.
   * @param list<string> $changed
   *   The files the run changed.
   * @param string $projectRoot
   *   The repository.
   *
   * @return list<string>
   *   The changed scripts, repository-relative.
   */
  private static function steeringScripts(string $cmd, array $changed, string $projectRoot): array {
    $changedSet = array_flip(array_map(static fn (string $f): string => ltrim(str_replace('\\', '/', $f), '/'), $changed));
    $found = [];
    foreach (preg_split('/[\s;&|()]+/', $cmd) ?: [] as $word) {
      $word = trim($word, '\'"');
      $word = (string) preg_replace('#^\./#', '', $word);
      if ($word === '' || str_starts_with($word, '-') || !isset($changedSet[$word])) {
        continue;
      }
      if (is_file(rtrim($projectRoot, '/') . '/' . $word)) {
        $found[$word] = TRUE;
      }
    }

    return array_keys($found);
  }

  /**
   * Whether a path matches one of a rule's globs, by its path or its name.
   *
   * `*` is any run of characters, `/` included, so `web/modules/custom/*`
   * reaches every file below it; `?` is one character; `{php,module}` is
   * either word.
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
    foreach (self::expandBraces($globs) as $glob) {
      $pattern = '#^' . strtr(preg_quote($glob, '#'), ['\\*' => '.*', '\\?' => '.']) . '$#';
      if (preg_match($pattern, $path) === 1 || preg_match($pattern, basename($path)) === 1) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Globs with each `{a,b}` written out as one glob per word.
   *
   * @param list<string> $globs
   *   The globs.
   *
   * @return list<string>
   *   The globs, with no braces left.
   */
  private static function expandBraces(array $globs): array {
    $out = [];
    foreach ($globs as $glob) {
      if (preg_match('/\{([^{}]*)\}/', $glob, $m, PREG_OFFSET_CAPTURE) !== 1) {
        $out[] = $glob;
        continue;
      }
      $before = substr($glob, 0, $m[0][1]);
      $after = substr($glob, $m[0][1] + strlen($m[0][0]));
      foreach (self::expandBraces(array_map(static fn (string $word): string => $before . $word . $after, explode(',', $m[1][0]))) as $one) {
        $out[] = $one;
      }
    }

    return $out;
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
