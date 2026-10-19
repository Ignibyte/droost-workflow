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
use Droost\Workflow\Knowledge\Embedding\HttpEmbeddingBackend;
use Droost\Workflow\Knowledge\Embedding\SemanticIndexer;

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
 * When droost.yml's `search.embeddings` names an endpoint, the code's chunks
 * are embedded beside them (SemanticIndexer) and `search:code` adds
 * semantic matches; otherwise the index is lexical, and says so.
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
   * @param \Droost\Workflow\Knowledge\Embedding\HttpEmbeddingBackend|null $embeddings
   *   The embeddings backend; NULL builds it from the settings.
   */
  public function __construct(
    private readonly KnowledgeSettings $settings,
    private readonly ?HttpEmbeddingBackend $embeddings = NULL,
  ) {}

  /**
   * Indexes the project.
   *
   * @param bool $full
   *   Rebuild from nothing rather than re-parse what changed.
   *
   * @return array<string, int|string>
   *   What was done: file, symbol, edge and document counts, the mode, the
   *   index's path, how long it took, and the semantic layer's state
   *   (`semantic`: off, built, incomplete or unavailable, with
   *   `semantic_reason`, `vectors` and `embedded_files`).
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
    $before = self::meta($pdo);
    $docroot = $this->settings->docroot;
    $discovery = new RepoSourceDiscovery($docroot);
    $indexer = new RepoIndexer(
      $discovery,
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

    $backend = $this->embeddings ?? new HttpEmbeddingBackend($this->settings->embeddings());
    $semantic = (new SemanticIndexer($pdo, $backend, $docroot))->index(
      $backend->isConfigured() ? $discovery->files() : [],
      $full,
      $before,
    );

    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::META . " (key TEXT PRIMARY KEY, value TEXT NOT NULL DEFAULT '')");
    if ($semantic['status'] !== 'built') {
      $pdo->exec('DELETE FROM ' . self::META . " WHERE key IN ('embeddings_model', 'embeddings_dimension')");
    }
    $meta = $pdo->prepare('INSERT OR REPLACE INTO ' . self::META . ' (key, value) VALUES (?, ?)');
    foreach ([
      'indexed_at' => date('c'),
      'docroot' => $docroot,
      'indexer' => 'droost code:index',
      'indexer_version' => (string) RepoIndexer::INDEXER_VERSION,
      'embeddings' => $semantic['status'],
      'embeddings_reason' => $semantic['reason'],
    ] + ($semantic['status'] === 'built' ? [
      // Only a complete layer records what it was built with: an incomplete
      // run must force the next one to rebuild rather than trust it.
      'embeddings_model' => $semantic['model'],
      'embeddings_dimension' => (string) $semantic['dimension'],
    ] : []) as $key => $value) {
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
      'semantic' => $semantic['status'],
      'semantic_reason' => $semantic['reason'],
      'vectors' => $semantic['vectors'],
      'embedded_files' => $semantic['embedded_files'],
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
