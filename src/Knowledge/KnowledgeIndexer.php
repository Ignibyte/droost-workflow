<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Engine\Search\Graph\PhpGraphExtractor;
use Droost\Engine\Search\Graph\SqliteCodeGraphStorage;
use Droost\Engine\Search\Graph\YamlGraphExtractor;
use Droost\Engine\Search\RepoIndexer;
use Droost\Engine\Search\RepoSourceDiscovery;
use Droost\Engine\Search\Storage\SqliteFileManifest;
use Droost\Workflow\Config\StoragePaths;

/**
 * Builds the code index from files: `droost code:index`.
 *
 * The symbol graph and the file manifest are droost-engine's, written by its
 * repository indexer over the docroot (core, contrib and custom alike), with
 * hook edges gated on the names the installation's `*.api.php` files
 * declare. The documentation's text goes beside them for `search:arch`. The
 * bookkeeping a booted site kept in its state (when the index was built,
 * over what) is kept in the index file itself.
 *
 * Paths in the index are docroot-relative, as the readers expect. The file
 * keeps SQLite's rollback journal, never WAL: one file is the whole index.
 */
final class KnowledgeIndexer {

  /**
   * The bookkeeping table.
   */
  public const string META = 'droost_index_meta';

  /**
   * Constructs a KnowledgeIndexer.
   *
   * @param \Droost\Workflow\Knowledge\KnowledgeSettings $settings
   *   Where the project and its docroot are.
   */
  public function __construct(private readonly KnowledgeSettings $settings) {}

  /**
   * Indexes the project.
   *
   * @param bool $full
   *   Rebuild from nothing rather than re-parse what changed.
   *
   * @return array<string, int|string>
   *   What was done: file, symbol, edge and document counts, the mode, the
   *   index's path, and how long it took.
   */
  public function index(bool $full = FALSE): array {
    $path = StoragePaths::index($this->settings->projectRoot);
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, TRUE) && !is_dir($dir)) {
      throw new \RuntimeException(sprintf('Could not create %s for the code index.', $dir));
    }
    $started = microtime(TRUE);
    $pdo = new \PDO('sqlite:' . $path, NULL, NULL, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('PRAGMA journal_mode = DELETE');

    $graph = new SqliteCodeGraphStorage($pdo);
    $manifest = new SqliteFileManifest($pdo);
    $graph->ensureSchema();
    $manifest->ensureSchema();

    // Rows::one closes the cursor: an open one holds a lock the docs table's
    // DROP below would meet.
    $version = (int) Rows::one($pdo->query('PRAGMA user_version'));
    $docroot = $this->settings->docroot;
    $indexer = new RepoIndexer(
      new RepoSourceDiscovery($docroot),
      new PhpGraphExtractor(HookNameScanner::hookNames($docroot)),
      new YamlGraphExtractor(),
      $manifest,
      $graph,
      $docroot,
    );
    $report = $indexer->index($full || $version !== RepoIndexer::INDEXER_VERSION);
    $pdo->exec('PRAGMA user_version = ' . RepoIndexer::INDEXER_VERSION);

    $inventory = new ExtensionInventory($this->settings);
    $documents = (new DocsIndex($pdo))->replace((new DocsCollector($this->settings, $inventory))->collect());

    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::META . " (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    $meta = $pdo->prepare('INSERT OR REPLACE INTO ' . self::META . ' (key, value) VALUES (?, ?)');
    foreach ([
      'indexed_at' => date('c'),
      'docroot' => $docroot,
      'indexer' => 'droost code:index',
      'indexer_version' => (string) RepoIndexer::INDEXER_VERSION,
    ] as $key => $value) {
      $meta->execute([$key, $value]);
    }

    return [
      'index' => $path,
      'mode' => (string) $report['mode'],
      'files' => (int) $report['files'],
      'symbols' => (int) $report['symbols'],
      'edges' => (int) $report['edges'],
      'parsed' => (int) $report['added'] + (int) $report['changed'],
      'removed' => (int) $report['removed'],
      'documents' => $documents,
      'seconds' => (string) round(microtime(TRUE) - $started, 1),
    ];
  }

  /**
   * The index's bookkeeping, or an empty map when there is none.
   *
   * @param \PDO $pdo
   *   An open index.
   *
   * @return array<string, string>
   *   Its keys and values.
   */
  public static function meta(\PDO $pdo): array {
    try {
      $rows = $pdo->query('SELECT key, value FROM ' . self::META);
    }
    catch (\Throwable) {
      return [];
    }
    $out = [];
    foreach (Rows::all($rows) as $row) {
      $out[$row['key'] ?? ''] = $row['value'] ?? '';
    }
    return $out;
  }

}
