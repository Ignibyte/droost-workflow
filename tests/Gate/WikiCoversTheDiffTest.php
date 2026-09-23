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
use Droost\Workflow\Gate\NullSiteDriver;
use Droost\Workflow\State\RunState;
use Droost\Workflow\Tests\WorkflowTestCase;
use Droost\Workflow\Vcs\VcsInterface;

/**
 * The wiki gate holds the extensions a run changed to having a page.
 *
 * F-60 (owner, 2026-09-23): with `gates.wiki_fresh.cover_diff`, which medium
 * and up set, a custom module or theme the run changed that no page covers
 * fails the gate. An extension an earlier run left without a page stays the
 * note it was, and contrib is never held: the run did not write it.
 */
final class WikiCoversTheDiffTest extends WorkflowTestCase {

  /**
   * A module the run changed with no page fails the gate, by name.
   */
  public function testChangedModuleWithNoPageFailsTheGate(): void {
    $root = $this->siteRoot("preset: medium\n");

    $wiki = $this->wikiAfter($root, ['example_contact', 'example_directory'], ['web/modules/custom/example_contact/src/Form/ContactForm.php']);

    $this->assertSame(GateStatus::Failed, $wiki->status);
    $this->assertStringContainsString('this run changed example_contact', $wiki->summary);
    $this->assertStringContainsString('gates.wiki_fresh.cover_diff', $wiki->summary);
    $this->assertStringNotContainsString('this run changed example_directory', $wiki->summary);
  }

  /**
   * A gap an earlier run left is named, and not held against this one.
   */
  public function testEarlierGapIsOnlyNoted(): void {
    $root = $this->siteRoot("preset: medium\n");

    $wiki = $this->wikiAfter($root, ['example_directory'], ['web/modules/custom/example_contact/src/Form/ContactForm.php']);

    $this->assertSame(GateStatus::Passed, $wiki->status);
    $this->assertStringContainsString('example_directory', $wiki->summary);
  }

  /**
   * A custom theme is held like a module; contrib never is.
   */
  public function testThemeIsHeldAndContribIsNot(): void {
    $root = $this->siteRoot("preset: medium\n");

    $theme = $this->wikiAfter($root, ['example_theme'], ['web/themes/custom/example_theme/templates/page.html.twig']);
    $contrib = $this->wikiAfter($root, ['example_contrib'], ['web/modules/contrib/example_contrib/src/Patched.php']);
    // A directory whose name merely ENDS in "custom" is not a custom tree.
    $lookalike = $this->wikiAfter($root, ['example_sub'], ['web/modules/contrib/example_custom/modules/example_sub/src/Sub.php']);

    $this->assertSame(GateStatus::Failed, $theme->status);
    $this->assertStringContainsString('this run changed example_theme', $theme->summary);
    $this->assertSame(GateStatus::Passed, $contrib->status);
    $this->assertSame(GateStatus::Passed, $lookalike->status);
  }

  /**
   * Without the lever the gate is what it was.
   */
  public function testWithoutTheLeverNothingIsHeld(): void {
    $root = $this->siteRoot("preset: custom\n");

    $wiki = $this->wikiAfter($root, ['example_contact'], ['web/modules/custom/example_contact/src/Form/ContactForm.php']);

    $this->assertSame(GateStatus::Passed, $wiki->status);
  }

  /**
   * A diff nobody could read is said, not taken for an empty one.
   */
  public function testUnreadableDiffSaysTheDemandWasNotApplied(): void {
    $root = $this->siteRoot("preset: medium\n");

    $wiki = $this->wikiAfter($root, ['example_contact'], NULL);

    $this->assertSame(GateStatus::Passed, $wiki->status);
    $this->assertStringContainsString('cover_diff was not applied', $wiki->summary);
  }

  /**
   * A project root: two custom modules, a custom theme, and contrib.
   *
   * @param string $yaml
   *   The lever file.
   *
   * @return string
   *   The root.
   */
  private function siteRoot(string $yaml): string {
    $root = $this->makeRootWithConfig($yaml);
    foreach ([
      'web/modules/custom/example_contact/example_contact.info.yml',
      'web/modules/custom/example_directory/example_directory.info.yml',
      'web/themes/custom/example_theme/example_theme.info.yml',
      'web/modules/contrib/example_contrib/example_contrib.info.yml',
      'web/modules/contrib/example_custom/modules/example_sub/example_sub.info.yml',
    ] as $info) {
      mkdir(dirname($root . '/' . $info), 0777, TRUE);
      file_put_contents($root . '/' . $info, "type: module\n");
    }
    return $root;
  }

  /**
   * The wiki gate's result at complete, given what it found and what changed.
   *
   * @param string $root
   *   The project root.
   * @param list<string> $uncovered
   *   The extensions the status report says have no page.
   * @param list<string>|null $changed
   *   The run's changed files, or NULL for a diff that cannot be read.
   *
   * @return \Droost\Workflow\Gate\GateResult
   *   The wiki gate's result.
   */
  private function wikiAfter(string $root, array $uncovered, ?array $changed): GateResult {
    $runner = new GateRunner($this->executor($uncovered), new NullSiteDriver(), $this->vcs($changed));
    $state = RunState::begin('run-1', '2026-09-23T00:00:00+00:00', WorkflowConfig::load($root), 'abc', NULL);

    foreach ($runner->run($state, Phase::Complete, $root)->results as $result) {
      if ($result->gate === 'wiki_fresh') {
        return $result;
      }
    }
    $this->fail('wiki_fresh did not run at complete');
  }

  /**
   * An executor that passes every gate, the wiki listing what has no page.
   *
   * @param list<string> $uncovered
   *   The extensions with no page.
   *
   * @return \Droost\Workflow\Gate\GateExecutorInterface
   *   The executor.
   */
  private function executor(array $uncovered): GateExecutorInterface {
    return new class($uncovered) implements GateExecutorInterface {

      /**
       * Constructs the fake.
       *
       * @param list<string> $uncovered
       *   The extensions with no page.
       */
      public function __construct(private readonly array $uncovered) {}

      /**
       * {@inheritdoc}
       */
      public function execute(GateSettings $gate, string $projectRoot): GateResult {
        if ($gate->name !== 'wiki_fresh') {
          return GateResult::ran($gate->name, GateStatus::Passed, 0, 1, $gate->name . ' passed', [], $gate->name);
        }
        $findings = array_map(
          static fn (string $name): array => [
            'rule' => 'wiki.uncovered',
            'message' => 'no wiki page covers ' . $name,
            'extension' => $name,
          ],
          $this->uncovered,
        );
        return GateResult::ran(
          'wiki_fresh',
          GateStatus::Passed,
          0,
          1,
          sprintf('wiki_fresh passed — 1 of 1 page(s) fresh; %d extension(s) have no page: %s', count($this->uncovered), implode(', ', $this->uncovered)),
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
