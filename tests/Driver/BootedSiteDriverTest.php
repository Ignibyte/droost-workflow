<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Driver;

use Droost\Workflow\Config\GateSettings;
use Droost\Workflow\Driver\BootedSiteDriver;
use Droost\Workflow\Gate\GateStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The rendered check through a booted kernel.
 */
class BootedSiteDriverTest extends TestCase {

  /**
   * A route that renders 200 with a body passes.
   */
  public function testRenderedRoutePasses(): void {
    $kernel = new class() implements HttpKernelInterface {

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        return new Response('<html><body>ok</body></html>', 200);
      }

    };
    $driver = new BootedSiteDriver($kernel, static fn (): int => 0);

    $result = $driver->run(new GateSettings('rendered_check', TRUE), '/tmp');

    $this->assertSame(GateStatus::Passed, $result->status);
    $this->assertSame([], $result->findings);
  }

  /**
   * A throwable during the render is the finding, WITH its origin.
   *
   * D70 round 2 recorded "threw Error: Call to a member function access() on
   * null" once, never reproduced it, and the record held no file, line or
   * frame to reason from. The finding now says where it threw and carries the
   * first frames, so a transient is diagnosable from run.json alone.
   */
  public function testThrowingRouteRecordsTheOrigin(): void {
    $kernel = new class() implements HttpKernelInterface {

      /**
       * {@inheritdoc}
       */
      public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
        $this->boom();
      }

      /**
       * Throws from a named method, so the trace has a frame to name.
       */
      private function boom(): never {
        throw new \Error('Call to a member function access() on null');
      }

    };
    $driver = new BootedSiteDriver($kernel, static fn (): int => 0);

    $result = $driver->run(new GateSettings('rendered_check', TRUE), '/tmp');

    $this->assertSame(GateStatus::Failed, $result->status);
    $this->assertSame('1 of 1 route(s) did not render', $result->summary);
    $this->assertCount(1, $result->findings);
    $finding = $result->findings[0];
    $this->assertSame('/', $finding['route']);
    $this->assertNull($finding['status']);
    $this->assertIsString($finding['problem']);
    $this->assertStringStartsWith('threw Error: Call to a member function access() on null at ', $finding['problem']);
    $this->assertMatchesRegularExpression('/ at BootedSiteDriverTest\.php:\d+$/', $finding['problem'], 'the origin file and line ride with the message');
    $this->assertIsArray($finding['trace']);
    $this->assertNotEmpty($finding['trace']);
    $this->assertLessThanOrEqual(5, count($finding['trace']), 'a tail, not the whole stack');
    $joined = '';
    foreach ($finding['trace'] as $frame) {
      $this->assertIsString($frame);
      $joined .= $frame . ' ';
    }
    // An instance call renders as "Class->boom()"; the method name is the
    // assertion, whichever call type the frame carries.
    $this->assertStringContainsString('boom()', $joined, 'the frames name the method that threw');
  }

}
