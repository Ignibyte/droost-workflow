<?php

declare(strict_types=1);

namespace Droost\Workflow\Driver;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\State\RunStateStore;

/**
 * Which routes rendered_check renders, and where each one came from.
 *
 * One answer for both site drivers. Each had its own copy of "the `routes`
 * option, or the front page", and for three live rounds that was the whole
 * story: the lever named nothing, so the gate rendered `/` while the ticket
 * built `/camps`, then `/rinks`, then `/private-lessons` — once with the
 * ticket's page returning 500 mid-build while the front page was fine (F-15).
 * The verification floor never asked for the one page the run existed to
 * make.
 *
 * So the spec's routes join the lever. The lever is the project's standing
 * list; the spec is this ticket's. The gate renders the union, and the record
 * names the source of every route, so a reader can see "the spec said
 * `/camps`, and it rendered" rather than an unlabelled green.
 *
 * DECLARED ROWS FIRST, the markdown section only when there are none. A
 * `declare-route` call is a fact with no shape to get wrong; the section is
 * prose whose meaning depends on where a heading sits and whether the writer
 * fenced their list, and two of Part 3's three specs lost their routes to
 * exactly that (F-32, F-34). The parse stays as the fallback for a run that
 * declared nothing — which is every run written before the tool existed —
 * and the record says `spec-declared` or `spec-parsed` so a reader can tell
 * which answered. Phase C deletes the second.
 */
final class RenderedRoutes {

  /**
   * What is rendered when nobody names anything: the front page.
   */
  public const DEFAULT = '/';

  /**
   * Resolves the routes for one gate run.
   *
   * @param \Droost\Workflow\Config\GateSettings $gate
   *   The gate, whose `routes` option is the lever's list.
   * @param string $projectRoot
   *   The repository. The open run's spec is read from its state directory;
   *   when there is no run, no spec, or no section, only the lever answers.
   *
   * @return array{routes: list<string>, sources: array<string, string>, spec: string|null, none: bool}
   *   The routes in order, each route's source (`lever`, `default`,
   *   `spec-declared`, `spec-parsed`), the spec that was consulted (NULL when
   *   none was) and whether that spec declared `none`.
   */
  public static function resolve(GateSettings $gate, string $projectRoot): array {
    $sources = [];
    foreach (self::fromOption($gate->option('routes')) as $route) {
      $sources[$route] = 'lever';
    }
    if ($sources === []) {
      $sources[self::DEFAULT] = 'default';
    }

    $none = FALSE;
    $declared = self::declaredRoutes($projectRoot);
    foreach ($declared as $route) {
      $sources[$route] ??= 'spec-declared';
    }

    // The document, only when nothing was declared. A run that called the
    // tool has said what it means; re-reading its prose could only disagree
    // with it, and a disagreement between two sources for the same fact is
    // the defect this whole redesign removes rather than arbitrates.
    $spec = self::specPath($projectRoot);
    if ($declared === [] && $spec !== NULL) {
      $parsed = SpecContract::routes($projectRoot, $spec);
      if ($parsed === NULL) {
        $spec = NULL;
      }
      else {
        $none = $parsed['none'];
        foreach ($parsed['routes'] as $route) {
          $sources[$route] ??= 'spec-parsed';
        }
      }
    }

    return [
      'routes' => array_keys($sources),
      'sources' => $sources,
      'spec' => $spec,
      'none' => $none,
    ];
  }

  /**
   * The open run's declared routes, from the store.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return list<string>
   *   The paths, in declaration order. Empty when no run is open, none were
   *   declared, or the store cannot be read — an unreachable store is not a
   *   reason to fail a render, and the summary says which source answered.
   */
  private static function declaredRoutes(string $projectRoot): array {
    try {
      $store = new RunStateStore($projectRoot);
      $state = $store->exists() ? $store->load() : NULL;
      if ($state === NULL) {
        return [];
      }

      return array_column((new EvidenceStore($projectRoot))->specRoutes($state->runId), 'path');
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * The routes with their sources, for a summary a reader can check.
   *
   * @param array{routes: list<string>, sources: array<string, string>, spec: string|null, none: bool} $resolved
   *   What resolve() returned.
   *
   * @return string
   *   E.g. "/ (default), /camps (spec-declared)" — with "; the spec declares
   *   no routes" when it said so, and "; no run spec to read" when there was
   *   no spec to ask, so an unlabelled front page is never mistaken for a
   *   ticket that had no page.
   */
  public static function describe(array $resolved): string {
    $parts = [];
    foreach ($resolved['sources'] as $route => $source) {
      $parts[] = sprintf('%s (%s)', $route, $source);
    }
    $line = implode(', ', $parts);
    // A run that DECLARED its routes needs no sentence about the document:
    // the rows answered, and "no run spec to read" beside a declared route
    // would read as a gap where there is none.
    if (in_array('spec-declared', $resolved['sources'], TRUE)) {
      return $line;
    }
    if ($resolved['spec'] === NULL) {
      return $line . '; no run spec to read';
    }
    if ($resolved['none']) {
      return $line . '; the spec declares no routes';
    }
    return $line;
  }

  /**
   * The lever's comma-separated list, as routes.
   *
   * @param mixed $option
   *   The `routes` option.
   *
   * @return list<string>
   *   Non-empty trimmed paths, in order.
   */
  private static function fromOption(mixed $option): array {
    if (!is_string($option) || trim($option) === '') {
      return [];
    }
    $routes = [];
    foreach (explode(',', $option) as $route) {
      $route = trim($route);
      if ($route !== '' && !in_array($route, $routes, TRUE)) {
        $routes[] = $route;
      }
    }
    return $routes;
  }

  /**
   * The open run's spec, project-relative, when there is one.
   *
   * @param string $projectRoot
   *   The repository.
   *
   * @return string|null
   *   The path, or NULL when no run is open or its record cannot be read. A
   *   record that cannot be read is not a reason to fail the render; the
   *   summary says no spec was consulted.
   */
  private static function specPath(string $projectRoot): ?string {
    try {
      $store = new RunStateStore($projectRoot);
      return $store->exists() ? $store->load()?->specPath : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
