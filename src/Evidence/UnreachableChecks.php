<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

/**
 * Says that contributed checks exist somewhere this surface cannot ask them.
 *
 * The standalone binary is Drupal-free by design, so it cannot instantiate a
 * `#[DroostCheck]` plugin — those live in a booted site. That is a real
 * limitation and not a defect. What WAS a defect is that it said nothing: a run
 * driven entirely through `bin/droost-workflow` recorded zero contributed
 * checks and read, in every surface downstream, exactly like a run that had
 * been asked and had nothing to answer.
 *
 * A reviewer drove two byte-identical projects, one through each door. Drush
 * blocked at plan on a missing work item; the binary advanced through plan,
 * code and test with no check rows at all, and only a phase that happened to
 * use another door would ever have caught it. `WorkflowFacadeTrait` states the
 * rule this broke — "a rule enforced through drush is a rule an agent evades
 * through MCP" — for the surface that had already been fixed, not this one.
 *
 * So the binary records the gap instead of leaving it silent. Not a pass and
 * not a failure: `Skipped` is the state for "asked for, and could not run
 * here", which is exactly what this is.
 *
 * Only when a site is actually present. A plain PHP repository has no
 * contributed checks to miss, and a row saying so on every run would be the
 * noise that teaches people to skim.
 */
final class UnreachableChecks implements CheckAdjudicatorInterface {

  /**
   * Constructs an UnreachableChecks.
   *
   * @param bool $siteIsPresent
   *   Whether this repository holds a Drupal site at all — from the same probe
   *   that resolves the contributed GATE catalog, so the two answers cannot
   *   disagree about whether a site exists.
   */
  public function __construct(private readonly bool $siteIsPresent) {}

  /**
   * {@inheritdoc}
   */
  public function adjudicate(string $projectRoot, string $phase, string $runId): array {
    if (!$this->siteIsPresent) {
      return [];
    }

    return [
      new CheckRecord(
        'check',
        'contributed_checks',
        CheckState::Skipped,
        Fault::None,
        'This repository holds a site, and a site may contribute checks — a '
        . 'ticket id, a transition, notes written back. This surface cannot ask '
        . 'them: the standalone binary boots no Drupal, so any check a module '
        . 'contributes went unasked for this phase. Advance through '
        . '`drush droost:workflow:run` to have them adjudicated.',
      ),
    ];
  }

}
