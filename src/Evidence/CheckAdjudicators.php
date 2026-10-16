<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Knowledge\PlanGroundedCheck;

/**
 * Several adjudicators asked as one: the library's own checks, then a site's.
 *
 * Each is asked in order, and a check's name is answered once: the first
 * adjudicator to emit it wins, so a site still contributing a check the
 * library now owns (droost's `plan_grounded`, before it was moved) never
 * records it twice. One that throws is recorded as blocked with an
 * environment fault, as ModeEngine records a throwing adjudicator, and the
 * others are still asked.
 */
final class CheckAdjudicators implements CheckAdjudicatorInterface {

  /**
   * The adjudicators, in the order they are asked.
   *
   * @var list<\Droost\Workflow\Evidence\CheckAdjudicatorInterface>
   */
  private readonly array $adjudicators;

  /**
   * Constructs a CheckAdjudicators.
   *
   * @param \Droost\Workflow\Evidence\CheckAdjudicatorInterface ...$adjudicators
   *   The adjudicators, in the order they are asked.
   */
  public function __construct(CheckAdjudicatorInterface ...$adjudicators) {
    $this->adjudicators = array_values($adjudicators);
  }

  /**
   * The library's checks, then a site's, for a door to hand the facade.
   *
   * @param \Droost\Workflow\Evidence\CheckAdjudicatorInterface|null $site
   *   The site's contributed checks (droost's catalog, or the binary's note
   *   that it cannot ask them), or NULL.
   *
   * @return self
   *   The adjudicator.
   */
  public static function withLibraryChecks(?CheckAdjudicatorInterface $site = NULL): self {
    return $site === NULL ? new self(new PlanGroundedCheck()) : new self(new PlanGroundedCheck(), $site);
  }

  /**
   * {@inheritdoc}
   */
  public function adjudicate(string $projectRoot, string $phase, string $runId): array {
    $records = [];
    $seen = [];
    foreach ($this->adjudicators as $adjudicator) {
      try {
        $answered = $adjudicator->adjudicate($projectRoot, $phase, $runId);
      }
      catch (\Throwable $e) {
        $answered = [
          new CheckRecord(
            'check',
            'contributed_checks',
            CheckState::Blocked,
            Fault::Environment,
            'The contributed checks could not be adjudicated: ' . $e::class,
            'Check the log of the module contributing checks, then run the phase again.',
          ),
        ];
      }
      foreach ($answered as $record) {
        if (isset($seen[$record->name])) {
          continue;
        }
        $seen[$record->name] = TRUE;
        $records[] = $record;
      }
    }

    return $records;
  }

}
