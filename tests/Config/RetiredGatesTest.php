<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\State\RunStateStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Gates that left with droost's Drupal module load with a notice.
 */
final class RetiredGatesTest extends WorkflowTestCase {

  /**
   * A lever file still naming a retired gate loads and says where it went.
   */
  public function testRetiredGatesLoadWithNotice(): void {
    $config = WorkflowConfig::fromArray([
      'preset' => 'low',
      'gates' => [
        'rendered_check' => ['on' => TRUE, 'routes' => '/'],
        'config_clean' => ['on' => TRUE],
        'grounding_check' => ['strict_citations' => TRUE],
        'composition_check' => ['on' => TRUE],
        'contributed' => ['snyk' => ['mode' => 'block']],
      ],
    ], 'droost/droost.yml');

    $this->assertCount(count(GateSettings::RETIRED_GATES), $config->deprecations);
    foreach (array_keys(GateSettings::RETIRED_GATES) as $name) {
      $this->assertArrayNotHasKey($name, $config->gates);
      $said = array_filter($config->deprecations, static fn (string $d): bool => str_contains($d, 'gates.' . $name . ' was retired'));
      $this->assertCount(1, $said, $name);
    }
  }

  /**
   * No retired name is a gate any more.
   */
  public function testNoRetiredNameIsKnown(): void {
    foreach (array_keys(GateSettings::RETIRED_GATES) as $name) {
      $this->assertFalse(GateSettings::isKnown($name), $name);
    }
  }

  /**
   * A run record from before droost left Drupal still loads.
   *
   * `late_woven` and `contributed_source` described module gates; they are
   * read past, never refused, so a run mid-flight across the upgrade can be
   * continued or reset.
   */
  public function testAnOldRunRecordStillLoads(): void {
    $root = $this->makeRoot();
    $store = new RunStateStore($root);
    mkdir($root . '/droost/droost-workflow', 0755, TRUE);
    file_put_contents($store->path(), (string) json_encode([
      'v' => 1,
      'run_id' => 'r-old',
      'started_at' => '2026-10-16T00:00:00+00:00',
      'mode' => 'agentic',
      'mode_override' => NULL,
      'preset' => 'low',
      'max_gate_retries' => 2,
      'provenance' => 'file',
      'resolved_gates' => [],
      'phases' => ['plan' => 'active'],
      'current_phase' => 'plan',
      'gate_results' => [],
      'awaiting' => NULL,
      'qa_history' => [],
      'feedback_attempts' => [],
      'late_woven' => ['module:snyk' => 'code'],
      'contributed_source' => 'the booted site',
    ]));

    $state = $store->load();

    $this->assertNotNull($state);
    $this->assertSame('r-old', $state->runId);
    $this->assertSame([], $state->ruleFiles);
  }

}
