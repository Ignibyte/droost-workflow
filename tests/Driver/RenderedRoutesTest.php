<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Driver;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Driver\RenderedRoutes;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * Which routes the gate renders, and which source answered.
 *
 * The verification floor used to ask for `/` while the ticket built
 * `/camps`, then `/rinks`, then `/private-lessons` — once with the ticket's
 * page returning 500 while the front page was fine (F-15). The fix pointed
 * the gate at the spec's `## Routes` section, and then two of Part 3's three
 * specs lost their routes to the document's shape: one fenced its list and
 * had a placeholder harvested out of a sentence instead (F-32, F-34).
 *
 * So the rows answer first. A declared route has no shape to get wrong.
 */
final class RenderedRoutesTest extends WorkflowTestCase {

  /**
   * Declared rows beat the document, and the label says which answered.
   */
  public function testDeclaredRoutesAnswerBeforeTheDocument(): void {
    $root = $this->makeRoot();
    // The document says one thing; the run declared another. The rows win,
    // and the parse is not consulted at all — two sources for one fact is
    // what this redesign removes rather than arbitrates.
    RunWithSpec::open($root, '- /from-the-document');
    (new EvidenceStore($root))->declareRoute('run-routes', 'plan', '/rinks', 'the listing');

    $resolved = RenderedRoutes::resolve($this->gate(), $root);
    $this->assertSame(['/', '/rinks'], $resolved['routes']);
    $this->assertSame('spec-declared', $resolved['sources']['/rinks']);
    $this->assertArrayNotHasKey('/from-the-document', $resolved['sources']);
    $this->assertSame(
      '/ (default), /rinks (spec-declared)',
      RenderedRoutes::describe($resolved),
      'and no sentence about a document nobody needed to read',
    );
  }

  /**
   * With nothing declared, the document still answers.
   *
   * Every run written before the tool existed is this case, which is why
   * Phase B keeps the parse and Phase C is what removes it.
   */
  public function testTheDocumentIsTheFallback(): void {
    $root = $this->makeRoot();
    RunWithSpec::open($root, '- /camps — the listing');

    $resolved = RenderedRoutes::resolve($this->gate(), $root);
    $this->assertSame(['/', '/camps'], $resolved['routes']);
    $this->assertSame('spec-parsed', $resolved['sources']['/camps']);
  }

  /**
   * The lever's route keeps its own label when the run declares it too.
   *
   * A project's standing list and this ticket's are different claims, and the
   * record naming the weaker one would make a standing route look like
   * something this ticket promised.
   */
  public function testTheLeverKeepsItsLabel(): void {
    $root = $this->makeRoot();
    RunWithSpec::open($root, '- none — no route changes');
    (new EvidenceStore($root))->declareRoute('run-routes', 'plan', '/camps');

    $resolved = RenderedRoutes::resolve(
      new GateSettings('rendered_check', TRUE, ['routes' => '/camps']),
      $root,
    );
    $this->assertSame(['/camps'], $resolved['routes']);
    $this->assertSame('lever', $resolved['sources']['/camps']);
  }

  /**
   * A route declared by another run is not this run's.
   *
   * F-6's shape, asked of the new table: a ledger that never cleared between
   * runs let run 2 inherit run 1's evidence, and that took a live round to
   * find.
   */
  public function testAnotherRunsRoutesAreNotRendered(): void {
    $root = $this->makeRoot();
    RunWithSpec::open($root, '- none — no route changes');
    (new EvidenceStore($root))->declareRoute('run-earlier', 'plan', '/gone');

    $resolved = RenderedRoutes::resolve($this->gate(), $root);
    $this->assertSame(['/'], $resolved['routes']);
    $this->assertArrayNotHasKey('/gone', $resolved['sources']);
  }

  /**
   * With no run at all, the lever and the default are the whole answer.
   */
  public function testWithNoRunOnlyTheLeverAnswers(): void {
    $root = $this->makeRoot();

    $resolved = RenderedRoutes::resolve($this->gate(), $root);
    $this->assertSame(['/'], $resolved['routes']);
    $this->assertStringContainsString('no run spec to read', RenderedRoutes::describe($resolved));
  }

  /**
   * The gate with no `routes` lever.
   *
   * @return \Droost\Workflow\Config\GateSettings
   *   The gate.
   */
  private function gate(): GateSettings {
    return new GateSettings('rendered_check', TRUE);
  }

}
