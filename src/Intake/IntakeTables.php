<?php

declare(strict_types=1);

namespace Droost\Workflow\Intake;

/**
 * The tables an intake's files hold, read by their headers.
 *
 * Each row is keyed by its column's header, lower-cased, so a column is found
 * by what it is called and not where it sits; a file that adds a column of
 * its own keeps every check reading the ones it names.
 */
final class IntakeTables {

  /**
   * The rows of the first table under a heading.
   *
   * @param string $text
   *   The file.
   * @param string $heading
   *   The heading, `## Tooling plan`; '' for the file's first table.
   *
   * @return list<array<string, string>>
   *   The rows, each cell by its header.
   */
  public static function rows(string $text, string $heading = ''): array {
    if ($heading !== '') {
      $quoted = preg_quote($heading, '/');
      if (preg_match('/^' . $quoted . '\b[^\n]*\n(.*?)(?=^#{1,2} |\z)/msi', $text, $m) !== 1) {
        return [];
      }
      $text = $m[1];
    }
    $headers = NULL;
    $rows = [];
    $fenced = FALSE;
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
      $line = trim($line);
      if (preg_match('/^(?:`{3,}|~{3,})/', $line) === 1) {
        $fenced = !$fenced;
        continue;
      }
      if ($fenced) {
        continue;
      }
      if (!str_starts_with($line, '|')) {
        if ($headers !== NULL) {
          break;
        }
        continue;
      }
      if (preg_match('/^[\s|:\-]+$/', $line) === 1) {
        continue;
      }
      $cells = array_map('trim', explode('|', trim($line, '|')));
      if ($headers === NULL) {
        $headers = array_map(static fn (string $h): string => strtolower(trim(str_replace(['*', '`'], '', $h))), $cells);
        continue;
      }
      $row = [];
      foreach ($headers as $i => $header) {
        $row[$header] = $cells[$i] ?? '';
      }
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * The audit ids a cell cites.
   *
   * @param string $cell
   *   The cell.
   *
   * @return list<string>
   *   `r:/…`, `p:/…` (with `#s1`, `#r1`, `#f1`), `d:…` and `frame`.
   */
  public static function citations(string $cell): array {
    preg_match_all('/(?<![\w:])(frame|[rp]:\/[^\s,;`)\]]*|d:[^\s,;`)\]]+)/', $cell, $m);
    return array_values(array_unique(array_map(static fn (string $id): string => rtrim($id, '.'), $m[1])));
  }

  /**
   * The numbers a cell names: `1, 2`, `rung 3`, or none for `—`.
   *
   * @param string $cell
   *   The cell.
   *
   * @return list<int>
   *   The numbers.
   */
  public static function numbers(string $cell): array {
    preg_match_all('/\d+/', $cell, $m);
    return array_values(array_map('intval', $m[0]));
  }

  /**
   * The routes and the frame a pages cell names.
   *
   * @param string $cell
   *   The cell: `/camps, /camps/:id`, `frame`.
   *
   * @return list<string>
   *   Each path, and `frame`.
   */
  public static function pages(string $cell): array {
    preg_match_all('/(?<![\w])(frame|\/[^\s,;`)]*)/', $cell, $m);
    return array_values(array_unique($m[1]));
  }

  /**
   * Text compared as a person would read it: case, space and quotes aside.
   *
   * @param string $text
   *   The text.
   *
   * @return string
   *   The text normalised.
   */
  public static function normal(string $text): string {
    $text = str_replace(['`', '*', '"', '“', '”', "'", '’'], '', $text);
    return strtolower(trim((string) preg_replace('/\s+/u', ' ', $text), " .?!:;"));
  }

}
