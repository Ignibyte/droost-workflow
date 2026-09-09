<?php

declare(strict_types=1);

namespace Droost\Workflow\Config;

use Droost\Workflow\Support\TypedArray;

/**
 * The optional work_item block: how a run's ticket is fetched and written back.
 *
 * Metadata for a work-item integration (droost_jira and its kin), not something
 * the engine itself consumes — the engine stays framework-free and knows
 * nothing of Jira. Parsed and validated here so a typo in the lever file
 * surfaces in review rather than at the first write, and surfaced by
 * `workflow:status` so the wiring is legible in a reviewable diff.
 *
 * The block is the PROJECT CONFIG of the integration, provider-agnostic in
 * shape: which tracker and cloud, which projects and issue types are
 * workable, how branches are named, what the tracker calls its transitions,
 * and — the keystone — a local map of every custom field by a name the site
 * chooses (`fields.developer_notes: { id: customfield_10001, format: adf }`),
 * so no provider module ever hardcodes another team's field ids, and the
 * team's own layer only ever says "developer_notes". Which fields a ticket
 * MUST carry, and what goes in them, is that layer's business, never this
 * block's.
 *
 * status_map is deliberately allowed to be empty, and empty is the common case:
 * droost emits SCM events (a branch, a PR) and lets the tracker's own
 * automation move the ticket, so a repo that names no transitions has not
 * misconfigured anything — it has declined to couple itself to one team's
 * status vocabulary. Every write the other fields describe is still gated.
 */
final class WorkItemSettings {

  /**
   * The keys the block defines. Unknown ones are refused, like seekers.
   */
  private const OPTIONS = [
    'provider',
    'cloud_id',
    'projects',
    'eligible_types',
    'branch',
    'transitions',
    'fields',
    'track_map',
    'writeback',
    'status_map',
    'publish',
  ];

  /**
   * The keys the `branch` child defines.
   */
  private const BRANCH_OPTIONS = ['prefixes', 'base'];

  /**
   * The keys each `fields.<name>` entry defines.
   */
  private const FIELD_OPTIONS = ['id', 'format'];

  /**
   * The format a mapped field carries when the entry names none.
   */
  public const DEFAULT_FIELD_FORMAT = 'text';

  /**
   * Constructs the settings.
   *
   * @param string|null $provider
   *   The provider id (jira, github, …), or NULL to leave it to the site.
   * @param string|null $cloudId
   *   The tracker instance (Jira's cloud id), pinned so every call names the
   *   same site instead of inferring it.
   * @param list<string> $projects
   *   The tracker project keys this repo accepts at intake.
   * @param list<string> $eligibleTypes
   *   The issue types a run may be opened for. Empty means the provider's
   *   default (every type track_map names).
   * @param array<string, string> $branchPrefixes
   *   Branch prefixes by track or purpose (feature, bugfix, release, …).
   * @param string|null $branchBase
   *   The branch new work is cut from and PRs target, when the repo fixes one.
   * @param array<string, string> $transitions
   *   Tracker transition ids by the site's own names (in_progress, done, …),
   *   for a `/transition`-style command to use; never fired by the engine.
   * @param array<string, array{id: string, format: string}> $fields
   *   The custom-field map: the site's name for a field, its tracker id and
   *   the format its content takes (text, adf, user, …).
   * @param array<string, string> $trackMap
   *   Issue type => workflow track (e.g. Bug => bugfix).
   * @param array<string, string> $writeback
   *   Where derived material is written (e.g. acceptance_criteria =>
   *   description, dev_notes_field => developer_notes). Values that name a
   *   `fields` entry resolve to its id; every write stays gated.
   * @param array<string, string> $statusMap
   *   Phase/outcome => tracker transition. Empty by default.
   * @param array<string, string> $publish
   *   Where the completed spec is published (target, space, parent, …).
   */
  private function __construct(
    public readonly ?string $provider,
    public readonly ?string $cloudId,
    public readonly array $projects,
    public readonly array $eligibleTypes,
    public readonly array $branchPrefixes,
    public readonly ?string $branchBase,
    public readonly array $transitions,
    public readonly array $fields,
    public readonly array $trackMap,
    public readonly array $writeback,
    public readonly array $statusMap,
    public readonly array $publish,
  ) {}

