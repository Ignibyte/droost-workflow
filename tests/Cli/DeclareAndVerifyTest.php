<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Cli;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The surface an agent declares its spec through, end to end.
 *
 * Every one of Part 3's three stoppages was the shape of a markdown file
 * while every code-quality gate passed (F-35). These verbs are the answer:
 * the structure the engine acts on is written by a tool call, so the only
 * question left is whether the call happened.
 */
final class DeclareAndVerifyTest extends WorkflowTestCase {

  /**
   * Routes, criteria, verification and notes, through the real dispatcher.
   */
  public function testTheDeclaredSpecReachesTheStore(): void {
    $root = $this->openRun();

    $this->assertSame(0, $this->cli($root, ['declare-route', '/rinks', 'the new listing'])[0]);
    $this->assertSame(0, $this->cli($root, ['declare-route', '/camps'])[0]);
    $this->assertSame(0, $this->cli($root, ['declare-criterion', 'AC-1', 'every published rink is listed'])[0]);
    $this->assertSame(0, $this->cli($root, ['verify-criterion', 'AC-1', 'RinkListTest::testEveryPublished'])[0]);
    $this->assertSame(
      0,
      $this->cli($root, ['declare-note', 'grounding', 'Drupal\\node\\Entity\\Node', 'read'])[0],
    );

    $store = new EvidenceStore($root);
    $run = $this->runId($root);
    $this->assertSame(['/rinks', '/camps'], array_column($store->specRoutes($run), 'path'));
    $criteria = $store->specCriteria($run);
    $this->assertSame('every published rink is listed', $criteria[0]['statement']);
    $this->assertSame('RinkListTest::testEveryPublished', $criteria[0]['verified_by']);
    $this->assertSame('grounding', $store->specNotes($run)[0]['kind']);
  }

  /**
   * A page is declared with what its main content is and what builds it.
   *
   * Owner, 2026-10-02: the composition gate reads the site's real owner
   * against the declaration and the owner's rules, so both go in the store,
   * with the reason when the page breaks a rule.
   */
  public function testPageIsDeclaredWithItsKindAndOwner(): void {
    $root = $this->openRun();

    [$code, $out] = $this->cli($root, [
      'declare-route', '/', '--kind=page', '--owner=canvas_page', 'the home page',
    ]);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('a page, built as canvas_page', $out);
    $this->assertSame(0, $this->cli($root, [
      'declare-route', '/camps/week', '--kind=collection', '--owner=view_page',
      '--except=the week rides in the path',
    ])[0]);
    $this->assertSame(0, $this->cli($root, ['declare-route', '/camps'])[0]);

    $routes = (new EvidenceStore($root))->specRoutes($this->runId($root));
    $this->assertSame(['page', 'canvas_page', NULL], [$routes[0]['kind'], $routes[0]['owner'], $routes[0]['exception']]);
    $this->assertSame('the home page', $routes[0]['reason']);
    $this->assertSame(
      ['collection', 'view_page', 'the week rides in the path'],
      [$routes[1]['kind'], $routes[1]['owner'], $routes[1]['exception']],
    );
    $this->assertSame([NULL, NULL], [$routes[2]['kind'], $routes[2]['owner']], 'a route may still be declared with neither');
  }

