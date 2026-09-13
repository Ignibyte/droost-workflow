<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

/**
 * Reads the evidence store's SQLite file directly, with the shapes stated.
 *
 * A test that inspects the store goes around the class it is testing on
 * purpose — asserting through `EvidenceStore` would pass on a store that never
 * wrote anything, because the same bug would be on both sides of the
 * assertion. But PDO hands back `mixed`, and `$row['state']` on `mixed` is an
 * assertion that would still pass if the row were a string.
 *
 * `PDO::query()` also returns `PDOStatement|false`, and a test that calls
 * `fetchAll()` on FALSE is a test that dies where it should fail with a
 * sentence. Both are narrowed here, once.
 */
trait ReadsTheStore {

  /**
   * Every row of a query.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param string $sql
   *   The query.
   * @param list<mixed> $args
   *   Bound parameters.
   *
   * @return list<array<string, mixed>>
   *   The rows.
   */
  private function storeRows(\PDO $pdo, string $sql, array $args = []): array {
    $statement = $pdo->prepare($sql);
    $this->assertNotFalse($statement, 'the query prepares: ' . $sql);
    $statement->execute($args);
    $rows = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $this->assertIsArray($row);
      $typed = [];
      foreach ($row as $column => $value) {
        $typed[(string) $column] = $value;
      }
      $rows[] = $typed;
    }

    return $rows;
  }

  /**
   * The first row of a query, or NULL when it matched nothing.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param string $sql
   *   The query.
   * @param list<mixed> $args
   *   Bound parameters.
   *
   * @return array<string, mixed>|null
   *   The row.
   */
  private function storeRow(\PDO $pdo, string $sql, array $args = []): ?array {
    return $this->storeRows($pdo, $sql, $args)[0] ?? NULL;
  }

  /**
   * The first column of the first row, as a string.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param string $sql
   *   The query.
   * @param list<mixed> $args
   *   Bound parameters.
   *
   * @return string
   *   The value, or the empty string when nothing matched.
   */
  private function storeValue(\PDO $pdo, string $sql, array $args = []): string {
    $row = $this->storeRow($pdo, $sql, $args);
    if ($row === NULL) {
      return '';
    }
    $value = reset($row);

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * The first column of the first row, as an integer.
   *
   * @param \PDO $pdo
   *   The connection.
   * @param string $sql
   *   The query.
   * @param list<mixed> $args
   *   Bound parameters.
   *
   * @return int
   *   The value, or zero.
   */
  private function storeCount(\PDO $pdo, string $sql, array $args = []): int {
    $value = $this->storeValue($pdo, $sql, $args);

    return is_numeric($value) ? (int) $value : 0;
  }

}
