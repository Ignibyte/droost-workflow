<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * Finds code symbols by a substring of their fully-qualified name.
 */
final class SymbolTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'Finds code symbols (classes, interfaces, traits, enums, functions, methods) by a substring of their fully-qualified name. Returns kind and file:line. Read-only.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'name';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'name' => [
      'type' => 'string',
      'description' => 'A substring of the symbol name, e.g. "EntityType" or "::access". "query" is read as the same.',
    ],
    'limit' => ['type' => 'integer', 'description' => 'Max results (default 20, max 100).'],
  ];

  /**
   * {@inheritdoc}
   */
  public const array REQUIRED = ['name'];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'code:symbol';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    // `query` is what search:code takes, and an agent reaching for one
    // reached for the other with it.
    $name = mb_substr(self::stringArg($arguments, 'name') ?: self::stringArg($arguments, 'query'), 0, 256);
    if ($name === '') {
      return $this->fail('Provide a non-empty "name".');
    }
    $graph = self::readyGraph($site);
    if ($graph === NULL) {
      return $this->fail(self::NO_INDEX);
    }
    $limit = self::intArg($arguments, 'limit', 20, 1, 100);
    // Fetch one extra to detect truncation, mirroring droost_graph.
    $symbols = $graph->findSymbols($name, $limit + 1);
    $truncated = count($symbols) > $limit;
    $symbols = array_slice($symbols, 0, $limit);

    return $this->succeed(
      sprintf('%d symbol(s) matching "%s"%s.', count($symbols), $name, $truncated ? ' (truncated; raise "limit" for more)' : ''),
      ['name' => $name, 'returned' => count($symbols), 'truncated' => $truncated, 'symbols' => $symbols],
    );
  }

}
