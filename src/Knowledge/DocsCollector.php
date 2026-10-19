<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

/**
 * The documentation `search:arch` searches: what the project says about itself.
 *
 * The wiki's pages, the inventory's generated pages (every installed module
 * and theme), and each non-core extension's README and docs. Collected the
 * same way for the index (`code:index` writes them to the docs table) and for
 * the grep fallback when there is no index, so the two find the same text.
 */
final class DocsCollector {

  /**
   * The most bytes of one document kept.
   */
  public const int MAX_BYTES = 200000;

  /**
   * Constructs a DocsCollector.
   *
   * @param \Droost\Workflow\Knowledge\KnowledgeSettings $settings
   *   Where the project's files are.
   * @param \Droost\Workflow\Knowledge\ExtensionInventory $inventory
   *   The installed extensions.
   */
  public function __construct(
    private readonly KnowledgeSettings $settings,
    private readonly ExtensionInventory $inventory,
  ) {}

  /**
   * Every document, in a stable order.
   *
   * @return list<array{path: string, title: string, kind: string, module: string, body: string}>
   *   The documents: a path a reader can open, its title, its kind (wiki,
   *   inventory, readme or doc), the extension it belongs to, and its text.
   */
  public function collect(): array {
    $docs = [];
    $wiki = $this->settings->wikiDir();
    foreach (self::markdown($wiki, 0, 6) as $file) {
      $relative = ltrim(substr($file, strlen($wiki)), '/');
      $docs[] = self::doc('wiki/' . $relative, 'wiki', '', $file);
    }
    foreach ($this->inventory->pages() as $path => $body) {
      $machine = basename($path, '.md');
      $docs[] = [
        'path' => $path,
        'title' => self::title($body, $machine),
        'kind' => 'inventory',
        'module' => $machine === 'index' ? '' : $machine,
        'body' => $body,
      ];
    }
    $root = rtrim($this->settings->projectRoot, '/');
    foreach ($this->inventory->extensions() as $machine => $entry) {
      $dir = $root . '/' . $entry['path'];
      $files = array_merge(
        glob($dir . '/README*') ?: [],
        glob($dir . '/*.md') ?: [],
        self::markdown($dir . '/docs', 0, 2),
      );
      foreach (array_values(array_unique($files)) as $file) {
        if (!is_file($file)) {
          continue;
        }
        $kind = stripos(basename($file), 'readme') === 0 ? 'readme' : 'doc';
        $docs[] = self::doc($entry['path'] . '/' . ltrim(substr($file, strlen($dir)), '/'), $kind, $machine, $file);
      }
    }
    return $docs;
  }

  /**
   * One document from a file.
   *
   * @param string $path
   *   The path a reader opens it by.
   * @param string $kind
   *   Its kind.
   * @param string $module
   *   Its extension, or ''.
   * @param string $file
   *   The file.
   *
   * @return array{path: string, title: string, kind: string, module: string, body: string}
   *   The document.
   */
  private static function doc(string $path, string $kind, string $module, string $file): array {
    $body = @file_get_contents($file, FALSE, NULL, 0, self::MAX_BYTES);
    $body = is_string($body) ? $body : '';
    return [
      'path' => $path,
      'title' => self::title($body, basename($file)),
      'kind' => $kind,
      'module' => $module,
      'body' => $body,
    ];
  }

  /**
   * A document's title: its frontmatter title, its first heading, or a name.
   *
   * @param string $body
   *   The text.
   * @param string $fallback
   *   The name to use when the text has none.
   *
   * @return string
   *   The title.
   */
  public static function title(string $body, string $fallback): string {
    if (preg_match('/^title:\s*["\']?(.+?)["\']?\s*$/m', substr($body, 0, 2000), $m) === 1 && str_starts_with($body, '---')) {
      return trim($m[1]);
    }
    if (preg_match('/^#\s+(.+)$/m', $body, $m) === 1) {
      return trim($m[1]);
    }
    return $fallback;
  }

  /**
   * Markdown files under a directory.
   *
   * @param string $dir
   *   The directory.
   * @param int $depth
   *   How deep this call is.
   * @param int $max
   *   How deep to go.
   *
   * @return list<string>
   *   The files, absolute, sorted.
   */
  public static function markdown(string $dir, int $depth, int $max): array {
    if ($depth > $max || !is_dir($dir)) {
      return [];
    }
    $out = [];
    $entries = @scandir($dir);
    foreach ($entries === FALSE ? [] : $entries as $name) {
      if ($name === '.' || $name === '..' || str_starts_with($name, '.')) {
        continue;
      }
      $path = $dir . '/' . $name;
      if (is_dir($path) && !is_link($path)) {
        array_push($out, ...self::markdown($path, $depth + 1, $max));
      }
      elseif (str_ends_with(strtolower($name), '.md')) {
        $out[] = $path;
      }
    }
    sort($out);
    return $out;
  }

}
