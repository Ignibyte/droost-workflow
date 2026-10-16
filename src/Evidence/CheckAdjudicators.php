<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Knowledge\PlanGroundedCheck;

/**
 * Several adjudicators asked as one: the library's own checks, then a site's.
 *
 * Each is asked in order. A name the library's own checks answer is theirs:
 * a site still contributing a check the library now owns (droost's
 * `plan_grounded`, before it was moved) never records it twice. Every other
 * record is kept as it came, duplicates included, so a site check's Blocked
 * verdict can never be hidden behind another's Satisfied one of the same name
 * (S4-7).
 *
 * One that throws is recorded as blocked and the others are still asked. A
 * site's adjudicator that throws is the site's environment, as ModeEngine
 * records it; a LIBRARY check that throws is recorded under its own name with
 * no escape, so the operator cannot lift a check that never measured anything
 * into a closed phase (S4-8).
 */
final class CheckAdjudicators implements CheckAdjudicatorInterface {

  /**
   * The names the library's own checks answer, each recorded once.
   */
  public const LIBRARY_CHECKS = [PlanGroundedCheck::NAME];

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
    $library = [];
    foreach ($this->adjudicators as $adjudicator) {
      $own = $adjudicator instanceof PlanGroundedCheck;
      try {
        $answered = $adjudicator->adjudicate($projectRoot, $phase, $runId);
      }
      catch (\Throwable $e) {
        $answered = [
          $own
            ? new CheckRecord(
              'check',
              PlanGroundedCheck::NAME,
              CheckState::Blocked,
              Fault::Unknown,
              sprintf('%s could not be adjudicated: %s: %s', PlanGroundedCheck::NAME, $e::class, $e->getMessage()),
              provider: PlanGroundedCheck::PROVIDER,
          )
            : new CheckRecord(
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
        if (in_array($record->name, self::LIBRARY_CHECKS, TRUE)) {
          if (isset($library[$record->name])) {
            continue;
          }
          $library[$record->name] = TRUE;
        }
        $records[] = $record;
      }
    }

    return $records;
  }

}
