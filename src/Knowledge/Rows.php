<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

/**
 * The rows a query returned, as strings.
 *
 * The index holds text and integers; a reader wants them as one type. A
 * statement that failed to prepare or run is no rows.
 */
final class Rows {

  /**
   * Every row, each column's value as a string ('' for NULL).
   *
   * @param \PDOStatement|false $statement
   *   An executed statement.
   *
   * @return list<array<string, string>>
   *   The rows.
   */
  public static function all(\PDOStatement|false $statement): array {
    if ($statement === FALSE) {
      return [];
    }
    $out = [];
    foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      if (!is_array($row)) {
        continue;
      }
      $clean = [];
      foreach ($row as $key => $value) {
        $clean[(string) $key] = is_scalar($value) ? (string) $value : '';
      }
      $out[] = $clean;
    }
    return $out;
  }

  /**
   * The first column of every row, as strings.
   *
   * @param \PDOStatement|false $statement
   *   An executed statement.
   *
   * @return list<string>
   *   The values.
   */
  public static function column(\PDOStatement|false $statement): array {
    if ($statement === FALSE) {
      return [];
    }
    $out = [];
    foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $value) {
      $out[] = is_scalar($value) ? (string) $value : '';
    }
    return $out;
  }

  /**
   * One value: the first row's first column; NULL when there is no row.
   *
   * @param \PDOStatement|false $statement
   *   An executed statement.
   *
   * @return string|null
   *   The value.
   */
  public static function one(\PDOStatement|false $statement): ?string {
    if ($statement === FALSE) {
      return NULL;
    }
    $value = $statement->fetchColumn();
    $statement->closeCursor();
    return $value === FALSE || !is_scalar($value) ? NULL : (string) $value;
  }

}
