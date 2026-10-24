<?php

declare(strict_types=1);

namespace Droost\Workflow\Knowledge\Wiki;

use Droost\Engine\Support\PathGuard;
use Droost\Engine\Wiki\BundleReader;

/**
 * Re-records a stale page's source hashes: `droost code:wiki stamp`.
 *
 * Stamping moves the CLAIM, not the page: it asserts the prose is still
 * accurate after its sources changed. Only a stale page can be stamped; an
 * orphaned, invalid or unmanaged one is refused with what to do instead, and
 * a stamp that does not leave the page fresh is rolled back to its original
 * bytes. No AI is involved: writing a page's prose is the agent's or a
 * person's, never this command's.
 */
final class WikiStamper {

  /**
   * The largest page a stamp rewrites.
   */
  private const int MAX_PAGE_BYTES = 1048576;

  /**
   * Constructs a WikiStamper.
   *
   * @param string $bundlePath
   *   The wiki's directory, absolute.
   * @param \Droost\Workflow\Knowledge\Wiki\WikiStatus $status
   *   The freshness checker.
   * @param \Droost\Workflow\Knowledge\Wiki\HashRewriter $rewriter
   *   The provenance block's hash rewriter.
   */
  public function __construct(
    private readonly string $bundlePath,
    private readonly WikiStatus $status,
    private readonly HashRewriter $rewriter,
  ) {}

  /**
   * The pages a stamp run targets.
   *
   * @param bool $allStale
   *   Every stale page.
   * @param string|null $page
   *   One page: a bundle-relative path or a module name.
   *
   * @return list<string>
   *   Bundle-relative paths. A named page is always attempted.
   */
  public function targets(bool $allStale, ?string $page): array {
    if (!is_dir($this->bundlePath)) {
      return [];
    }
    $pages = $this->status->report()['pages'];
    if ($allStale) {
      $stale = [];
      foreach ($pages as $row) {
        if ($row['status'] === 'stale') {
          $stale[] = $row['file'];
        }
      }
      return $stale;
    }
    if ($page === NULL) {
      return [];
    }
    $known = [];
    foreach ($pages as $row) {
      $known[$row['file']] = TRUE;
    }
    // The path as written, then what someone typing a module name means.
    foreach ([$page, $page . '.md', 'modules/' . $page . '.md'] as $candidate) {
      $candidate = ltrim(str_replace('\\', '/', $candidate), '/');
      if (isset($known[$candidate])) {
        return [$candidate];
      }
    }
    return [$page];
  }

  /**
   * Stamps one page.
   *
   * @param string $file
   *   The bundle-relative page path.
   * @param bool $dryRun
   *   Report what would move; write nothing.
   *
   * @return \Droost\Workflow\Knowledge\Wiki\StampRow
   *   The outcome.
   */
  public function stamp(string $file, bool $dryRun): StampRow {
    if (BundleReader::isReserved($file)) {
      return StampRow::refused($file, 'reserved', sprintf('"%s" is a reserved OKF page and never carries provenance.', $file));
    }
    $row = $this->status->classifyPage($file);
    if ($row['status'] === 'fresh') {
      return StampRow::skipped($file);
    }
    if ($row['status'] === 'unmanaged') {
      return StampRow::refused($file, 'unmanaged', 'The page carries no provenance block, so it has no hashes to refresh. Hand-written pages never fail the gate.');
    }
    if ($row['status'] === 'invalid') {
      $error = $row['error'] ?? 'the provenance block is malformed';
      return StampRow::refused($file, 'invalid', sprintf('%s — fix the block by hand first; stamping cannot repair it.', ucfirst($error)));
    }
    if ($row['status'] === 'orphaned') {
      return StampRow::refused($file, 'orphaned', sprintf(
        'These sources no longer exist: %s. A deleted file cannot be re-hashed — rewrite the page, or edit its source list to match what it now describes.',
        implode(', ', $row['missing_sources']),
      ));
    }

    $updates = [];
    $moved = [];
    foreach ($row['stale_sources'] as $source) {
      if (preg_match('/^[a-z0-9]+:[0-9a-f]+$/', $source['current']) !== 1) {
        // "(unreadable)": writing it would forge a hash.
        return StampRow::refused($file, 'unreadable', sprintf('Source "%s" exists but cannot be read, so no honest hash can be recorded for it.', $source['path']));
      }
      $updates[$source['path']] = $source['current'];
      $moved[] = ['path' => $source['path'], 'from' => $source['recorded'], 'to' => $source['current']];
    }
    if ($updates === []) {
      return StampRow::failed($file, 'not-fresh', 'The page reports stale but names no changed source.');
    }
    if ($dryRun) {
      return StampRow::wouldStamp($file, $moved);
    }

    $target = PathGuard::resolve($this->bundlePath, ltrim(str_replace('\\', '/', $file), '/'));
    if ($target === NULL) {
      return StampRow::refused($file, 'missing', 'The page does not resolve inside the wiki bundle.');
    }
    $original = @file_get_contents($target, FALSE, NULL, 0, self::MAX_PAGE_BYTES + 1);
    if ($original === FALSE) {
      return StampRow::refused($file, 'missing', 'The page cannot be read.');
    }
    if (strlen($original) > self::MAX_PAGE_BYTES) {
      return StampRow::refused($file, 'missing', sprintf('The page exceeds the %d-byte stamping cap.', self::MAX_PAGE_BYTES));
    }
    try {
      $page = $this->rewriter->rewrite($original, $updates);
    }
    catch (\RuntimeException $e) {
      return StampRow::refused($file, 'rewrite', sprintf('Cannot rewrite the provenance block: %s.', $e->getMessage()));
    }
    if (!self::atomicWrite($target, $page)) {
      return StampRow::failed($file, 'write-error', 'The page could not be written.');
    }

    $after = $this->status->classifyPage($file);
    if ($after['status'] !== 'fresh') {
      // Never leave a page the checker does not accept: restore the bytes.
      $restored = self::atomicWrite($target, $original);
      return StampRow::failed($file, 'not-fresh', sprintf(
        'The stamped page classified "%s" rather than fresh, so it was %s.',
        $after['status'],
        $restored ? 'rolled back' : 'LEFT MODIFIED — the rollback also failed',
      ));
    }
    return StampRow::stamped($file, $moved);
  }

  /**
   * Writes a file through a temporary sibling and a rename.
   *
   * @param string $target
   *   The file.
   * @param string $page
   *   Its new content.
   *
   * @return bool
   *   Whether it was written whole.
   */
  private static function atomicWrite(string $target, string $page): bool {
    foreach (glob($target . '.tmp.*') ?: [] as $stale) {
      @unlink($stale);
    }
    $tmp = $target . '.tmp.' . uniqid();
    $bytes = @file_put_contents($tmp, $page);
    if ($bytes === FALSE || $bytes !== strlen($page)) {
      @unlink($tmp);
      return FALSE;
    }
    if (!@rename($tmp, $target)) {
      @unlink($tmp);
      return FALSE;
    }
    return TRUE;
  }

}
