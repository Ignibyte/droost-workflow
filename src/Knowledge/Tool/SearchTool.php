<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * Searches the code index's symbol names and the wiki's text, lexically.
 *
 * `search:arch` searches the documentation; this searches the code.
 */
final class SearchTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'Searches this codebase lexically: symbol names in the code index, and the text of the wiki\'s pages. For the documentation (READMEs, the inventory, the wiki) use `droost search:arch`. Read-only.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'query';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'query' => ['type' => 'string', 'description' => 'A symbol name, a fragment of one, or a phrase from the wiki.'],
    'limit' => ['type' => 'integer', 'description' => 'Max results per result set (default 10, max 50).'],
  ];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'search:code';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $query = mb_substr(self::stringArg($arguments, 'query'), 0, 2000);
    if ($query === '') {
      return $this->fail('Provide a non-empty "query".');
    }
    $graph = self::readyGraph($site);
    if ($graph === NULL) {
      return $this->fail(self::NO_INDEX);
    }
    $limit = self::intArg($arguments, 'limit', 10, 1, 50);
    $symbols = $graph->findSymbols($query, $limit + 1);
    $truncated = count($symbols) > $limit;
    $symbols = array_slice($symbols, 0, $limit);
    $wiki = is_dir($site->wikiDir) ? $site->wiki()->search($query, $limit, 3) : [];

    return $this->succeed(
      sprintf(
        '%d symbol match(es)%s and %d wiki page(s) for "%s". Lexical.',
        count($symbols),
        $truncated ? ' (more exist; raise "limit")' : '',
        count($wiki),
        $query,
      ),
      [
        'query' => $query,
        'mode' => 'lexical',
        'symbols_truncated' => $truncated,
        'symbols' => $symbols,
        'wiki' => $wiki,
      ],
    );
  }

}
