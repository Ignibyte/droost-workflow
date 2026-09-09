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
   * The project config half parses: cloud, eligibility, branches, fields.
   *
   * The field map is the keystone of a provider-agnostic bridge: the site
   * names a field, the map holds the tracker's id and format, and no module
   * ever hardcodes another team's `customfield_11330`.
   */
  public function testProjectConfigKeysParse(): void {
    $yaml = <<<'YAML'
preset: custom
work_item:
  provider: jira
  cloud_id: 46ee8f13-8379-4206-9b1f-f446940f1db1
  projects: [EMT]
  eligible_types: [Story, Task, Bug, Sub-Story]
  branch:
    prefixes: { feature: feature, bugfix: bugfix, release: release }
    base: development
  transitions:
    in_progress: 21
    in_review: "121"
    done: 31
  fields:
    developer_notes: { id: customfield_11330, format: adf }
    testing_notes: { id: customfield_12335, format: adf }
    developer_id: { id: customfield_12317, format: user }
    fix_version: { id: fixVersions }
  track_map:
    Bug: bugfix
  writeback:
    acceptance_criteria: description
    dev_notes_field: developer_notes
YAML;
    $work = WorkflowConfig::load($this->makeRootWithConfig($yaml))->workItem;
    $this->assertInstanceOf(WorkItemSettings::class, $work);
    $this->assertSame('46ee8f13-8379-4206-9b1f-f446940f1db1', $work->cloudId);
    $this->assertSame(['Story', 'Task', 'Bug', 'Sub-Story'], $work->eligibleTypes);
    $this->assertSame(['feature' => 'feature', 'bugfix' => 'bugfix', 'release' => 'release'], $work->branchPrefixes);
    $this->assertSame('development', $work->branchBase);
    $this->assertSame(
      ['in_progress' => '21', 'in_review' => '121', 'done' => '31'],
      $work->transitions,
      'ids written as numbers or strings both read back as strings',
    );
    $this->assertSame(
      ['id' => 'customfield_11330', 'format' => 'adf'],
      $work->fields['developer_notes'],
    );
    $this->assertSame(
      ['id' => 'fixVersions', 'format' => WorkItemSettings::DEFAULT_FIELD_FORMAT],
      $work->fields['fix_version'],
      'format defaults to text',
    );
    // A writeback target that names a mapped field resolves to its id; one
    // that does not is returned as written.
    $this->assertSame('customfield_11330', $work->fieldId('developer_notes'));
    $this->assertSame('description', $work->fieldId('description'));
    // Status carries the whole block, so the wiring reads in a diff.
    $array = $work->toArray();
    $this->assertSame(['prefixes' => $work->branchPrefixes, 'base' => 'development'], $array['branch']);
    $this->assertArrayHasKey('fields', $array);
  }

  /**
   * The keys absent from a block read as empty, never as an error.
   */
  public function testProjectConfigKeysDefault(): void {
    $work = WorkflowConfig::load($this->makeRootWithConfig("preset: custom\nwork_item:\n  provider: jira\n"))->workItem;
    $this->assertInstanceOf(WorkItemSettings::class, $work);
    $this->assertNull($work->cloudId);
    $this->assertSame([], $work->eligibleTypes);
    $this->assertSame([], $work->branchPrefixes);
    $this->assertNull($work->branchBase);
    $this->assertSame([], $work->transitions);
    $this->assertSame([], $work->fields);
  }

  /**
   * A misspelled key under branch or a field entry is refused by name.
   */
  public function testUnknownNestedKeysAreRefused(): void {
    $yaml = "preset: custom\nwork_item:\n  branch:\n    prefix: feature\n";
    try {
      WorkflowConfig::load($this->makeRootWithConfig($yaml));
      $this->fail('branch.prefix must be refused');
    }
    catch (ConfigError $e) {
      $this->assertStringContainsString('branch.prefix', $e->getMessage());
    }

    $yaml = "preset: custom\nwork_item:\n  fields:\n    notes: { id: customfield_1, formats: adf }\n";
    $this->expectException(ConfigError::class);
    $this->expectExceptionMessage('fields.notes.formats');
    WorkflowConfig::load($this->makeRootWithConfig($yaml));
  }

  /**
   * A mapped field with no id maps nothing and is refused.
   */
  public function testFieldWithoutIdIsRefused(): void {
    $yaml = "preset: custom\nwork_item:\n  fields:\n    notes: { format: adf }\n";
    $this->expectException(ConfigError::class);
    WorkflowConfig::load($this->makeRootWithConfig($yaml));
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
