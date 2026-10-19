<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Embedding;

use Droost\Engine\Search\Chunk;
use Droost\Engine\Search\Chunker\ChunkerRegistry;
use Droost\Engine\Search\Chunker\DocChunker;
use Droost\Engine\Search\Chunker\PhpChunker;
use Droost\Engine\Search\Chunker\TwigChunker;
use Droost\Engine\Search\VectorStore\SqliteVectorStore;
use Droost\Workflow\Knowledge\Rows;

/**
 * The index's semantic layer: chunks embedded and stored beside the graph.
 *
 * Built by `droost code:index` when droost.yml's `search.embeddings` names an
 * endpoint, over the project's custom code by default (`scope: all` for the
 * whole docroot). Incremental: a file is re-embedded when its content
 * changed, by a manifest of its own, and the whole layer is rebuilt when the
 * model or the dimension changed.
 *
 * Its failures keep what was there: a backend that answers nothing for most
 * chunks, or a dimension that matches no vector, leaves the previous vectors
 * in place and reports the layer incomplete, rather than replacing a
 * complete layer with an empty one. With no endpoint configured the layer is
 * removed: lexical search is then the whole answer, by design, and says so.
 */
final class SemanticIndexer {

  /**
   * The table recording which file's content each vector set describes.
   */
  public const string MANIFEST = 'droost_embed_manifest';

  /**
   * Chunks per request to the backend.
   */
  private const int BATCH = 32;

  /**
   * Above this share of chunks with no vector, the run keeps the old layer.
   */
  private const float MAX_FAIL_RATIO = 0.5;

  /**
   * The bookkeeping keys this layer writes.
   */
  public const array META_KEYS = ['embeddings', 'embeddings_model', 'embeddings_dimension', 'embeddings_reason'];

  /**
   * Constructs a SemanticIndexer.
   *
   * @param \PDO $pdo
   *   The open index, writable.
   * @param \Droost\Workflow\Knowledge\Embedding\HttpEmbeddingBackend $backend
   *   The embeddings backend.
   * @param string $docroot
   *   The docroot, absolute: file paths are relative to it.
   */
  public function __construct(
    private readonly \PDO $pdo,
    private readonly HttpEmbeddingBackend $backend,
    private readonly string $docroot,
  ) {}

  /**
   * Brings the semantic layer up to date with the files.
   *
   * @param array<int, array{path: string, module: string, type: string}> $files
   *   The indexable files, docroot-relative.
   * @param bool $full
   *   Re-embed everything.
   * @param array<string, string> $meta
   *   The index's bookkeeping as it stood before this run.
   *
   * @return array{status: string, reason: string, embedded_files: int, stored: int, skipped: int, vectors: int, model: string, dimension: int}
   *   `status` is off (not configured), built (complete), incomplete (kept
   *   the previous layer) or unavailable (configured but unusable).
   */
  public function index(array $files, bool $full, array $meta): array {
    $store = new SqliteVectorStore($this->pdo);
    $result = [
      'status' => 'off',
      'reason' => '',
      'embedded_files' => 0,
      'stored' => 0,
      'skipped' => 0,
      'vectors' => 0,
      'model' => $this->backend->model(),
      'dimension' => 0,
    ];
    if (!$this->backend->isConfigured()) {
      $store->delete();
      $this->pdo->exec('DROP TABLE IF EXISTS ' . self::MANIFEST);
      $result['reason'] = 'search.embeddings names no endpoint: lexical only';
      return $result;
    }
    $reason = $this->backend->unavailableReason();
    if ($reason !== '') {
      return ['status' => 'unavailable', 'reason' => $reason, 'vectors' => $store->count()] + $result;
    }
    $metaDimension = (int) ($meta['embeddings_dimension'] ?? 0);
    $configured = $this->backend->configuredDimension();
    $full = $full
      || ($meta['embeddings_model'] ?? '') !== $this->backend->model()
      || $metaDimension < 1
      || ($configured > 0 && $configured !== $metaDimension)
      || $store->count() === 0;

    $this->pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::MANIFEST . ' (file TEXT PRIMARY KEY, hash TEXT NOT NULL)');
    $stored = [];
    foreach (Rows::all($this->pdo->query('SELECT file, hash FROM ' . self::MANIFEST)) as $row) {
      $stored[(string) ($row['file'] ?? '')] = (string) ($row['hash'] ?? '');
    }

    $hashes = [];
    $byPath = [];
    foreach ($files as $file) {
      if (!in_array($file['type'], ['php', 'twig', 'doc'], TRUE) || !$this->inScope($file['path'])) {
        continue;
      }
      $content = @file_get_contents($this->docroot . '/' . $file['path']);
      if (!is_string($content)) {
        continue;
      }
      $hashes[$file['path']] = hash('xxh3', $content);
      $byPath[$file['path']] = $file + ['content' => $content];
    }
    $changed = array_keys(array_filter($hashes, static fn (string $hash, string $path): bool => ($stored[$path] ?? NULL) !== $hash, ARRAY_FILTER_USE_BOTH));
    $removed = array_keys(array_diff_key($stored, $hashes));

