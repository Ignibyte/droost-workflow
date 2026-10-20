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
    // The graph keys names with no leading backslash (S1-6).
    $symbol = ltrim($symbol, '\\');
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

    // A WRONG FORM IS NOT "NO EDGES" (S1-6). The lookup is exact and
    // case-sensitive, so a name in another case, or `Short::method`, came back
    // as success with 0 edges, which reads as "safe to change".
    if ($edges === [] && $resolvedFrom === '' && !self::isPseudoSymbol($symbol)) {
      $known = self::knownForm($graph, $symbol);
      if ($known === NULL) {
        return $this->fail(sprintf(
          'No symbol "%s" is in the code index, so its %s cannot be read: the name is in another form, or not indexed. `droost code:symbol %s` finds the form the index uses.',
          $symbol,
          $direction,
          self::shortOf($symbol),
        ));
      }
      if ($known !== $symbol) {
        $resolvedFrom = $symbol;
        $symbol = $known;
        $edges = self::edgesFor($graph, $symbol, $direction, $limit + 1);
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
   * Whether a symbol is a pseudo-symbol (`service:x`, `route:x`, `hook:x`).
   *
   * @param string $symbol
   *   The requested symbol.
   *
   * @return bool
   *   TRUE when it names no class.
   */
  private static function isPseudoSymbol(string $symbol): bool {
    return preg_match('/^[a-z_]+:(?!:)/', $symbol) === 1;
  }

  /**
   * The form the index uses for a symbol, or NULL when it holds none.
   *
   * The symbol itself when indexed as written; else its one case-insensitive
   * match; for `Class::method`, the class's form (a short class name
   * resolved when it is unique) with the method kept.
   *
   * @param \Droost\Engine\Search\Graph\CodeGraphStorageInterface $graph
   *   The graph.
   * @param string $symbol
   *   The requested symbol.
   *
   * @return string|null
   *   The indexed form, or NULL.
   */
  private static function knownForm(CodeGraphStorageInterface $graph, string $symbol): ?string {
    $parts = explode('::', $symbol, 2);
    $class = $parts[0];
    $member = $parts[1] ?? NULL;
    $exact = NULL;
    $folded = [];
    foreach ($graph->findSymbols($class, 50) as $row) {
      $fqcn = is_string($row['fqcn'] ?? NULL) ? $row['fqcn'] : '';
      if ($fqcn === $class) {
        $exact = $fqcn;
      }
      elseif (strcasecmp($fqcn, $class) === 0) {
        $folded[] = $fqcn;
      }
    }
    $found = $exact ?? (count($folded) === 1 ? $folded[0] : NULL);
    if ($found === NULL && self::isBareShortName($class)) {
      $candidates = $graph->resolveShortName($class, 2);
      $found = count($candidates) === 1 ? $candidates[0] : NULL;
    }
    if ($found === NULL) {
      return NULL;
    }
    return $member === NULL ? $found : $found . '::' . $member;
  }

  /**
   * The short name of a symbol, for `code:symbol`.
   *
   * @param string $symbol
   *   The symbol.
   *
   * @return string
   *   Its last segment, without a member.
   */
  private static function shortOf(string $symbol): string {
    $class = explode('::', $symbol, 2)[0];
    $parts = explode('\\', $class);
    return (string) end($parts);
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
