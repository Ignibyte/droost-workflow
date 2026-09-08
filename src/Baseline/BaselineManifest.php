<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

use Droost\Workflow\Support\DataError;
use Droost\Workflow\Support\TypedArray;

/**
 * The baseline's own account of itself: when, at which commit, what, how much.
 *
 * `droost/baseline/baseline.json`. Everything a report needs to say "inherited
 * debt, recorded by the operator on <date> at <commit>" without opening the
 * per-gate files, plus the hash of each of them, so a run can freeze one
 * number at begin and tell later whether anything under it moved.
 */
final class BaselineManifest {

  /**
   * The document schema version.
   */
  public const SCHEMA_VERSION = 1;

  /**
   * Constructs a BaselineManifest.
   *
   * @param string $generatedAt
   *   When the baseline was written or last refreshed, ISO-8601.
   * @param string|null $generatedCommit
   *   The commit the tree was at, or NULL when no repository answered.
   * @param string $preset
   *   The effort level in force when it was measured.
   * @param array<string, array{file: string, count: int, sha256: string}> $gates
   *   Per gate: the file carrying its baseline, how many findings (or, for a
   *   metric, the floor as an integer percent) it records, and the file's
   *   content hash.
   * @param list<array{at: string, reason: string, added: array<string, int>}> $grown
   *   Every time the baseline was allowed to GROW: when, why, and how many
   *   findings each gate gained. Empty on a baseline that only ever shrank.
   */
  public function __construct(
    public readonly string $generatedAt,
    public readonly ?string $generatedCommit,
    public readonly string $preset,
    public readonly array $gates,
    public readonly array $grown = [],
  ) {}

  /**
   * Whether the baseline records anything for a gate.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return bool
   *   TRUE when a per-gate file is recorded.
   */
  public function has(string $gate): bool {
    return isset($this->gates[$gate]);
  }

  /**
   * The recorded count for a gate, or NULL when it has none.
   *
   * @param string $gate
   *   The gate name.
   *
   * @return int|null
   *   The count.
   */
  public function count(string $gate): ?int {
    return $this->gates[$gate]['count'] ?? NULL;
  }

  /**
   * This manifest as the data written to disk.
   *
   * @return array<string, mixed>
   *   The document.
   */
  public function toArray(): array {
    return [
      'v' => self::SCHEMA_VERSION,
      'generated_at' => $this->generatedAt,
      'generated_commit' => $this->generatedCommit,
      'preset' => $this->preset,
      'gates' => $this->gates,
      'grown' => $this->grown,
    ];
  }

  /**
   * Rebuilds a manifest from its document.
   *
   * @param array<array-key, mixed> $raw
   *   The decoded JSON.
   * @param string $label
   *   The file, as an operator sees it.
   *
   * @return self
   *   The manifest.
   *
   * @throws \Droost\Workflow\Baseline\BaselineError
   *   When the document is not a manifest this package can read.
   */
  public static function fromArray(array $raw, string $label): self {
    try {
      $node = TypedArray::serialized($raw);
      if ($node->int('v') !== self::SCHEMA_VERSION) {
        throw BaselineError::corrupt($label, sprintf('schema version %d is not %d', $node->int('v'), self::SCHEMA_VERSION));
      }
      $gates = [];
      $gatesNode = $node->optionalChild('gates');
      if ($gatesNode !== NULL) {
        foreach ($gatesNode->keys() as $gate) {
          $entry = $gatesNode->child($gate);
          $gates[$gate] = [
            'file' => $entry->string('file'),
            'count' => $entry->int('count'),
            'sha256' => $entry->string('sha256'),
          ];
        }
      }
      $grown = [];
      foreach ($node->optionalChild('grown')?->toArray() ?? [] as $index => $entry) {
        if (!is_array($entry)) {
          throw BaselineError::corrupt($label, sprintf('grown[%s] is not a mapping', (string) $index));
        }
        $typed = TypedArray::serialized($entry);
        $added = [];
        foreach ($typed->optionalChild('added')?->toArray() ?? [] as $gate => $count) {
          if (!is_string($gate) || !is_int($count)) {
            throw BaselineError::corrupt($label, 'grown[].added must map gate names to integers');
          }
          $added[$gate] = $count;
        }
        $grown[] = [
          'at' => $typed->string('at'),
          'reason' => $typed->string('reason'),
          'added' => $added,
        ];
      }
      return new self(
        $node->string('generated_at'),
        $node->optionalString('generated_commit'),
        $node->string('preset'),
        $gates,
        $grown,
      );
    }
    catch (DataError $e) {
      throw BaselineError::corrupt($label, $e->getMessage());
    }
  }

}
