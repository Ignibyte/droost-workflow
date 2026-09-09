<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Nothing shipped here belongs to one company or one site.
 *
 * The workflow is contrib: the pack, the engine and the README travel to
 * every project that installs them. The first real site's identifiers — its
 * Atlassian cloud id, its Jira custom-field ids, its Confluence page ids, its
 * ticket keys — kept turning up as "examples" while that site was the only
 * one, and an example with a real id is a leak of someone's tracker
 * configuration into a public repository. Finding ids from that site's
 * ledger (F-EMT-n) are allowed: they name a fixed defect, not a site.
 */
final class PackPortabilityTest extends WorkflowTestCase {

  /**
   * The strings a contrib pack, engine or README must never carry.
   *
   * @var list<string>
   */
  private const FORBIDDEN = [
    '/46ee8f13/',
    '/customfield_1[12]\d{3}/',
    '/4946788355/',
    '/littler/i',
    '/edgemgmt/i',
    '/(?<!F-)EMT-\d/',
    '/\[EMT\]/',
    '/release\/1\.9/',
  ];

  /**
   * The pack, the engine and the README carry no site's identifiers.
   */
  public function testNoSiteIdentifiersShip(): void {
    $root = dirname(__DIR__, 2);
    $files = [$root . '/README.md'];
    foreach (['pack', 'src'] as $dir) {
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if ($file instanceof \SplFileInfo && $file->isFile()) {
          $files[] = $file->getPathname();
        }
      }
    }
    $this->assertGreaterThan(20, count($files), 'the scan saw the pack and the engine');
    $hits = [];
    foreach ($files as $path) {
      $text = (string) file_get_contents($path);
      foreach (self::FORBIDDEN as $pattern) {
        if (preg_match_all($pattern, $text, $m, PREG_OFFSET_CAPTURE) > 0) {
          foreach ($m[0] as [$match, $offset]) {
            $line = substr_count(substr($text, 0, $offset), "\n") + 1;
            $hits[] = sprintf('%s:%d — %s', substr($path, strlen($root) + 1), $line, $match);
          }
        }
      }
    }
    $this->assertSame([], $hits, "Site-specific identifiers in contrib files:\n" . implode("\n", $hits));
  }

}
