<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\ShellGateExecutor;

/**
 * Measures a tree's debt and writes it down — once, and then only downward.
 *
 * The operator's half of D71. Measurement runs every consulting gate the
 * level turns on, through the same executor a real gate run uses, so what is
 * recorded is exactly what the gate will judge. Writing is the ratchet:
 * the first write records everything; a refresh drops findings that no
 * longer match and REFUSES to add any, unless the operator says --grow with a
 * reason, which the manifest then carries forever.
 *
 * Nothing here decides who may call it. That is the surfaces' job: the drush
 * verb demands an operator terminal and the pack guard refuses the agent's
 * shell, because a baseline the agent can write is a finding it can hide.
 */
final class BaselineWriter {

  /**
   * The temporary file phpstan generates its baseline into.
   *
   * Inside the baseline directory on purpose: phpstan writes `path:` entries
   * relative to the baseline file, and the wrapper that includes the final
   * file lives in the same directory, so the paths must be computed there.
   */
  private const PHPSTAN_SCRATCH = '.phpstan-measure.neon';

  /**
   * Constructs a BaselineWriter.
   *
   * @param \Droost\Workflow\Gate\ShellGateExecutor $executor
   *   The executor whose measure() runs each tool raw.
   * @param callable(): string $clock
   *   Returns an ISO-8601 timestamp for the manifest.
   */
  public function __construct(
    private readonly ShellGateExecutor $executor,
    private readonly mixed $clock,
  ) {}

  /**
   * Measures the current debt of every consulting gate the level turns on.
   *
   * @param \Droost\Workflow\Config\WorkflowConfig $config
   *   The resolved levers.
   * @param string $projectRoot
   *   The repository.
   * @param list<string>|null $configDrift
   *   The site's current config drift, measured by the live surface, or NULL
   *   when there is no site to ask.
   *
   * @return \Droost\Workflow\Baseline\Measurement
   *   What was found, and what could not be measured.
   */
  public function measure(WorkflowConfig $config, string $projectRoot, ?array $configDrift = NULL): Measurement {
    $root = rtrim($projectRoot, '/');
    $findings = [];
    $skipped = [];
    $prettier = NULL;
    $neon = NULL;
    $metrics = ['coverage' => NULL, 'msi' => NULL];

    foreach (Baseline::CONSULTING as $gate) {
      $settings = $config->gates[$gate] ?? NULL;
      if ($settings === NULL || !$settings->on) {
        $skipped[$gate] = sprintf('off at level %s — a baseline records only what the level judges', $config->preset);
        continue;
      }
      if ($gate === 'config_clean') {
        if ($configDrift === NULL) {
          $skipped[$gate] = 'no site — config drift is measured by the live surface (drush)';
        }
        continue;
      }
      if ($gate === 'phpstan') {
        [$neon, $why] = $this->generatePhpstanBaseline($settings, $root);
        if ($why !== NULL) {
          $skipped[$gate] = $why;
        }
        continue;
      }
      $run = $this->executor->measure($settings, $root);
      if ($run === NULL) {
        $skipped[$gate] = 'tool missing, no suite config, or nothing to analyse';
        continue;
      }
      switch ($gate) {
        case 'phpcs':
          $findings[$gate] = self::errorsOnly(FindingParsers::phpcs($run['stdout'], $root));
          break;

        case 'eslint':
          $findings[$gate] = self::errorsOnly(FindingParsers::eslint($run['stdout'], $root));
          break;

        case 'stylelint':
          $findings[$gate] = self::errorsOnly(FindingParsers::stylelint($run['stdout'], $root));
          break;

        case 'prettier':
          $prettier = FindingParsers::prettierUnformatted($run['stdout'], $run['stderr'], $root);
          break;

        case 'coverage':
          $percent = FindingParsers::coveragePercent($run['stdout']);
          if ($percent === NULL) {
            $skipped[$gate] = $run['exit'] === 0
              ? 'the suite ran but no coverage was measured (no xdebug/pcov driver)'
              : 'the suite failed — fix the suite before baselining coverage';
          }
          else {
            $metrics['coverage'] = $percent;
          }
          break;

        case 'mutation':
          $percent = FindingParsers::msiPercent($run['stdout']);
          if ($percent === NULL) {
            $skipped[$gate] = 'infection printed no MSI — fix the mutation run before baselining it';
          }
          else {
            $metrics['msi'] = $percent;
          }
          break;
      }
    }

    return new Measurement($findings, $prettier, $neon, $metrics, $configDrift, $skipped);
  }

