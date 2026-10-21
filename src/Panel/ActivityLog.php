<?php

declare(strict_types=1);

namespace Droost\Workflow\Panel;

use Droost\Workflow\State\RunStateStore;

/**
 * What the agent says it is doing: `droost workflow:say "<text>"`.
 *
 * The panel's Activity tab reads it. It is the agent's own account, kept
 * apart from the evidence store on purpose: a line here proves nothing, and
 * the panel says so beside it. What droost measured is the Gates tab.
 *
 * One JSON line per entry in `activity.jsonl` in the state directory, each
 * stamped with the open run (or none) and the time.
 */
final class ActivityLog {

  /**
   * The file, in the state directory.
   */
  public const FILE = 'activity.jsonl';

  /**
   * At most this many characters in an entry's text, and in its detail.
   */
  public const MAX_TEXT = 200;
  public const MAX_DETAIL = 400;

  /**
   * Constructs an ActivityLog.
   *
   * @param string $projectRoot
   *   The project root.
   */
  public function __construct(private readonly string $projectRoot) {}

  /**
   * Appends one entry.
   *
   * @param string $text
   *   What the agent is doing, in a few words.
   * @param string|null $detail
   *   A file, a count, a name: what makes the line specific.
   * @param string|null $runId
   *   The open run, or NULL.
   * @param string $at
   *   The time, ISO 8601.
   *
   * @return bool
   *   Whether the line was written.
   */
  public function append(string $text, ?string $detail, ?string $runId, string $at): bool {
    $dir = (new RunStateStore($this->projectRoot))->directory();
    if (!is_dir($dir) && !@mkdir($dir, 0775, TRUE) && !is_dir($dir)) {
      return FALSE;
    }
    $line = json_encode([
      'at' => $at,
      'run' => $runId,
      'text' => mb_substr(trim($text), 0, self::MAX_TEXT),
      'detail' => $detail === NULL || trim($detail) === '' ? NULL : mb_substr(trim($detail), 0, self::MAX_DETAIL),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    return is_string($line) && @file_put_contents($dir . '/' . self::FILE, $line . "\n", FILE_APPEND | LOCK_EX) !== FALSE;
  }

  /**
   * The entries for one run (or for no run), oldest first.
   *
   * @param string|null $runId
   *   The run, or NULL for the entries made with none open.
   * @param int $limit
   *   The most recent entries kept.
   *
   * @return list<array{at: string, text: string, detail: string|null}>
   *   The entries.
   */
  public function forRun(?string $runId, int $limit = 50): array {
    $path = (new RunStateStore($this->projectRoot))->directory() . '/' . self::FILE;
    $lines = is_file($path) ? @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    $entries = [];
    foreach ($lines === FALSE ? [] : $lines as $line) {
      $row = json_decode($line, TRUE);
      if (!is_array($row) || ($row['run'] ?? NULL) !== $runId || !is_string($row['text'] ?? NULL)) {
        continue;
      }
      $entries[] = [
        'at' => is_string($row['at'] ?? NULL) ? $row['at'] : '',
        'text' => $row['text'],
        'detail' => is_string($row['detail'] ?? NULL) ? $row['detail'] : NULL,
      ];
    }

    return array_slice($entries, -$limit);
  }

}
