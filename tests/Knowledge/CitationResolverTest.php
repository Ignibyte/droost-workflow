<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Knowledge;

use Droost\Workflow\Knowledge\CitationResolver;
use Droost\Workflow\Knowledge\CodeIndex;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A plan's citations resolve from droost's code index and the files, no site.
 *
 * What droost's grounding gate resolved through Drupal's database, read here
 * from the SQLite file droost writes, so the standalone binary and drush
 * resolve a citation the same way (F-276).
 */
final class CitationResolverTest extends WorkflowTestCase {

  use KnowledgeFixture;

  /**
   * Each kind of citation resolves against the store that holds it.
   */
  public function testEachCitationResolvesWhereItIs(): void {
    $root = $this->makeRoot();
    $this->drupalTree($root);
    $this->codeIndex(
      $root,
      [['Drupal\camps\CampList', 'modules/custom/camps/src/CampList.php', 'camps']],
      ['modules/custom/camps/camps.info.yml'],
    );
    $resolver = new CitationResolver();
    $docroot = CitationResolver::docroot($root);
    $this->assertSame($root . '/web', $docroot, 'web/ holds core');
    $prefix = CitationResolver::docrootPrefix($root, $docroot);
    $this->assertSame('web', $prefix);

    $expected = [
      '`Drupal\camps\CampList`' => 'symbol graph',
      '\Drupal\camps\CampList::ids()' => 'symbol graph',
      'CampList' => 'symbol graph',
      'camps' => 'symbol graph',
      'Drupal\Core\Entity\EntityBase' => 'filesystem (psr-4)',
      'web/modules/custom/camps/camps.info.yml' => 'search index',
      'web/modules/custom/camps/src/CampList.php' => 'filesystem',
      'overview.md' => 'wiki',
      'wiki:overview.md' => 'wiki',
      'none: hockey_stats' => 'absent (re-run empty)',
      'none: camps' => NULL,
      'none: CampList' => NULL,
      'Drupal\camps\Missing' => NULL,
      'Missing' => NULL,
      'missing.md' => NULL,
      '../../etc/passwd' => NULL,
      'web/../../../etc/hosts' => NULL,
      // S4-2: what exists by construction is no evidence of looking.
      './' => NULL,
      'web/modules/' => NULL,
      'droost/droost-workflow/spec-test-run.md' => NULL,
      'droost/droost-workflow/code-index.sqlite' => NULL,
      // S4-10: a namespace that climbs out of the project.
      'Drupal\camps\..\..\..\..\Outside' => NULL,
    ];
    foreach ($expected as $cite => $store) {
      $this->assertSame($store, $resolver->resolveStore($cite, $root, $docroot, $prefix), $cite);
    }
  }

  /**
   * A wiki elsewhere, named by wiki.path, is where its pages are (F-374).
   */
  public function testWikiPathIsHonoured(): void {
    $root = $this->makeRoot();
    $this->drupalTree($root);
    mkdir($root . '/docs/wiki', 0755, TRUE);
    file_put_contents($root . '/docs/wiki/billing.md', "# Billing\n");
    file_put_contents($root . '/droost/droost.yml', "wiki:\n  path: docs/wiki\n");
    $resolver = new CitationResolver();
    $docroot = CitationResolver::docroot($root);
    $prefix = CitationResolver::docrootPrefix($root, $docroot);
    $this->assertSame('wiki', $resolver->resolveStore('billing.md', $root, $docroot, $prefix));
    $this->assertSame('wiki', $resolver->resolveStore('wiki:billing.md', $root, $docroot, $prefix));
  }

  /**
   * A `none:` claim cannot resolve while the index cannot be asked.
   */
  public function testNoneNeedsAnIndex(): void {
    $root = $this->makeRoot();
    $this->drupalTree($root);
    $docroot = CitationResolver::docroot($root);
    $prefix = CitationResolver::docrootPrefix($root, $docroot);

    $this->assertNull((new CitationResolver())->resolveStore('none: QzxvMadeUp', $root, $docroot, $prefix), 'no index: a made-up name must not "re-run empty"');

    $this->codeIndex($root, [], []);
    $this->assertSame(CitationResolver::ABSENT, (new CitationResolver())->resolveStore('none: QzxvMadeUp', $root, $docroot, $prefix));
  }

