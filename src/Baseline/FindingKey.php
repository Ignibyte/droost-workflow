<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

/**
 * The identity of one finding, stable across line shifts, changed by edits.
 *
 * The owner's decision for D71 (2026-09-07): inherited debt stays inherited
 * until the LINE carrying it changes — touching a file does not make the
 * rest of its debt yours. So a finding is keyed by the file, the rule, the
 * message and a hash of the offending line's TEXT, never by its line number:
 * a line that shifts down because something was inserted above keeps its
 * text and therefore its key; a line that was edited has a new text and the
 * finding on it is new. The line number at adoption is recorded beside the
 * key for a human reading the file, and for nothing else.
 */
final class FindingKey {

  /**
   * The key for a finding.
   *
   * @param string $root
   *   The project root, where the file is read from.
   * @param string $file
   *   The file, project-relative.
   * @param string $rule
   *   The rule or sniff id (phpcs "source", eslint ruleId, stylelint rule).
   * @param string $message
   *   The tool's message.
   * @param int $line
   *   The line the finding is on right now.
   *
   * @return string
   *   A hex digest.
   */
  public static function of(string $root, string $file, string $rule, string $message, int $line): string {
    return sha1(implode("\0", [
      $file,
      $rule,
      $message,
      sha1(self::lineText($root, $file, $line)),
    ]));
  }

  /**
   * The trimmed text of one line of a file, or '' when it cannot be read.
   *
   * Trimmed so a re-indent alone does not turn inherited debt into new debt:
   * indentation is what a formatter changes wholesale, and a finding that
   * survives a formatter is the same finding.
   *
   * @param string $root
   *   The project root.
   * @param string $file
   *   The file, project-relative.
   * @param int $line
   *   The 1-based line number.
   *
   * @return string
   *   The line's text, trimmed.
   */
  public static function lineText(string $root, string $file, int $line): string {
    $path = rtrim($root, '/') . '/' . ltrim($file, '/');
    if ($line < 1 || !is_file($path)) {
      return '';
    }
    $handle = @fopen($path, 'r');
    if ($handle === FALSE) {
      return '';
    }
    $current = 0;
    $text = '';
    while (($read = fgets($handle)) !== FALSE) {
      $current++;
      if ($current === $line) {
        $text = trim($read);
        break;
      }
    }
    fclose($handle);
    return $text;
  }

  /**
   * A project-relative path for a tool's absolute one.
   *
   * The tools print absolute paths; the baseline must not carry the checkout
   * location, or the same tree cloned elsewhere would read as all-new debt.
   *
   * @param string $root
   *   The project root.
   * @param string $path
   *   The path as the tool printed it.
   *
   * @return string
   *   The project-relative path when the file is under the root, else as
   *   given with any leading "./" removed.
   */
  public static function relative(string $root, string $path): string {
    $root = rtrim($root, '/') . '/';
    if (str_starts_with($path, $root)) {
      return substr($path, strlen($root));
    }
    return str_starts_with($path, './') ? substr($path, 2) : $path;
  }

}
