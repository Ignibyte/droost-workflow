<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Driver;

use Droost\Workflow\Cli\ArgvDispatcher;
use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Driver\BootedSiteDriver;
use Droost\Workflow\Driver\RenderedRoutes;
use Droost\Workflow\Evidence\EvidenceStore;
use Droost\Workflow\Gate\GateStatus;
use Droost\Workflow\Spec\SpecContract;
use Droost\Workflow\Tests\WorkflowTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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
   * A route string carries its expected status, and only a refusal.
   */
  public function testExpectationRidesOnTheRoute(): void {
    $this->assertSame(['path' => '/camps', 'status' => 200], RenderedRoutes::expectation('/camps'));
    $this->assertSame(['path' => '/admin/x', 'status' => 403], RenderedRoutes::expectation('/admin/x@403'));
    $this->assertSame(['path' => '/admin/x', 'status' => 401], RenderedRoutes::expectation('/admin/x@401'));
    $this->assertSame(['path' => '/admin/x@500', 'status' => 200], RenderedRoutes::expectation('/admin/x@500'), 'a 500 is no refusal to declare');
    // A redirect is an answer to declare too (F-179).
    $this->assertSame(['path' => '/account', 'status' => 303], RenderedRoutes::expectation('/account@303'));
    $this->assertSame('/admin/x@403', RenderedRoutes::withStatus('/admin/x', 403));
    $this->assertSame('/camps', RenderedRoutes::withStatus('/camps', 200));
  }

  /**
   * Declaring a path again replaces it, so a bare admin route can be corrected.
   */
  public function testLaterDeclarationOfPathWins(): void {
    $root = $this->makeRoot();
    RunWithSpec::open($root, "- /camps\n");
    $store = new EvidenceStore($root);
    $store->declareRoute('run-routes', 'plan', '/admin/content/registrations', NULL);
    $store->declareRoute('run-routes', 'test', '/admin/content/registrations@403', 'editors only');

    $resolved = RenderedRoutes::resolve(new GateSettings('rendered_check', TRUE), $root);

    $this->assertSame(['/', '/admin/content/registrations@403'], $resolved['routes']);
    RunWithSpec::close($root);
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
   * A declared refusal passes on the refusal, and fails if the page is public.
   */
  public function testDeclaredRefusalIsMeasured(): void {
    $refuses = $this->kernel(403);
    $renders = $this->kernel(200);
    $gate = new GateSettings('rendered_check', TRUE, ['routes' => '/admin/content/registrations@403']);

    $passed = (new BootedSiteDriver($refuses, static fn (): int => 0))->run($gate, '/tmp');
    $this->assertSame(GateStatus::Passed, $passed->status);
    $this->assertSame('0 route(s) rendered, 1 refused an anonymous visitor as declared', $passed->summary);

    $public = (new BootedSiteDriver($renders, static fn (): int => 0))->run($gate, '/tmp');
    $this->assertSame(GateStatus::Failed, $public->status);
    $problem = $public->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('the page is public', $problem);
  }

  /**
   * A declared redirect passes on that redirect only (F-179).
   */
  public function testDeclaredRedirectIsMeasured(): void {
    $gate = new GateSettings('rendered_check', TRUE, ['routes' => '/account@303']);

    $passed = (new BootedSiteDriver($this->kernel(303), static fn (): int => 0))->run($gate, '/tmp');
    $this->assertSame(GateStatus::Passed, $passed->status);
    $this->assertSame('0 route(s) rendered, 1 redirected an anonymous visitor as declared', $passed->summary);

    $renders = (new BootedSiteDriver($this->kernel(200), static fn (): int => 0))->run($gate, '/tmp');
    $this->assertSame(GateStatus::Failed, $renders->status);
    $problem = $renders->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('declared to redirect anonymous visitors with 303, and it rendered', $problem);

    $other = (new BootedSiteDriver($this->kernel(302), static fn (): int => 0))->run($gate, '/tmp');
    $this->assertSame(GateStatus::Failed, $other->status);
    $problem = $other->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('declared to redirect with 303, answered 302', $problem);

    $bare = (new BootedSiteDriver($this->kernel(303), static fn (): int => 0))->run(new GateSettings('rendered_check', TRUE, ['routes' => '/account']), '/tmp');
    $this->assertSame(GateStatus::Failed, $bare->status);
    $problem = $bare->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('declare-route /account --status=303', $problem);
  }

  /**
   * A bare route that refuses says how to declare the refusal.
   */
  public function testBareRouteThatRefusesNamesTheRemedy(): void {
    $gate = new GateSettings('rendered_check', TRUE, ['routes' => '/admin/content/registrations']);

    $result = (new BootedSiteDriver($this->kernel(403), static fn (): int => 0))->run($gate, '/tmp');

    $this->assertSame(GateStatus::Failed, $result->status);
    $problem = $result->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('declare-route /admin/content/registrations --status=403', $problem);
  }

  /**
   * A refusal Drupal THROWS is read as the status it carries.
   *
   * The booted driver's sub-request runs with catching off, so Drupal's
   * access denied arrives as an exception, not a 403 response. The first
   * version of this fix compared response statuses only, and the tests above
   * faked a 403 response: all green here, while the subject's render-probe
   * reported the declared refusal as a thrown exception.
   */
  public function testThrownRefusalIsReadAsItsStatus(): void {
    $throws = new class() implements HttpKernelInterface {

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        throw new AccessDeniedHttpException("The 'access content overview' permission is required.");
      }

    };

    $declared = (new BootedSiteDriver($throws, static fn (): int => 0))->run(new GateSettings('rendered_check', TRUE, ['routes' => '/admin/content@403']), '/tmp');
    $this->assertSame(GateStatus::Passed, $declared->status);

    $bare = (new BootedSiteDriver($throws, static fn (): int => 0))->run(new GateSettings('rendered_check', TRUE, ['routes' => '/admin/content']), '/tmp');
    $this->assertSame(GateStatus::Failed, $bare->status);
    $problem = $bare->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('declare-route /admin/content --status=403', $problem);
  }

  /**
   * A refusal the site's exception listeners answer is judged as answered.
   *
   * P8 run 20's agent sent an anonymous visitor of /my-registrations to log
   * in from a kernel.exception subscriber: the View threw access denied, and
   * the subscriber answered it with a 303. Every visitor got the 303; the
   * gate, rendering with catching off, read the thrown 403 and failed a
   * declared redirect the site makes (F-253). This kernel does what Drupal
   * does: it throws the refusal with catching off and answers it, through
   * its listener, with catching on.
   */
  public function testRefusalAnsweredByListenersIsJudgedAsAnswered(): void {
    $listens = new class() implements HttpKernelInterface {

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        if (!$catch) {
          throw new AccessDeniedHttpException('access denied');
        }
        return new RedirectResponse('/user/login?destination=/my-registrations', 303);
      }

    };

    $declared = (new BootedSiteDriver($listens, static fn (): int => 0))->run(new GateSettings('rendered_check', TRUE, ['routes' => '/my-registrations@303']), '/tmp');
    $this->assertSame(GateStatus::Passed, $declared->status, 'a redirect made in kernel.exception is the redirect a visitor gets');

    $refusal = (new BootedSiteDriver($listens, static fn (): int => 0))->run(new GateSettings('rendered_check', TRUE, ['routes' => '/my-registrations@403']), '/tmp');
    $this->assertSame(GateStatus::Failed, $refusal->status, 'a visitor gets the 303, not the 403 the route threw');
    $problem = $refusal->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('answered 303', $problem);

    $bare = (new BootedSiteDriver($listens, static fn (): int => 0))->run(new GateSettings('rendered_check', TRUE, ['routes' => '/my-registrations']), '/tmp');
    $this->assertSame(GateStatus::Failed, $bare->status);
    $problem = $bare->findings[0]['problem'] ?? NULL;
    $this->assertIsString($problem);
    $this->assertStringContainsString('declare-route /my-registrations --status=303', $problem);
  }

  /**
   * A kernel that answers every request with one status.
   *
   * @param int $status
   *   The status.
   *
   * @return \Symfony\Component\HttpKernel\HttpKernelInterface
   *   The kernel.
   */
  private function kernel(int $status): HttpKernelInterface {
    return new class($status) implements HttpKernelInterface {

      public function __construct(private readonly int $status) {}

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('<html><body>page</body></html>', $this->status);
      }

    };
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
