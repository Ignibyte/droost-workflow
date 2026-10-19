<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Engine\Wiki\FileHasher;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\Knowledge\DroostCli;
use PHPUnit\Framework\TestCase;

/**
 * The wiki's status and stamp commands, over a project on disk, no drush.
 *
 * The report is the wiki_fresh gate's measurement, so every verdict here is
 * made to come out both ways: fresh and stale, covered and uncovered, a
 * stamp that lands and one that is refused.
 */
final class WikiStatusTest extends TestCase {

  use KnowledgeFixture;
  use TypedReads;

  /**
   * The project.
   */
  private string $root;

  /**
   * What the last command printed to stdout.
   *
   * @var list<string>
   */
  private array $out = [];

  /**
   * What it printed to stderr.
   *
   * @var list<string>
   */
  private array $err = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->root = sys_get_temp_dir() . '/droost-wiki-' . bin2hex(random_bytes(4));
    mkdir($this->root, 0755, TRUE);
    $this->put($this->root, 'composer.json', (string) json_encode(['extra' => ['drupal-scaffold' => ['locations' => ['web-root' => 'web/']]]]));
    $packages = [
      ['name' => 'drupal/core', 'version' => '11.2.0'],
      ['name' => 'drupal/paragraphs', 'version' => '1.18.0'],
    ];
    $this->put($this->root, 'composer.lock', (string) json_encode(['packages' => $packages]));
    $this->put($this->root, 'web/core/lib/Drupal.php', "<?php\nclass Drupal {\n  const VERSION = '11.2.0';\n}\n");
    $this->put($this->root, 'web/modules/custom/camps/camps.info.yml', "name: Camps\ntype: module\n");
    $this->put($this->root, 'web/modules/custom/camps/camps.module', "<?php\n\nfunction camps_cron() {\n}\n");
    $this->put($this->root, 'web/modules/contrib/paragraphs/paragraphs.info.yml', "name: Paragraphs\ntype: module\n");
    $this->put($this->root, 'web/modules/contrib/paragraphs/src/Entity/Paragraph.php', "<?php\n\nnamespace Drupal\\paragraphs\\Entity;\n\nclass Paragraph {\n}\n");
    $this->put($this->root, 'config/sync/core.extension.yml', "module:\n  camps: 0\n  paragraphs: 0\n  system: 0\ntheme:\n  olivero: 0\n");
    $this->put($this->root, 'droost/wiki/overview.md', "# Overview\n\nHand-written: no provenance, never stale.\n");
    $this->managedPage('camps.md', 'camps', ['web/modules/custom/camps/camps.module']);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
      if ($file instanceof \SplFileInfo) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
    }
    rmdir($this->root);
    parent::tearDown();
  }

  /**
   * Fresh passes; a changed source fails it; a stamp makes it fresh again.
   */
  public function testFreshStaleAndStamped(): void {
    $this->assertSame(0, $this->droost(['code:wiki', 'status', '--json']));
    $report = $this->report();
    $summary = [
      'pages' => 2,
      'fresh' => 1,
      'stale' => 0,
      'orphaned' => 0,
      'invalid' => 0,
      'unmanaged' => 1,
      'uncovered_modules' => 0,
    ];
    $this->assertSame($summary, $this->dig($report, 'summary'));
    $this->assertTrue($this->dig($report, 'ok'));
    $this->assertSame('droost/wiki', $this->dig($report, 'bundle_path'));
    $this->assertStringContainsString('core.extension.yml', $this->digString($report, 'installed_from'));
    $this->assertFalse($this->dig($report, 'contrib_scope_measured'), 'No index: contrib scope is not measured, and the report says so.');

    file_put_contents($this->root . '/web/modules/custom/camps/camps.module', "<?php\n\nfunction camps_cron() {\n  // Changed.\n}\n");
    $this->assertSame(1, $this->droost(['code:wiki', 'status', '--json']));
    $report = $this->report();
    $this->assertSame(1, $this->dig($report, 'summary.stale'));
    $this->assertFalse($this->dig($report, 'ok'));
    $page = $this->page($report, 'camps.md');
    $this->assertSame('stale', $this->dig($page, 'status'));
    $this->assertSame('web/modules/custom/camps/camps.module', $this->dig($page, 'stale_sources.0.path'));

    $before = (string) file_get_contents($this->root . '/droost/wiki/camps.md');
    $this->assertSame(0, $this->droost(['code:wiki', 'stamp', 'camps', '--dry-run']));
    $this->assertSame('would-stamp', $this->dig($this->envelope(), 'data.rows.0.action'));
    $this->assertSame($before, file_get_contents($this->root . '/droost/wiki/camps.md'), 'A dry run writes nothing.');

    $this->assertSame(0, $this->droost(['code:wiki', 'stamp', 'camps']));
    $this->assertSame('stamped', $this->dig($this->envelope(), 'data.rows.0.action'));
    $this->assertStringContainsString('Body stays as written.', (string) file_get_contents($this->root . '/droost/wiki/camps.md'), 'Only the claim moves, never the prose.');
    $this->assertSame(0, $this->droost(['code:wiki', 'status', '--json']));
    $this->assertSame(1, $this->dig($this->report(), 'summary.fresh'));

    // The human form says the same, a line per page.
    $this->assertSame(0, $this->droost(['code:wiki', 'status']));
    $this->assertStringContainsString('fresh      camps.md', implode("\n", $this->out));
    $this->assertStringContainsString('Wiki: 1 fresh, 0 stale', implode("\n", $this->out));
  }

  /**
   * A missing source orphans the page; stamping it is refused.
   */
  public function testOrphanedPageFailsAndCannotBeStamped(): void {
    unlink($this->root . '/web/modules/custom/camps/camps.module');
    $this->assertSame(1, $this->droost(['code:wiki', 'status', '--json']));
    $this->assertSame(1, $this->dig($this->report(), 'summary.orphaned'));
    $missing = $this->dig($this->page($this->report(), 'camps.md'), 'missing_sources');
    $this->assertSame(['web/modules/custom/camps/camps.module'], $missing);

    // An orphan is not stale: a sweep finds nothing, which is not a failure.
    $this->assertSame(0, $this->droost(['code:wiki', 'stamp', '--all-stale']));
    $this->assertSame('Nothing to stamp: no managed page is stale.', $this->dig($this->envelope(), 'message'));
    $this->assertSame(1, $this->droost(['code:wiki', 'stamp', 'camps.md']));
    $this->assertSame('refused', $this->dig($this->envelope(), 'data.rows.0.action'));
  }

  /**
   * An installed custom extension with no page is named; --strict fails on it.
   */
  public function testUncoveredAndStrict(): void {
    $this->put($this->root, 'web/modules/custom/trips/trips.info.yml', "name: Trips\ntype: module\n");
    $this->put($this->root, 'web/modules/custom/unused/unused.info.yml', "name: Unused\ntype: module\n");
    $this->put($this->root, 'config/sync/core.extension.yml', "module:\n  camps: 0\n  trips: 0\n  paragraphs: 0\ntheme:\n  olivero: 0\n");

    $this->assertSame(0, $this->droost(['code:wiki', 'status', '--json']));
    $this->assertSame(['trips'], $this->dig($this->report(), 'uncovered'), 'Enabled and uncovered is owed a page; not enabled is not.');
    $this->assertSame(1, $this->droost(['code:wiki', 'status', '--json', '--strict']));
    $this->assertFalse($this->dig($this->report(), 'ok'));
  }

  /**
   * With an index, contrib the custom code reaches is owed a page too.
   */
  public function testContribTheCustomCodeReaches(): void {
    $this->put($this->root, 'web/modules/custom/camps/src/Session.php', "<?php\n\nnamespace Drupal\\camps;\n\nuse Drupal\\paragraphs\\Entity\\Paragraph;\n\nclass Session extends Paragraph {\n}\n");
    $this->managedPage('camps.md', 'camps', ['web/modules/custom/camps/camps.module']);
    $this->assertSame(0, $this->droost(['code:index']));

    $this->assertSame(0, $this->droost(['code:wiki', 'status', '--json']));
    $report = $this->report();
    $this->assertTrue($this->dig($report, 'contrib_scope_measured'));
    $this->assertSame(['paragraphs'], $this->dig($report, 'uncovered'));

    $this->put($this->root, 'droost/droost.yml', "wiki:\n  contrib_scope: all\n");
    $this->assertSame(0, $this->droost(['code:wiki', 'status', '--json']));
    $this->assertSame(['paragraphs'], $this->dig($this->report(), 'uncovered'));
  }

  /**
   * No wiki is a failure with a reason; a stranger flag is a usage error.
   */
  public function testNoWikiAndUsage(): void {
    $this->assertSame(2, $this->droost(['code:wiki', 'status', '--table']));
    $this->assertSame(2, $this->droost(['code:wiki', 'stamp']));
    $this->assertSame(2, $this->droost(['code:wiki', 'stamp', 'a', 'b']));

    $this->put($this->root, 'droost/droost.yml', "wiki:\n  path: docs/nowhere\n");
    $this->assertSame(1, $this->droost(['code:wiki', 'status', '--json']));
    $decoded = json_decode(implode("\n", $this->out), TRUE);
    $this->assertIsArray($decoded);
    $this->assertFalse($this->dig($decoded, 'ok'));
    $this->assertArrayNotHasKey('summary', $decoded, 'With no report, the gate must not read a summary of zeros.');
    $this->assertStringContainsString('docs/nowhere', implode("\n", $this->err));
  }

  /**
   * The wiki_fresh gate reads this report as it read drush's.
   *
   * Through the gate's own parser, so a shape that drifted from what it reads
   * would fail here rather than as an unverified pass in a run.
   */
  public function testTheGateReadsTheReport(): void {
    $parse = new \ReflectionMethod(ShellGateExecutor::class, 'wikiResult');

    $exit = $this->droost(['code:wiki', 'status', '--json']);
    $result = $parse->invoke(NULL, $exit, 5, implode("\n", $this->out), 'droost code:wiki status --json');
    $this->assertInstanceOf(GateResult::class, $result);
    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertStringContainsString('1 of 2 page(s) fresh', $result->summary);

    file_put_contents($this->root . '/web/modules/custom/camps/camps.module', "<?php\n// Changed.\n");
    $exit = $this->droost(['code:wiki', 'status', '--json']);
    $result = $parse->invoke(NULL, $exit, 5, implode("\n", $this->out), 'droost code:wiki status --json');
    $this->assertInstanceOf(GateResult::class, $result);
    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertStringContainsString('1 stale', $result->summary);
    $this->assertSame('wiki.stale', $this->dig($result->findings, '0.rule'));
  }

  /**
   * A status run is the gate's measurement, not the agent consulting the wiki.
   */
  public function testStatusWritesNoLedgerRow(): void {
    $this->droost(['code:wiki', 'status', '--json']);
    $this->assertFileDoesNotExist($this->root . '/droost/droost-workflow/tool-calls.jsonl');
    $this->droost(['code:wiki']);
    $this->assertStringContainsString('"code:wiki"', (string) file_get_contents($this->root . '/droost/droost-workflow/tool-calls.jsonl'));
  }

  /**
   * Writes a managed page whose sources hash as they stand now.
   *
   * @param string $file
   *   The bundle-relative page.
   * @param string $module
   *   The extension it covers.
   * @param list<string> $sources
   *   Project-relative source files.
   */
  private function managedPage(string $file, string $module, array $sources): void {
    $hasher = new FileHasher();
    $lines = '';
    foreach ($sources as $source) {
      $lines .= sprintf("    - path: %s\n      hash: \"%s\"\n", $source, $hasher->hash($this->root . '/' . $source));
    }
    $this->put($this->root, 'droost/wiki/' . $file, "---\ntype: \"Drupal Module\"\ntitle: \"$module\"\ndroost:\n  spec: 1\n  modules: [$module]\n  sources:\n$lines---\n\n# $module\n\nBody stays as written.\n");
  }

  /**
   * Runs the droost command.
   *
   * @param list<string> $argv
   *   The arguments.
   *
   * @return int
   *   The exit code.
   */
  private function droost(array $argv): int {
    $this->out = [];
    $this->err = [];
    $cli = new DroostCli(
      $this->root,
      static fn (array $line): int => 0,
      dirname(__DIR__, 2),
      function (string $line): void {
        $this->out[] = $line;
      },
      function (string $line): void {
        $this->err[] = $line;
      },
    );
    return $cli->run($argv);
  }

  /**
   * The last status report.
   *
   * @return array<array-key, mixed>
   *   The decoded report.
   */
  private function report(): array {
    $decoded = json_decode(implode("\n", $this->out), TRUE);
    $this->assertIsArray($decoded, implode("\n", [...$this->out, ...$this->err]));
    $this->assertArrayHasKey('summary', $decoded);
    return $decoded;
  }

  /**
   * The last envelope.
   *
   * @return array<array-key, mixed>
   *   The decoded answer.
   */
  private function envelope(): array {
    $decoded = json_decode(implode("\n", $this->out), TRUE);
    $this->assertIsArray($decoded, implode("\n", [...$this->out, ...$this->err]));
    return $decoded;
  }

  /**
   * One page's row in a report.
   *
   * @param array<array-key, mixed> $report
   *   The report.
   * @param string $file
   *   The page.
   *
   * @return array<array-key, mixed>
   *   The row.
   */
  private function page(array $report, string $file): array {
    foreach ($this->digArray($report, 'pages') as $page) {
      if (is_array($page) && ($page['file'] ?? NULL) === $file) {
        return $page;
      }
    }
    $this->fail('No row for ' . $file);
  }

}
