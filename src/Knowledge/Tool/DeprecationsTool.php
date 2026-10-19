<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * Looks up Drupal core deprecations offline, from the ledger droost ships.
 */
final class DeprecationsTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'Look up Drupal core deprecations offline from the shipped, version-keyed ledger: pass "symbol" for an exact match (function/method/class/constant/hook), "search" for a substring, or no argument for ledger stats. Returns "deprecated in X, removed in Y, replacement, change-record URL". Read-only.';

  /**
   * {@inheritdoc}
   */
  public const string POSITIONAL = 'symbol';

  /**
   * {@inheritdoc}
   */
  public const array ARGUMENTS = [
    'symbol' => [
      'type' => 'string',
      'description' => 'Exact symbol to look up, e.g. "hide" or "Drupal\\Core\\X::y".',
    ],
    'search' => [
      'type' => 'string',
      'description' => 'Substring to search symbols for (used when "symbol" is empty).',
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'code:deprecations';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $ledger = $site->deprecations();
    $symbol = self::stringArg($arguments, 'symbol');
    if ($symbol !== '') {
      $row = $ledger->lookup($symbol);
      if ($row === NULL) {
        return $this->succeed(
          sprintf('No deprecation recorded for "%s" in the shipped ledger.', $symbol),
          ['symbol' => $symbol, 'match' => NULL, 'stats' => $ledger->stats()],
        );
      }
      return $this->succeed(
        sprintf(
          '"%s" is deprecated in %s and is removed in %s.',
          $symbol,
          self::text($row['deprecated_in'] ?? NULL),
          self::text($row['removed_in'] ?? NULL),
        ),
        ['match' => $row],
      );
    }
    $search = self::stringArg($arguments, 'search');
    if ($search !== '') {
      $rows = $ledger->search($search);
      return $this->succeed(
        sprintf('%d deprecation(s) whose symbol contains "%s".', count($rows), $search),
        ['matches' => $rows],
      );
    }
    $stats = $ledger->stats();
    return $this->succeed(
      sprintf('Deprecation ledger: %d row(s) for core major %s.', $stats['rows'], $stats['major'] === '' ? '(none)' : $stats['major']),
      ['stats' => $stats],
    );
  }

  /**
   * A dataset value as text, '?' when it is not one.
   *
   * @param mixed $value
   *   The value.
   *
   * @return string
   *   The text.
   */
  private static function text(mixed $value): string {
    return is_scalar($value) ? (string) $value : '?';
  }

}
