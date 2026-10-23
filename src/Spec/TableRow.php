<?php

declare(strict_types=1);

namespace Droost\Workflow\Spec;

/**
 * One markdown table row, read into its cells.
 *
 * The spec's tables are read in two places, the contract and the freeze, and
 * each had its own copy of this; a pipe one learned to read as text the other
 * still split on, so a row could be frozen as one thing and judged as another.
 */
final class TableRow {

  /**
   * The cells of a row, outer pipes and padding removed.
   *
   * An escaped pipe (`\|`) is text (F-359), and so is a pipe inside a code
   * span: an
   * agent writes `int|string` in backticks as the type it is, and splitting
   * there moved every later cell one column right (F-365). GFM would split it;
   * the reader of a spec is droost, and the writer meant one cell.
   *
   * @param string $row
   *   The row, starting with `|`.
   *
   * @return list<string>
   *   The trimmed cells.
   */
  public static function cells(string $row): array {
    $inner = trim($row);
    $inner = (string) preg_replace('/^\|/', '', $inner);
    $inner = (string) preg_replace('/\|$/', '', $inner);
    $inner = str_replace('\\|', "\x00", $inner);
    // A code span opens and closes on runs of the same number of backticks.
    $inner = (string) preg_replace_callback(
      '/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/',
      static fn (array $m): string => $m[1] . str_replace('|', "\x00", $m[2]) . $m[1],
      $inner,
    );

    return array_map(
      static fn (string $cell): string => trim(str_replace("\x00", '|', $cell)),
      explode('|', $inner),
    );
  }

}
