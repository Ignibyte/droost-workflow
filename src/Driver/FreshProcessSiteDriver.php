<?php

declare(strict_types=1);

namespace Droost\Workflow\Driver;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\SiteDriverInterface;

/**
 * Renders the site's routes in a FRESH process, not the one running the gates.
 *
 * R-D70-F4 (round 30, T26 at max): the in-process sub-request threw
 * "Call to a member function access() on null" three times inside the gate
 * run — a run driven through the MCP server, a drush process alive for hours
 * and hundreds of tool calls — while the same route rendered 200 in every
 * fresh process anyone tried, including a driver's re-run of the identical
 * gate sequence afterwards. The failure belonged to the long-lived process,
 * not to the route. So the render leaves the process: this driver shells out
 * to the site's drush, which boots clean, renders through the in-process
 * driver there, and answers in the gate result's own JSON shape. wiki_fresh
 * has always gone through drush for the same reason.
 *
 * Framework-free: the runner is injected (the same callable the shell gates
 * use), so a test hands it a fake and never spawns anything.
 */
final class FreshProcessSiteDriver implements SiteDriverInterface {

  /**
   * The drush command that renders in the fresh process.
   */
  public const PROBE_COMMAND = 'droost:workflow:render-probe';

  /**
   * How long the fresh process may take before it is killed and fails.
   */
  public const DEFAULT_TIMEOUT = 120;

  /**
   * Constructs a FreshProcessSiteDriver.
   *
   * @param callable(list<string>, string, int): array{int, string, string} $runner
   *   Runs a command: argv, cwd, timeout → exit code, stdout, stderr.
   * @param callable(): int $clock
   *   Returns milliseconds.
   * @param string $drush
   *   The drush binary, relative to the project root.
   * @param int $timeout
   *   Seconds the probe may take.
   */
  public function __construct(
    private readonly mixed $runner,
    private readonly mixed $clock,
    private readonly string $drush = 'vendor/bin/drush',
    private readonly int $timeout = self::DEFAULT_TIMEOUT,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function available(): bool {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function supports(): array {
    return ['rendered_check'];
  }

  /**
   * {@inheritdoc}
   */
  public function run(GateSettings $gate, string $projectRoot): GateResult {
    if ($gate->name !== 'rendered_check') {
      return GateResult::toolMissing(
        $gate->name,
        sprintf('%s (this driver only runs rendered_check)', $gate->name),
      );
    }
    $root = rtrim($projectRoot, '/');
    $binary = $root . '/' . $this->drush;
    $routes = self::routes($gate);
    // One comma-separated argument — the gate option's own shape — rather
    // than one argument per route: Drush maps a variadic PHP array
    // inconsistently, and a single string is unambiguous on both sides.
    $argv = [$binary, self::PROBE_COMMAND, implode(',', $routes)];
    $invocation = implode(' ', $argv);
    if (!is_file($binary)) {
      return GateResult::toolMissing('rendered_check', $invocation);
    }

    $started = $this->tick();
    [$exit, $stdout, $stderr] = ($this->runner)($argv, $root, $this->timeout);
    $elapsed = $this->tick() - $started;

    if ($exit === 127) {
      return GateResult::toolMissing('rendered_check', $invocation);
    }
    $answer = self::decode($stdout);
    if ($answer === NULL) {
      // The probe did not answer in the contract's shape: a boot failure, a
      // fatal, a timeout. That is a failed gate with the process's own words
      // as the finding — never a pass, never a silent skip.
      $tail = trim(substr(trim($stderr !== '' ? $stderr : $stdout), -400));
      return GateResult::ran(
        'rendered_check',
        GateStatus::Failed,
        $exit,
        $elapsed,
        sprintf('the render probe did not answer (exit %d)', $exit),
        [['route' => implode(', ', $routes), 'status' => NULL, 'problem' => $tail === '' ? 'no output' : $tail]],
        $invocation,
      );
    }
    return GateResult::ran(
      'rendered_check',
      $answer['status'],
      $exit,
      $elapsed,
      $answer['summary'],
      $answer['findings'],
      $invocation,
    );
  }

  /**
   * The probe's answer, when stdout carries a gate result.
   *
   * The wire shape is GateResult::toArray() as the probe command prints it;
   * anything else — including a status word this package does not know — is
   * "no answer".
   *
   * @param string $stdout
   *   The probe's standard output.
   *
   * @return array{status: \Droost\Workflow\Gate\GateStatus, summary: string, findings: list<array<string, mixed>>}|null
   *   The decoded verdict, or NULL when stdout is not one.
   */
  private static function decode(string $stdout): ?array {
    // Drush may print notices before the JSON; the answer is the last line
    // that decodes to an object with a status.
    foreach (array_reverse(explode("\n", trim($stdout))) as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] !== '{') {
        continue;
      }
      $decoded = json_decode($line, TRUE);
      if (!is_array($decoded) || !is_string($decoded['status'] ?? NULL)) {
        continue;
      }
      $status = GateStatus::tryFrom($decoded['status']);
      if ($status === NULL) {
        return NULL;
      }
      $findings = [];
      foreach (is_array($decoded['findings'] ?? NULL) ? $decoded['findings'] : [] as $finding) {
        if (!is_array($finding)) {
          continue;
        }
        $row = [];
        foreach ($finding as $key => $value) {
          $row[(string) $key] = $value;
        }
        $findings[] = $row;
      }
      $summary = $decoded['summary'] ?? NULL;
      return [
        'status' => $status,
        'summary' => is_string($summary) ? $summary : $status->label(),
        'findings' => $findings,
      ];
    }
    return NULL;
  }

  /**
   * The routes to render: the `routes` option, or the front page.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate.
   *
   * @return list<string>
   *   Internal paths.
   */
  private static function routes(GateSettings $gate): array {
    $option = $gate->option('routes');
    if (!is_string($option) || trim($option) === '') {
      return ['/'];
    }
    $routes = [];
    foreach (explode(',', $option) as $route) {
      $route = trim($route);
      if ($route !== '') {
        $routes[] = $route;
      }
    }
    return $routes === [] ? ['/'] : $routes;
  }

  /**
   * Milliseconds now, from the injected clock.
   *
   * @return int
   *   Milliseconds.
   */
  private function tick(): int {
    $value = ($this->clock)();
    return is_int($value) ? $value : 0;
  }

}
