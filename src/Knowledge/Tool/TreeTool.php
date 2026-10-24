<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Knowledge\CitationResolver;
use Droost\Workflow\Knowledge\Rows;
use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * The code's structure, from the index: `droost code:tree`.
 *
 * With no argument, the project's modules and themes with what each holds;
 * with a module's machine name, its files and, in each, its classes,
 * functions and methods in order, and the hooks it implements, the services
 * it declares and the routes it serves; with a path, the same for the files
 * under it. One of the two commands plan must consult.
 */
final class TreeTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'The code\'s structure, from the code index. No argument: the modules and themes (custom first) with their symbol counts. A module\'s machine name: its files, each file\'s classes, functions and methods in order, and the hooks it implements, the services it declares and the routes it serves. A path (docroot- or project-relative): the same for the files under it. Read-only.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'target';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'target' => [
      'type' => 'string',
      'description' => 'A module or theme machine name, or a path; empty for the overview.',
    ],
    'limit' => ['type' => 'integer', 'description' => 'Max symbols listed (default 400, max 2000).'],
  ];

  /**
   * The symbol tables.
   */
  private const string SYMBOLS = 'droost_search_symbol';

  /**
   * The edge table.
   */
  private const string EDGES = 'droost_search_edge';

  /**
   * The kinds a file's outline lists.
   */
  private const array CODE_KINDS = ['class', 'interface', 'trait', 'enum', 'function', 'method'];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'code:tree';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $pdo = $site->pdo();
    $graph = $site->graph();
    if ($pdo === NULL || $graph === NULL || !$graph->isReady()) {
      return $this->fail(self::NO_INDEX);
    }
    $target = trim(self::stringArg($arguments, 'target'), '/');
    $limit = self::intArg($arguments, 'limit', 400, 1, 2000);
    if ($target === '') {
      return $this->overview($pdo, $site);
    }
    $module = $this->moduleExists($pdo, $target);
    if ($module) {
      return $this->outline($pdo, 'module = ?', [$target], $target, $limit, $target);
    }
    // Paths in the index are the docroot's; a project-relative one loses
    // its docroot segment (`web/modules/custom/x` is `modules/custom/x`).
    $docroot = CitationResolver::docrootPrefix($site->projectRoot, $site->docroot);
    $prefix = $docroot !== '' && str_starts_with($target, $docroot . '/') ? substr($target, strlen($docroot) + 1) : $target;
    return $this->outline($pdo, "(file = ? OR file LIKE ? ESCAPE '\\')", [$prefix, addcslashes($prefix, '%_\\') . '/%'], $target, $limit, NULL);
  }

  /**
   * The modules and themes the index holds, custom first.
   *
   * @param \PDO $pdo
   *   The index.
   * @param \Droost\Workflow\Knowledge\Site $site
   *   The site.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope.
   */
  private function overview(\PDO $pdo, Site $site): array {
    $rows = Rows::all($pdo->query(
      'SELECT module, MIN(file) AS file, COUNT(*) AS n FROM ' . self::SYMBOLS
      . " WHERE module <> '' AND kind IN ('class','interface','trait','enum','function','method') GROUP BY module",
    ));
    $groups = ['custom' => [], 'contrib' => [], 'core' => [], 'other' => []];
    foreach ($rows as $row) {
      $file = '/' . ($row['file'] ?? '');
      $scope = match (TRUE) {
        str_contains($file, '/custom/') => 'custom',
        str_contains($file, '/contrib/') => 'contrib',
        str_starts_with($file, '/core/') => 'core',
        default => 'other',
      };
      $groups[$scope][$row['module'] ?? ''] = (int) ($row['n'] ?? 0);
    }
    foreach ($groups as &$group) {
      ksort($group);
    }
    unset($group);
    $inventory = $site->inventory()->extensions();
    $themes = array_keys(array_filter($inventory, static fn (array $e): bool => $e['type'] === 'theme'));
    return $this->succeed(
      sprintf(
        '%d custom, %d contrib and %d core extension(s) in the index; `droost code:tree <machine name>` outlines one. Custom: %s.',
        count($groups['custom']),
        count($groups['contrib']),
        count($groups['core']),
        $groups['custom'] === [] ? 'none' : implode(', ', array_keys($groups['custom'])),
      ),
      [
        'custom' => $groups['custom'],
        'contrib' => $groups['contrib'],
        'core_count' => count($groups['core']),
        'other' => $groups['other'],
        'themes' => $themes,
      ],
    );
  }

  /**
   * The outline of the symbols one condition selects.
   *
   * @param \PDO $pdo
   *   The index.
   * @param string $where
   *   The condition over the symbol table.
   * @param list<string> $params
   *   Its parameters.
   * @param string $label
   *   What was asked for.
   * @param int $limit
   *   The most symbols listed.
   * @param string|null $module
   *   The module, when one was asked for: its services and routes are its
   *   own symbols, kinds `service` and `route`.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope.
   */
  private function outline(\PDO $pdo, string $where, array $params, string $label, int $limit, ?string $module): array {
    $kinds = "'" . implode("','", self::CODE_KINDS) . "'";
    $statement = $pdo->prepare('SELECT fqcn, kind, file, line FROM ' . self::SYMBOLS . ' WHERE ' . $where . ' AND kind IN (' . $kinds . ') ORDER BY file, line LIMIT ?');
    $statement->execute([...$params, $limit + 1]);
    $symbols = Rows::all($statement);
    $truncated = count($symbols) > $limit;
    $symbols = array_slice($symbols, 0, $limit);

    $files = [];
    $names = [];
    foreach ($symbols as $row) {
      $fqcn = $row['fqcn'] ?? '';
      $kind = $row['kind'] ?? '';
      $names[] = $fqcn;
      $files[$row['file'] ?? ''][] = sprintf(
        '%s%s %s:%d',
        $kind === 'method' ? '  ' : '',
        $kind,
        $kind === 'method' ? substr($fqcn, (int) strrpos($fqcn, '::') + 2) : $fqcn,
        (int) ($row['line'] ?? 0),
      );
    }

    $hooks = [];
    if ($names !== []) {
      foreach (array_chunk($names, 400) as $chunk) {
        $edges = $pdo->prepare('SELECT DISTINCT dst FROM ' . self::EDGES . " WHERE kind = 'implements_hook' AND src IN (" . implode(',', array_fill(0, count($chunk), '?')) . ')');
        $edges->execute($chunk);
        foreach (Rows::column($edges) as $dst) {
          $hooks[] = substr($dst, strlen('hook:'));
        }
      }
      sort($hooks);
      $hooks = array_values(array_unique($hooks));
    }

    $services = [];
    $routes = [];
    $select = $pdo->prepare('SELECT fqcn, kind FROM ' . self::SYMBOLS . ' WHERE ' . $where . " AND kind IN ('service', 'route') ORDER BY fqcn");
    $select->execute($params);
    foreach (Rows::all($select) as $row) {
      $name = $row['fqcn'] ?? '';
      if (($row['kind'] ?? '') === 'service') {
        $services[] = substr($name, strlen('service:'));
      }
      else {
        $routes[] = substr($name, strlen('route:'));
      }
    }

    if ($files === [] && $services === [] && $routes === []) {
      return $this->fail(sprintf('Nothing indexed for "%s": not a module or theme the index knows, nor a path under the docroot. `droost code:tree` lists them; re-run `droost code:index` if it is new.', $label));
    }

    return $this->succeed(
      sprintf(
        '%s: %d file(s), %d symbol(s)%s, %d hook(s) implemented, %d service(s), %d route(s).',
        $label,
        count($files),
        count($symbols),
        $truncated ? ' (truncated; raise "limit")' : '',
        count($hooks),
        count($services),
        count($routes),
      ),
      [
        'target' => $label,
        'module' => $module,
        'files' => $files,
        'truncated' => $truncated,
        'hooks' => $hooks,
        'services' => $services,
        'routes' => $routes,
      ],
    );
  }

  /**
   * Whether the index knows a module or theme by this name.
   *
   * @param \PDO $pdo
   *   The index.
   * @param string $name
   *   The name.
   *
   * @return bool
   *   TRUE when it does.
   */
  private function moduleExists(\PDO $pdo, string $name): bool {
    if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
      return FALSE;
    }
    $statement = $pdo->prepare('SELECT 1 FROM ' . self::SYMBOLS . ' WHERE module = ? LIMIT 1');
    $statement->execute([$name]);
    return $statement->fetchColumn() !== FALSE;
  }

}
