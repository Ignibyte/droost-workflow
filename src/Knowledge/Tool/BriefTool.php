<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Tool;

use Droost\Workflow\Config\StoragePaths;
use Droost\Workflow\Knowledge\KnowledgeIndexer;
use Droost\Workflow\Knowledge\Site;
use Droost\Workflow\Knowledge\ToolBase;

/**
 * The project in one answer, read from its files.
 *
 * Drupal's and PHP's versions, the custom code, how much is contrib, what
 * the last export enabled, the house rules the project holds itself to, and
 * whether the index and the wiki are there to ask. What is not a file (the
 * running site's content, its database) is not here, and says so.
 */
final class BriefTool extends ToolBase {

  /**
   * {@inheritdoc}
   */
  public const string DESCRIPTION = 'The project in one answer, from its files: Drupal and PHP versions, the custom modules and themes, the contrib count, what the last configuration export enabled, the house rules, and whether the code index and the wiki are there. The running site (content, database) is not read. Read-only; no arguments.';

  /**
   * {@inheritdoc}
   */
  public function id(): string {
    return 'brief';
  }

  /**
   * {@inheritdoc}
   */
  public function answer(array $arguments, Site $site): array {
    $inventory = $site->inventory();
    $extensions = $inventory->extensions();
    $custom = [];
    $contrib = 0;
    foreach ($extensions as $machine => $entry) {
      if ($entry['scope'] === 'custom') {
        $custom[] = sprintf('%s (%s)', $machine, $entry['type']);
      }
      elseif ($entry['scope'] === 'contrib') {
        $contrib++;
      }
    }
    $exported = $inventory->exported();

    $rules = [];
    $config = StoragePaths::config($site->projectRoot);
    $house = is_array($config['rules'] ?? NULL) ? ($config['rules']['house'] ?? NULL) : NULL;
    foreach (is_array($house) ? $house : [] as $key => $rule) {
      if (is_array($rule)) {
        $id = is_string($rule['id'] ?? NULL) ? $rule['id'] : (string) $key;
        $text = is_string($rule['rule'] ?? NULL) ? $rule['rule'] : (is_string($rule['description'] ?? NULL) ? $rule['description'] : '');
        $rules[] = trim($id . ': ' . $text, ': ');
      }
      elseif (is_string($rule)) {
        $rules[] = $rule;
      }
    }

    $pdo = $site->pdo();
    $meta = $pdo === NULL ? [] : KnowledgeIndexer::meta($pdo);
    $index = $pdo === NULL ? 'absent: run `droost code:index`' : ($meta['indexed_at'] ?? FALSE ? 'built ' . $meta['indexed_at'] : 'present, built by an earlier droost');
    $wikiPages = is_dir($site->wikiDir) ? $site->wiki()->count() : 0;

    $data = [
      'drupal' => $site->coreVersion,
      'php' => PHP_VERSION,
      'docroot' => $site->docroot,
      'custom' => $custom,
      'contrib_count' => $contrib,
      'enabled_count' => $exported === FALSE ? NULL : count($exported['modules']) + count($exported['themes']),
      'enabled_as_of' => $exported === FALSE ? NULL : $exported['exported'],
      'house_rules' => $rules,
      'code_index' => $index,
      'wiki_pages' => $wikiPages,
    ];
    $lines = [
      sprintf('Drupal %s, PHP %s.', $site->coreVersion !== '' ? $site->coreVersion : '(version unknown)', PHP_VERSION),
      sprintf('Custom: %s.', $custom === [] ? 'none found' : implode(', ', $custom)),
      sprintf('Contrib: %d module(s) and theme(s); `droost code:wiki inventory` lists them.', $contrib),
      $exported === FALSE
        ? 'Enabled: unknown (no exported core.extension.yml).'
        : sprintf('Enabled: %d, as of the configuration export of %s.', $data['enabled_count'], $exported['exported']),
      sprintf('House rules: %s.', $rules === [] ? 'none' : implode('; ', $rules)),
      sprintf('Code index: %s. Wiki: %d page(s).', $index, $wikiPages),
    ];
    return $this->succeed(implode("\n", $lines), $data);
  }

}
