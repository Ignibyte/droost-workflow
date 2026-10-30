<?php

declare(strict_types=1);

namespace Droost\Workflow\Tests\State;

use Droost\Workflow\State\InstallFreshness;
use Droost\Workflow\Tests\WorkflowTestCase;

/**
 * A run begins only on the droost the project's lock pins (owner, 2026-10-30).
 */
final class InstallFreshnessTest extends WorkflowTestCase {

  /**
   * An install behind its lock is named, with the command that fixes it.
   */
  public function testInstallBehindItsLockIsRefused(): void {
    $root = $this->projectWithLock('1276c2cbe645b15e78e373dce54bb4cf702e328f');

    $why = InstallFreshness::compare($root, 'e7ee9c5584bb1716ac95685c48232fc0fb7cd934', $root . '/tools/droost/vendor/droost/workflow');

    $this->assertIsString($why);
    $this->assertStringContainsString('droost here is e7ee9c5, but tools/droost/composer.lock pins 1276c2c', $why);
    $this->assertStringContainsString('ddev composer install -d tools/droost', $why);

    // As Composer reports it, through its own directory (2026-10-30:
    // read unresolved, the lock was looked for in vendor/composer).
    @mkdir($root . '/tools/droost/vendor/composer', 0755, TRUE);
    $reported = $root . '/tools/droost/vendor/composer/../droost/workflow';
    $this->assertIsString(InstallFreshness::compare($root, 'e7ee9c5584bb1716ac95685c48232fc0fb7cd934', $reported));
  }

  /**
   * The install its lock pins, or nothing to compare, says nothing.
   */
  public function testMatchingOrUncomparableInstallsPass(): void {
    $root = $this->projectWithLock('1276c2cbe645b15e78e373dce54bb4cf702e328f');
    $path = $root . '/tools/droost/vendor/droost/workflow';

    $this->assertNull(InstallFreshness::compare($root, '1276c2cbe645b15e78e373dce54bb4cf702e328f', $path), 'the pinned install');
    $this->assertNull(InstallFreshness::compare($root, 'e7ee9c5', '/elsewhere/vendor/droost/workflow'), 'an install outside the project');
    $this->assertNull(InstallFreshness::compare($this->makeRoot(), 'e7ee9c5', NULL), 'no install path');
    $bare = $this->makeRoot();
    @mkdir($bare . '/tools/droost', 0755, TRUE);
    file_put_contents($bare . '/tools/droost/composer.lock', (string) json_encode([
      'packages' => [['name' => 'droost/engine', 'source' => ['reference' => 'abc']]],
    ]));
    $this->assertNull(InstallFreshness::compare($bare, 'e7ee9c5', $bare . '/tools/droost/vendor/droost/workflow'), 'a lock that does not name droost/workflow');
  }

  /**
   * A project root holding tools/droost/composer.lock.
   *
   * @param string $reference
   *   The droost/workflow commit the lock pins.
   *
   * @return string
   *   The root.
   */
  private function projectWithLock(string $reference): string {
    $root = $this->makeRoot();
    @mkdir($root . '/tools/droost/vendor/droost/workflow', 0755, TRUE);
    file_put_contents($root . '/tools/droost/composer.lock', (string) json_encode([
      'packages' => [['name' => 'droost/workflow', 'source' => ['reference' => $reference]]],
    ]));
    return $root;
  }

}