    if (!$full && $changed === []) {
      // Nothing to embed: the server is not asked, not even for its width.
      $delete = $this->pdo->prepare('DELETE FROM ' . self::MANIFEST . ' WHERE file = ?');
      foreach ($removed as $path) {
        $store->deleteByFile($path);
        $delete->execute([$path]);
      }
      return ['status' => 'built', 'dimension' => $metaDimension, 'vectors' => $store->count()] + $result;
    }

    $dimension = $this->backend->dimension();
    if ($dimension < 1) {
      $error = $this->backend->lastError() !== '' ? ': ' . $this->backend->lastError() : '';
      return [
        'status' => 'incomplete',
        'reason' => 'the model gave no usable dimension' . $error . '; the previous vectors were kept',
        'vectors' => $store->count(),
      ] + $result;
    }
    $result['dimension'] = $dimension;
    // A model that now answers at another width cannot share a store with
    // the vectors it gave before.
    $full = $full || $dimension !== $metaDimension;
    $toEmbed = $full ? array_keys($hashes) : $changed;
    $toDrop = $full ? [] : array_values(array_unique(array_merge(
      $removed,
      array_values(array_filter($changed, static fn (string $p): bool => isset($stored[$p]))),
    )));

    $registry = new ChunkerRegistry(new PhpChunker(), new DocChunker(), new TwigChunker());
    $chunks = [];
    foreach ($toEmbed as $path) {
      $file = $byPath[$path];
      foreach ($registry->chunk($file['type'], $file['content'], $path, $file['module']) as $chunk) {
        $chunks[] = $chunk;
      }
    }
    ['rows' => $rows, 'skipped' => $skipped, 'failed' => $failed] = $this->embed($chunks, $dimension);
    $result['skipped'] = $skipped;
    $total = count($chunks);
    $error = $this->backend->lastError() !== '' ? ' (' . $this->backend->lastError() . ')' : '';
    $kept = '';
    if ($total > 0 && $failed / $total > self::MAX_FAIL_RATIO) {
      $kept = sprintf('the backend gave no vector for %d of %d chunk(s)%s', $failed, $total, $error);
    }
    elseif ($total > 0 && $rows === []) {
      $kept = sprintf('every vector had the wrong width (expected %d: does search.embeddings.dimension match the model?)', $dimension);
    }
    if ($kept !== '') {
      return [
        'status' => 'incomplete',
        'reason' => $kept . '; the previous vectors were kept',
        'vectors' => $store->count(),
      ] + $result;
    }

    if ($full) {
      $store->replace($rows, $dimension);
      $this->pdo->exec('DELETE FROM ' . self::MANIFEST);
    }
    else {
      foreach ($toDrop as $path) {
        $store->deleteByFile($path);
      }
      if ($rows !== []) {
        $store->upsertBatch($rows, $dimension);
      }
      $delete = $this->pdo->prepare('DELETE FROM ' . self::MANIFEST . ' WHERE file = ?');
      foreach ($toDrop as $path) {
        $delete->execute([$path]);
      }
    }
    $insert = $this->pdo->prepare('INSERT OR REPLACE INTO ' . self::MANIFEST . ' (file, hash) VALUES (?, ?)');
    foreach ($toEmbed as $path) {
      $insert->execute([$path, $hashes[$path]]);
    }

    $result['status'] = 'built';
    $result['embedded_files'] = count($toEmbed);
    $result['stored'] = count($rows);
    $result['vectors'] = $store->count();
    $result['reason'] = $skipped > 0 ? sprintf('%d chunk(s) had no usable vector and were skipped', $skipped) : '';
    return $result;
  }

  /**
   * Whether a file is in the configured scope.
   *
   * @param string $path
   *   The docroot-relative path.
   *
   * @return bool
   *   TRUE when it is embedded.
   */
  private function inScope(string $path): bool {
    return $this->backend->scope() === 'all' || str_contains('/' . $path, '/custom/');
  }

  /**
   * Embeds chunks in batches.
   *
   * @param list<\Droost\Engine\Search\Chunk> $chunks
   *   The chunks.
   * @param int $dimension
   *   The width every vector must have.
   *
   * @return array{rows: list<array{corpus: string, ref: string, vector: list<float>, meta: array<string, mixed>, file: string}>, skipped: int, failed: int}
   *   The storable rows, and how many chunks were skipped and how many of
   *   those the backend gave nothing for.
   */
  private function embed(array $chunks, int $dimension): array {
    $rows = [];
    $skipped = 0;
    $failed = 0;
    foreach (array_chunk($chunks, self::BATCH) as $batch) {
      $vectors = $this->backend->embed(array_map(static fn (Chunk $c): string => $c->text, $batch));
      foreach ($batch as $i => $chunk) {
        $vector = $vectors[$i] ?? [];
        if ($vector === []) {
          $failed++;
          $skipped++;
          continue;
        }
        if (count($vector) !== $dimension) {
          $skipped++;
          continue;
        }
        $rows[] = [
          'corpus' => $chunk->corpus,
          'ref' => $chunk->ref,
          'vector' => array_values($vector),
          'meta' => $chunk->meta,
          'file' => is_string($chunk->meta['file'] ?? NULL) ? $chunk->meta['file'] : '',
        ];
      }
    }
    return ['rows' => $rows, 'skipped' => $skipped, 'failed' => $failed];
  }

}
