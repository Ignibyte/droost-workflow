<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

/**
 * The documentation's text, in the code index: what `search:arch` asks.
 *
 * A plain text search, deliberately: SQLite's FTS5 over the documents
 * DocsCollector gathers, ranked by bm25. Where this SQLite has no FTS5, a
 * plain table answered with LIKE, which finds the same pages more slowly.
 * Not a graph: the structure is `code:tree`'s.
 */
final class DocsIndex {

  /**
   * The table.
   */
  public const string TABLE = 'droost_docs';

  /**
   * Constructs a DocsIndex.
   *
   * @param \PDO $pdo
   *   The index's connection.
   */
  public function __construct(private readonly \PDO $pdo) {
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  }

  /**
   * Whether the index holds the docs table.
   *
   * @return bool
   *   TRUE when it does.
   */
  public function exists(): bool {
    $statement = $this->pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE name = ?");
    $statement->execute([self::TABLE]);
    return (int) $statement->fetchColumn() > 0;
  }

  /**
   * Replaces every document.
   *
   * @param list<array{path: string, title: string, kind: string, module: string, body: string}> $docs
   *   The documents.
   *
   * @return int
   *   How many were written.
   */
  public function replace(array $docs): int {
    $this->pdo->exec('DROP TABLE IF EXISTS ' . self::TABLE);
    try {
      $this->pdo->exec('CREATE VIRTUAL TABLE ' . self::TABLE . ' USING fts5(path UNINDEXED, title, kind UNINDEXED, module UNINDEXED, body)');
    }
    catch (\PDOException) {
      $this->pdo->exec('CREATE TABLE ' . self::TABLE . " (path TEXT NOT NULL DEFAULT '', title TEXT NOT NULL DEFAULT '', kind TEXT NOT NULL DEFAULT '', module TEXT NOT NULL DEFAULT '', body TEXT NOT NULL DEFAULT '')");
    }
    $this->pdo->beginTransaction();
    try {
      $insert = $this->pdo->prepare('INSERT INTO ' . self::TABLE . ' (path, title, kind, module, body) VALUES (?, ?, ?, ?, ?)');
      foreach ($docs as $doc) {
        $insert->execute([$doc['path'], $doc['title'], $doc['kind'], $doc['module'], $doc['body']]);
      }
      $this->pdo->commit();
    }
    catch (\Throwable $e) {
      $this->pdo->rollBack();
      throw $e;
    }
    return count($docs);
  }

  /**
   * Searches the documents.
   *
   * @param string $query
   *   Words; each must appear. Punctuation is a separator.
   * @param int $limit
   *   The most results.
   *
   * @return list<array{path: string, title: string, kind: string, module: string, snippet: string}>
   *   The matches, best first.
   */
  public function search(string $query, int $limit): array {
    $words = self::words($query);
    if ($words === []) {
      return [];
    }
    if ($this->isFts()) {
      $match = implode(' ', array_map(static fn (string $w): string => '"' . str_replace('"', '""', $w) . '"', $words));
      $statement = $this->pdo->prepare(
        'SELECT path, title, kind, module, snippet(' . self::TABLE . ", 4, '[', ']', '…', 24) AS snippet FROM " . self::TABLE
        . ' WHERE ' . self::TABLE . ' MATCH ? ORDER BY bm25(' . self::TABLE . ') LIMIT ?'
      );
      $statement->execute([$match, $limit]);
    }
    else {
      $where = implode(' AND ', array_fill(0, count($words), "(title LIKE ? ESCAPE '\\' OR body LIKE ? ESCAPE '\\')"));
      $params = [];
      foreach ($words as $word) {
        $like = '%' . addcslashes($word, '%_\\') . '%';
        $params[] = $like;
        $params[] = $like;
      }
      $params[] = $limit;
      $statement = $this->pdo->prepare('SELECT path, title, kind, module, substr(body, 1, 200) AS snippet FROM ' . self::TABLE . ' WHERE ' . $where . ' LIMIT ?');
      $statement->execute($params);
    }
    $out = [];
    foreach (Rows::all($statement) as $row) {
      $out[] = [
        'path' => $row['path'] ?? '',
        'title' => $row['title'] ?? '',
        'kind' => $row['kind'] ?? '',
        'module' => $row['module'] ?? '',
        'snippet' => trim((string) preg_replace('/\s+/', ' ', $row['snippet'] ?? '')),
      ];
    }
    return $out;
  }

  /**
   * How many documents the table holds.
   *
   * @return int
   *   The count.
   */
  public function count(): int {
    return $this->exists() ? (int) Rows::one($this->pdo->query('SELECT COUNT(*) FROM ' . self::TABLE)) : 0;
  }

  /**
   * The words of a query.
   *
   * @param string $query
   *   The query.
   *
   * @return list<string>
   *   The words, lower-cased, at most twelve.
   */
  public static function words(string $query): array {
    $parts = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower($query)) ?: [];
    $words = array_values(array_filter($parts, static fn (string $w): bool => $w !== ''));
    return array_slice(array_values(array_unique($words)), 0, 12);
  }

  /**
   * Whether the table is FTS5's.
   *
   * @return bool
   *   TRUE for FTS5.
   */
  private function isFts(): bool {
    $statement = $this->pdo->prepare('SELECT sql FROM sqlite_master WHERE name = ?');
    $statement->execute([self::TABLE]);
    $sql = $statement->fetchColumn();
    return is_string($sql) && stripos($sql, 'fts5') !== FALSE;
  }

}
