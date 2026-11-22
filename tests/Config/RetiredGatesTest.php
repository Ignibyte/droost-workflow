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
   * The command advised in place of config_clean fails on drift (F-455).
   *
   * The advice was `drush config:status`, which exits 0 whether or not
   * config has drifted (drush 13.8 returns the rows and no error), and a
   * `cmd` gate passes on exit 0. Each place that gives the advice is run
   * here as the gate would run it, over a drush that prints what the real
   * one prints: a row per differing name, or its "No differences" notice
   * on stderr.
   */
  public function testAdvisedConfigCleanCommandFailsOnDrift(): void {
    $bin = $this->makeRoot();
    file_put_contents($bin . '/drush', implode("\n", [
      '#!/bin/sh',
      'if [ "$DRIFT" = 1 ]; then echo " system.site  Different"; exit 0; fi',
      'echo " [notice] No differences between DB and sync directory." >&2',
      '',
    ]));
    chmod($bin . '/drush', 0755);
    $package = dirname(__DIR__, 2);
    $advice = [
      'the retired-gate notice' => GateSettings::RETIRED_GATES['config_clean'],
      'pack/droost.yml' => (string) file_get_contents($package . '/pack/droost.yml'),
      'docs/project-rules.md' => (string) file_get_contents($package . '/docs/project-rules.md'),
    ];
    foreach ($advice as $where => $text) {
      $this->assertSame(1, preg_match('/config_clean: \{[^}\n]*cmd: "([^"\n]+)"/', $text, $m), $where . ' advises a command');
      $run = static function (string $drift) use ($bin, $m): int {
        $command = sprintf('PATH=%s:$PATH DRIFT=%s /bin/sh -c %s 2>/dev/null', escapeshellarg($bin), $drift, escapeshellarg($m[1]));
        exec($command, $output, $exit);
        return $exit;
      };
      $this->assertSame(0, $run('0'), $where . ': a clean site passes');
      $this->assertNotSame(0, $run('1'), $where . ': a drifted site fails');
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
