<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Gate;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Config\Phase;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Gate\GateExecutorInterface;
use Droost\Workflow\Gate\GateResult;
use Droost\Workflow\Gate\GateRunner;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Gate\ShellGateExecutor;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\VcsInterface;

/**
 * The wiki gate fails on the pages a run made unfresh, not every page.
 *
 * A client's run failed complete on a contrib theme's page that a
 * dependency bump had left stale before the run began, and spent a retry on
 * a page nothing in its scope could fix.
 */
final class WikiHeldToTheRunTest extends WorkflowTestCase {

  private const GIN = ['page' => 'gin.md', 'sources' => 'web/themes/contrib/gin/gin.info.yml'];

  private const OWN = [
    'page' => 'example_contact.md',
    'sources' => 'web/modules/custom/example_contact/src/Form/ContactForm.php',
  ];

  /**
   * A page the run did not make stale is named, and does not fail the gate.
   */
  public function testPageFoundStaleDoesNotFailTheRun(): void {
    $wiki = $this->wikiAfter("preset: high\n", [self::GIN], ['web/modules/custom/example_contact/src/Form/ContactForm.php']);

    $this->assertSame(GateStatus::Passed, $wiki->status);
    $this->assertStringContainsString('already unfresh', $wiki->summary);
    $this->assertStringContainsString('gin.md is stale (changed: web/themes/contrib/gin/gin.info.yml)', $wiki->summary);
  }

  /**
   * A page whose source the run changed fails it, by name.
   */
  public function testPageTheRunMadeStaleFailsIt(): void {
    $wiki = $this->wikiAfter("preset: high\n", [self::GIN, self::OWN], ['web/modules/custom/example_contact/src/Form/ContactForm.php']);

    $this->assertSame(GateStatus::Failed, $wiki->status);
    $this->assertStringContainsString('1 page(s) this run made unfresh: example_contact.md', $wiki->summary);
    $this->assertStringContainsString('gin.md', $wiki->summary, 'the one found stale is still named');
  }

  /**
   * Scope all holds the run to every page, as before.
   */
  public function testScopeAllHoldsEveryPage(): void {
    $wiki = $this->wikiAfter("preset: high\ngates:\n  wiki_fresh: { scope: all }\n", [self::GIN], ['web/modules/custom/example_contact/src/Form/ContactForm.php']);

    $this->assertSame(GateStatus::Failed, $wiki->status);
    $this->assertStringNotContainsString('already unfresh', $wiki->summary);
  }

  /**
   * With no diff to read, every page holds, and the summary says why.
   */
  public function testAnUnreadableDiffHoldsEveryPage(): void {
    $wiki = $this->wikiAfter("preset: high\n", [self::GIN], NULL);

    $this->assertSame(GateStatus::Failed, $wiki->status);
    $this->assertStringContainsString('held to every page', $wiki->summary);
  }

  /**
   * The executor turns the status report's failing pages into rows.
   */
  public function testTheExecutorKeepsEachFailingPageAndItsSources(): void {
    $report = json_encode([
      'summary' => ['pages' => 2, 'fresh' => 1, 'stale' => 1, 'orphaned' => 0, 'invalid' => 0],
      'pages' => [
        [
          'file' => 'gin.md',
          'status' => 'stale',
          'stale_sources' => [
            ['path' => 'web/themes/contrib/gin/gin.info.yml', 'recorded' => 'a', 'current' => 'b'],
          ],
          'missing_sources' => [],
        ],
        ['file' => 'example_contact.md', 'status' => 'fresh', 'stale_sources' => [], 'missing_sources' => []],
      ],
    ]);
    $method = new \ReflectionMethod(ShellGateExecutor::class, 'wikiResult');
    $result = $method->invoke(NULL, 1, 5, (string) $report, 'drush droost:wiki:status --format=json');

    $this->assertInstanceOf(GateResult::class, $result);
    $rows = array_values(array_filter($result->findings, static fn (array $f): bool => ($f['rule'] ?? NULL) === 'wiki.stale'));
    $this->assertCount(1, $rows);
    $this->assertSame('gin.md', $rows[0]['page']);
    $this->assertSame('web/themes/contrib/gin/gin.info.yml', $rows[0]['sources']);
  }

  /**
   * The wiki gate's result at complete.
   *
   * @param string $yaml
   *   The lever file.
   * @param list<array{page: string, sources: string}> $stale
   *   The pages the status report says are stale.
   * @param list<string>|null $changed
   *   The run's changed files, or NULL for a diff that cannot be read.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The wiki gate's result.
   */
  private function wikiAfter(string $yaml, array $stale, ?array $changed): GateResult {
    $root = $this->makeRootWithConfig($yaml);
    $runner = new GateRunner($this->executor($stale), $this->vcs($changed));
    $state = RunState::begin('run-1', '2026-10-15T00:00:00+00:00', WorkflowConfig::load($root), 'abc', NULL);
    foreach ($runner->run($state, Phase::Complete, $root)->results as $result) {
      if ($result->gate === 'wiki_fresh') {
        return $result;
      }
    }
    $this->fail('wiki_fresh did not run at complete');
  }

  /**
   * An executor that passes every gate but the wiki, which finds stale pages.
   *
   * @param list<array{page: string, sources: string}> $stale
   *   The stale pages.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The executor.
   */
  private function executor(array $stale): GateExecutorInterface {
    return new class($stale) implements GateExecutorInterface {

      /**
       * Constructs the fake.
       *
       * @param list<array{page: string, sources: string}> $stale
       *   The stale pages.
       */
      public function __construct(private readonly array $stale) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        if ($gate->name !== 'wiki_fresh') {
          return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
        }
        $findings = array_map(
          static fn (array $page): array => [
            'rule' => 'wiki.stale',
            'message' => sprintf('%s is stale (changed: %s)', $page['page'], $page['sources']),
            'page' => $page['page'],
            'sources' => $page['sources'],
          ],
          $this->stale,
        );
        return GateResult::ran(
          'wiki_fresh',
          GateStatus::Failed,
          1,
          1,
          sprintf('wiki_fresh FAILED — %d stale, 0 orphaned, 0 invalid of 23 page(s)', count($this->stale)),
          $findings,
          'drush droost:wiki:status --format=json',
        );
      }

    };
  }

  /**
   * A fake vcs, or one with no repository to ask.
   *
   * @param list<string>|null $changed
   *   The changed files, or NULL for no repository.
   *
   * @return \Droost\Workflow\Vcs\VcsInterface
   *   The vcs.
   */
  private function vcs(?array $changed): VcsInterface {
    return new class($changed) implements VcsInterface {

      /**
       * Constructs the fake.
       *
       * @param list<string>|null $changed
       *   The changed files, or NULL for no repository.
       */
      public function __construct(private readonly ?array $changed) {}

      /**
       * {@inheritdoc}
       */
      public function head(string $projectRoot): string {
        return 'abc';
      }

      /**
       * {@inheritdoc}
       */
      public function isRepository(string $projectRoot): bool {
        return $this->changed !== NULL;
      }

      /**
       * {@inheritdoc}
       */
      public function changedFiles(string $projectRoot, ?string $base): array {
        return $this->changed ?? [];
      }

    };
  }

}
