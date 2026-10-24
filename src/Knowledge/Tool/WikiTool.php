<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Knowledge\ExtensionInventory;
use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * The codebase wiki: its pages, and the inventory of what is installed.
 *
 * The pages people wrote (the OKF bundle in `droost/wiki`), and generated
 * beside them, `inventory/`: a page for every installed contrib and custom
 * module and theme, read from composer.lock, the extensions' own YAML and
 * the last configuration export. One of the two commands plan must consult.
 */
final class WikiTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'The codebase wiki and an inventory of every installed module and theme. With no argument it lists both; "page" reads one ("camps", "inventory/views" for an installed module or theme, "inventory" for all of them); "query" searches both. The inventory is generated from composer.lock, each extension\'s info/routing/services/permissions YAML, and the exported core.extension.yml (enabled as of the last export). Read-only. Two subcommands: `droost code:wiki status [--json] [--strict]` reports each managed page fresh, stale, orphaned or invalid against the sources it records (the wiki_fresh gate\'s measurement; exit 0 fresh, 1 not, 3 when it could not run); `droost code:wiki stamp <page>|--all-stale [--dry-run]` re-records a stale page\'s source hashes once you have re-read it and it is still accurate. droost writes no page prose: there is no AI page generator, so a page is written by the agent or a person.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'page';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'page' => [
      'type' => 'string',
      'description' => 'A page to read: bundle-relative ("camps", "modules/camps.md"), or "inventory/<machine name>", or "inventory".',
    ],
    'query' => [
      'type' => 'string',
      'description' => 'A case-insensitive search term, over the pages and the inventory.',
    ],
    'limit' => ['type' => 'integer', 'description' => 'Maximum search results (default 20, max 50).'],
  ];

  /**
   * The most pages a listing returns.
   */
  private const int MAX_LIST = 200;

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'code:wiki';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $inventory = $site->inventory();
    $hasWiki = is_dir($site->wikiDir);
    $page = self::stringArg($arguments, 'page');
    if ($page !== '') {
      $generated = $inventory->page($page);
      if ($generated !== NULL) {
        return $this->succeed(
          sprintf('%s (generated from the project\'s files).', $page),
          ['path' => $page, 'content' => $generated, 'generated' => TRUE],
        );
      }
      // A module or theme named bare (`camps`, or `camps.md` as the inventory
      // links it) is its inventory page when the wiki has no page for it,
      // and on a project with no wiki yet, which is how every project starts
      // (F-304: it failed there, saying the inventory had no such extension).
      $machine = basename($page, '.md');
      $inventoryPage = isset($inventory->extensions()[$machine]) ? [
        'path' => ExtensionInventory::PREFIX . $machine . '.md',
        'content' => (string) $inventory->page(ExtensionInventory::PREFIX . $machine),
      ] : NULL;
      if (!$hasWiki) {
        if ($inventoryPage !== NULL) {
          return $this->succeed(sprintf('No wiki has been written yet; "%s" is its inventory page (generated).', $page), $inventoryPage + ['generated' => TRUE]);
        }
        return $this->fail(sprintf('No page "%s": no wiki has been written at %s yet, and the inventory has no module or theme by that name (`droost code:wiki inventory` lists them).', $page, $site->wikiDir));
      }
      $reader = $site->wiki();
      // A page named as the module it covers is the page: `camps` is
      // `camps.md`.
      $result = $reader->read($page) ?? (str_ends_with($page, '.md') ? NULL : $reader->read($page . '.md'));
      if ($result === NULL) {
        if ($inventoryPage !== NULL) {
          return $this->succeed(sprintf('No wiki page "%s"; its inventory page (generated) instead.', $page), $inventoryPage + ['generated' => TRUE]);
        }
        return $this->fail(sprintf('No page "%s" in the wiki (paths are bundle-relative, .md only) or the inventory.', $page));
      }
      return $this->succeed(sprintf('%s (%d bytes shown).', $page, strlen($result['content'])), $result);
    }

    $query = self::stringArg($arguments, 'query');
    if ($query !== '') {
      $limit = self::intArg($arguments, 'limit', 20, 1, 50);
      $results = $hasWiki ? $site->wiki()->search($query, $limit, 5) : [];
      $inventoryHits = [];
      foreach ($inventory->pages() as $path => $content) {
        if (count($inventoryHits) >= $limit) {
          break;
        }
        if (stripos($content, $query) !== FALSE) {
          $inventoryHits[] = ['path' => $path, 'title' => strtok($content, "\n") ?: $path];
        }
      }
      return $this->succeed(
        sprintf('%d wiki page(s) and %d inventory page(s) match "%s".', count($results), count($inventoryHits), $query),
        ['total' => count($results) + count($inventoryHits), 'results' => $results, 'inventory' => $inventoryHits],
      );
    }

    $total = $hasWiki ? $site->wiki()->count() : 0;
    $pages = $hasWiki ? $site->wiki()->index(self::MAX_LIST) : [];
    $extensions = [];
    foreach ($inventory->extensions() as $machine => $entry) {
      $extensions[] = [
        'page' => ExtensionInventory::PREFIX . $machine . '.md',
        'type' => $entry['type'],
        'scope' => $entry['scope'],
        'version' => $entry['version'],
        'enabled' => $entry['enabled'],
      ];
    }
    $exported = $inventory->exported();
    return $this->succeed(
      sprintf(
        '%s; and %d installed module(s) and theme(s) in the inventory (enabled %s). Read one with `droost code:wiki <page>`.',
        $hasWiki ? sprintf('%d wiki page(s)%s', $total, $total > count($pages) ? sprintf(', showing %d', count($pages)) : '') : sprintf('No wiki written at %s yet', $site->wikiDir),
        count($extensions),
        $exported === FALSE ? 'unknown: no configuration export found' : 'as of the export of ' . $exported['exported'],
      ),
      [
        'total' => $total,
        'returned' => count($pages),
        'has_more' => $total > count($pages),
        'pages' => $pages,
        'inventory' => $extensions,
      ],
    );
  }

}