  /**
   * A `none:` claim is not a positive citation.
   */
  public function testPositiveCitationsLeaveOutNegatives(): void {
    $root = $this->makeRoot();
    $this->drupalTree($root);
    $this->codeIndex($root, [], []);
    $spec = $this->groundedSpec($root, [['custom', 'none: QzxvMadeUp'], ['core', 'Drupal\Core\Entity\EntityBase']]);

    $citations = (new CitationResolver())->citations($root, $spec);

    $this->assertSame(['none: QzxvMadeUp', 'Drupal\Core\Entity\EntityBase'], $citations['resolved']);
    $this->assertSame(['Drupal\Core\Entity\EntityBase'], $citations['positive']);
  }

  /**
   * The Composer map above the project is never read (S4-14).
   */
  public function testComposerMapAboveTheProjectIsNotRead(): void {
    $outer = $this->makeRoot();
    $root = $outer . '/project';
    mkdir($root, 0755, TRUE);
    $this->put($outer, 'vendor/composer/autoload_psr4.php', '<?php return [\'Outer\\\\\' => [\'' . $outer . '/lib\']];');
    $this->put($outer, 'lib/Thing.php', "<?php\n");

    $this->assertNull((new CitationResolver())->resolveStore('Outer\Thing', $root, $root, ''));
  }

  /**
   * The spec's table is resolved whole, with each tier's citations counted.
   */
  public function testTheSpecIsResolvedWithItsTiers(): void {
    $root = $this->makeRoot();
    $this->drupalTree($root);
    $this->codeIndex($root, [['Drupal\camps\CampList', 'modules/custom/camps/src/CampList.php', 'camps']], []);
    $spec = $this->groundedSpec($root, [
      ['custom', '`Drupal\camps\CampList`'],
      ['core', 'Drupal\Core\Entity\EntityBase'],
      ['contrib', 'Drupal\nowhere\Thing'],
    ]);

    $citations = (new CitationResolver())->citations($root, $spec);

    $this->assertTrue($citations['table']);
    $this->assertSame(['Drupal\camps\CampList', 'Drupal\Core\Entity\EntityBase'], $citations['resolved']);
    $this->assertSame(['Drupal\nowhere\Thing'], $citations['unresolved']);
    $this->assertSame(['contrib'], $citations['uncited_tiers'], 'a tier claimed with nothing resolving for it');
    $this->assertSame('ready', $citations['index']);
  }

  /**
   * Without an index only the files answer, and the result says so.
   */
  public function testWithoutAnIndexOnlyTheFilesAnswer(): void {
    $root = $this->makeRoot();
    $this->drupalTree($root);
    $spec = $this->groundedSpec($root, [
      ['custom', 'Drupal\camps\CampList'],
      ['custom', 'CampList'],
    ]);

    $citations = (new CitationResolver())->citations($root, $spec);

    $this->assertSame(['Drupal\camps\CampList'], $citations['resolved'], 'PSR-4 finds the file');
    $this->assertSame(['CampList'], $citations['unresolved'], 'a short name needs the index');
    $this->assertSame('absent', $citations['index']);
  }

  /**
   * A spec with no Grounding table says so.
   */
  public function testNoTableIsSaid(): void {
    $root = $this->makeRoot();
    $this->put($root, 'droost/droost-workflow/spec-x.md', "# Spec\n\n## Routes\n\nnone\n");

    $citations = (new CitationResolver())->citations($root, 'droost/droost-workflow/spec-x.md');

    $this->assertFalse($citations['table']);
  }

  /**
   * The docroot is Composer's scaffold location when the project names one.
   */
  public function testTheDocrootIsTheScaffoldLocation(): void {
    $root = $this->makeRoot();
    $this->put($root, 'composer.json', (string) json_encode(['extra' => ['drupal-scaffold' => ['locations' => ['web-root' => 'docroot/']]]]));
    $this->put($root, 'docroot/index.php', "<?php\n");
    $this->put($root, 'web/core/lib/Drupal.php', "<?php\n");

    $this->assertSame($root . '/docroot', CitationResolver::docroot($root));
    $bare = $this->makeRoot();
    $this->assertSame($bare, CitationResolver::docroot($bare), 'no docroot found: the project root');
  }

  /**
   * An index that is not one is said, never read as empty.
   */
  public function testAnUnreadableIndexIsSaid(): void {
    $root = $this->makeRoot();
    $this->put($root, 'droost/droost-workflow/code-index.sqlite', 'not a database');

    $index = CodeIndex::open($root);

    $this->assertFalse($index->ready());
    $this->assertStringStartsWith('unreadable: ', $index->status);
  }

}
