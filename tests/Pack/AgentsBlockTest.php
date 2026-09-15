<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Pack;

use Droost\Workflow\Pack\AgentsBlock;
use Droost\Workflow\Pack\PackMaterializer;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The pipeline tells the agent it exists, on every host.
 *
 * Eval T01's headline miss was an installed pipeline whose subject never
 * mentioned it once, because the doctrine lived only in surfaces an agent had
 * to go looking for. The block in AGENTS.md is the fix, and until 2026-09-15
 * only `drush droost:workflow:install` wrote it — so the documented
 * `droost-workflow init` route, the one a project without Drupal has,
 * reproduced the original defect as its default.
 */
final class AgentsBlockTest extends WorkflowTestCase {

  /**
   * Init alone writes the block — no drush, no Drupal.
   */
  public function testInitWritesTheBlockWithNoDrupal(): void {
    $root = $this->makeRoot();
    $report = (new PackMaterializer())->init($root);

    $path = $root . '/' . AgentsBlock::FILE;
    $this->assertFileExists($path, 'init creates AGENTS.md when the project has none');
    $body = (string) file_get_contents($path);
    $this->assertStringContainsString(AgentsBlock::BEGIN, $body);
    $this->assertStringContainsString(AgentsBlock::END, $body);
    $this->assertStringContainsString('droost-workflow run', $body, 'it names a command this surface actually has');
    $this->assertStringContainsString('require_run: hard', $body, 'and says the discipline is enforced');
    $this->assertContains(AgentsBlock::FILE, $report->written, 'the report says so');
  }

  /**
   * The doctrine names only commands the standalone surface can run.
   *
   * The library's paragraphs are not droost's. A project with no Drupal told
   * to run `/droost:workflow:start` or `drush droost:workflow:reset` has been
   * handed instructions it cannot follow, which is worse than none: an agent
   * that tries and fails learns the pipeline is broken.
   */
  public function testTheStandaloneDoctrineNamesNoDrushOrSlashCommands(): void {
    $body = AgentsBlock::render(AgentsBlock::paragraphs());
    $this->assertStringNotContainsString('drush ', $body);
    $this->assertStringNotContainsString('/droost:workflow:', $body);
    $this->assertStringContainsString('droost-workflow reset', $body);
    $this->assertStringContainsString('droost-workflow bypass', $body);
    $this->assertStringContainsString('OPERATOR', $body, 'the bypass is named as the operator\'s');
  }

  /**
   * A project's own instructions survive, and a re-init leaves one block.
   */
  public function testTheProjectsOwnContentSurvivesAndTheBlockIsNotDuplicated(): void {
    $root = $this->makeRoot();
    file_put_contents(
      $root . '/' . AgentsBlock::FILE,
      "# House rules\n\nRun the linter before you push.\n\n## Tail\n\nKeep this.\n",
    );

    $this->assertSame('written', AgentsBlock::write($root, AgentsBlock::paragraphs()));
    $body = (string) file_get_contents($root . '/' . AgentsBlock::FILE);
    $this->assertStringContainsString('Run the linter before you push.', $body);
    $this->assertStringContainsString('Keep this.', $body);
    $this->assertSame(1, substr_count($body, AgentsBlock::BEGIN));

    // Converging, not accumulating.
    $this->assertSame('kept', AgentsBlock::write($root, AgentsBlock::paragraphs()));
    (new PackMaterializer())->init($root);
    $body = (string) file_get_contents($root . '/' . AgentsBlock::FILE);
    $this->assertSame(1, substr_count($body, AgentsBlock::BEGIN), 'init does not add a second block');
    $this->assertStringContainsString('Run the linter before you push.', $body);
  }

  /**
   * A stale block is replaced IN PLACE, and text after it is untouched.
   */
  public function testTheStaleBlockIsReplacedWhereItStands(): void {
    $root = $this->makeRoot();
    file_put_contents(
      $root . '/' . AgentsBlock::FILE,
      "# Head\n\n" . AgentsBlock::BEGIN . "\nold doctrine\n" . AgentsBlock::END . "\n\n## Tail section\n",
    );

    $this->assertSame('written', AgentsBlock::write($root, AgentsBlock::paragraphs()));
    $body = (string) file_get_contents($root . '/' . AgentsBlock::FILE);
    $this->assertStringNotContainsString('old doctrine', $body);
    $this->assertStringContainsString('## Tail section', $body, 'content after the block survives');
    $this->assertLessThan(
      strpos($body, '## Tail section'),
      strpos($body, AgentsBlock::BEGIN),
      'the block stays where it was, rather than moving to the end',
    );
  }

  /**
   * An extension that hands back nothing does not leave a hollow block.
   *
   * Droost runs its paragraphs through a module alter hook, so a listener can
   * return an empty string or a non-string. A block with a hole in it is the
   * one outcome worse than no block: it looks installed and teaches nothing.
   */
  public function testEmptyParagraphsAreDroppedRatherThanRendered(): void {
    $rendered = AgentsBlock::render(['', '   ', "real doctrine\n", '']);
    $this->assertStringContainsString('real doctrine', $rendered);
    $this->assertStringNotContainsString("\n\n\n", $rendered);
    $this->assertSame(1, substr_count($rendered, AgentsBlock::BEGIN));
    $this->assertSame(1, substr_count($rendered, AgentsBlock::END));
  }

}
