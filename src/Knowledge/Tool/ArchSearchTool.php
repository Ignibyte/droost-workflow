<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Knowledge\DocsCollector;
use Droost\Workflow\Knowledge\DocsIndex;
use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * A plain text search over the project's documentation: `droost search:arch`.
 *
 * Kept small on purpose (owner, 2026-10-19): the wiki's pages, the
 * inventory of installed modules and themes, and each extension's README and
 * docs, searched as text. From the index's FTS table when `code:index` has
 * run; by reading the files when it has not, which finds the same pages.
 * The code's structure is `code:tree`'s, its symbols `code:symbol`'s.
 */
final class ArchSearchTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'Searches the project\'s documentation as plain text: the wiki, the inventory of installed modules and themes, and each module\'s and theme\'s README and docs. Every word must appear. From the code index when it is built, else by reading the files. Read-only.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'query';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'query' => ['type' => 'string', 'description' => 'Words to find, e.g. "paragraphs translation".'],
    'limit' => ['type' => 'integer', 'description' => 'Max results (default 20, max 100).'],
  ];

  /**
   * {@inheritdoc}
   */
  public const array REQUIRED = ['query'];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'search:arch';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $query = mb_substr(self::stringArg($arguments, 'query'), 0, 500);
    if (DocsIndex::words($query) === []) {
      return $this->fail('Provide a non-empty "query": droost search:arch "<words>".');
    }
    $limit = self::intArg($arguments, 'limit', 20, 1, 100);
    $pdo = $site->pdo();
    $docs = $pdo === NULL ? NULL : new DocsIndex($pdo);
    if ($docs !== NULL && $docs->exists()) {
      $hits = $docs->search($query, $limit);
      $source = 'index';
    }
    else {
      $hits = self::grep((new DocsCollector($site->settings, $site->inventory()))->collect(), $query, $limit);
      $source = 'files (no docs index: `droost code:index` builds it)';
    }
    return $this->succeed(
      sprintf('%d document(s) match "%s", from the %s.', count($hits), $query, $source),
      ['query' => $query, 'source' => $source, 'results' => $hits],
    );
  }

  /**
   * Searches documents in memory: every word must appear.
   *
   * @param list<array{path: string, title: string, kind: string, module: string, body: string}> $docs
   *   The documents.
   * @param string $query
   *   The query.
   * @param int $limit
   *   The most results.
   *
   * @return list<array{path: string, title: string, kind: string, module: string, snippet: string}>
   *   The matches, the most occurrences first.
   */
  public static function grep(array $docs, string $query, int $limit): array {
    $words = DocsIndex::words($query);
    $scored = [];
    foreach ($docs as $doc) {
      $text = mb_strtolower($doc['title'] . "\n" . $doc['body']);
      $score = 0;
      foreach ($words as $word) {
        $n = substr_count($text, $word);
        if ($n === 0) {
          continue 2;
        }
        $score += $n;
      }
      $at = (int) stripos($doc['body'], $words[0]);
      $snippet = trim((string) preg_replace('/\s+/', ' ', mb_substr($doc['body'], max(0, $at - 80), 200)));
      $scored[] = [
        'score' => $score,
        'hit' => [
          'path' => $doc['path'],
          'title' => $doc['title'],
          'kind' => $doc['kind'],
          'module' => $doc['module'],
          'snippet' => $snippet,
        ],
      ];
    }
    usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
    return array_map(static fn (array $s): array => $s['hit'], array_slice($scored, 0, $limit));
  }

}