  /**
   * A kind without an owner, an unknown name, or a bare exception is refused.
   */
  public function testHalfDeclaredPageIsRefused(): void {
    $root = $this->openRun();

    [$code, , $err] = $this->cli($root, ['declare-route', '/', '--kind=page']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('with both its kind', $err);
    [$code, , $err] = $this->cli($root, ['declare-route', '/', '--kind=landing', '--owner=canvas_page']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('--kind=landing is not a kind', $err);
    [$code, , $err] = $this->cli($root, ['declare-route', '/', '--kind=page', '--owner=paragraphs']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('--owner=paragraphs is not an owner', $err);
    [$code, , $err] = $this->cli($root, ['declare-route', '/', '--except=because']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('An exception (--except=) is to a rule', $err);
    $this->assertSame([], (new EvidenceStore($root))->specRoutes($this->runId($root)));
  }

  /**
   * A route that is not a path is refused, and it is the only route refusal.
   *
   * The cost of accepting it is a gate rendering a value that cannot be
   * requested and failing a phase over the spelling — which is F-32 and F-34
   * both, arriving through the new surface instead of the old one.
   */
  public function testEveryDeclaredRouteMustLookLikeOne(): void {
    $root = $this->openRun();

    [$code, , $err] = $this->cli($root, ['declare-route', 'rinks']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('must begin with "/"', $err);
    $this->assertSame([], (new EvidenceStore($root))->specRoutes($this->runId($root)));
  }

  /**
   * Verifying an undeclared criterion is refused, and says what is declared.
   */
  public function testVerifyingAnUndeclaredCriterionIsRefused(): void {
    $root = $this->openRun();
    $this->cli($root, ['declare-criterion', 'AC-1', 'x']);

    [$code, , $err] = $this->cli($root, ['verify-criterion', 'AC-2', 'SomeTest']);
    $this->assertSame(2, $code);
    $this->assertStringContainsString('No criterion "AC-2" was declared', $err);
    $this->assertStringContainsString('Declared so far: AC-1', $err);
  }

  /**
   * Each verb says what it needs when called bare.
   *
   * An agent runs the binary to find out what it can do, and a verb that
   * fails silently is a verb it stops using — which is how `declare-changes`
   * came to be measured as an audit problem.
   */
  public function testEachVerbSaysWhatItNeeds(): void {
    $root = $this->openRun();
    foreach ([
      'declare-route' => 'declare-route /rinks',
      'declare-criterion' => 'declare-criterion',
      'verify-criterion' => 'verify-criterion',
      'declare-note' => 'declare-note grounding',
    ] as $verb => $expected) {
      [$code, , $err] = $this->cli($root, [$verb]);
      $this->assertSame(2, $code, $verb);
      $this->assertStringContainsString($expected, $err, $verb);
    }
  }

  /**
   * A project with no open run refuses all four, rather than losing the rows.
   */
  public function testWithNoRunOpenEveryVerbRefuses(): void {
    $root = $this->makeRootWithConfig("preset: low\n");
    foreach ([
      ['declare-route', '/rinks'],
      ['declare-criterion', 'AC-1', 'x'],
      ['verify-criterion', 'AC-1', 'y'],
      ['declare-note', 'grounding', 'z'],
    ] as $argv) {
      [$code] = $this->cli($root, $argv);
      $this->assertSame(2, $code, implode(' ', $argv));
    }
  }

  /**
   * A project root with one open run at plan.
   *
   * @return string
   *   The root.
   */
  private function openRun(): string {
    $root = $this->makeRootWithConfig("preset: low\n");
    $this->cli($root, ['run']);
    $this->assertNotSame('', $this->runId($root), 'a run is open');

    return $root;
  }

  /**
   * The open run's id, read from the record.
   *
   * @param string $root
   *   The project root.
   *
   * @return string
   *   The id, or '' when no run is open.
   */
  private function runId(string $root): string {
    $document = @file_get_contents($root . '/droost/droost-workflow/run.json');
    if (!is_string($document)) {
      return '';
    }
    $decoded = json_decode($document, TRUE);

    return is_array($decoded) && is_string($decoded['run_id'] ?? NULL) ? $decoded['run_id'] : '';
  }

  /**
   * Runs one verb through the real dispatcher.
   *
   * @param string $root
   *   The project root.
   * @param list<string> $argv
   *   The verb and its arguments.
   *
   * @return array{int, string, string}
   *   Exit code, stdout, stderr.
   */
  private function cli(string $root, array $argv): array {
    $out = [];
    $err = [];
    $dispatcher = new ArgvDispatcher(
      function (string $line) use (&$out): void {
        $out[] = $line;
      },
      function (string $line) use (&$err): void {
        $err[] = $line;
      },
      static fn (): string => '2026-09-18T10:00:00+00:00',
      static fn (): string => 'run-declare',
    );

    return [
      $dispatcher->dispatch([...$argv, '--project=' . $root], $root),
      implode("\n", $out),
      implode("\n", $err),
    ];
  }

}
