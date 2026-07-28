<?php

declare(strict_types=1);

namespace Drupal\droost_workflow\Gate;

use Drupal\droost_workflow\Config\Phase;

/**
 * Every gate's outcome for one phase, and whether the run may advance.
 *
 * The one place a verdict is derived. Surfaces render this; none of them
 * re-decides it, because a second opinion about whether a run passed is a
 * second answer waiting to disagree with the first.
 *
 * The rule the whole package turns on lives in advance(): a skipped gate does
 * not block, and it is never counted as a pass. Both halves matter. Blocking
 * would make the CLI surface unusable for any site-gated configuration;
 * counting it as a pass would make "we could not check" and "we checked and
 * it was fine" the same sentence.
 */
final class PhaseReport {

  /**
   * Constructs a PhaseReport.
   *
   * @param \Drupal\droost_workflow\Config\Phase $phase
   *   The phase these gates belong to.
   * @param list<\Drupal\droost_workflow\Gate\GateResult> $results
   *   One result per configured gate.
   */
  public function __construct(
    public readonly Phase $phase,
    public readonly array $results = [],
  ) {}

  /**
   * This report with one more result.
   *
   * @param \Drupal\droost_workflow\Gate\GateResult $result
   *   The result to add.
   *
   * @return self
   *   A new report.
   */
  public function with(GateResult $result): self {
    return new self($this->phase, [...$this->results, $result]);
  }

  /**
   * Whether the run may move on from this phase.
   *
   * @return bool
   *   TRUE when nothing blocking happened.
   */
  public function advance(): bool {
    foreach ($this->results as $result) {
      if ($result->status->blocksAdvance()) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The results with a given status.
   *
   * @param \Drupal\droost_workflow\Gate\GateStatus $status
   *   The status to filter by.
   *
   * @return list<\Drupal\droost_workflow\Gate\GateResult>
   *   The matching results, in order.
   */
  public function withStatus(GateStatus $status): array {
    return array_values(array_filter(
      $this->results,
      static fn (GateResult $r): bool => $r->status === $status,
    ));
  }

  /**
   * The gates that could not run for want of a site.
   *
   * Exposed as its own method because every surface has to show these, and a
   * surface that has to remember to look for them is a surface that will
   * eventually forget.
   *
   * @return list<\Drupal\droost_workflow\Gate\GateResult>
   *   The skipped results.
   */
  public function skipped(): array {
    return $this->withStatus(GateStatus::SkippedNoSite);
  }

  /**
   * A count per status, for a summary line.
   *
   * @return array<string, int>
   *   Status value to count, including zeroes, in enum order.
   */
  public function tally(): array {
    $tally = [];
    foreach (GateStatus::cases() as $status) {
      $tally[$status->value] = count($this->withStatus($status));
    }
    return $tally;
  }

  /**
   * A one-line summary that cannot hide a skip.
   *
   * Built from the tally rather than from a pass count, so a phase where
   * nothing failed but three gates never ran cannot render as "all passed".
   *
   * @return string
   *   The summary.
   */
  public function summaryLine(): string {
    $parts = [];
    foreach ($this->tally() as $status => $count) {
      if ($count > 0) {
        $parts[] = $count . ' ' . $status;
      }
    }
    return sprintf(
      '%s: %s',
      $this->phase->value,
      $parts === [] ? 'no gates configured' : implode(', ', $parts),
    );
  }

  /**
   * This report as plain data.
   *
   * @return array<string, mixed>
   *   The serialized report.
   */
  public function toArray(): array {
    return [
      'phase' => $this->phase->value,
      'advance' => $this->advance(),
      'tally' => $this->tally(),
      'gates' => array_map(
        static fn (GateResult $r): array => $r->toArray(),
        $this->results,
      ),
    ];
  }

}
