<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

/**
 * How hard the pipeline holds an agent to the phase discipline mid-run.
 *
 * This lever governs the ENFORCEMENT layer (the harness hooks that guard
 * edits and turn-endings while a run is active), not the gates: gates decide
 * whether the artefact passes, enforcement decides whether the agent may act
 * out of phase at all. It is deliberately orthogonal to the preset — a repo
 * may run the full factory gate set with enforcement off. Not advised, but a
 * lever file is a reviewable diff, and a visible loosening is the honest way
 * to allow it.
 *
 * Outside an active run this lever means nothing by design: every hook's
 * first act is reading the run state, and no run means no opinion.
 */
enum Enforcement: string {

  // Out-of-phase actions are blocked with an explanation.
  case Hard = 'hard';

  // Out-of-phase actions warn once per phase, then proceed.
  case Soft = 'soft';

  // The hooks stand down entirely; only the gates judge the run.
  case Off = 'off';

  /**
   * The names a config file may use.
   *
   * @return list<string>
   *   The backing values.
   */
  public static function names(): array {
    return array_map(static fn (self $e): string => $e->value, self::cases());
  }

}