  /**
   * Writes the first baseline, or refreshes one under the ratchet.
   *
   * @param string $projectRoot
   *   The repository.
   * @param \Droost\Workflow\Baseline\Measurement $measured
   *   The current debt.
   * @param string $preset
   *   The level in force.
   * @param string|null $commit
   *   The tree's commit, or NULL when unknown.
   * @param bool $refresh
   *   TRUE to refresh an existing baseline; FALSE to write the first.
   * @param bool $grow
   *   TRUE to allow the refresh to record MORE debt than before.
   * @param string|null $reason
   *   Why growth is accepted; required with $grow.
   *
   * @return \Droost\Workflow\Baseline\BaselineManifest
   *   The manifest written.
   *
   * @throws \Droost\Workflow\Baseline\BaselineError
   *   When a first write finds a baseline, a refresh finds none, a refresh
   *   would grow without --grow, or --grow comes without a reason.
   */
  public function write(
    string $projectRoot,
    Measurement $measured,
    string $preset,
    ?string $commit,
    bool $refresh = FALSE,
    bool $grow = FALSE,
    ?string $reason = NULL,
  ): BaselineManifest {
    $root = rtrim($projectRoot, '/');
    $previous = BaselineStore::load($root);
    if (!$refresh && $previous !== NULL) {
      throw BaselineError::alreadyExists($root);
    }
    if ($refresh && $previous === NULL) {
      throw BaselineError::nothingToRefresh($root);
    }
    if ($grow && ($reason === NULL || trim($reason) === '')) {
      throw BaselineError::growNeedsReason();
    }

    $files = [];
    $gates = [];
    $added = [];

    // The linters: set arithmetic on finding keys.
    foreach (['phpcs', 'eslint', 'stylelint'] as $gate) {
      if (!isset($measured->findings[$gate])) {
        $this->carryForward($gate, $previous, $files, $gates);
        continue;
      }
      $current = $measured->findings[$gate];
      $record = $current;
      if ($previous !== NULL) {
        $old = $previous->has($gate) ? $previous->findingKeys($gate) : [];
        $kept = array_values(array_filter($current, static fn (array $f): bool => isset($old[$f['key']])));
        $new = count($current) - count($kept);
        if ($new > 0) {
          $added[$gate] = $new;
        }
        $record = $grow ? $current : $kept;
      }
      $content = json_encode([
        'v' => 1,
        'gate' => $gate,
        'findings' => array_map(static fn (array $f): array => [
          'key' => $f['key'],
          'file' => $f['file'],
          'rule' => $f['rule'],
          'message' => $f['message'],
          'line' => $f['line'],
        ], $record),
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
      $files[Baseline::FILES[$gate]] = $content;
      $gates[$gate] = self::entry(Baseline::FILES[$gate], count($record), $content);
    }

    // prettier: the same arithmetic on file paths.
    if ($measured->prettier === NULL) {
      $this->carryForward('prettier', $previous, $files, $gates);
    }
    else {
      $current = $measured->prettier;
      $record = $current;
      if ($previous !== NULL) {
        $old = $previous->has('prettier') ? $previous->prettierFiles() : [];
        $kept = array_values(array_intersect($current, $old));
        $new = count($current) - count($kept);
        if ($new > 0) {
          $added['prettier'] = $new;
        }
        $record = $grow ? $current : $kept;
      }
      $content = "# Files unformatted when the baseline was written; inherited until the run touches them.\n"
        . implode("\n", $record) . ($record === [] ? '' : "\n");
      $files[Baseline::FILES['prettier']] = $content;
      $gates['prettier'] = self::entry(Baseline::FILES['prettier'], count($record), $content);
    }

    // phpstan: its own generated baseline; the ratchet compares totals.
    if ($measured->phpstanBaseline === NULL) {
      $this->carryForward('phpstan', $previous, $files, $gates);
      if ($previous !== NULL && $previous->has('phpstan') && is_file($previous->dir . '/' . Baseline::PHPSTAN_WRAPPER)) {
        $files[Baseline::PHPSTAN_WRAPPER] = (string) file_get_contents($previous->dir . '/' . Baseline::PHPSTAN_WRAPPER);
      }
    }
    else {
      $count = $measured->phpstanCount();
      $oldCount = $previous?->phpstanInheritedCount() ?? 0;
      if ($previous !== NULL && $count > $oldCount) {
        $added['phpstan'] = $count - $oldCount;
      }
      if ($previous === NULL || $grow || $count <= $oldCount) {
        $files[Baseline::FILES['phpstan']] = $measured->phpstanBaseline;
        $gates['phpstan'] = self::entry(Baseline::FILES['phpstan'], $count, $measured->phpstanBaseline);
      }
      else {
        // Refused below; keep the previous file so a refusal changes nothing.
        $this->carryForward('phpstan', $previous, $files, $gates);
      }
      $files[Baseline::PHPSTAN_WRAPPER] = $this->phpstanWrapper($root);
    }

    // The metrics: a floor that only rises without --grow.
    $metrics = [];
    $previousMetrics = ['coverage' => $previous?->metric('coverage'), 'msi' => $previous?->metric('msi')];
    foreach (['coverage' => 'coverage', 'msi' => 'mutation'] as $metric => $gate) {
      $current = $measured->metrics[$metric];
      $old = $previousMetrics[$metric];
      if ($current === NULL) {
        if ($old !== NULL) {
          $metrics[$metric] = $old;
        }
        continue;
      }
      if ($old !== NULL && $current < $old) {
        $added[$gate] = (int) ceil($old - $current);
        $metrics[$metric] = $grow ? $current : $old;
        continue;
      }
      $metrics[$metric] = $current;
    }
    if ($metrics !== []) {
      $content = json_encode($metrics + ['coverage' => NULL, 'msi' => NULL], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
      $files[Baseline::FILES['coverage']] = $content;
      foreach (['coverage' => 'coverage', 'msi' => 'mutation'] as $metric => $gate) {
        if (isset($metrics[$metric])) {
          $gates[$gate] = self::entry(Baseline::FILES['coverage'], (int) floor($metrics[$metric]), $content);
        }
      }
    }

    // config_clean: set arithmetic on the drift entries.
    if ($measured->configDrift === NULL) {
      $this->carryForward('config_clean', $previous, $files, $gates);
    }
    else {
      $current = $measured->configDrift;
      $record = $current;
      if ($previous !== NULL) {
        $old = $previous->has('config_clean') ? $previous->configDrift() : [];
        $kept = array_values(array_intersect($current, $old));
        $new = count($current) - count($kept);
        if ($new > 0) {
          $added['config_clean'] = $new;
        }
        $record = $grow ? $current : $kept;
      }
      $content = json_encode(['v' => 1, 'drift' => $record], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
      $files[Baseline::FILES['config_clean']] = $content;
      $gates['config_clean'] = self::entry(Baseline::FILES['config_clean'], count($record), $content);
    }

    if ($added !== [] && !$grow) {
      throw BaselineError::refusedGrowth($added);
    }

    $grown = $previous?->manifest->grown ?? [];
    if ($grow && $added !== []) {
      $grown[] = ['at' => $this->now(), 'reason' => (string) $reason, 'added' => $added];
    }
    $manifest = new BaselineManifest($this->now(), $commit, $preset, $gates, $grown);
    BaselineStore::write($root, $manifest, $files);
    return $manifest;
  }

  /**
   * Keeps a previous baseline's file for a gate that was not measured now.
   *
   * A tool missing at refresh time must not silently drop the debt it
   * recorded — that would turn every one of those findings NEW on the next
   * run the tool is back. The bill says the gate was skipped.
   *
   * @param string $gate
   *   The gate.
   * @param \Droost\Workflow\Baseline\Baseline|null $previous
   *   The previous baseline, if any.
   * @param array<string, string> $files
   *   The files being written, by reference.
   * @param array<string, array{file: string, count: int, sha256: string}> $gates
   *   The manifest entries being written, by reference.
   */
  private function carryForward(string $gate, ?Baseline $previous, array &$files, array &$gates): void {
    if ($previous === NULL || !$previous->has($gate)) {
      return;
    }
    $entry = $previous->manifest->gates[$gate];
    $files[$entry['file']] ??= (string) file_get_contents($previous->dir . '/' . $entry['file']);
    $gates[$gate] = $entry;
  }

  /**
   * Generates phpstan's own baseline for the tree.
   *
   * `--generate-baseline` cannot be combined with `--error-format`, so the
   * gate's JSON flag is dropped for this one run. The file is generated into
   * the baseline directory (relative `path:` entries depend on it) under a
   * scratch name, read, and removed — the writer decides what lands.
   *
   * @param \Droost\Workflow\Config\GateSettings $settings
   *   The phpstan gate's levers.
   * @param string $root
   *   The project root.
   *
   * @return array{string|null, string|null}
   *   The neon content, or NULL with the reason it could not be produced.
   */
  private function generatePhpstanBaseline(GateSettings $settings, string $root): array {
    $dir = BaselineStore::dir($root);
    if (!is_dir($dir) && !mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      return [NULL, 'could not create ' . BaselineStore::DIR];
    }
    $scratch = BaselineStore::DIR . '/' . self::PHPSTAN_SCRATCH;
    $path = $root . '/' . $scratch;
    $run = $this->executor->measure($settings, $root, ['--generate-baseline=' . $scratch], ['--error-format=']);
    if ($run === NULL) {
      return [NULL, 'tool missing or nothing to analyse'];
    }
    if (!is_file($path)) {
      $line = strtok(trim($run['stderr']) !== '' ? $run['stderr'] : $run['stdout'], "\n");
      return [
        NULL,
        sprintf('phpstan did not write a baseline (exit %d)%s', $run['exit'], $line === FALSE ? '' : ': ' . $line),
      ];
    }
    $neon = (string) file_get_contents($path);
    @unlink($path);
    return [$neon, NULL];
  }

  /**
   * The wrapper the phpstan gate runs through when a baseline is honoured.
   *
   * Includes the project's own config when it has one (relative to the
   * baseline directory), then the recorded baseline. Unmatched ignores are
   * paid-off debt, never a failure; the next refresh drops them.
   *
   * @param string $root
   *   The project root.
   *
   * @return string
   *   The neon.
   */
  private function phpstanWrapper(string $root): string {
    $includes = [];
    foreach (['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'] as $candidate) {
      if (is_file($root . '/' . $candidate)) {
        $includes[] = '../../' . $candidate;
        break;
      }
    }
    $includes[] = Baseline::FILES['phpstan'];
    $lines = [
      '# Written by droost (`droost:workflow:baseline`). The phpstan gate runs',
      '# through this file while a baseline is honoured: the project\'s own',
      '# config, then the recorded baseline. Unmatched ignores are paid-off',
      '# debt and never fail a run — a refresh drops them. Do not edit by hand.',
      'includes:',
    ];
    foreach ($includes as $include) {
      $lines[] = "\t- " . $include;
    }
    $lines[] = 'parameters:';
    $lines[] = "\treportUnmatchedIgnoredErrors: false";
    return implode("\n", $lines) . "\n";
  }

  /**
   * One manifest entry: the file, its count, and the hash of what it holds.
   *
   * @param string $file
   *   The file name inside the baseline directory.
   * @param int $count
   *   The recorded count (findings, files, or a floor as a whole percent).
   * @param string $content
   *   The file's content.
   *
   * @return array{file: string, count: int, sha256: string}
   *   The entry.
   */
  private static function entry(string $file, int $count, string $content): array {
    return ['file' => $file, 'count' => $count, 'sha256' => hash('sha256', $content)];
  }

  /**
   * The error findings only — warnings never fail a gate.
   *
   * @param list<array{file: string, rule: string, message: string, line: int, error: bool, key: string}> $findings
   *   Every finding.
   *
   * @return list<array{file: string, rule: string, message: string, line: int, error: bool, key: string}>
   *   The errors.
   */
  private static function errorsOnly(array $findings): array {
    return array_values(array_filter($findings, static fn (array $f): bool => $f['error']));
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

}
