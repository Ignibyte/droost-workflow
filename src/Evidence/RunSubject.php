<?php

declare(strict_types=1);

namespace Droost\Workflow\Evidence;

use Droost\Workflow\Gate\ShellGateExecutor;

/**
 * The fingerprint of what a run's code and test gates measured (0.11).
 *
 * In the strict flow complete re-runs every gate, so an edit made after code
 * is measured before the run ends. The fast flow's complete runs none, and its
 * test runs only the browser, so an edit made at test or at complete would
 * stand under code's green unmeasured: a green that could not have failed.
 * This fingerprint, taken each time those gates run, is what says the code
 * moved. The recorded browser run is held to the same fingerprint.
 *
 * Every changed file counts but three kinds. Markdown is the documentation
 * complete writes (the wiki, the evidence); `droost/` is the workflow's own
 * output (the record, parity's captures, the tickets); and the test tools'
 * reports are what a run of them writes, never what one reads. For code's
 * subject the browser specs do not count either: they are test's work.
 */
final class RunSubject {

  /**
   * The subject code's gates measure.
   */
  public const CODE = 'code';

  /**
   * The subject test's gates measure: code's, and the browser specs.
   */
  public const TEST = 'test';

  /**
   * Where the test tools write their reports, by default.
   *
   * And where the browser tier writes its snapshots (F-203): Playwright
   * MCP keeps `.playwright-mcp/` in the project root. P7 run 16's agent
   * looked at a page after code's gates ran and was sent back to code for
   * the folder; it deleted the folder, and was sent back again from
   * complete. Neither was a change to anything a gate measures.
   */
  private const TOOL_OUTPUT = [
    'test-results/',
    'playwright-report/',
    'blob-report/',
    'coverage/',
    '.phpunit.cache/',
    '.phpunit.result.cache',
    '.playwright-mcp/',
  ];

  /**
   * The fingerprint, or NULL when the repository cannot say what changed.
   *
   * @param string $projectRoot
   *   The repository.
   * @param list<string>|null $changed
   *   The files the run changed, or NULL when unknown.
   * @param string $which
   *   self::CODE or self::TEST.
   *
   * @return string|null
   *   A digest of the paths and their content, or NULL.
   */
  public static function of(string $projectRoot, ?array $changed, string $which): ?string {
    if ($changed === NULL) {
      return NULL;
    }
    $paths = self::measured($changed, $which);
    sort($paths);
    $existing = array_values(array_filter(
      $paths,
      static fn (string $path): bool => file_exists(rtrim($projectRoot, '/') . '/' . $path),
    ));

    // The paths as well as their content: a deleted file changes the list and
    // hashes to nothing.
    return hash('xxh128', implode("\n", $paths) . "\0" . (SubjectHasher::hash($projectRoot, $existing) ?? ''));
  }

  /**
   * The changed files a subject covers.
   *
   * @param list<string> $changed
   *   The files the run changed.
   * @param string $which
   *   self::CODE or self::TEST.
   *
   * @return list<string>
   *   The files, normalised.
   */
  public static function measured(array $changed, string $which): array {
    $out = [];
    foreach ($changed as $path) {
      $path = ltrim(str_replace('\\', '/', trim($path)), '/');
      if ($path === '' || str_starts_with($path, 'droost/') || preg_match('/\.md$/i', $path) === 1) {
        continue;
      }
      foreach (self::TOOL_OUTPUT as $output) {
        if ($path === rtrim($output, '/') || str_starts_with($path, $output) || str_contains($path, '/' . $output)) {
          continue 2;
        }
      }
      if ($which === self::CODE && preg_match(ShellGateExecutor::SPEC_FILE, $path) === 1) {
        continue;
      }
      $out[] = $path;
    }

    return array_values(array_unique($out));
  }

}
