<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Engine\Search\Graph\CodeGraphStorageInterface;
use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * Returns code-graph edges for a symbol, in either direction.
 */
final class GraphTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'Returns code-graph edges for a fully-qualified symbol or a pseudo-symbol ("service:<id>", "route:<name>", "hook:<name>"). A bare short class name ("PathGuard") is resolved to its fully-qualified match, or the candidates are returned when it is ambiguous — so a wrong name form is never mistaken for "no edges". direction=callers (who references it) or dependencies (what it references). Edge kinds: extends, implements, uses, instantiates, calls, plus the framework-semantic service_class, injects, routes_to, implements_hook, uses_service, derived_by. Read-only.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'symbol';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'symbol' => [
      'type' => 'string',
      'description' => 'A fully-qualified name, e.g. "Drupal\\\\Core\\\\Entity\\\\EntityType".',
    ],
    'direction' => [
      'type' => 'string',
      'enum' => ['callers', 'dependencies'],
      'description' => 'callers (referrers) or dependencies (referents). Default callers.',
    ],
    'limit' => ['type' => 'integer', 'description' => 'Max edges (default 100, max 500).'],
  ];

  /**
   * {@inheritdoc}
   */
  public const array REQUIRED = ['symbol'];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'code:graph';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $symbol = mb_substr(self::stringArg($arguments, 'symbol'), 0, 256);
    if ($symbol === '') {
      return $this->fail('Provide a non-empty "symbol".');
    }
    $graph = self::readyGraph($site);
    if ($graph === NULL) {
      return $this->fail(self::NO_INDEX);
    }
    $direction = self::stringArg($arguments, 'direction');
    if ($direction === '') {
      $direction = 'callers';
    }
    elseif ($direction !== 'callers' && $direction !== 'dependencies') {
      return $this->fail('The "direction" must be "callers" or "dependencies".');
    }
    $limit = self::intArg($arguments, 'limit', 100, 1, 500);
    // Fetch one extra row to detect (and report) truncation.
    $edges = self::edgesFor($graph, $symbol, $direction, $limit + 1);

    // The graph is keyed by fully-qualified name, so a bare short name
    // ("PathGuard") matches nothing — which a caller misreads as "no callers"
    // rather than "wrong name form" (backlog D18). When a short name yields
    // nothing, resolve it against the symbol index: retry transparently on a
    // single match, or return the candidates so the caller can pass an FQCN.
    $resolvedFrom = '';
    if ($edges === [] && self::isBareShortName($symbol)) {
      $candidates = $graph->resolveShortName($symbol, 26);
      if (count($candidates) === 1) {
        $resolvedFrom = $symbol;
        $symbol = $candidates[0];
        $edges = self::edgesFor($graph, $symbol, $direction, $limit + 1);
      }
      elseif ($candidates !== []) {
        // resolveShortName is fetched capped (26); if it filled, report "at
        // least N" rather than presenting the capped count as exhaustive.
        $capped = count($candidates) > 25;
        $shown = array_slice($candidates, 0, 25);
        return $this->fail(sprintf(
          '"%s" is a short name; code:graph is keyed by fully-qualified name and it matches %s%d symbol(s). Re-call with one of: %s.',
          $symbol,
          $capped ? 'at least ' : '',
          count($shown),
          implode(', ', $shown),
        ));
      }
    }

    $truncated = count($edges) > $limit;
    $edges = array_slice($edges, 0, $limit);

    // What the graph cannot see, said where a reader would take an empty or
    // short answer as "nothing calls this": calls on $this, self::, static::
    // and through a class name are recorded; a call on any other object is
    // not, since its class cannot be known statically (F-322).
    $blind = $direction === 'callers' && str_contains($symbol, '::')
      ? ' Calls on $this, self::, static:: and Class:: are recorded; a call through another object ($this->service->m(), $x->m()) is not, so search the text before trusting a short list.'
      : '';
    return $this->succeed(
      sprintf(
        '%d %s edge(s) for %s%s%s.%s',
        count($edges),
        $direction,
        $symbol,
        $resolvedFrom !== '' ? sprintf(' (resolved from "%s")', $resolvedFrom) : '',
        $truncated ? ' (truncated; raise "limit" for more)' : '',
        $blind,
      ),
      [
        'symbol' => $symbol,
        'direction' => $direction,
        'truncated' => $truncated,
        'edges' => $edges,
      ],
    );
  }

  /**
   * Fetches edges for a symbol in the requested direction.
   *
   * @param \Droost\Engine\Search\Graph\CodeGraphStorageInterface $graph
   *   The graph.
   * @param string $symbol
   *   The fully-qualified or pseudo-symbol.
   * @param string $direction
   *   Either "callers" or "dependencies".
   * @param int $limit
   *   Maximum rows to fetch.
   *
   * @return array<int, array<string, mixed>>
   *   The edge rows.
   */
  private static function edgesFor(CodeGraphStorageInterface $graph, string $symbol, string $direction, int $limit): array {
    return $direction === 'dependencies'
      ? $graph->dependencies($symbol, $limit)
      : $graph->callers($symbol, $limit);
  }

  /**
   * Whether a symbol is a bare short name (no namespace, not a pseudo-symbol).
   *
   * FQCNs contain a backslash; pseudo-symbols ("service:", "route:", "hook:")
   * and method refs ("Class::method") contain a colon. A symbol with neither is
   * a bare short name the FQCN-keyed graph cannot match directly.
   *
   * @param string $symbol
   *   The requested symbol.
   *
   * @return bool
   *   TRUE when it is a bare short name worth resolving.
   */
  private static function isBareShortName(string $symbol): bool {
    return $symbol !== '' && !str_contains($symbol, '\\') && !str_contains($symbol, ':');
  }

}
