<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Wiki;

use Droost\Engine\Search\Graph\CodeGraphStorageInterface;
use Droost\Engine\Wiki\BundleReader;
use Droost\Engine\Wiki\FileHasher;
use Droost\Workflow\Knowledge\ExtensionInventory;

/**
 * The wiki's freshness report: `droost code:wiki status`.
 *
 * The provenance contract (drupal/droost's docs/wiki-provenance-spec.md, at
 * the tag the module was retired at): each managed page records the sources
 * it describes and their hashes; a page is fresh when every source still
 * hashes as recorded, stale when one changed, orphaned when one is gone,
 * invalid when its provenance block is malformed, and unmanaged when it
 * claims nothing. Beside the pages, the extensions no page covers.
 *
 * Read from files only. Which extensions are installed comes from the
 * inventory: enabled as of the last configuration export, or every extension
 * on disk when nothing was exported (the report says which). Which contrib
 * modules are owed a page comes from the code index: those the project's own
 * code reaches. With no index that half is not measured, and the report says
 * so rather than counting it as nothing.
 *
 * The JSON is the shape the `wiki_fresh` gate reads: `summary` (pages, fresh,
 * stale, orphaned, invalid, unmanaged, uncovered_modules), `pages` (file,
 * status, modules, stale_sources, missing_sources, error), `uncovered`, `ok`.
 */
final class WikiStatus {

  /**
   * Constructs a WikiStatus.
   *
   * @param \Droost\Engine\Wiki\BundleReader $reader
   *   The wiki bundle's reader.
   * @param \Droost\Engine\Wiki\FileHasher $hasher
   *   The content hasher the provenance contract names.
   * @param string $projectRoot
   *   The project root, absolute: sources are relative to it.
   * @param string $bundlePath
   *   The wiki's directory as configured, for the report.
   * @param \Droost\Workflow\Knowledge\ExtensionInventory $inventory
   *   The installed modules and themes.
   * @param \Droost\Engine\Search\Graph\CodeGraphStorageInterface|null $graph
   *   The code graph, or NULL when there is no index.
   * @param string $contribScope
   *   Either `reached` (contrib modules the project's code reaches) or `all`.
   * @param \Closure(): int $clock
   *   The time, for the report's stamp.
   */
  public function __construct(
    private readonly BundleReader $reader,
    private readonly FileHasher $hasher,
    private readonly string $projectRoot,
    private readonly string $bundlePath,
    private readonly ExtensionInventory $inventory,
    private readonly ?CodeGraphStorageInterface $graph,
    private readonly string $contribScope = 'reached',
    private readonly ?\Closure $clock = NULL,
  ) {}

  /**
   * Builds the status report.
   *
   * @param bool $strict
   *   When TRUE, uncovered extensions also fail the report.
   *
   * @return array{bundle_path: string, checked: string, summary: array{pages: int, fresh: int, stale: int, orphaned: int, invalid: int, unmanaged: int, uncovered_modules: int}, pages: list<array{file: string, status: string, modules: list<string>, stale_sources: list<array{path: string, recorded: string, current: string}>, missing_sources: list<string>, error: string|null}>, uncovered: list<string>, installed_from: string, contrib_scope_measured: bool, ok: bool}
   *   The report.
   */
  public function report(bool $strict = FALSE): array {
    $counts = ['fresh' => 0, 'stale' => 0, 'orphaned' => 0, 'invalid' => 0, 'unmanaged' => 0];
    $pages = [];
    $covered = [];
    foreach ($this->reader->pages() as $file) {
      $page = $this->classifyPage($file);
      $counts[$page['status']]++;
      foreach ($page['modules'] as $module) {
        $covered[$module] = TRUE;
      }
      $pages[] = $page;
    }
    $uncovered = $this->uncovered($covered);
    $failing = $counts['stale'] + $counts['orphaned'] + $counts['invalid'];
    $exported = $this->inventory->exported();
    return [
      'bundle_path' => $this->bundlePath,
      'checked' => gmdate('Y-m-d\TH:i:s\Z', $this->clock !== NULL ? ($this->clock)() : time()),
      'summary' => [
        'pages' => count($pages),
        'fresh' => $counts['fresh'],
        'stale' => $counts['stale'],
        'orphaned' => $counts['orphaned'],
        'invalid' => $counts['invalid'],
        'unmanaged' => $counts['unmanaged'],
        'uncovered_modules' => count($uncovered),
      ],
      'pages' => $pages,
      'uncovered' => $uncovered,
      'installed_from' => $exported === FALSE
        ? 'disk: no configuration export was found, so every extension on disk counts as installed'
        : 'the configuration export of ' . $exported['exported'] . ' (' . $exported['file'] . ')',
      'contrib_scope_measured' => $this->contribScopeReady(),
      'ok' => $failing === 0 && (!$strict || $uncovered === []),
    ];
  }

  /**
   * A one-line summary of a report.
   *
   * @param array{summary: array{fresh: int, stale: int, orphaned: int, invalid: int, uncovered_modules: int}, contrib_scope_measured: bool} $report
   *   A report from report().
   *
   * @return string
   *   E.g. "Wiki: 8 fresh, 2 stale, 1 orphaned, 0 invalid; 2 extension(s)
   *   uncovered."
   */
  public static function summaryLine(array $report): string {
    $s = $report['summary'];
    return sprintf(
      'Wiki: %d fresh, %d stale, %d orphaned, %d invalid; %d extension(s) uncovered%s.',
      $s['fresh'],
      $s['stale'],
      $s['orphaned'],
      $s['invalid'],
      $s['uncovered_modules'],
      // "0 uncovered" means two different things, and only one is good news.
      $report['contrib_scope_measured']
        ? ''
        : ' (custom only: there is no code index, so contrib scope was NOT measured; run droost code:index)',
    );
  }

