<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

/**
 * A baseline as loaded from disk: the manifest plus typed readers per gate.
 *
 * Read-only. Files are opened lazily and cached, because a phase runs several
 * gates and each should pay for its own file once.
 */
final class Baseline {

  /**
   * The gates whose verdict consults a baseline.
   *
   * The static and front-end tools partition their findings; phpstan carries
   * its native baseline; the two metric gates have a floor; config_clean has
   * a drift snapshot (read by the site driver). phpunit, playwright,
   * rendered_check and wiki_fresh get none: a failing suite is broken, not
   * inherited (design §9).
   */
  public const CONSULTING = [
    'phpcs',
    'phpstan',
    'eslint',
    'stylelint',
    'prettier',
    'coverage',
    'mutation',
    'config_clean',
  ];

  /**
   * Per-gate file names inside the baseline directory.
   */
  public const FILES = [
    'phpcs' => 'phpcs.json',
    'eslint' => 'eslint.json',
    'stylelint' => 'stylelint.json',
    'prettier' => 'prettier.txt',
    'phpstan' => 'phpstan-baseline.neon',
    'coverage' => 'metrics.json',
    'mutation' => 'metrics.json',
    'config_clean' => 'config_clean.json',
  ];

  /**
   * The wrapper phpstan is pointed at when a baseline is honoured.
   */
  public const PHPSTAN_WRAPPER = 'phpstan.wrapper.neon';

  /**
   * Finding keys per gate, loaded on first use.
   *
   * @var array<string, array<string, true>>
   */
  private array $keys = [];

  /**
   * Constructs a Baseline.
   *
   * @param string $dir
   *   The absolute baseline directory.
   * @param \Droost\Workflow\Baseline\BaselineManifest $manifest
   *   The manifest.
   */
  public function __construct(
    public readonly string $dir,
    public readonly BaselineManifest $manifest,
  ) {}

  /**
   * Whether the baseline records anything for a gate.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return bool
   *   TRUE when the manifest lists it and its file exists.
   */
  public function has(string $gate): bool {
    $file = $this->manifest->gates[$gate]['file'] ?? NULL;
    return $file !== NULL && is_file($this->dir . '/' . $file);
  }

  /**
   * Whether a finding key is inherited for a gate.
   *
   * @param string $gate
   *   The gate (phpcs, eslint, stylelint).
   * @param string $key
   *   The finding key.
   *
   * @return bool
   *   TRUE when recorded.
   */
  public function inherits(string $gate, string $key): bool {
    return isset($this->findingKeys($gate)[$key]);
  }

  /**
   * The finding keys recorded for a gate.
   *
   * @param string $gate
   *   The gate.
   *
   * @return array<string, true>
   *   Keys as a set.
   */
  public function findingKeys(string $gate): array {
    if (isset($this->keys[$gate])) {
      return $this->keys[$gate];
    }
    $set = [];
    foreach ($this->findings($gate) as $finding) {
      $set[$finding['key']] = TRUE;
    }
    $this->keys[$gate] = $set;
    return $set;
  }

  /**
   * The findings recorded for a gate, as written.
   *
   * @param string $gate
   *   The gate.
   *
   * @return list<array{key: string, file: string, rule: string, message: string, line: int}>
   *   The recorded findings.
   */
  public function findings(string $gate): array {
    if (!$this->has($gate)) {
      return [];
    }
    $decoded = $this->json($this->manifest->gates[$gate]['file']);
    $out = [];
    foreach (is_array($decoded['findings'] ?? NULL) ? $decoded['findings'] : [] as $entry) {
      if (!is_array($entry) || !is_string($entry['key'] ?? NULL)) {
        continue;
      }
      $out[] = [
        'key' => $entry['key'],
        'file' => is_string($entry['file'] ?? NULL) ? $entry['file'] : '',
        'rule' => is_string($entry['rule'] ?? NULL) ? $entry['rule'] : '',
        'message' => is_string($entry['message'] ?? NULL) ? $entry['message'] : '',
        'line' => is_int($entry['line'] ?? NULL) ? $entry['line'] : 0,
      ];
    }
    return $out;
  }

  /**
   * The files recorded as unformatted at adoption.
   *
   * @return list<string>
   *   Project-relative paths.
   */
  public function prettierFiles(): array {
    if (!$this->has('prettier')) {
      return [];
    }
    $lines = preg_split('/\R/', (string) file_get_contents($this->dir . '/' . self::FILES['prettier'])) ?: [];
    $files = [];
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line !== '' && !str_starts_with($line, '#')) {
        $files[] = $line;
      }
    }
    return $files;
  }

  /**
   * A metric floor recorded at adoption.
   *
   * @param string $metric
   *   Which one: 'coverage' or 'msi'.
   *
   * @return float|null
   *   The floor, or NULL when none is recorded.
   */
  public function metric(string $metric): ?float {
    $gate = $metric === 'msi' ? 'mutation' : 'coverage';
    if (!$this->has($gate)) {
      return NULL;
    }
    $decoded = $this->json(self::FILES[$gate]);
    $value = $decoded[$metric] ?? NULL;
    return is_int($value) || is_float($value) ? (float) $value : NULL;
  }

  /**
   * The wrapper neon phpstan runs through, when the baseline has one.
   *
   * @return string|null
   *   The project-relative path (from the project root), or NULL.
   */
  public function phpstanWrapper(): ?string {
    if (!$this->has('phpstan') || !is_file($this->dir . '/' . self::PHPSTAN_WRAPPER)) {
      return NULL;
    }
    return BaselineStore::DIR . '/' . self::PHPSTAN_WRAPPER;
  }

  /**
   * How many findings the phpstan baseline records.
   *
   * @return int
   *   The total of its count lines.
   */
  public function phpstanInheritedCount(): int {
    if (!$this->has('phpstan')) {
      return 0;
    }
    return FindingParsers::phpstanBaselineCount((string) file_get_contents($this->dir . '/' . self::FILES['phpstan']));
  }

  /**
   * The config drift recorded at adoption.
   *
   * @return list<string>
   *   Drift entries exactly as the config_clean gate names them.
   */
  public function configDrift(): array {
    if (!$this->has('config_clean')) {
      return [];
    }
    $decoded = $this->json(self::FILES['config_clean']);
    $out = [];
    foreach (is_array($decoded['drift'] ?? NULL) ? $decoded['drift'] : [] as $entry) {
      if (is_string($entry)) {
        $out[] = $entry;
      }
    }
    return $out;
  }

  /**
   * One of the baseline's JSON files, decoded.
   *
   * @param string $file
   *   The file name inside the directory.
   *
   * @return array<array-key, mixed>
   *   The document, or [] when unreadable.
   */
  private function json(string $file): array {
    $raw = @file_get_contents($this->dir . '/' . $file);
    if ($raw === FALSE) {
      return [];
    }
    try {
      $decoded = json_decode($raw, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    return is_array($decoded) ? $decoded : [];
  }

}
