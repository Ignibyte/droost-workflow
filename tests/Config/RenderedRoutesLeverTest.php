<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\Config;

use Droost\Workflow\Config\ConfigError;
use Droost\Workflow\Config\WorkflowConfig;
use Droost\Workflow\Support\DataError;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * The lever declares each rendered_check route with the status it must answer.
 *
 * F-299: once the probe saw a login gate's redirect (F-285), a site whose
 * admin pages redirect anonymous visitors to log in could not say so in the
 * lever: the validator refused `@302`, which the README documents and the
 * parser reads.
 */
final class RenderedRoutesLeverTest extends WorkflowTestCase {

  /**
   * A route may carry 200, a refusal or a redirect.
   */
  public function testRoutesCarryTheirStatus(): void {
    $routes = '/user/login,/admin/client-portal@302,/admin/reports@403';
    $config = WorkflowConfig::load($this->makeRootWithConfig("preset: custom\ngates:\n  rendered_check: { on: true, routes: \"$routes\" }\n"));

    $this->assertSame($routes, $config->gates['rendered_check']->option('routes'));
  }

  /**
   * A status no route can be declared to answer is refused, by name.
   */
  public function testUndeclarableStatusIsRefused(): void {
    try {
      WorkflowConfig::load($this->makeRootWithConfig("preset: custom\ngates:\n  rendered_check: { on: true, routes: \"/teapot@418\" }\n"));
      $this->fail('418 is not a status a route can be declared to answer');
    }
    catch (ConfigError | DataError $e) {
      $this->assertStringContainsString('/teapot@418', $e->getMessage());
    }
  }

  /**
   * A path that climbs out is refused, status or not.
   */
  public function testTraversalIsRefused(): void {
    $this->expectException(\Throwable::class);
    WorkflowConfig::load($this->makeRootWithConfig("preset: custom\ngates:\n  rendered_check: { on: true, routes: \"../etc@302\" }\n"));
  }

}
