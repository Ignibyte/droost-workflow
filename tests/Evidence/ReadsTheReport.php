<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Evidence;

/**
 * Reads the rendered evaluation by column HEADING, never by column number.
 *
 * Four tests read §4's gate table by counting pipes and indexing a hard-coded
 * column — `count($cells) === 11` for "nine columns plus the empty ends", then
 * `$cells[8]`. Adding a tenth column broke all four at once, in the shape that
 * teaches nothing: `Undefined array key "phpcs"`, from a helper that had
 * quietly stopped recognising the table it parses.
 *
 * A test that knows a column by name survives the next column, and fails
 * loudly when the heading is RENAMED — which is the fact worth knowing —
 * rather than silently reading the neighbouring cell.
 */
trait ReadsTheReport {

  /**
   * One cell of §4's gate table, found by its column heading.
   *
   * @param string $report
   *   The rendered evaluation.
   * @param string $gate
   *   The gate name, as §4 prints it inside backticks.
   * @param string $header
   *   The column heading, exactly as rendered.
   *
   * @return string|null
   *   The cell, or NULL when the table or the row is not there.
   */
  protected function gateCell(string $report, string $gate, string $header): ?string {
    $index = NULL;
    $width = NULL;
    $found = NULL;
    foreach (explode("\n", $report) as $line) {
      $cells = array_map(trim(...), explode('|', $line));
      if ($index === NULL) {
        $at = array_search($header, $cells, TRUE);
        if ($at !== FALSE) {
          $index = (int) $at;
          $width = count($cells);
        }
        continue;
      }
      // The width of the header row, so §3's narrower gate table — which
      // opens with the same two cells — cannot answer for §4's.
      if (count($cells) === $width && $cells[1] === '`' . $gate . '`') {
        $found = $cells[$index] ?? NULL;
      }
    }

    return $found;
  }

}