  /**
   * Classifies one page per the contract's precedence.
   *
   * @param string $file
   *   The bundle-relative page path.
   *
   * @return array{file: string, status: string, modules: list<string>, stale_sources: list<array{path: string, recorded: string, current: string}>, missing_sources: list<string>, error: string|null}
   *   The page row.
   */
  public function classifyPage(string $file): array {
    $row = [
      'file' => $file,
      'status' => 'unmanaged',
      'modules' => [],
      'stale_sources' => [],
      'missing_sources' => [],
      'error' => NULL,
    ];
    if (BundleReader::isReserved($file)) {
      return $row;
    }
    $meta = $this->reader->readMeta($file);
    if ($meta === NULL) {
      // A page that cannot be read proves nothing; surfaced, never skipped.
      $row['status'] = 'invalid';
      $row['error'] = 'page cannot be read';
      return $row;
    }
    if ($meta->isInvalid()) {
      $row['status'] = 'invalid';
      $row['error'] = $meta->error;
      return $row;
    }
    if (!$meta->isManaged() || $meta->provenance === NULL) {
      return $row;
    }
    $row['modules'] = array_values($meta->provenance->modules);
    foreach ($meta->provenance->sources as $source) {
      $absolute = rtrim($this->projectRoot, '/') . '/' . $source['path'];
      if (!file_exists($absolute)) {
        $row['missing_sources'][] = $source['path'];
        continue;
      }
      $current = $this->hasher->hash($absolute);
      if ($current === NULL) {
        // Exists but unreadable: freshness cannot be proven, so stale.
        $row['stale_sources'][] = [
          'path' => $source['path'],
          'recorded' => $source['hash'],
          'current' => '(unreadable)',
        ];
        continue;
      }
      if ($current !== $source['hash']) {
        $row['stale_sources'][] = ['path' => $source['path'], 'recorded' => $source['hash'], 'current' => $current];
      }
    }
    $row['status'] = match (TRUE) {
      $row['missing_sources'] !== [] => 'orphaned',
      $row['stale_sources'] !== [] => 'stale',
      default => 'fresh',
    };
    return $row;
  }

  /**
   * Whether the contrib half of the coverage figure could be determined.
   *
   * @return bool
   *   TRUE when every contrib module counts, or the code graph is built.
   */
  public function contribScopeReady(): bool {
    return $this->contribScope === 'all' || ($this->graph !== NULL && $this->graph->isReady());
  }

  /**
   * Installed custom modules and themes, and in-scope contrib, with no page.
   *
   * @param array<string, true> $covered
   *   Extension names claimed by any page's provenance.
   *
   * @return list<string>
   *   The uncovered names, sorted.
   */
  private function uncovered(array $covered): array {
    $custom = [];
    $contrib = [];
    $exported = $this->inventory->exported();
    foreach ($this->inventory->extensions() as $machine => $entry) {
      // Installed: enabled as of the export, or on disk when nothing was
      // exported (entry['enabled'] is NULL then).
      if ($entry['enabled'] === FALSE || ($exported !== FALSE && $entry['enabled'] !== TRUE)) {
        continue;
      }
      if ($entry['scope'] === 'custom' && in_array($entry['type'], ['module', 'theme'], TRUE)) {
        $custom[$machine] = $entry['type'];
      }
      elseif ($entry['scope'] === 'contrib' && $entry['type'] === 'module') {
        $contrib[$machine] = TRUE;
      }
    }
    $uncovered = [];
    foreach (array_keys($custom) as $name) {
      if (!isset($covered[$name])) {
        $uncovered[] = $name;
      }
    }
    $customModules = array_keys(array_filter($custom, static fn (string $type): bool => $type === 'module'));
    $inScope = $this->contribScope === 'all' ? array_keys($contrib) : $this->contribReached($customModules, $contrib);
    foreach ($inScope as $name) {
      if (!isset($covered[$name])) {
        $uncovered[] = $name;
      }
    }
    $uncovered = array_values(array_unique(array_map('strval', $uncovered)));
    sort($uncovered);
    return $uncovered;
  }

  /**
   * The installed contrib modules the project's own code reaches.
   *
   * A contrib module is owed a page when a custom module's code reaches it (a
   * service injected, a hook implemented, a plugin extended): an outbound
   * edge in the code graph. An unbuilt graph contributes nothing, and
   * contribScopeReady() says so.
   *
   * @param list<string> $customModules
   *   The installed custom modules.
   * @param array<string, true> $contrib
   *   The installed contrib modules.
   *
   * @return list<string>
   *   Contrib module names in scope.
   */
  private function contribReached(array $customModules, array $contrib): array {
    if ($this->graph === NULL || !$this->graph->isReady()) {
      return [];
    }
    $scope = [];
    foreach ($customModules as $module) {
      $summary = $this->graph->fanSummary($module);
      if ($summary['ready'] !== TRUE) {
        continue;
      }
      foreach ($summary['outbound'] as $edge) {
        $name = $edge['module'];
        if ($name !== '' && $name !== $module && isset($contrib[$name])) {
          $scope[$name] = TRUE;
        }
      }
    }
    return array_map('strval', array_keys($scope));
  }

}
