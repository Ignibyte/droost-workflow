<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * Reads and searches module documentation on disk.
 */
final class ModuleDocsTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'Reads and searches module documentation (README, *.md, *.api.php, docs/*.md, and the info description) across core, contrib, and custom modules. "module" (+ optional "file"): return that module\'s docs. "query": search doc contents (narrow with "scope": core, contrib, custom, all). Neither: list documented modules. Read-only.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'module';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'query' => [
      'type' => 'string',
      'description' => 'Case-insensitive text to search for within module documentation.',
    ],
    'module' => [
      'type' => 'string',
      'description' => 'A module machine name; returns that module\'s primary doc (or the file named by "file").',
    ],
    'file' => [
      'type' => 'string',
      'description' => 'A specific doc file for "module", e.g. "node.api.php" or "docs/overview.md".',
    ],
    'scope' => [
      'type' => 'string',
      'enum' => ['core', 'contrib', 'custom', 'all'],
      'description' => 'Limit to one module scope (default "all").',
    ],
    'limit' => [
      'type' => 'integer',
      'description' => 'Max items to return — searching: default 25, max 100; listing: default 200, max 2000.',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'code:docs';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $scope = self::stringArg($arguments, 'scope');
    if (!in_array($scope, ['core', 'contrib', 'custom', 'all'], TRUE)) {
      $scope = 'all';
    }

    $module = self::stringArg($arguments, 'module');
    if ($module !== '') {
      $file = self::stringArg($arguments, 'file');
      $doc = $site->docs->read($module, $file);
      if ($doc === NULL) {
        return $this->fail(sprintf(
          'No documentation found for module "%s"%s.',
          $module,
          $file !== '' ? sprintf(' file "%s"', $file) : '',
        ));
      }
      return $this->succeed(sprintf('%s: %s (%s).', $module, $doc['file'], $doc['scope']), $doc);
    }

    $query = self::stringArg($arguments, 'query');
    if ($query !== '') {
      $limit = self::intArg($arguments, 'limit', 25, 1, 100);
      // Fetch one more than the limit so a truncated result set is signalled
      // honestly: the search caps rows, so "25 matches" would otherwise look
      // complete when there may be more.
      $results = $site->docs->search($query, $scope, $limit + 1, 5);
      $has_more = count($results) > $limit;
      $results = array_slice($results, 0, $limit);
      return $this->succeed(
        sprintf('%d%s documentation match(es) for "%s" in scope "%s".', count($results), $has_more ? '+' : '', $query, $scope),
        [
          'query' => $query,
          'scope' => $scope,
          'matched' => count($results),
          'has_more' => $has_more,
          'results' => $results,
        ],
      );
    }

    $limit = self::intArg($arguments, 'limit', 200, 1, 2000);
    $index = $site->docs->index($scope);
    $returned = array_slice($index, 0, $limit);
    return $this->succeed(
      sprintf('%d documented module(s) in scope "%s"; showing %d.', count($index), $scope, count($returned)),
      ['scope' => $scope, 'total' => count($index), 'returned' => count($returned), 'modules' => $returned],
    );
  }

}