  /**
   * Parses the work_item block.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The block.
   * @param string $source
   *   The document label, for error messages.
   *
   * @return self
   *   The parsed settings.
   *
   * @throws \Droost\Workflow\Config\ConfigError
   *   When the block, its `branch` child or a `fields` entry carries an
   *   option it does not define.
   * @throws \Droost\Workflow\Support\DataError
   *   When a value is the wrong type, or a mapped field has no id.
   */
  public static function fromNode(TypedArray $node, string $source): self {
    foreach ($node->keys() as $key) {
      if (!in_array($key, self::OPTIONS, TRUE)) {
        throw ConfigError::unknownWorkItemOption($source, $key, self::OPTIONS);
      }
    }

    $prefixes = [];
    $base = NULL;
    $branch = $node->optionalChild('branch');
    if ($branch !== NULL) {
      foreach ($branch->keys() as $key) {
        if (!in_array($key, self::BRANCH_OPTIONS, TRUE)) {
          throw ConfigError::unknownWorkItemOption(
            $source,
            'branch.' . $key,
            array_map(static fn (string $k): string => 'branch.' . $k, self::BRANCH_OPTIONS),
          );
        }
      }
      $prefixes = self::stringMap($branch, 'prefixes');
      $base = $branch->has('base') ? $branch->string('base') : NULL;
    }

    $fields = [];
    $fieldsNode = $node->optionalChild('fields');
    if ($fieldsNode !== NULL) {
      foreach ($fieldsNode->keys() as $name) {
        $field = $fieldsNode->child($name);
        foreach ($field->keys() as $key) {
          if (!in_array($key, self::FIELD_OPTIONS, TRUE)) {
            throw ConfigError::unknownWorkItemOption(
              $source,
              'fields.' . $name . '.' . $key,
              array_map(static fn (string $k): string => 'fields.<name>.' . $k, self::FIELD_OPTIONS),
            );
          }
        }
        // The id is the whole point of the entry: a name with no id maps
        // nothing, so it is required (string() throws when absent).
        $fields[$name] = [
          'id' => (string) $field->intOrString('id'),
          'format' => $field->has('format') ? $field->string('format') : self::DEFAULT_FIELD_FORMAT,
        ];
      }
    }

    return new self(
      $node->has('provider') ? $node->string('provider') : NULL,
      $node->has('cloud_id') ? $node->string('cloud_id') : NULL,
      $node->optionalStringList('projects', []),
      $node->optionalStringList('eligible_types', []),
      $prefixes,
      $base,
      self::scalarMap($node, 'transitions'),
      $fields,
      self::stringMap($node, 'track_map'),
      self::stringMap($node, 'writeback'),
      self::stringMap($node, 'status_map'),
      self::stringMap($node, 'publish'),
    );
  }

  /**
   * A string => string map read from an optional child block.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The parent block.
   * @param string $key
   *   The child key.
   *
   * @return array<string, string>
   *   The map, empty when the child is absent.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When any value is not a string.
   */
  private static function stringMap(TypedArray $node, string $key): array {
    $child = $node->optionalChild($key);
    if ($child === NULL) {
      return [];
    }
    $map = [];
    foreach ($child->keys() as $inner) {
      $map[$inner] = $child->string($inner);
    }
    return $map;
  }

  /**
   * A string => string map whose values may be written as numbers.
   *
   * Transition ids are integers in every tracker's own UI (`in_progress:
   * 21`); refusing the bare number would make the lever file say "21" in
   * quotes for no reason a reader could name.
   *
   * @param \Droost\Workflow\Support\TypedArray $node
   *   The parent block.
   * @param string $key
   *   The child key.
   *
   * @return array<string, string>
   *   The map, values as strings, empty when the child is absent.
   *
   * @throws \Droost\Workflow\Support\DataError
   *   When any value is neither a string nor an integer.
   */
  private static function scalarMap(TypedArray $node, string $key): array {
    $child = $node->optionalChild($key);
    if ($child === NULL) {
      return [];
    }
    $map = [];
    foreach ($child->keys() as $inner) {
      $map[$inner] = (string) $child->intOrString($inner);
    }
    return $map;
  }

  /**
   * The tracker id a writeback target resolves to, when it names a field.
   *
   * `writeback.dev_notes_field: developer_notes` → `customfield_10001` when
   * `fields.developer_notes.id` is that; an unmapped name is returned as it
   * is, so a provider can still treat it as a literal field name.
   *
   * @param string $target
   *   A writeback value.
   *
   * @return string
   *   The mapped id, or the value itself.
   */
  public function fieldId(string $target): string {
    return $this->fields[$target]['id'] ?? $target;
  }

  /**
   * This block as a plain array, for status reporting.
   *
   * @return array<string, mixed>
   *   The settings, in the block's own shape.
   */
  public function toArray(): array {
    return [
      'provider' => $this->provider,
      'cloud_id' => $this->cloudId,
      'projects' => $this->projects,
      'eligible_types' => $this->eligibleTypes,
      'branch' => ['prefixes' => $this->branchPrefixes, 'base' => $this->branchBase],
      'transitions' => $this->transitions,
      'fields' => $this->fields,
      'track_map' => $this->trackMap,
      'writeback' => $this->writeback,
      'status_map' => $this->statusMap,
      'publish' => $this->publish,
    ];
  }

}
