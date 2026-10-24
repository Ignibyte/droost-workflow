<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

/**
 * Serves the shipped deprecation ledger at runtime, offline.
 *
 * Lazy-loads the running core major's dataset (the TICKET-104 `<major>`
 * derivation) and answers exact lookups and substring searches with zero
 * network. A missing file, a malformed dataset, or a '' major yields an empty
 * ledger whose stats say so — never an error.
 *
 * Drupal-free: the dataset ships inside droost, so it is found beside this
 * class, and the core major is the caller's to say.
 */
final class DeprecationLedger {

  /**
   * The loaded rows keyed by symbol, or NULL before the first load.
   *
   * @var array<string, array<array-key, mixed>>|null
   */
  private ?array $index = NULL;

  /**
   * The loaded dataset's _meta header.
   *
   * @var array<array-key, mixed>
   */
  private array $meta = [];

  /**
   * Where the datasets are.
   */
  private string $dataDir;

  /**
   * Constructs a DeprecationLedger.
   *
   * @param string $coreMajor
   *   The running core major, or '' to serve an empty ledger.
   * @param string|null $dataDir
   *   Where the `<major>.json` datasets are; NULL for the ones droost ships.
   */
  public function __construct(
    private readonly string $coreMajor,
    ?string $dataDir = NULL,
  ) {
    $this->dataDir = $dataDir ?? dirname(__DIR__, 2) . '/data/deprecations';
  }

  /**
   * Looks up a symbol's deprecation row (exact match), or NULL.
   *
   * @param string $symbol
   *   The symbol (FQCN, FQCN::member, function/hook name).
   *
   * @return array<array-key, mixed>|null
   *   The row, or NULL when the symbol is not deprecated in the ledger.
   */
  public function lookup(string $symbol): ?array {
    $this->load();
    return $this->index[$symbol] ?? NULL;
  }

  /**
   * Finds rows whose symbol contains a fragment (case-insensitive).
   *
   * @param string $fragment
   *   The substring to match.
   * @param int $limit
   *   The maximum number of rows to return.
   *
   * @return array<int, array<array-key, mixed>>
   *   The matching rows, capped at $limit.
   */
  public function search(string $fragment, int $limit = 25): array {
    $this->load();
    if ($fragment === '' || $this->index === NULL) {
      return [];
    }
    $out = [];
    foreach ($this->index as $symbol => $row) {
      if (stripos($symbol, $fragment) !== FALSE) {
        $out[] = $row;
        if (count($out) >= $limit) {
          break;
        }
      }
    }
    return $out;
  }

  /**
   * Returns the ledger's stats (for the no-args tool call and diagnostics).
   *
   * @return array{major: string, rows: int, schema: mixed, core_version: mixed}
   *   The major, row count, and dataset schema/core version (NULL when unloaded
   *   or absent).
   */
  public function stats(): array {
    $this->load();
    return [
      'major' => $this->coreMajor,
      'rows' => count($this->index ?? []),
      'schema' => $this->meta['schema'] ?? NULL,
      'core_version' => $this->meta['core_version'] ?? NULL,
    ];
  }

  /**
   * Lazily loads the running major's dataset (empty on any miss).
   */
  private function load(): void {
    if ($this->index !== NULL) {
      return;
    }
    $this->index = [];
    if ($this->coreMajor === '') {
      return;
    }
    $path = $this->dataDir . '/' . $this->coreMajor . '.json';
    $raw = @file_get_contents($path);
    if (!is_string($raw)) {
      return;
    }
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      return;
    }
    $this->meta = is_array($data['_meta'] ?? NULL) ? $data['_meta'] : [];
    $rows = is_array($data['deprecations'] ?? NULL) ? $data['deprecations'] : [];
    foreach ($rows as $row) {
      if (is_array($row) && isset($row['symbol']) && is_string($row['symbol'])) {
        $this->index[$row['symbol']] = $row;
      }
    }
  }

}
