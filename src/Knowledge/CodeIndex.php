<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\State\RunStateStore;

/**
 * Droost's code index, read with no Drupal: one SQLite file.
 *
 * Droost writes its search index (the file manifest, the symbol graph) to
 * `<state dir>/code-index.sqlite` through droost-engine's SQLite stores, so
 * a process that boots no Drupal can read what droost indexed: the
 * `vendor/bin/droost` tools, and `plan_grounded` resolving a plan's
 * citations. The schema is the engine's (`droost_search_symbol`,
 * `droost_search_edge`, `droost_search_file`); paths in it are
 * docroot-relative.
 *
 * Opened read-only: the writer is droost, never a reader. The file keeps a
 * rollback journal, never WAL, so one file is the whole index and a read-only
 * open needs nothing beside it. An index that is absent, or will not open,
 * is said, never mistaken for an empty one.
 */
final class CodeIndex {

  /**
   * The index's file name, in the state directory.
   */
  public const FILE = 'code-index.sqlite';

  /**
   * The symbols table.
   */
  private const SYMBOLS = 'droost_search_symbol';

  /**
   * The file manifest table.
   */
  private const FILES = 'droost_search_file';

  /**
   * Constructs a CodeIndex.
   *
   * @param \PDO|null $pdo
   *   The open index, or NULL when there is none to read.
   * @param string $status
   *   What was found: `ready`, `absent`, or why it could not be read.
   */
  public function __construct(
    private readonly ?\PDO $pdo,
    public readonly string $status,
  ) {}

  /**
   * The index's path for a project, absolute.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return string
   *   The path, whether or not the file exists.
   */
  public static function path(string $projectRoot): string {
    $root = rtrim($projectRoot, '/');

    return $root . '/' . RunStateStore::resolveStateDir($root) . '/' . self::FILE;
  }

  /**
   * Opens a project's index read-only, or says why there is none.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return self
   *   The index.
   */
  public static function open(string $projectRoot): self {
    return self::openFile(self::path($projectRoot));
  }

  /**
   * Opens an index file read-only, or says why there is none.
   *
   * For a writer that keeps its index somewhere else than the project's
   * state directory (droost's test sites do).
   *
   * @param string $path
   *   The index file, absolute.
   *
   * @return self
   *   The index.
   */
  public static function openFile(string $path): self {
    if (!is_file($path)) {
      return new self(NULL, 'absent');
    }
    try {
      $pdo = new \PDO('sqlite:' . $path, NULL, NULL, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
      ]);
      $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'");
      $names = $tables === FALSE ? [] : $tables->fetchAll(\PDO::FETCH_COLUMN);
      if (!in_array(self::SYMBOLS, $names, TRUE)) {
        return new self(NULL, 'unreadable: it holds no symbol table');
      }
    }
    catch (\Throwable $e) {
      return new self(NULL, 'unreadable: ' . $e->getMessage());
    }

    return new self($pdo, 'ready');
  }

  /**
   * Whether there is an index to ask.
   *
   * @return bool
   *   TRUE when it opened.
   */
  public function ready(): bool {
    return $this->pdo !== NULL;
  }

  /**
   * How many indexed symbols a name is: the whole name or its last segment.
   *
   * A symbol, not a substring: `node` must not find `NodeReader`, and
   * `none: hockey` must not fail on `myhockey_roster`. Narrowed in SQL, then
   * decided here. A module's machine name counts when no symbol does.
   *
   * @param string $needle
   *   The name.
   *
   * @return int
   *   The match count; 0 when there is no index.
   */
  public function symbolMatches(string $needle): int {
    if ($needle === '' || $this->pdo === NULL) {
      return 0;
    }
    try {
      $statement = $this->pdo->prepare(
        'SELECT fqcn FROM ' . self::SYMBOLS . " WHERE fqcn LIKE ? ESCAPE '\\' OR module = ?",
      );
      $statement->execute(['%' . self::escapeLike($needle) . '%', $needle]);
      $count = 0;
      foreach ($statement->fetchAll(\PDO::FETCH_COLUMN) as $value) {
        if (!is_string($value)) {
          continue;
        }
        $segments = explode('\\', $value);
        if ($value === $needle || end($segments) === $needle) {
          $count++;
        }
      }
      if ($count > 0) {
        return $count;
      }
      $module = $this->pdo->prepare('SELECT COUNT(*) FROM ' . self::SYMBOLS . ' WHERE module = ?');
      $module->execute([$needle]);
      $n = $module->fetchColumn();

      return is_numeric($n) ? (int) $n : 0;
    }
    catch (\Throwable) {
      return 0;
    }
  }

  /**
   * Whether the index carries a file, by its docroot-relative path.
   *
   * Anchored: a path either IS the indexed row or ends it, so `foo.module`
   * never matches `barfoo.module`.
   *
   * @param string $relative
   *   Docroot-relative, no leading slash.
   *
   * @return bool
   *   TRUE when indexed.
   */
  public function indexedFile(string $relative): bool {
    if ($relative === '' || $this->pdo === NULL) {
      return FALSE;
    }
    try {
      $statement = $this->pdo->prepare(
        'SELECT COUNT(*) FROM ' . self::FILES . " WHERE file = ? OR file LIKE ? ESCAPE '\\'",
      );
      $statement->execute([$relative, '%/' . self::escapeLike($relative)]);
      $n = $statement->fetchColumn();

      return is_numeric($n) && (int) $n > 0;
    }
    catch (\Throwable) {
      // No manifest table: an index of symbols only.
      return FALSE;
    }
  }

  /**
   * A value made literal inside a LIKE pattern escaped with a backslash.
   *
   * @param string $value
   *   The value.
   *
   * @return string
   *   The escaped value.
   */
  private static function escapeLike(string $value): string {
    return strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
  }

}
