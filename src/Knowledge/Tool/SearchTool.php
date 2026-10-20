<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Engine\Search\VectorStore\SqliteVectorStore;
use Droost\Workflow\Knowledge\KnowledgeIndexer;
use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * Searches the code: symbol names and the wiki's text, and by meaning.
 *
 * Lexical always. Semantic too when droost.yml's `search.embeddings` names an
 * endpoint and `droost code:index` built the vectors with the same model: the
 * query is embedded and the nearest code chunks are added. The answer says
 * which it was, and why semantic was left out when it was.
 *
 * `search:arch` searches the documentation; this searches the code.
 */
final class SearchTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'Searches this codebase: symbol names in the code index and the text of the wiki\'s pages, lexically; and, when droost.yml\'s search.embeddings names an endpoint and code:index built the vectors, the code chunks nearest the query in meaning. The answer\'s "mode" says which ran. For the documentation (READMEs, the inventory, the wiki) use `droost search:arch`. Read-only.';

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
    ['hits' => $semantic, 'why' => $why] = self::semantic($site, $query, $limit);

    return $this->succeed(
      sprintf(
        '%d symbol match(es)%s and %d wiki page(s) for "%s". %s Symbols are matched by name and the wiki by its text; strings inside the code (labels, messages) are not indexed, so grep finds those.',
        count($symbols),
        $truncated ? ' (more exist; raise "limit")' : '',
        count($wiki),
        $query,
        $semantic === NULL ? 'Lexical only: ' . $why . '.' : sprintf('Lexical and semantic: %d chunk(s) by meaning.', count($semantic)),
      ),
      [
        'query' => $query,
        'mode' => $semantic === NULL ? 'lexical' : 'lexical+semantic',
        'semantic_off' => $semantic === NULL ? $why : NULL,
        'symbols_truncated' => $truncated,
        'symbols' => $symbols,
        'wiki' => $wiki,
        'semantic' => $semantic ?? [],
      ],
    );
  }

  /**
   * The code chunks nearest the query, or why there are none.
   *
   * @param \Droost\Workflow\Knowledge\Site $site
   *   The site.
   * @param string $query
   *   The query.
   * @param int $limit
   *   The most to return.
   *
   * @return array{hits: list<array{score: float, corpus: string, ref: string, file: string, line: int|null, module: string, kind: string, name: string}>|null, why: string}
   *   The hits, NULL when semantic search did not run, with the reason.
   */
  private static function semantic(Site $site, string $query, int $limit): array {
    $backend = $site->embeddings();
    if (!$backend->isConfigured()) {
      return ['hits' => NULL, 'why' => 'search.embeddings names no endpoint'];
    }
    $reason = $backend->unavailableReason();
    if ($reason !== '') {
      return ['hits' => NULL, 'why' => $reason];
    }
    $pdo = $site->pdo();
    if ($pdo === NULL) {
      return ['hits' => NULL, 'why' => 'there is no index'];
    }
    $meta = KnowledgeIndexer::meta($pdo);
    if (($meta['embeddings'] ?? '') !== 'built' || ($meta['embeddings_model'] ?? '') === '') {
      $reason = ($meta['embeddings_reason'] ?? '') !== '' ? ' (' . $meta['embeddings_reason'] . ')' : '';
      $why = 'the index holds no complete semantic layer' . $reason . '; run droost code:index';
      return ['hits' => NULL, 'why' => $why];
    }
    if ($meta['embeddings_model'] !== $backend->model()) {
      // Vectors from another model live in another space: scoring against
      // them would rank noise.
      $why = sprintf('the vectors were built with %s, not %s; run droost code:index', $meta['embeddings_model'], $backend->model());
      return ['hits' => NULL, 'why' => $why];
    }
    $vector = $backend->embed([$query])[0] ?? [];
    if ($vector === []) {
      $error = $backend->lastError() !== '' ? ' (' . $backend->lastError() . ')' : '';
      return ['hits' => NULL, 'why' => 'the embeddings endpoint did not embed the query' . $error];
    }
    $hits = [];
    foreach ((new SqliteVectorStore($pdo))->search($vector, $limit) as $hit) {
      $meta = $hit['meta'];
      $hits[] = [
        'score' => round($hit['score'], 4),
        'corpus' => $hit['corpus'],
        'ref' => $hit['ref'],
        'file' => is_string($meta['file'] ?? NULL) ? $meta['file'] : '',
        'line' => is_numeric($meta['line'] ?? NULL) ? (int) $meta['line'] : NULL,
        'module' => is_string($meta['module'] ?? NULL) ? $meta['module'] : '',
        'kind' => is_string($meta['kind'] ?? NULL) ? $meta['kind'] : '',
        'name' => is_string($meta['name'] ?? NULL) ? $meta['name'] : '',
      ];
    }
    return ['hits' => $hits, 'why' => ''];
  }

}
