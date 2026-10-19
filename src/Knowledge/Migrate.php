<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\Config\StoragePaths;
use Droost\Workflow\State\RunStateStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Moves a project onto droost's folder: `droost migrate`.
 *
 * The root `droost.workflow.yml` becomes `droost/droost.yml`, comments and
 * all. The settings a site kept as Drupal configuration (the wiki's path,
 * the embeddings endpoint) are read from its exported config files, never
 * from drush, and added when the file does not already say them. An API key
 * is never copied: the file is committed.
 *
 * Refused while a run is open: the levers a run is held to do not move
 * under it.
 */
final class Migrate {

  /**
   * Constructs a Migrate.
   *
   * @param \Droost\Workflow\Knowledge\KnowledgeSettings $settings
   *   The project's settings.
   */
  public function __construct(private readonly KnowledgeSettings $settings) {}

  /**
   * Migrates the project.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   What was done, or why nothing was.
   */
  public function run(): array {
    $root = rtrim($this->settings->projectRoot, '/');
    $legacy = $root . '/' . StoragePaths::LEGACY_CONFIG_FILE;
    $target = $root . '/' . StoragePaths::CONFIG_FILE;

    if ($this->runIsOpen($root)) {
      return self::envelope(FALSE, 'A run is open, and the levers it is held to do not move under it. Finish it (or `droost workflow:reset` it), then run `droost migrate` again.', NULL);
    }
    if (is_file($legacy) && is_file($target)) {
      return self::envelope(FALSE, sprintf('Both %s and %s exist; droost reads %s. Fold what you still need from the root file into it and delete the root file; nothing was changed.', StoragePaths::LEGACY_CONFIG_FILE, StoragePaths::CONFIG_FILE, StoragePaths::CONFIG_FILE), NULL);
    }

    $done = [];
    if (is_file($legacy)) {
      if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0755, TRUE) && !is_dir(dirname($target))) {
        return self::envelope(FALSE, sprintf('Could not create %s.', dirname($target)), NULL);
      }
      if (!@rename($legacy, $target)) {
        return self::envelope(FALSE, sprintf('Could not move %s to %s.', StoragePaths::LEGACY_CONFIG_FILE, StoragePaths::CONFIG_FILE), NULL);
      }
      $done[] = sprintf('moved %s to %s', StoragePaths::LEGACY_CONFIG_FILE, StoragePaths::CONFIG_FILE);
    }
    elseif (!is_file($target)) {
      return self::envelope(FALSE, sprintf('Neither %s nor %s exists: there is nothing to migrate. `droost init` writes a new %s.', StoragePaths::LEGACY_CONFIG_FILE, StoragePaths::CONFIG_FILE, StoragePaths::CONFIG_FILE), NULL);
    }

    $text = (string) file_get_contents($target);
    $parsed = self::parse($text);
    $append = '';

    $wikiPath = $this->settings->exportedValue('droost.wiki.settings', 'path');
    if ($wikiPath !== '' && !array_key_exists('wiki', $parsed)) {
      $append .= sprintf("\n# From the exported droost.wiki.settings (droost migrate).\nwiki:\n  path: %s\n", Yaml::dump($wikiPath));
      $done[] = 'wiki.path from the exported droost.wiki.settings';
    }

    $embeddings = [];
    foreach (['embedding_endpoint' => 'endpoint', 'embedding_model' => 'model', 'embedding_format' => 'format'] as $from => $to) {
      $value = $this->settings->exportedValue('droost.search.settings', $from);
      if ($value !== '') {
        $embeddings[$to] = $value;
      }
    }
    if (isset($embeddings['endpoint']) && !array_key_exists('search', $parsed)) {
      $append .= "\n# From the exported droost.search.settings (droost migrate). An API key is\n# never copied here: this file is committed.\nsearch:\n  embeddings:\n";
      foreach ($embeddings as $key => $value) {
        $append .= sprintf("    %s: %s\n", $key, Yaml::dump($value));
      }
      $done[] = 'search.embeddings from the exported droost.search.settings';
    }

    if ($append !== '') {
      file_put_contents($target, rtrim($text, "\n") . "\n" . $append);
    }
    if ($done === []) {
      return self::envelope(
        TRUE,
        sprintf('%s is already in place; nothing to migrate.', StoragePaths::CONFIG_FILE),
        ['config' => StoragePaths::CONFIG_FILE, 'done' => []],
      );
    }
    return self::envelope(
      TRUE,
      'Migrated: ' . implode('; ', $done) . '. Run `droost init` to refresh the pack and the harnesses.',
      ['config' => StoragePaths::CONFIG_FILE, 'done' => $done],
    );
  }

  /**
   * Whether a run is under way.
   *
   * @param string $root
   *   The project root.
   *
   * @return bool
   *   TRUE when one is, or when its record cannot be read (refusing is the
   *   safe reading).
   */
  private function runIsOpen(string $root): bool {
    $record = $root . '/' . RunStateStore::resolveStateDir($root) . '/run.json';
    if (!is_file($record)) {
      return FALSE;
    }
    try {
      $state = (new RunStateStore($root))->load();
    }
    catch (\Throwable) {
      return TRUE;
    }
    return $state !== NULL && $state->currentPhase !== NULL;
  }

  /**
   * A YAML text's top-level map; empty when it is not one.
   *
   * @param string $text
   *   The text.
   *
   * @return array<array-key, mixed>
   *   The map.
   */
  private static function parse(string $text): array {
    try {
      $parsed = Yaml::parse($text);
    }
    catch (\Throwable) {
      return [];
    }
    return is_array($parsed) ? $parsed : [];
  }

  /**
   * An answer.
   *
   * @param bool $success
   *   Whether it worked.
   * @param string $message
   *   What happened.
   * @param array<string, mixed>|null $data
   *   The detail.
   *
   * @return array{success: bool, message: string, data: mixed}
   *   The envelope.
   */
  private static function envelope(bool $success, string $message, ?array $data): array {
    return ['success' => $success, 'message' => $message, 'data' => $data];
  }

}
