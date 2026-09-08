<?php

declare(strict_types=1);

namespace Droost\Workflow\Baseline;

/**
 * Reads each tool's machine output into one finding shape.
 *
 * Shared by the executor (to partition a run's findings into inherited and
 * new) and the writer (to record them at adoption), so the two cannot drift:
 * a finding is keyed exactly the same way whichever side computes it. Every
 * parser is best-effort — unrecognisable output yields an empty list, and the
 * caller falls back to the exit code, the verdict this package trusts most.
 */
final class FindingParsers {

  /**
   * The findings in a phpcs `--report=json` report.
   *
   * @param string $stdout
   *   The report.
   * @param string $root
   *   The project root, for relative paths and line text.
   *
   * @return list<array{file: string, rule: string, message: string, line: int, error: bool, key: string}>
   *   The findings.
   */
  public static function phpcs(string $stdout, string $root): array {
    $decoded = self::decode($stdout);
    $files = is_array($decoded['files'] ?? NULL) ? $decoded['files'] : [];
    $out = [];
    foreach ($files as $path => $entry) {
      if (!is_string($path) || !is_array($entry) || !is_array($entry['messages'] ?? NULL)) {
        continue;
      }
      $file = FindingKey::relative($root, $path);
      foreach ($entry['messages'] as $message) {
        if (!is_array($message)) {
          continue;
        }
        $out[] = self::finding(
          $root,
          $file,
          is_string($message['source'] ?? NULL) ? $message['source'] : '',
          is_string($message['message'] ?? NULL) ? $message['message'] : '',
          is_int($message['line'] ?? NULL) ? $message['line'] : 0,
          ($message['type'] ?? '') === 'ERROR',
        );
      }
    }
    return $out;
  }

  /**
   * The findings in an eslint `--format=json` report.
   *
   * @param string $stdout
   *   The report.
   * @param string $root
   *   The project root.
   *
   * @return list<array{file: string, rule: string, message: string, line: int, error: bool, key: string}>
   *   The findings.
   */
  public static function eslint(string $stdout, string $root): array {
    $decoded = self::decode($stdout);
    $out = [];
    foreach ($decoded as $entry) {
      if (!is_array($entry) || !is_string($entry['filePath'] ?? NULL) || !is_array($entry['messages'] ?? NULL)) {
        continue;
      }
      $file = FindingKey::relative($root, $entry['filePath']);
      foreach ($entry['messages'] as $message) {
        if (!is_array($message)) {
          continue;
        }
        $out[] = self::finding(
          $root,
          $file,
          is_string($message['ruleId'] ?? NULL) ? $message['ruleId'] : '',
          is_string($message['message'] ?? NULL) ? $message['message'] : '',
          is_int($message['line'] ?? NULL) ? $message['line'] : 0,
          ($message['severity'] ?? 0) === 2,
        );
      }
    }
    return $out;
  }

  /**
   * The findings in a stylelint `--formatter=json` report.
   *
   * @param string $stdout
   *   The report.
   * @param string $root
   *   The project root.
   *
   * @return list<array{file: string, rule: string, message: string, line: int, error: bool, key: string}>
   *   The findings.
   */
  public static function stylelint(string $stdout, string $root): array {
    $decoded = self::decode($stdout);
    $out = [];
    foreach ($decoded as $entry) {
      if (!is_array($entry) || !is_string($entry['source'] ?? NULL) || !is_array($entry['warnings'] ?? NULL)) {
        continue;
      }
      $file = FindingKey::relative($root, $entry['source']);
      foreach ($entry['warnings'] as $warning) {
        if (!is_array($warning)) {
          continue;
        }
        $out[] = self::finding(
          $root,
          $file,
          is_string($warning['rule'] ?? NULL) ? $warning['rule'] : '',
          is_string($warning['text'] ?? NULL) ? $warning['text'] : '',
          is_int($warning['line'] ?? NULL) ? $warning['line'] : 0,
          ($warning['severity'] ?? '') === 'error',
        );
      }
    }
    return $out;
  }

