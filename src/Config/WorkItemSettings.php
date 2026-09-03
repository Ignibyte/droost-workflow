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
    'projects',
    'track_map',
    'writeback',
    'status_map',
    'publish',
  ];

  /**
   * Constructs the settings.
   *
   * @param string|null $provider
   *   The provider id (jira, github, …), or NULL to leave it to the site.
   * @param list<string> $projects
   *   The tracker project keys this repo accepts at intake.
   * @param array<string, string> $trackMap
   *   Issue type => workflow track (e.g. Bug => bugfix).
   * @param array<string, string> $writeback
   *   Where derived material is written (e.g. acceptance_criteria =>
   *   description). Consumed by the provider; every write stays gated.
   * @param array<string, string> $statusMap
   *   Phase/outcome => tracker transition. Empty by default.
   * @param array<string, string> $publish
   *   Where the completed spec is published (target, space, parent).
   */
  private function __construct(
    public readonly ?string $provider,
    public readonly array $projects,
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
   *   When the block carries an option it does not define.
   * @throws \Droost\Workflow\Support\DataError
   *   When a value is the wrong type.
   */
  public static function fromNode(TypedArray $node, string $source): self {
    foreach ($node->keys() as $key) {
      if (!in_array($key, self::OPTIONS, TRUE)) {
        throw ConfigError::unknownWorkItemOption($source, $key, self::OPTIONS);
      }
    }
    return new self(
      $node->optionalString('provider'),
      $node->optionalStringList('projects', []),
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
   * This block as a plain array, for status reporting.
   *
   * @return array<string, mixed>
   *   The settings, in the block's own shape.
   */
  public function toArray(): array {
    return [
      'provider' => $this->provider,
      'projects' => $this->projects,
      'track_map' => $this->trackMap,
      'writeback' => $this->writeback,
      'status_map' => $this->statusMap,
      'publish' => $this->publish,
    ];
  }

}
