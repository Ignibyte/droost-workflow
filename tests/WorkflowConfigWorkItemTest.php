<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\WorkItemSettings;
use Droost\Workflow\Config\WorkflowConfig;

/**
 * The optional work_item lever parses, validates, and stays absent when unset.
 *
 * The config half of the work-item integration. The engine never consumes this
 * block, so the contract is that the lever file can DECLARE it (provider,
 * projects, track map, writeback, status map, publish) and a typo is refused in
 * review rather than at the first write. An empty status_map is the common,
 * correct case: droost emits SCM events and lets the tracker's own automation
 * move the ticket, never coupling to one team's status vocabulary.
 */
final class WorkflowConfigWorkItemTest extends WorkflowTestCase {

  /**
   * A full work_item block parses into typed, exact values.
   */
  public function testWorkItemBlockParses(): void {
    $yaml = <<<'YAML'
preset: custom
work_item:
  provider: jira
  projects: [EMT, LCR]
  track_map:
    Bug: bugfix
    Story: standard
  writeback:
    acceptance_criteria: description
    dev_notes_field: Developer Notes
  status_map: {}
  publish:
    target: confluence
    space: DRUP
YAML;
    $config = WorkflowConfig::load($this->makeRootWithConfig($yaml));

    $work = $config->workItem;
    $this->assertInstanceOf(WorkItemSettings::class, $work);
    $this->assertSame('jira', $work->provider);
    $this->assertSame(['EMT', 'LCR'], $work->projects);
    $this->assertSame(
      ['Bug' => 'bugfix', 'Story' => 'standard'],
      $work->trackMap,
    );
    $this->assertSame(
      ['acceptance_criteria' => 'description', 'dev_notes_field' => 'Developer Notes'],
      $work->writeback,
    );
    $this->assertSame([], $work->statusMap, 'an empty status_map is the common case');
    $this->assertSame(
      ['target' => 'confluence', 'space' => 'DRUP'],
      $work->publish,
    );
  }

  /**
   * No block declared means no integration, and nothing else changes.
   */
  public function testWorkItemAbsentIsNull(): void {
    $config = WorkflowConfig::load($this->makeRootWithConfig("preset: custom\n"));
    $this->assertNull($config->workItem);
  }

  /**
   * A misspelled option is refused in review, not swallowed.
   */
  public function testUnknownWorkItemOptionIsRefused(): void {
    $yaml = "preset: custom\nwork_item:\n  provder: jira\n";
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('work_item accepts only');
    WorkflowConfig::load($this->makeRootWithConfig($yaml));
  }

}
