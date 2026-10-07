<?php

declare(strict_types=1);

namespace Droost\Workflow\Mode;

use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\PhaseReport;
use Droost\Workflow\State\PhaseStatus;
use Droost\Workflow\State\RunState;

/**
 * What happened when a phase was worked.
 *
 * Carries the resulting state rather than mutating anything, so a caller that
 * forgets to persist has visibly not persisted, instead of believing it had.
 */
final class RunOutcome {

  /**
   * How much of a failed gate's own words the envelope carries.
   */
  private const OUTPUT_TAIL = 1500;

  /**
   * Constructs a RunOutcome.
   *
   * @param \Droost\Workflow\Mode\Outcome $outcome
   *   Which of the four things happened.
   * @param \Droost\Workflow\State\RunState $state
   *   The run afterwards. Not yet saved.
   * @param \Droost\Workflow\Gate\PhaseReport|null $report
   *   The phase's gate report, when gates ran.
   * @param \Droost\Workflow\Mode\PendingQuestion|null $question
   *   The question the run is waiting on, when it paused.
   * @param list<array<string, string>> $blocked
   *   The non-gate checks holding this phase — name, fault, summary and
   *   remedy. A gate failure is already in the report; these were not
   *   anywhere a caller could see, which made them unactionable.
   * @param array<string, array{summary: string, why: string}> $deferred
   *   On a Deferred outcome, each gate whose failure becomes a follow-up,
   *   with its summary and why: `spent` (the loop budget ran out) or
   *   `outside` (every failure is in a spec the run did not change).
   */
  public function __construct(
    public readonly Outcome $outcome,
    public readonly RunState $state,
    public readonly ?PhaseReport $report = NULL,
    public readonly ?PendingQuestion $question = NULL,
    public readonly array $blocked = [],
    public readonly array $deferred = [],
  ) {}

  /**
   * Whether the run is waiting for an answer.
   *
   * @return bool
   *   TRUE when paused.
   */
  public function isPaused(): bool {
    return $this->outcome === Outcome::Paused;
  }

  /**
   * Whether the current phase has spent its retry budget and stopped.
   *
   * The one bit that separates "failed, fix it and invoke run again" from
   * "failed, and run will now refuse". Exit codes cannot carry it — both
   * cases exit non-zero so scripts stay simple — so it lives in the
   * envelope.
   *
   * @return bool
   *   TRUE when the current phase is terminally failed.
   */
  public function exhausted(): bool {
    $phase = $this->state->currentPhase;
    return $phase !== NULL
      && $this->state->statusOf($phase) === PhaseStatus::Failed;
  }

  /**
   * The phase report, with what a failed gate that parsed nothing said.
   *
   * A custom gate is a shell command, and droost parses nothing out of it,
   * so a failed one reached the envelope as a summary and no findings: the
   * reason was only in `droost-workflow evidence`, and nothing said to look
   * there (F-183, druplit-02). The tail of what it wrote rides on its row
   * here, and the row names where the whole of it is. Only the envelope
   * carries it; the report the run keeps in run.json stays as it was.
   *
   * @return array<string, mixed>|null
   *   The report, or NULL when no gates ran.
   */
  private function reportWithOutput(): ?array {
    if ($this->report === NULL) {
      return NULL;
    }
    $report = $this->report->toArray();
    $gates = [];
    foreach ($this->report->results as $result) {
      $row = $result->toArray();
      // A failure, or one a gate in report mode recorded without blocking.
      $failed = $result->status->blocksAdvance() || $result->status === GateStatus::Reported;
      $said = implode("\n", array_filter(
        [trim($result->stderr), trim($result->stdout)],
        static fn (string $part): bool => $part !== '',
      ));
      if ($failed && $result->findings === [] && $said !== '') {
        $row['output_tail'] = strlen($said) > self::OUTPUT_TAIL
          ? '…' . mb_strcut($said, strlen($said) - self::OUTPUT_TAIL, self::OUTPUT_TAIL, 'UTF-8')
          : $said;
        $row['output_in'] = 'droost-workflow evidence';
      }
      $gates[] = $row;
    }
    $report['gates'] = $gates;
    return $report;
  }

  /**
   * The one run envelope every surface renders.
   *
   * Until this existed the same five fields were assembled three times — in
   * the bin, the drush command and the MCP tool — which is exactly the
   * second-implementation drift the facade exists to prevent. The surfaces
   * differ in how they PRINT this, never in what it says.
   *
   * @return array<string, mixed>
   *   The envelope: outcome, current_phase, preset (the effort level the run
   *   is held to — the frozen, canonical name, so a reader can tell a gate
   *   the level dropped from one that failed), report, awaiting, and the
   *   retries block (attempts per gate, what each has LEFT, the bound, and
   *   whether the phase's budget is exhausted).
   */
  public function toArray(): array {
    return [
      'outcome' => $this->outcome->value,
      'current_phase' => $this->state->currentPhase?->value,
      'preset' => $this->state->preset,
      'report' => $this->reportWithOutput(),
      // The blocks that are NOT gates, which used to exist only as rows in a
      // SQLite file. A reviewer driving a real run hit `outcome: failed` with
      // `failed: 0`, `advance: true`, every gate green and no reason anywhere
      // in the output — and escaped only by opening the store with a
      // third-party tool. An agent cannot do that, and loops.
      'blocked' => $this->blocked,
      'awaiting' => $this->question?->toArray(),
      // HOW MANY ARE LEFT, not only how many are spent. `attempts` and
      // `max_gate_retries` do determine it — `GateRunner::mayRetry()` is
      // `attempts < max` — but only to a reader who knows the comparison is
      // `<` and not `<=`. `{"attempts":{"phpcs":2},"max_gate_retries":2}`
      // reads just as naturally as "two of two used, one more coming", and
      // the agent that believes that spends a turn on a gate that will not
      // run again.
      //
      // `exhausted` cannot answer it either: it is the PHASE's status, so
      // mid-loop it is FALSE while one particular gate already has nothing
      // left. Stating the remainder per gate costs a few bytes and removes
      // the inference.
      'retries' => $this->state->retries(),
      // The fast flow's loop (0.11): the budget, what it has spent, every
      // return and every follow-up. A deferred phase's tickets are named here,
      // because the outcome word alone says only that the run moved on.
      'loop' => $this->state->loop->envelope(),
    ];
  }

}
