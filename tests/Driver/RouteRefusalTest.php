<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Driver;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Driver\RenderedRoutes;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A route may be declared to refuse an anonymous visitor, and is checked to.
 *
 * The rendered_check gate renders every declared route as an anonymous visitor
 * and expects 200. P6 run 11's second attempt declared the editors' listing its
 * ticket required, `/admin/content/registrations`. The page answered 403 to the
 * anonymous probe, exactly as its criterion demanded, and the gate failed. No
 * verb could correct the declaration, so the agent could only ask the operator
 * for a waiver (F-120). A route now carries the refusal it must give
 * (`declare-route /admin/x --status=403`, or `/admin/x (403)` in the spec), and
 * a refusal is measured, not waived: a page declared to refuse that renders for
 * anyone fails as public.
 */
final class RouteRefusalTest extends WorkflowTestCase {

  /**
   * A route string carries its expected status, and only a declarable one.
   */
  public function testExpectationRidesOnTheRoute(): void {
    $this->assertSame('/admin/x@403', RenderedRoutes::withStatus('/admin/x', 403));
    $this->assertSame('/camps', RenderedRoutes::withStatus('/camps', 200));
    $this->assertTrue(RenderedRoutes::declarable(401));
    // A redirect is an answer to declare too (F-179).
    $this->assertTrue(RenderedRoutes::declarable(303));
    $this->assertFalse(RenderedRoutes::declarable(500), 'a 500 is no answer to declare');
  }

  /**
   * The spec's section takes the refusal in brackets after the path.
   */
  public function testTheSectionTakesTheRefusalInBrackets(): void {
    $root = $this->makeRoot();
    RunWithSpec::open($root, "- /camps — the listing\n- /admin/content/registrations (403) — editors only\n- /members (401)\n- /odd (500)\n- /account (303) — to the login\n");

    $routes = SpecContract::routes($root, 'droost/droost-workflow/spec-routes.md');

    $this->assertNotNull($routes);
    $this->assertSame(['/camps', '/admin/content/registrations@403', '/members@401', '/odd', '/account@303'], $routes['routes']);
    RunWithSpec::close($root);
  }

  /**
   * The verb takes a status, refuses one that is not a refusal, and says so.
   */
  public function testVerbTakesStatus(): void {
    $root = $this->makeRoot();
    RunWithSpec::open($root, "- /camps\n");

    [$code, $out] = $this->cli($root, ['declare-route', '/admin/content/registrations', '--status=403', 'editors only']);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('must refuse an anonymous visitor with 403', $out);
    $routes = array_column((new EvidenceStore($root))->specRoutes($this->runId($root)), 'path');
    $this->assertContains('/admin/content/registrations@403', $routes);

    [$code, , $err] = $this->cli($root, ['declare-route', '/admin/content/registrations', '--status=500']);
    $this->assertNotSame(0, $code);
    $this->assertStringContainsString('401 or 403', $err);
    $this->assertStringContainsString('301, 302, 303, 307, 308', $err);

    // A page whose honest answer is a redirect is declared as one (F-179).
    [$code, $out] = $this->cli($root, ['declare-route', '/account', '--status=303', 'to the login']);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('must redirect an anonymous visitor with 303', $out);
    RunWithSpec::close($root);
  }

  /**
   * A POST-only route is declared as the 405 an anonymous GET gets (F-364).
   *
   * A subject's run touched three POST-only API routes. Each answers an
   * anonymous GET with 405 Method Not Allowed, which no declarable status
   * described, so the routes were left out of the record.
   */
  public function testRouteForAnotherMethodIsDeclaredAs405(): void {
    $this->assertTrue(RenderedRoutes::declarable(405));
    $this->assertSame('/api/cpat/registration@405', RenderedRoutes::withStatus('/api/cpat/registration', 405));

    $root = $this->makeRoot();
    RunWithSpec::open($root, "- /api/cpat/registration (405) — POST only\n");
    $routes = SpecContract::routes($root, 'droost/droost-workflow/spec-routes.md');
    $this->assertNotNull($routes);
    $this->assertSame(['/api/cpat/registration@405'], $routes['routes']);

    [$code, $out] = $this->cli($root, ['declare-route', '/api/cpat/revalidate', '--status=405', 'POST only']);
    $this->assertSame(0, $code);
    $this->assertStringContainsString('takes another method: an anonymous GET gets 405', $out);

    [, , $err] = $this->cli($root, ['declare-route', '/api/x', '--status=500']);
    $this->assertStringContainsString('to take another method (405', $err);
    RunWithSpec::close($root);
  }

  /**
   * The open run's id.
   *
   * @param string $root
   *   The project root.
   *
   * @return string
   *   The id.
   */
  private function runId(string $root): string {
    $record = json_decode((string) file_get_contents($root . '/droost/droost-workflow/run.json'), TRUE);
    $this->assertIsArray($record);
    $id = $record['run_id'] ?? NULL;
    $this->assertIsString($id);
    return $id;
  }

  /**
   * Runs the CLI in-process.
   *
   * @param string $root
   *   The project root.
   * @param list<string> $argv
   *   The verb and its arguments.
   *
   * @return array{int, string, string}
   *   The exit code, stdout and stderr.
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
      static fn (): string => '2026-09-26T10:00:00+00:00',
      static fn (): string => 'run-refusal',
    );

    return [
      $dispatcher->dispatch([...$argv, '--project=' . $root], $root),
      implode("\n", $out),
      implode("\n", $err),
    ];
  }

}
