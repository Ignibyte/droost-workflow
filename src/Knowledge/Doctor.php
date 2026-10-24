<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\Baseline\BaselineStore;
use Droost\Workflow\Config\StoragePaths;
use Droost\Workflow\Config\WorkflowConfig;

/**
 * What droost can see of a project, row by row: `droost doctor`.
 *
 * Each row is a fact read from the files, with what to do when it is not
 * as it should be. A row that could not be read says so; it is never
 * reported as fine.
 */
final class Doctor {

  /**
   * Constructs a Doctor.
   *
   * @param \Droost\Workflow\Knowledge\Site $site
   *   The project.
   */
  public function __construct(private readonly Site $site) {}

  /**
   * Every row.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope: success when every row is ok; data lists the rows.
   */
  public function run(): array {
    $root = $this->site->projectRoot;
    $rows = [];

    $config = StoragePaths::configFile($root);
    $rows[] = match (TRUE) {
      $config === NULL => self::row('config', 'warn', 'No droost/droost.yml: `droost init` writes one.'),
      str_ends_with($config, StoragePaths::LEGACY_CONFIG_FILE) => self::row('config', 'warn', 'The configuration is still the root droost.workflow.yml: `droost migrate` moves it to droost/droost.yml.'),
      default => self::row('config', 'ok', StoragePaths::CONFIG_FILE),
    };

    $rows[] = $this->site->coreVersion === ''
      ? self::row('drupal', 'warn', sprintf('No Drupal core version found (composer.lock, core/lib/Drupal.php) under the docroot %s.', $this->site->docroot))
      : self::row('drupal', 'ok', sprintf('Drupal %s at %s', $this->site->coreVersion, $this->site->docroot));

    $sqlite = in_array('sqlite', \PDO::getAvailableDrivers(), TRUE);
    $rows[] = $sqlite ? self::row('sqlite', 'ok', 'pdo_sqlite is available') : self::row('sqlite', 'fail', 'PHP has no pdo_sqlite: the code index cannot be read or built.');

    $pdo = $this->site->pdo();
    if ($pdo === NULL) {
      $rows[] = self::row('code index', 'fail', sprintf('No code index at %s: `droost code:index` builds it from files.', $this->site->indexPath));
    }
    else {
      $meta = KnowledgeIndexer::meta($pdo);
      $graph = $this->site->graph();
      $counts = $graph !== NULL && $graph->isReady() ? $graph->count() : ['symbols' => 0, 'edges' => 0];
      $docs = (new DocsIndex($pdo))->count();
      $rows[] = $counts['symbols'] === 0
        ? self::row('code index', 'fail', 'The code index holds no symbols: `droost code:index --full`.')
        : self::row('code index', 'ok', sprintf('%d symbols, %d edges, %d documents%s', $counts['symbols'], $counts['edges'], $docs, isset($meta['indexed_at']) ? ', built ' . $meta['indexed_at'] : ', built by an earlier droost (rebuild with `droost code:index --full` to add the documents)'));
    }

    $rows[] = is_dir($this->site->wikiDir)
      ? self::row('wiki', 'ok', sprintf('%d page(s) at %s', $this->site->wiki()->count(), $this->site->wikiDir))
      : self::row('wiki', 'warn', sprintf('No wiki at %s yet; `droost code:wiki` still lists the inventory.', $this->site->wikiDir));

    $exported = $this->site->inventory()->exported();
    $rows[] = $exported === FALSE
      ? self::row('config export', 'warn', 'No exported core.extension.yml found: the inventory cannot say what is enabled.')
      : self::row('config export', 'ok', sprintf('%s, exported %s', $exported['file'], $exported['exported']));

    // THE BASELINE, BEFORE ANY RUN (F-378). Missing, it surfaced only when a
    // run's gates failed on findings older than the ticket, and recording it
    // then is the operator's, in a terminal, mid-run.
    try {
      $lever = WorkflowConfig::load($root)->baseline;
    }
    catch (\Throwable) {
      $lever = NULL;
    }
    $rows[] = match (TRUE) {
      $lever === NULL => self::row('baseline', 'warn', 'The configuration could not be read, so the baseline lever is unknown.'),
      $lever === FALSE => self::row('baseline', 'ok', 'off: every gate judges the whole tree (strict mode)'),
      BaselineStore::exists($root) => self::row('baseline', 'ok', sprintf('recorded at %s', BaselineStore::DIR)),
      default => self::row('baseline', 'warn', 'None recorded: every gate judges the whole tree, so findings that predate a ticket fail it. On an existing codebase the operator records one with `droost workflow:baseline` before the first run.'),
    };

    $rows[] = is_file($root . '/.claude/skills/workflow-start/SKILL.md')
      ? self::row('pack', 'ok', 'the workflow pack is installed (.claude/skills)')
      : self::row('pack', 'warn', 'The workflow pack is not installed: `droost init`.');

    $mcp = @file_get_contents($root . '/.mcp.json');
    $decoded = is_string($mcp) ? json_decode($mcp, TRUE) : NULL;
    $servers = is_array($decoded) && is_array($decoded['mcpServers'] ?? NULL) ? $decoded['mcpServers'] : [];
    $rows[] = isset($servers['droost'])
      ? self::row('mcp', 'warn', '.mcp.json still names a droost MCP server, which droost no longer ships: `droost init` removes it.')
      : self::row('mcp', 'ok', 'no droost MCP entry (droost is reached by command)');

    $bad = array_filter($rows, static fn (array $r): bool => $r['status'] !== 'ok');
    $failed = array_filter($rows, static fn (array $r): bool => $r['status'] === 'fail');
    return [
      'success' => $failed === [],
      'message' => $bad === []
        ? sprintf('All %d rows ok.', count($rows))
        : implode("\n", array_map(static fn (array $r): string => sprintf('%s %s: %s', strtoupper($r['status']), $r['check'], $r['detail']), $bad)),
      'data' => ['project' => $root, 'rows' => $rows],
    ];
  }

  /**
   * One row.
   *
   * @param string $check
   *   What was checked.
   * @param string $status
   *   One of ok, warn or fail.
   * @param string $detail
   *   What was found, or what to do.
   *
   * @return array{check: string, status: string, detail: string}
   *   The row.
   */
  private static function row(string $check, string $status, string $detail): array {
    return ['check' => $check, 'status' => $status, 'detail' => $detail];
  }

}
