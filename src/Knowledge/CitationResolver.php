<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge;

use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\State\RunStateStore;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves a spec's Grounding citations from files and the code index.
 *
 * A citation is what the agent says it looked at: a class, a file, a wiki
 * page, or `none: <query>` for a lookup that found nothing. It resolves when
 * something this repository holds confirms it, and the FIRST store that
 * answers is reported, so the record says how far the lookup had to go:
 *
 *   - `none: x`: nothing in the symbol index and nothing on disk is `x`,
 *     and only while the index can be asked (with none, every made-up name
 *     would "re-run empty");
 *   - a qualified class: the symbol index, then where PSR-4 puts its file
 *     (an extension's `src/`, core's `lib/`, the project's Composer map),
 *     inside the project, never through a `..` segment;
 *   - a path: the index's file manifest, then a FILE on disk under the
 *     docroot or the project root, never a directory, never the run's own
 *     state (its spec included), never outside them;
 *   - `page.md` or `wiki:page.md`: a page of the project's wiki;
 *   - a bare name: the symbol index (a short class name or a module).
 *
 * Nothing here boots Drupal. The protected hooks were where droost's
 * Drupal-side grounding gate added the brain and the site's autoloader until
 * that gate left with the module (2026-10-19); they stay as the seam a test
 * (or a future surface) overrides.
 */
class CitationResolver {

  /**
   * The store a `none:` claim resolves against: a negative, not a thing.
   */
  public const ABSENT = 'absent (re-run empty)';

  /**
   * The indexes opened, by project root.
   *
   * @var array<string, \Droost\Workflow\Knowledge\CodeIndex>
   */
  private array $indexes = [];

  /**
   * Constructs a CitationResolver.
   *
   * @param \Droost\Workflow\Knowledge\CodeIndex|null $index
   *   The index to ask; NULL opens each project's own.
   */
  public function __construct(private readonly ?CodeIndex $index = NULL) {}

  /**
   * One spec's citations, resolved.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param string $spec
   *   The spec, project-relative.
   * @param string $docroot
   *   Drupal's docroot, absolute; '' finds it (see docroot()).
   *
   * @return array{table: bool, resolved: list<string>, positive: list<string>, unresolved: list<string>, uncited_tiers: list<string>, index: string}
   *   Whether the spec carries a grounding table, the citations that
   *   resolved, those of them that name something that exists (every one
   *   but a `none:` claim), those that did not resolve, each tier the table
   *   claims with nothing resolving for it, and the code index's status
   *   (`ready`, `absent`, or why it could not be read).
   */
  public function citations(string $projectRoot, string $spec, string $docroot = ''): array {
    $index = $this->index($projectRoot)->status;
    $grounding = SpecContract::grounding($projectRoot, $spec);
    if ($grounding === NULL) {
      return [
        'table' => FALSE,
        'resolved' => [],
        'positive' => [],
        'unresolved' => [],
        'uncited_tiers' => [],
        'index' => $index,
      ];
    }
    $docroot = $docroot !== '' ? $docroot : self::docroot($projectRoot);
    $prefix = self::docrootPrefix($projectRoot, $docroot);
    $resolved = [];
    $positive = [];
    $unresolved = [];
    $citedTiers = [];
    foreach ($grounding['evidence'] as $row) {
      $cite = trim($row['cite'], " `");
      $store = $this->resolveStore($cite, $projectRoot, $docroot, $prefix);
      if ($store === NULL) {
        $unresolved[] = $cite;
        continue;
      }
      $resolved[] = $cite;
      if ($store !== self::ABSENT) {
        $positive[] = $cite;
      }
      if ($row['tier'] !== '') {
        $citedTiers[$row['tier']] = TRUE;
      }
    }
    $claimed = [];
    foreach ($grounding['phases'] as $tiers) {
      foreach ($tiers as $tier) {
        $claimed[$tier] = TRUE;
      }
    }

    return [
      'table' => TRUE,
      'resolved' => $resolved,
      'positive' => $positive,
      'unresolved' => $unresolved,
      'uncited_tiers' => array_values(array_map('strval', array_keys(array_diff_key($claimed, $citedTiers)))),
      'index' => $index,
    ];
  }

  /**
   * Which store a citation resolves against, or NULL for none.
   *
   * @param string $cite
   *   The Evidence cell, as written.
   * @param string $projectRoot
   *   The repository root.
   * @param string $docroot
   *   The docroot, absolute, or ''.
   * @param string $prefix
   *   The docroot's name relative to the project root, or ''.
   *
   * @return string|null
   *   The store's label, or NULL when nothing here confirms it.
   */
  public function resolveStore(string $cite, string $projectRoot, string $docroot, string $prefix): ?string {
    $cite = self::normalise($cite);
    if ($cite === '') {
      return NULL;
    }
    $index = $this->index($projectRoot);

    // A negative claim: re-run it and insist it is still empty. It is the row
    // proving a duplicate was not about to be built, so it has to be
    // checkable. The disk counts too.
    if (preg_match('/^none:\s*(.+)$/i', $cite, $m) === 1) {
      // Only an index that can be asked can say a thing is absent: with none,
      // every made-up name "re-ran empty" and a negative claim resolved by
      // construction.
      if (!$index->ready()) {
        return NULL;
      }
      $needle = trim($m[1], " `");
      $empty = $index->symbolMatches($needle) === 0
        && $this->noneMatches($needle) === 0
        && !self::noneOnDisk($needle, $projectRoot, $docroot, $prefix);

      return $empty ? self::ABSENT : NULL;
    }

    // A class, interface, trait or enum. A `.` or `..` segment is a path
    // climbing out of the namespace, never a class.
    if (str_contains($cite, '\\')) {
      if (preg_match('/(^|\\\\)\.{1,2}(\\\\|$)/', $cite) === 1) {
        return NULL;
      }
      if ($index->symbolMatches($cite) > 0) {
        return 'symbol graph';
      }
      $store = $this->classStore($cite);
      if ($store !== NULL) {
        return $store;
      }
      // `Drupal\<extension>\…` lives where PSR-4 says, enabled or not: the
      // module the code phase has just written is not enabled yet.
      if (self::inside(self::psr4File($cite, $docroot), $projectRoot, $docroot)) {
        return 'filesystem (psr-4)';
      }

      return self::inside(self::composerFile($cite, $projectRoot, $docroot), $projectRoot, $docroot) ? 'filesystem (composer)' : NULL;
    }

    // A file. The index stores docroot-relative paths, and the citation may
    // carry the docroot in front. `config/sync/…` and `vendor/…` sit above
    // the docroot and are never indexed, so the disk is asked too.
    if (str_contains($cite, '/')) {
      $relative = self::stripPrefix(ltrim($cite, '/'), $prefix);
      if ($index->indexedFile($relative)) {
        return 'search index';
      }
      if (self::fileOnDisk($relative, $projectRoot, $docroot)) {
        return 'filesystem';
      }

      return self::isWikiCitation($cite, $projectRoot) ? 'wiki' : NULL;
    }

    // A wiki page, by its file name, at the bundle's place or as `wiki:…`.
    if (self::isWikiCitation($cite, $projectRoot)) {
      return 'wiki';
    }

    // A bare name: a module, or a class by its short name.
    if ($index->symbolMatches($cite) > 0) {
      return 'symbol graph';
    }

    return $this->nameStore($cite);
  }

  /**
   * A further store for a qualified class name, after the symbol index.
   *
   * None here. droost's gate answers from the brain and the site's
   * autoloader, which only a booted site has.
   *
   * @param string $fqcn
   *   The name, normalised.
   *
   * @return string|null
   *   The store's label, or NULL.
   */
  protected function classStore(string $fqcn): ?string {
    return NULL;
  }

  /**
   * A further store for a bare name, after the symbol index.
   *
   * @param string $name
   *   The name, normalised.
   *
   * @return string|null
   *   The store's label, or NULL.
   */
  protected function nameStore(string $name): ?string {
    return NULL;
  }

  /**
   * Further matches that falsify a `none:` claim.
   *
   * @param string $needle
   *   The claim's subject.
   *
   * @return int
   *   The match count.
   */
  protected function noneMatches(string $needle): int {
    return 0;
  }

  /**
   * The code index for a project, opened once.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return \Droost\Workflow\Knowledge\CodeIndex
   *   The index, ready or not.
   */
  protected function index(string $projectRoot): CodeIndex {
    if ($this->index !== NULL) {
      return $this->index;
    }
    $root = rtrim($projectRoot, '/');

    return $this->indexes[$root] ??= CodeIndex::open($root);
  }

  /**
   * Drupal's docroot, absolute.
   *
   * Composer's scaffold location when the project names one; else `web/` or
   * `docroot/` when it holds core; else the project root itself.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return string
   *   The docroot.
   */
  public static function docroot(string $projectRoot): string {
    $root = rtrim($projectRoot, '/');
    $composer = @file_get_contents($root . '/composer.json');
    $decoded = $composer === FALSE ? NULL : json_decode($composer, TRUE);
    foreach (['extra', 'drupal-scaffold', 'locations', 'web-root'] as $key) {
      $decoded = is_array($decoded) ? ($decoded[$key] ?? NULL) : NULL;
    }
    $webRoot = $decoded;
    if (is_string($webRoot) && trim($webRoot, './') !== '' && !str_contains($webRoot, '..')) {
      $candidate = $root . '/' . trim($webRoot, '/');
      if (is_dir($candidate)) {
        return rtrim((string) preg_replace('#/\./#', '/', $candidate . '/'), '/');
      }
    }
    foreach (['web', 'docroot'] as $name) {
      if (is_dir($root . '/' . $name . '/core')) {
        return $root . '/' . $name;
      }
    }

    return $root;
  }

  /**
   * The docroot's name relative to the project root: `web`, usually.
   *
   * The index stores docroot-relative paths (`modules/contrib/…`), while an
   * agent reads them off disk with the docroot in front.
   *
   * @param string $projectRoot
   *   The repository root.
   * @param string $docroot
   *   The docroot, or ''.
   *
   * @return string
   *   The prefix, or '' when the docroot is the project root or unknown.
   */
  public static function docrootPrefix(string $projectRoot, string $docroot): string {
    $project = rtrim($projectRoot, '/');
    $app = rtrim($docroot, '/');
    if ($app === '' || $app === $project) {
      return '';
    }
    if (str_starts_with($app, $project . '/')) {
      return trim(substr($app, strlen($project)), '/');
    }

    return basename($app);
  }

  /**
   * A citation with its decoration removed.
   *
   * Backticks and whitespace; a leading `\`; a trailing `::method()` or
   * `->method()`.
   *
   * @param string $cite
   *   As written.
   *
   * @return string
   *   As resolved.
   */
  public static function normalise(string $cite): string {
    $cite = trim($cite, " `");
    if (preg_match('/^none:/i', $cite) === 1) {
      return $cite;
    }
    $cite = ltrim($cite, '\\');
    $cite = (string) preg_replace('/(::|->)[A-Za-z_]\w*(\([^)]*\))?$/', '', $cite);

    return rtrim($cite, '()');
  }

  /**
   * Whether a citation names a page of the project's wiki that is there.
   *
   * @param string $cite
   *   The citation, normalised.
   * @param string $projectRoot
   *   The repository root.
   *
   * @return bool
   *   TRUE when the page exists.
   */
  private static function isWikiCitation(string $cite, string $projectRoot): bool {
    if (preg_match('/^(?:wiki:)?([A-Za-z0-9_.\/-]+\.md)$/', $cite, $page) !== 1 || str_contains($page[1], '..')) {
      return FALSE;
    }
    foreach (self::wikiDirs($projectRoot) as $dir) {
      if (is_file($dir . '/' . ltrim($page[1], '/'))) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Where the project's wiki pages are: the configured bundle, and default.
   *
   * @param string $projectRoot
   *   The repository root.
   *
   * @return list<string>
   *   Absolute directories.
   */
  private static function wikiDirs(string $projectRoot): array {
    $root = rtrim($projectRoot, '/');
    $dirs = [$root . '/droost/wiki'];
    $settings = $root . '/config/sync/droost.wiki.settings.yml';
    if (is_file($settings)) {
      try {
        $parsed = Yaml::parseFile($settings);
        $path = is_array($parsed) && is_string($parsed['path'] ?? NULL) ? trim($parsed['path']) : '';
        if ($path !== '' && !str_contains($path, '..')) {
          array_unshift($dirs, str_starts_with($path, '/') ? rtrim($path, '/') : $root . '/' . trim($path, '/'));
        }
      }
      catch (\Throwable) {
        // An unreadable settings file leaves the default.
      }
    }

    return array_values(array_unique($dirs));
  }

  /**
   * Where PSR-4 says a `Drupal\…` class lives, if it is there.
   *
   * An extension's class under its `src/`, wherever the extension sits; a
   * `Drupal\Core` or `Drupal\Component` class under core's `lib/`.
   *
   * @param string $fqcn
   *   The name.
   * @param string $docroot
   *   The docroot, or ''.
   *
   * @return string|null
   *   The file, or NULL.
   */
  private static function psr4File(string $fqcn, string $docroot): ?string {
    if ($docroot === '') {
      return NULL;
    }
    $root = rtrim($docroot, '/');
    if (preg_match('/^Drupal\\\\(Core|Component)\\\\(.+)$/', $fqcn, $core) === 1) {
      $file = $root . '/core/lib/Drupal/' . $core[1] . '/' . str_replace('\\', '/', $core[2]) . '.php';

      return is_file($file) ? $file : NULL;
    }
    if (preg_match('/^Drupal\\\\([a-z][a-z0-9_]*)\\\\(.+)$/', $fqcn, $m) !== 1) {
      return NULL;
    }
    $extension = $m[1];
    $relative = str_replace('\\', '/', $m[2]) . '.php';
    $candidates = array_merge(
      glob($root . '/{modules,themes,profiles}/{custom,contrib}/' . $extension . '/src/' . $relative, GLOB_BRACE) ?: [],
      glob($root . '/{modules,themes,profiles}/{custom,contrib}/*/modules/' . $extension . '/src/' . $relative, GLOB_BRACE) ?: [],
      glob($root . '/{modules,themes,profiles}/' . $extension . '/src/' . $relative, GLOB_BRACE) ?: [],
      [
        $root . '/core/modules/' . $extension . '/src/' . $relative,
        $root . '/core/themes/' . $extension . '/src/' . $relative,
        $root . '/core/profiles/' . $extension . '/src/' . $relative,
      ],
    );
    foreach ($candidates as $candidate) {
      if (is_file($candidate)) {
        return $candidate;
      }
    }

    return NULL;
  }

  /**
   * Where the project's Composer PSR-4 map puts a class, if it is there.
   *
   * Read from `vendor/composer/autoload_psr4.php`, the map Composer
   * generates; the class itself is never loaded.
   *
   * @param string $fqcn
   *   The name.
   * @param string $projectRoot
   *   The repository root.
   * @param string $docroot
   *   The docroot, or ''.
   *
   * @return string|null
   *   The file, or NULL.
   */
  private static function composerFile(string $fqcn, string $projectRoot, string $docroot): ?string {
    // The project's map, and the docroot's parent's when that is inside the
    // project (a `site/` layout); never a directory above the project.
    $project = rtrim($projectRoot, '/');
    $parent = $docroot !== '' ? dirname(rtrim($docroot, '/')) : '';
    $roots = array_unique(array_filter([$project, str_starts_with($parent . '/', $project . '/') ? $parent : '']));
    foreach ($roots as $root) {
      $map = $root . '/vendor/composer/autoload_psr4.php';
      if (!is_file($map)) {
        continue;
      }
      try {
        $prefixes = (static fn (string $file): mixed => require $file)($map);
      }
      catch (\Throwable) {
        continue;
      }
      if (!is_array($prefixes)) {
        continue;
      }
      foreach ($prefixes as $namespace => $dirs) {
        if (!is_string($namespace) || !str_starts_with($fqcn, $namespace) || !is_array($dirs)) {
          continue;
        }
        $relative = str_replace('\\', '/', substr($fqcn, strlen($namespace))) . '.php';
        foreach ($dirs as $dir) {
          if (is_string($dir) && is_file(rtrim($dir, '/') . '/' . $relative)) {
            return rtrim($dir, '/') . '/' . $relative;
          }
        }
      }
    }

    return NULL;
  }

  /**
   * A FILE a citation names, inside the project and outside the run's state.
   *
   * A directory (`./`, `modules/`), the project root, the state directory and
   * the spec itself all exist by construction, so citing them proved nothing
   * was looked up (S4-2). Only a file counts, and none of the run's own.
   *
   * @param string $relative
   *   Docroot-relative, no leading slash.
   * @param string $projectRoot
   *   The repository root.
   * @param string $docroot
   *   The docroot, or ''.
   *
   * @return bool
   *   TRUE for a file there that is evidence.
   */
  private static function fileOnDisk(string $relative, string $projectRoot, string $docroot): bool {
    if (!self::onDisk($relative, $projectRoot, $docroot)) {
      return FALSE;
    }
    $project = realpath(rtrim($projectRoot, '/'));
    foreach (array_filter([$docroot !== '' ? realpath(rtrim($docroot, '/')) : FALSE, $project]) as $root) {
      $real = realpath($root . '/' . $relative);
      if ($real === FALSE || !is_file($real)) {
        continue;
      }
      foreach ([RunStateStore::STATE_DIR, RunStateStore::LEGACY_STATE_DIR] as $state) {
        if ($project !== FALSE && str_starts_with($real, $project . '/' . $state . '/')) {
          return FALSE;
        }
      }
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Whether a file a class citation resolved to is inside the project.
   *
   * @param string|null $file
   *   The file, or NULL.
   * @param string $projectRoot
   *   The repository root.
   * @param string $docroot
   *   The docroot, or ''.
   *
   * @return bool
   *   TRUE when it is a file inside the project or the docroot.
   */
  private static function inside(?string $file, string $projectRoot, string $docroot): bool {
    if ($file === NULL) {
      return FALSE;
    }
    $real = realpath($file);
    if ($real === FALSE || !is_file($real)) {
      return FALSE;
    }
    foreach (array_filter([realpath(rtrim($projectRoot, '/')), $docroot !== '' ? realpath(rtrim($docroot, '/')) : FALSE]) as $bound) {
      if (str_starts_with($real, $bound . '/')) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Whether a path exists under the docroot or the project root, inside them.
   *
   * @param string $relative
   *   Docroot-relative, no leading slash.
   * @param string $projectRoot
   *   The repository root.
   * @param string $docroot
   *   The docroot, or ''.
   *
   * @return bool
   *   TRUE for a file or directory that is there and inside the project.
   */
  private static function onDisk(string $relative, string $projectRoot, string $docroot): bool {
    if ($relative === '' || str_contains($relative, "\0")) {
      return FALSE;
    }
    $project = realpath(rtrim($projectRoot, '/'));
    if ($project === FALSE) {
      return FALSE;
    }
    $roots = [$project];
    if ($docroot !== '') {
      $app = realpath(rtrim($docroot, '/'));
      if ($app !== FALSE && $app !== $project) {
        array_unshift($roots, $app);
      }
    }
    foreach ($roots as $root) {
      $real = realpath($root . '/' . $relative);
      if ($real === FALSE) {
        continue;
      }
      // Never outside the project: `../../etc/passwd` exists and is nobody's
      // evidence.
      foreach ($roots as $bound) {
        if ($real === $bound || str_starts_with($real, $bound . '/')) {
          return TRUE;
        }
      }
    }

    return FALSE;
  }

  /**
   * A path with the docroot's name removed from the front, if it was there.
   *
   * @param string $path
   *   No leading slash.
   * @param string $prefix
   *   The docroot's name, or ''.
   *
   * @return string
   *   The path as the index stores it.
   */
  private static function stripPrefix(string $path, string $prefix): string {
    if ($prefix !== '' && str_starts_with($path, $prefix . '/')) {
      return substr($path, strlen($prefix) + 1);
    }

    return $path;
  }

  /**
   * Whether the thing a `none:` claim denies is on disk.
   *
   * A path-shaped needle is checked as a path; a machine name as an
   * extension directory. Anything else has no disk form.
   *
   * @param string $needle
   *   The claim's subject.
   * @param string $projectRoot
   *   The repository root.
   * @param string $docroot
   *   The docroot, or ''.
   * @param string $prefix
   *   The docroot's name, or ''.
   *
   * @return bool
   *   TRUE when it exists, which falsifies the claim.
   */
  private static function noneOnDisk(string $needle, string $projectRoot, string $docroot, string $prefix): bool {
    if (str_contains($needle, '/')) {
      return self::onDisk(self::stripPrefix(ltrim($needle, '/'), $prefix), $projectRoot, $docroot);
    }
    if ($docroot === '' || preg_match('/^[a-z][a-z0-9_]*$/', $needle) !== 1) {
      return FALSE;
    }
    $root = rtrim($docroot, '/');
    $dirs = array_merge(
      glob($root . '/{modules,themes,profiles}/{custom,contrib}/' . $needle, GLOB_BRACE | GLOB_ONLYDIR) ?: [],
      glob($root . '/{modules,themes,profiles}/{custom,contrib}/*/modules/' . $needle, GLOB_BRACE | GLOB_ONLYDIR) ?: [],
      [$root . '/core/modules/' . $needle],
    );
    foreach ($dirs as $dir) {
      if (is_dir($dir)) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