  /**
   * The files `prettier --check` reports as unformatted.
   *
   * Prettier prints "[warn] path" per file (to stderr in recent versions,
   * stdout in older ones); either stream is read.
   *
   * @param string $stdout
   *   Standard output.
   * @param string $stderr
   *   Standard error.
   * @param string $root
   *   The project root.
   *
   * @return list<string>
   *   Project-relative paths, sorted.
   */
  public static function prettierUnformatted(string $stdout, string $stderr, string $root): array {
    $files = [];
    foreach (preg_split('/\R/', $stdout . "\n" . $stderr) ?: [] as $line) {
      if (preg_match('/^\[warn\]\s+(\S.*?)\s*$/', $line, $m) === 1
        && !str_contains($m[1], 'Code style issues')) {
        $files[] = FindingKey::relative($root, $m[1]);
      }
    }
    $files = array_values(array_unique($files));
    sort($files);
    return $files;
  }

  /**
   * How many errors phpstan's `--error-format=json` reports.
   *
   * @param string $stdout
   *   The report.
   *
   * @return int|null
   *   The total (file errors plus general errors), or NULL when the output is
   *   not that report.
   */
  public static function phpstanErrorCount(string $stdout): ?int {
    $decoded = self::decode($stdout);
    $totals = is_array($decoded['totals'] ?? NULL) ? $decoded['totals'] : NULL;
    if ($totals === NULL) {
      return NULL;
    }
    $fileErrors = $totals['file_errors'] ?? 0;
    $errors = $totals['errors'] ?? 0;
    return (is_int($fileErrors) ? $fileErrors : 0) + (is_int($errors) ? $errors : 0);
  }

  /**
   * The findings a phpstan-baseline.neon records, summed from its counts.
   *
   * The generated file uses NEON with tab indentation, which no YAML parser
   * accepts, so the count lines are read directly: every ignoreErrors entry
   * carries `count: N`.
   *
   * @param string $neon
   *   The baseline file's content.
   *
   * @return int
   *   The recorded total.
   */
  public static function phpstanBaselineCount(string $neon): int {
    if (preg_match_all('/^\s*count:\s*(\d+)\s*$/m', $neon, $matches) === 0) {
      return 0;
    }
    return array_sum(array_map(intval(...), $matches[1]));
  }

  /**
   * The Lines coverage percentage from phpunit's text summary.
   *
   * @param string $stdout
   *   Standard output.
   *
   * @return float|null
   *   The percentage, or NULL when none was printed.
   */
  public static function coveragePercent(string $stdout): ?float {
    return preg_match('/^\s*Lines:\s+([0-9.]+)%/m', $stdout, $m) === 1 ? (float) $m[1] : NULL;
  }

  /**
   * The Mutation Score Indicator from infection's summary.
   *
   * @param string $stdout
   *   Standard output.
   *
   * @return float|null
   *   The percentage, or NULL when none was printed.
   */
  public static function msiPercent(string $stdout): ?float {
    return preg_match('/Mutation Score Indicator \(MSI\):\s*([0-9.]+)%/', $stdout, $m) === 1 ? (float) $m[1] : NULL;
  }

  /**
   * One finding in the shared shape, keyed.
   *
   * @param string $root
   *   The project root.
   * @param string $file
   *   The file, project-relative.
   * @param string $rule
   *   The rule id.
   * @param string $message
   *   The message.
   * @param int $line
   *   The line.
   * @param bool $error
   *   Whether the tool ranks it an error (warnings never fail a gate).
   *
   * @return array{file: string, rule: string, message: string, line: int, error: bool, key: string}
   *   The finding.
   */
  private static function finding(string $root, string $file, string $rule, string $message, int $line, bool $error): array {
    return [
      'file' => $file,
      'rule' => $rule,
      'message' => $message,
      'line' => $line,
      'error' => $error,
      'key' => FindingKey::of($root, $file, $rule, $message, $line),
    ];
  }

  /**
   * Decodes JSON to an array, or [] when it is not one.
   *
   * @param string $json
   *   The text.
   *
   * @return array<array-key, mixed>
   *   The decoded document.
   */
  private static function decode(string $json): array {
    if (trim($json) === '') {
      return [];
    }
    try {
      $decoded = json_decode($json, TRUE, 64, JSON_THROW_ON_ERROR);
    }
    catch (\JsonException) {
      return [];
    }
    return is_array($decoded) ? $decoded : [];
  }

}
