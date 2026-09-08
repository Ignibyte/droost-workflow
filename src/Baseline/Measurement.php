<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

/**
 * What the tools report about the tree right now — the debt, measured.
 *
 * The writer's input and the bill an operator reads before deciding to
 * baseline. Nothing here is a verdict; it is what each gate found, per gate,
 * plus the gates that could not be measured and why. A gate that is off at
 * the level is not measured either: a baseline records what the level will
 * judge, no more.
 */
final class Measurement {

  /**
   * Constructs a Measurement.
   *
   * @param array<string, list<array{file: string, rule: string, message: string, line: int, error: bool, key: string}>> $findings
   *   Per linter gate (phpcs, eslint, stylelint), the ERROR findings found —
   *   warnings never fail a gate, so they are not debt to inherit.
   * @param list<string>|null $prettier
   *   The unformatted files, or NULL when prettier was not measured.
   * @param string|null $phpstanBaseline
   *   The neon phpstan generated for the tree, or NULL when not measured.
   * @param array{coverage: float|null, msi: float|null} $metrics
   *   The measured percentages, NULL where not measured.
   * @param list<string>|null $configDrift
   *   The config_clean drift entries, or NULL when no site measured them.
   * @param array<string, string> $skipped
   *   Gate name to why it was not measured.
   */
  public function __construct(
    public readonly array $findings = [],
    public readonly ?array $prettier = NULL,
    public readonly ?string $phpstanBaseline = NULL,
    public readonly array $metrics = ['coverage' => NULL, 'msi' => NULL],
    public readonly ?array $configDrift = NULL,
    public readonly array $skipped = [],
  ) {}

  /**
   * How many findings phpstan's generated baseline records.
   *
   * @return int
   *   The total.
   */
  public function phpstanCount(): int {
    return $this->phpstanBaseline === NULL ? 0 : FindingParsers::phpstanBaselineCount($this->phpstanBaseline);
  }

  /**
   * The bill: what each measured gate would inherit.
   *
   * @return array<string, int>
   *   Gate name to count — findings for the linters and drift, files for
   *   prettier, and the floor as a whole percent for the two metrics.
   */
  public function bill(): array {
    $bill = [];
    foreach ($this->findings as $gate => $list) {
      $bill[$gate] = count($list);
    }
    if ($this->phpstanBaseline !== NULL) {
      $bill['phpstan'] = $this->phpstanCount();
    }
    if ($this->prettier !== NULL) {
      $bill['prettier'] = count($this->prettier);
    }
    if ($this->metrics['coverage'] !== NULL) {
      $bill['coverage'] = (int) floor($this->metrics['coverage']);
    }
    if ($this->metrics['msi'] !== NULL) {
      $bill['mutation'] = (int) floor($this->metrics['msi']);
    }
    if ($this->configDrift !== NULL) {
      $bill['config_clean'] = count($this->configDrift);
    }
    return $bill;
  }

}
