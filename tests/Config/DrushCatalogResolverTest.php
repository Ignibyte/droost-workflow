<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ContributedGate;
use Droost\Workflow\Config\DrushCatalogResolver;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The standalone surface asks drush for the site's contributed gates.
 *
 * R31-F3: a run begun with the standalone binary was held to fewer gates than
 * the same run through drush or the MCP tool, because that surface boots no
 * Drupal and saw none of what enabled modules declared — and nothing said so.
 */
final class DrushCatalogResolverTest extends WorkflowTestCase {

  /**
   * The argv the fake drush received, for the assertions.
   *
   * @var list<string>
   */
  private array $argv = [];

  /**
   * A site that answers is read back whole, and the source names drush.
   */
  public function testReadsTheCatalogThroughDrush(): void {
    $root = $this->rootWithDrush();
    $snyk = new ContributedGate('snyk', 'droost_snyk', ['code', 'test'], 'snyk test', 'report', 'exit 0: clean; exit 1: findings.');
    $resolved = $this->resolver(0, "Note: chatter first\n" . json_encode([$snyk->toArray()], JSON_THROW_ON_ERROR))->resolve($root);

    $this->assertCount(1, $resolved['gates']);
    $this->assertSame('module:snyk', $resolved['gates'][0]->name());
    $this->assertSame('droost_snyk', $resolved['gates'][0]->provider);
    $this->assertSame('report', $resolved['gates'][0]->defaultMode);
    $this->assertSame(['code', 'test'], $resolved['gates'][0]->phases);
    $this->assertStringContainsString('the booted site, asked through `vendor/bin/drush droost:workflow:catalog` (1 contributed gate)', $resolved['source']);
    $this->assertSame([$root . '/vendor/bin/drush', 'droost:workflow:catalog', '--no-interaction'], $this->argv);
  }

  /**
   * A site with nothing to declare is an empty catalog with a named source.
   */
  public function testAnEmptyCatalogIsStillResolved(): void {
    $resolved = $this->resolver(0, "[]\n")->resolve($this->rootWithDrush());
    $this->assertSame([], $resolved['gates']);
    $this->assertStringContainsString('(0 contributed gates)', $resolved['source']);
    $this->assertStringNotContainsString('unresolved', $resolved['source']);
  }

  /**
   * No drush: nothing to ask, and the source says exactly that.
   */
  public function testNoDrushIsUnresolvedByName(): void {
    $root = $this->makeRoot();
    $called = FALSE;
    $resolver = new DrushCatalogResolver(static function () use (&$called): array {
      $called = TRUE;
      return [0, '[]', ''];
    });
    $resolved = $resolver->resolve($root);
    $this->assertSame([], $resolved['gates']);
    $this->assertStringContainsString('unresolved — no vendor/bin/drush', $resolved['source']);
    $this->assertStringContainsString("held to the lever file's gates only", $resolved['source']);
    $this->assertFalse($called, 'nothing is run when there is no drush to run');
  }

  /**
   * A site that cannot answer is unresolved, with drush's own first line.
   */
  public function testDrushFailureIsUnresolvedWithItsWords(): void {
    $resolved = $this->resolver(1, '', "Note: Using configuration file x\n [error]  Command \"droost:workflow:catalog\" is not defined.\n")->resolve($this->rootWithDrush());
    $this->assertSame([], $resolved['gates']);
    $this->assertStringContainsString('exited 1 (error]  Command "droost:workflow:catalog" is not defined.)', $resolved['source']);
  }

  /**
   * Output that is not a JSON list, or a malformed row, is unresolved by name.
   */
  public function testBadOutputIsUnresolvedNotHalfRead(): void {
    $noJson = $this->resolver(0, "Drupal bootstrapped.\n")->resolve($this->rootWithDrush());
    $this->assertSame([], $noJson['gates']);
    $this->assertStringContainsString('printed no JSON list', $noJson['source']);

    $good = (new ContributedGate('a', 'mod_a', ['code'], 'a-cmd', 'block', 'A verdict.'))->toArray();
    $bad = ['id' => 'b', 'provider' => 'mod_b', 'phases' => 'code', 'command' => 'b', 'verdict' => 'B.'];
    $half = $this->resolver(0, json_encode([$good, $bad], JSON_THROW_ON_ERROR))->resolve($this->rootWithDrush());
    $this->assertSame([], $half['gates'], 'one malformed row voids the catalog rather than passing a partial set as whole');
    $this->assertStringContainsString('Contributed gate row b: "phases" must be a list of phase names.', $half['source']);
  }

  /**
   * A root carrying a drush binary (the file only needs to exist).
   *
   * @return string
   *   The root.
   */
  private function rootWithDrush(): string {
    $root = $this->makeRoot();
    mkdir($root . '/vendor/bin', 0775, TRUE);
    file_put_contents($root . '/vendor/bin/drush', "#!/bin/sh\n");
    return $root;
  }

  /**
   * A resolver over a fake drush that answers with the given result.
   *
   * @param int $exit
   *   The exit code.
   * @param string $stdout
   *   Standard output.
   * @param string $stderr
   *   Standard error.
   *
   * @return \Droost\Workflow\Config\DrushCatalogResolver
   *   The resolver.
   */
  private function resolver(int $exit, string $stdout, string $stderr = ''): DrushCatalogResolver {
    return new DrushCatalogResolver(function (array $argv) use ($exit, $stdout, $stderr): array {
      $this->argv = array_values(array_map(strval(...), $argv));
      return [$exit, $stdout, $stderr];
    });
  }

}
